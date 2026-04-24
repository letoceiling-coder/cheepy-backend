# Парсер — полная документация (master)

> Master-документ по парсеру Садоводбазы. Остальные файлы `PARSER_*.md`,
> `parser-*.md` — исторические аудиты/отчёты, смотрите их только для контекста
> прошлых инцидентов. Для повседневной работы — этот файл.

## Содержание
- [Что делает парсер](#что-делает-парсер)
- [Архитектура](#архитектура)
- [Режимы работы (3 режима)](#режимы-работы-3-режима)
- [Настройки в админке](#настройки-в-админке-admin)
- [Жизненный цикл прогона](#жизненный-цикл-прогона)
- [Доступность товаров (`is_relevant`)](#доступность-товаров-is_relevant)
- [Диагностика «парсер не работает»](#диагностика-парсер-не-работает)
- [Типовые команды на сервере](#типовые-команды-на-сервере)
- [Supervisor / воркеры](#supervisor--воркеры)
- [Частые вопросы UX](#частые-вопросы-ux)

---

## Что делает парсер

Забирает товары с **sadovodbaza.ru** (донор) в локальную БД `sadavod_parser`.

Источники на донере:
- **Меню категорий** — один раз в 6 часов синхронизируется в `categories`.
- **Листинг категории** (постранично) — основной источник товаров. URL вида
  `https://sadovodbaza.ru/{category-slug}?page=N`. Возвращает JSON с массивом
  товаров: id, title, price, photos, seller, флаг `has_more`.
- **Страница товара** — `https://sadovodbaza.ru/odejda/{external_id}`. Содержит
  детали (описание, характеристики, фото в большом разрешении). HTTP-запрос к
  ней — самая дорогая операция, выполняется только когда нужно (см. режимы).

---

## Архитектура

### Ключевые таблицы
| Таблица | Назначение |
|---|---|
| `parser_settings` | Одна строка с глобальными настройками парсера |
| `parser_state` | Одна строка: `status` (running/stopped/paused/paused_network), `network_mode` |
| `parser_jobs` | История прогонов: type, status, parsed_categories, saved_products, errors_count, started_at/finished_at |
| `categories` | Дерево категорий донора + флаги `enabled`, `parser_selected`, `last_parsed_at` |
| `products` | Товары донера: `external_id`, `title`, `price`, `status`, **`is_relevant`**, **`relevance_checked_at`**, `parsed_at`, `category_id`, `seller_id` |
| `product_photos` | Ссылки/файлы фото товаров |
| `parser_logs` | Детальные логи (info/warn/error) для UI |

### Ключевые классы
| Класс | Назначение |
|---|---|
| `App\Models\ParserSetting` | Singleton настройки (см. таблицу выше) |
| `App\Support\ParserJobOptions` | Строит `options` для `parser_jobs.options`. SSOT для воркера |
| `App\Jobs\ParserDaemonJob` | Петля демона: каждые `daemon_interval_seconds` проверяет условия и создаёт новый `ParserJob` |
| `App\Jobs\RunParserJob` | Запускает `DatabaseParserService::run()` для конкретного ParserJob |
| `App\Jobs\ParseCategoryJob` | Обходит одну категорию (вызывается из `DatabaseParserService::runFullPipeline()`) |
| `App\Jobs\DownloadPhotoJob` / `DownloadPhotosJob` | Скачивание фото (одиночно/батчем) |
| `App\Jobs\CleanupUnavailableProductsJob` | Hourly HEAD-проверка доступности товаров |
| `App\Services\DatabaseParserService` | Сердце парсинга: `parseCategoryPages`, `saveProductFromListing` |

### Очереди (Redis)
- `queues:parser` — `ParseCategoryJob`, `RunParserJob`, `ParserDaemonJob`.
- `queues:photos` — `DownloadPhotoJob`, `DownloadPhotosJob`.
- `queues:default` — `CleanupUnavailableProductsJob`, auxiliary jobs.

**Важно**: `Queue::size('parser')` = `LLEN + ZCARD(delayed) + ZCARD(reserved)`.
Для пользовательской диагностики «сколько реально ждёт обработки» используйте
`Redis::llen('queues:parser')`.

### Scheduler (`routes/console.php`)
- `parser:watchdog` — каждые 5 мин, рестарт при зависании.
- `parser:network-recover` — каждые 5 мин, восстановление после `paused_network`.
- `parser:lock-heartbeat` — каждые 30 сек, продлевает `parser_lock` TTL.
- `scheduler-full-parser` — cron `0 */6 * * *`, создаёт `ParserJob` (гарантия
  хотя бы одного полного прогона каждые 6 часов).
- `scheduler-download-photos-batch` — hourly, диспатчит `DownloadPhotosJob(100)`
  (пропускает если `download_photos=false`).
- `cleanup-unavailable-products` — hourly HEAD-проверка 100 товаров.
- `queue:prune-failed --hours=168` — daily 03:00, чистит старше 7 дней.

---

## Режимы работы (3 режима)

Комбинация двух флажков в админке определяет режим:

### 1. «Только новые» (рекомендация для фоновой работы)
```
update_existing = OFF
update_availability_only = (не важно)
```
- Листинг каждой категории обходится страница за страницей.
- Для каждой страницы одним SQL (`WHERE external_id IN …`) отсекаются уже
  существующие в БД товары.
- Для **новых** → полный `saveProductFromListing` (HTTP на детали + upsert + фото).
- **Early-exit**: если подряд `incremental_tail_pages` страниц целиком состоят
  из existing → выход из категории («догнали хвост»). По умолчанию 3.

**Скорость**: ~4 минуты на все 132 категории. **Нагрузка на донор минимальна**.

### 2. «Обновление: только доступность» (быстрое обновление)
```
update_existing = ON
update_availability_only = ON   (по умолчанию ON)
```
- Обход всех страниц всех категорий целиком (без early-exit).
- Для existing external_id → **batch UPDATE** `is_relevant=true`,
  `relevance_checked_at=NOW()`. БЕЗ HTTP на страницу товара.
- Для **новых** → полный `saveProductFromListing`.
- После прохода категории: товары этой `category_id`, которых мы НЕ видели, →
  `is_relevant=false` + `relevance_checked_at=NOW()`. Есть safety-порог:
  отметка применяется только если увидели ≥ 50% от прежнего `products_count`
  категории (защита от сбоев донора).

**Скорость**: ~10x быстрее полного режима. **Использовать еженедельно** или
когда нужно синхронизировать наличие.

### 3. «Полное обновление» (тяжёлый режим)
```
update_existing = ON
update_availability_only = OFF
```
- Как (2), но для existing товаров тоже делается **полный upsert с HTTP на
  детали** (описание, характеристики, фото).
- Занимает часы.
- Нужен **редко** — когда меняли правила извлечения характеристик и надо
  перетащить их для всех товаров.

---

## Настройки в админке (/admin)

Страница **/admin/parser**, секция «Настройки».

| Поле | Тип | Дефолт | Смысл |
|---|---|---|---|
| `download_photos` | Switch | OFF | Скачивать файлы фото |
| `store_photo_links` | Switch | ON | Сохранять ссылки на фото (без скачивания) |
| `download_medium` | Switch | OFF | Дополнительно качать medium-версию |
| `update_existing` | Switch | ON | Полный режим (ON) vs «только новые» (OFF) |
| `update_availability_only` | Switch | ON | Для режима обновления: только проверка доступности. Заблокирован если `update_existing=OFF` |
| `incremental_tail_pages` | int 1..10 | 3 | Глубина early-exit в режиме «только новые» |
| **`daemon_interval_seconds`** | int 30..600 | **180** | Интервал между итерациями демона. 60 = 12 прогонов/час (тяжело для донора), 180 = 6/час (рекомендуется), 300 = 3/час |
| `workers_parser` | int 1..20 | 2 | Число воркеров для `queues:parser` (применяется через supervisor `numprocs`) |
| `workers_photos` | int 1..20 | 1 | Число воркеров для `queues:photos` |
| `request_delay_min` / `_max` | ms | 1500 / 3000 | Пауза между HTTP к донеру |
| `timeout_seconds` | int 5..300 | 60 | HTTP timeout |
| `proxy_enabled` + `proxy_urls[]` | bool + list | ON | Прокси-ротация |
| `queue_threshold` | int | 150 | Потолок очереди `parser`: при превышении демон не создаёт новый ParserJob |
| `default_max_pages` | int | 0 | 0 = без лимита, иначе — максимум страниц/категория |
| `default_products_per_category` | int | 0 | 0 = без лимита, иначе — максимум товаров/категория |
| `default_category_ids[]` | int[] | [] | Категории для полного режима (если пусто — берутся все `enabled=true`) |
| `default_linked_only` | Switch | OFF | Парсить только `linked_to_parser=true` категории |
| `default_no_details` | Switch | OFF | Не тянуть детали товара (чисто листинг) — экспериментальный |

### Кнопки
- **Запустить (ручной)** — `POST /api/v1/parser/start` одноразовый прогон.
- **Остановить** — выставляет `ParserState=stopped`, ставит cancellation flag.
- **Включить демон** — `POST /admin/parser/start-daemon` + диспатч `ParserDaemonJob`.
- **Освободить блокировку** — `DEL parser_lock` в Redis (крайняя мера при зависшем lock).
- **Сброс очередей** — чистит `queues:parser`, `photos`, `default`.

---

## Жизненный цикл прогона

```
[cron * * * *]  schedule:run
      │
      ▼
[ParserDaemonJob] ──── state=running? ──── нет → выход
      │
      ├── есть running/pending ParserJob? ─── да → self::dispatch(delay=180s), return
      ├── LLEN queues:parser > 0?         ─── да → self::dispatch(delay=180s), return
      ├── SET parser_lock EX=1200 NX      ─── fail → return
      ├── LLEN queues:parser > 150?       ─── да → DEL parser_lock, log QUEUE BLOCKED, return
      │
      ├── ParserJob::create(type=full, status=pending)
      ├── RunParserJob::dispatch(jobId) ──┐
      └── self::dispatch(delay=180s)       │
                                           ▼
                                      [RunParserJob]
                                           │
                                           ├── parser_jobs.status=running
                                           ├── DatabaseParserService::run()
                                           │      └── runFullPipeline()
                                           │             ├── MENU sync (раз в 6ч)
                                           │             ├── для каждой Category:
                                           │             │     ParseCategoryJob::dispatch
                                           │             └── return (132 джоба в очереди)
                                           │
                                           └── (job НЕ помечается completed, pipeline в флайте)
                                                      ▼
                                            [ParseCategoryJob] × 132 (parallel: workers_parser)
                                                      │
                                                      ├── DatabaseParserService::runCategoryPipeline($cat)
                                                      │      ├── parseCategoryPages()
                                                      │      │    ├── fetch page N
                                                      │      │    ├── existing external_id lookup
                                                      │      │    ├── batch UPDATE availability (если режим 2)
                                                      │      │    └── saveProductFromListing для новых
                                                      │      ├── финальный UPDATE исчезнувших is_relevant=false (режим 2)
                                                      │      └── category.last_parsed_at=now()
                                                      │
                                                      └── maybeCompleteFullPipelineJob()
                                                            └── последний джоб в пакете помечает
                                                                parser_jobs.status=completed, finished_at=now()
```

### Heartbeat
`parser:lock-heartbeat` каждые 30 сек: если есть running `ParserJob`, продлевает
TTL `parser_lock` до 1200 сек (20 мин). Это защищает от stale lock если worker
упал, не удаляя lock.

---

## Доступность товаров (`is_relevant`)

### Как проставляется
1. **В режиме (2) «availability-only»**:
   - Видели товар в листинге → `is_relevant=true`, `relevance_checked_at=NOW()`.
   - Не видели при полном обходе категории → `is_relevant=false`.
2. **`CleanupUnavailableProductsJob`** (hourly):
   - Берёт 100 товаров с самой старой `relevance_checked_at` (или NULL).
   - HEAD-запрос к `/odejda/{external_id}` с прокси + rate-limit.
   - 200/3xx → `is_relevant=true`.
   - 404/410 → `is_relevant=false`.
   - 403/429/5xx → не меняет, только `relevance_checked_at=NOW()` (чтобы не застрять).

### Как использовать на витрине
- Показывать только `is_relevant=true` для каталога.
- Администратор видит все, но с бейджем «Недоступен» если `is_relevant=false`.
- `status` **не меняется** автоматически — решение о скрытии принимает CRM/оператор.

---

## Диагностика «парсер не работает»

Чеклист в порядке убывания вероятности:

### 1. UX vs реальность
Прежде чем лезть на сервер — убедитесь, что проблема НЕ в UI:

| Симптом на UI | Что это на самом деле |
|---|---|
| «Блокировка: Активна» (нейтральный бейдж) | Норма, идёт прогон |
| «Блокировка: Stale» (красный) | Реально зависший lock → жмите «Освободить блокировку» |
| «Очередь парсера: N» | Мгновенный снимок. За секунды падает до 0 |
| «Категория X: новых нет» | Норма в режиме «только новые» |
| «Failed jobs: 0» | Всё ок |
| «Failed jobs: >0» | Идите в п.5 |

### 2. Supervisor и воркеры
```bash
supervisorctl status
ps -eo pid,etime,cmd --no-headers | grep queue:work | grep -v grep
```
Должны быть `parser-worker_00/01[/02/03]` RUNNING, `parser-worker-default_00`, `parser-worker-photos_00`.

Если STOPPED/FATAL:
```bash
supervisorctl restart parser-worker:
tail -n 100 /var/www/online-parser.siteaacess.store/storage/logs/worker.log
```

### 3. Очереди Redis
```bash
redis-cli <<EOF
LLEN queues:parser
LLEN queues:photos
LLEN queues:default
ZCARD queues:parser:delayed
ZCARD queues:parser:reserved
GET parser_lock
TTL parser_lock
EOF
```
- `LLEN queues:parser > 200` — заторможены воркеры. Проверить п.2.
- `parser_lock` есть + ParserJob `status=running` → норма.
- `parser_lock` есть + нет `running` ParserJob → **stale**. Удалить: `DEL parser_lock`.
- `ZCARD parser:delayed` растёт → демон перепланирует себя, но не запускает
  новый прогон (условие не выполнилось). Проверить логи.

### 4. История ParserJob
```sql
SELECT id, type, status, parsed_categories, saved_products, errors_count,
       TIMESTAMPDIFF(SECOND, started_at, COALESCE(finished_at, NOW())) AS dur_sec,
       started_at, finished_at
FROM parser_jobs
ORDER BY id DESC LIMIT 20;
```
Ожидаемо: `status=completed`, `parsed_categories=132/132`, `dur_sec=200..350`.

- Все `status=failed` → смотреть `error_message`.
- Один висит `running` больше 30 мин → ручной stop или расследование.

### 5. Failed jobs
```sql
SELECT queue, SUBSTRING_INDEX(exception, "\n", 1) AS err, COUNT(*) cnt,
       MAX(failed_at) last_at
FROM failed_jobs GROUP BY queue, err ORDER BY cnt DESC LIMIT 10;
```
- `TimeoutExceededException App\Jobs\DownloadPhotosJob` → безопасно (если
  `download_photos=false`, scheduler уже пропускает диспатч).
- `Duplicate entry` в `product_attribute_values` → race condition, уже
  защищено try-catch. Игнорировать если единичные.

Очистка: `php artisan queue:flush` (все) или targeted delete.

### 6. Логи Laravel
```bash
tail -n 200 storage/logs/laravel-$(date +%Y-%m-%d).log | grep -E 'ERROR|CRITICAL|PARSER|CATEGORY'
```
Ключевые маркеры:
- `CATEGORY EARLY-EXIT: incremental tail reached` — норма для режима «только новые».
- `CATEGORY HARD PAGE CAP REACHED` — категория зациклилась, хард-кап 300 страниц сработал.
- `AVAILABILITY PASS SKIPPED: seen count below safety threshold` — в режиме (2)
  категория отдала мало товаров → маркировка исчезнувших пропущена (защита).
- `QUEUE BLOCKED` — `queue_threshold` превышен, демон ждёт.
- `OPTIONS EMPTY - JOB BLOCKED` → критичная ошибка в build options. Смотреть в
  `parser_settings`.

### 7. Дашборд не обновляется
- Проверить Reverb: `supervisorctl status reverb`.
- `curl https://online-parser.siteaacess.store/api/v1/admin/parser/diagnostics` —
  отдаёт ли JSON.
- Кэш: иногда нужен `php artisan cache:clear`.

---

## Типовые команды на сервере

```bash
cd /var/www/online-parser.siteaacess.store

# Статус всего
supervisorctl status
redis-cli LLEN queues:parser
redis-cli GET parser_lock

# Глянуть что делает парсер прямо сейчас
php artisan tinker --execute='
$j = App\Models\ParserJob::whereIn("status",["running","pending"])->latest()->first();
echo $j ? "#{$j->id} {$j->status} cat={$j->parsed_categories}/{$j->total_categories} action=".$j->current_action.PHP_EOL : "idle".PHP_EOL;
'

# Последние прогоны
php artisan tinker --execute='
foreach(App\Models\ParserJob::latest("id")->limit(10)->get() as $j) {
  $dur = $j->started_at && $j->finished_at ? strtotime($j->finished_at) - strtotime($j->started_at) : "?";
  printf("#%d %s/%s cat=%s/%s saved=%s err=%s dur=%ss\n",
    $j->id, $j->type, $j->status, $j->parsed_categories, $j->total_categories,
    $j->saved_products, $j->errors_count, $dur);
}
'

# Принудительный старт одного прогона (без демона)
php artisan tinker --execute='
$opts = App\Support\ParserJobOptions::buildFromSettings();
$j = App\Models\ParserJob::create(["type"=>"full","status"=>"pending","options"=>$opts]);
App\Jobs\RunParserJob::dispatch($j->id);
echo "dispatched #{$j->id}".PHP_EOL;
'

# Экстренная остановка
redis-cli DEL parser_lock
php artisan tinker --execute='
App\Models\ParserState::current()->update(["status"=>"stopped"]);
App\Models\ParserJob::whereIn("status",["running","pending"])->update(["status"=>"stopped","finished_at"=>now()]);
'
# Очистка всех очередей
redis-cli <<EOF
DEL queues:parser queues:parser:delayed queues:parser:reserved queues:parser:notify
DEL queues:photos queues:photos:delayed queues:photos:reserved queues:photos:notify
DEL queues:default queues:default:delayed queues:default:reserved queues:default:notify
EOF

# Включить демон обратно
php artisan tinker --execute='
App\Models\ParserState::current()->update(["status"=>"running"]);
App\Jobs\ParserDaemonJob::dispatch();
'

# Чистка failed_jobs
php artisan queue:flush
# или прицельно
php artisan tinker --execute='DB::table("failed_jobs")->where("queue","photos")->delete();'
```

---

## Supervisor / воркеры

Три программы в `/etc/supervisor/conf.d/`:

### parser-worker.conf
```ini
[program:parser-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/online-parser.siteaacess.store/artisan queue:work redis --queue=parser --sleep=3 --tries=3 --max-time=3600
numprocs=4     ; 2 было узким местом. 4 обрабатывает 132 категории за ~2 мин
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
stopwaitsecs=3600
```

### parser-worker-default.conf / parser-worker-photos.conf
`numprocs=1` каждый — одиночные воркеры для default/photos.

### После правки конфига
```bash
supervisorctl reread
supervisorctl update
supervisorctl status
```

`supervisorctl restart all` НЕ использовать — долго останавливает parser-worker
(stopwaitsecs=3600). Таргетно: `supervisorctl restart parser-worker:` (с двоеточием).

---

## Частые вопросы UX

### «Почему не растут товары?»
Вы в режиме «только новые» (`update_existing=OFF`). Парсер добавляет **только
впервые встреченные** external_id. Количество прибавляющихся — это то, что
донер публикует заново. Наблюдаемая норма: 100–300 новых в сутки.

Если нужно «разогнать» — включите `update_existing=ON` + `update_availability_only=ON`
на сутки. Это не добавит товаров (они уже в БД), но синхронизирует доступность.

### «Почему dashboard пишет что-то про блокировку?»
- «Активна» (нейтральный бейдж) = прогон идёт, всё хорошо.
- «Stale» (красный) = lock без живого прогона → кнопка «Освободить блокировку».
- «Свободна» = между прогонами.

### «Почему в событиях “Категория X: новых нет”?»
В категории не появилось ничего нового с прошлого обхода. Норма.

### «Сколько реально прогонов в сутки?»
При `daemon_interval_seconds=180` + длительность прогона ~4 мин → ~8–10/час
≈ **200–240 прогонов в сутки**. Плюс 4 прогона от `scheduler-full-parser`
(каждые 6 часов, страховочный).

### «Когда можно считать что парсер завис?»
- `parser_state=running` **и** `parser_lock=yes` **и** ParserJob `running` **и**
  `started_at` старше **20 минут** **и** `current_action` не меняется **и**
  `parsed_categories` не увеличивается.

Тогда: `parser:watchdog` ребутнет воркер через 10 минут; вручную —
`supervisorctl restart parser-worker:` + ручная чистка lock/stop старого job.
