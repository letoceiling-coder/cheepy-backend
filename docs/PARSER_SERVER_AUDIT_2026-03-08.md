# Аудит парсера на сервере (без изменений)

**Дата:** 2026-03-08  
**Сервер:** root@85.117.235.93  
**Путь:** /var/www/online-parser.siteaacess.store

---

## 1. Текущее состояние

| Метрика | Значение |
|---------|----------|
| Latest ParserJob | #10, status=completed, type=full |
| saved_products | 1 399 877 |
| errors_count | 9 386 |
| Products total | 46 722 |
| Products today (parsed_at) | 26 220 |
| Products last hour | 2 996 |
| Failed jobs | 110 |
| Queue parser | 187 |
| Queue photos | 179 991 |
| Queue default | 6 |
| Workers | 9 |
| ParserLog errors today | 0 |
| ParserLog errors last hour | 0 |

---

## 2. Выявленные проблемы

### 2.1 Критично: ReleaseParserLockOnFinished — отсутствует use

**Симптом:** 109 ParseCategoryJob и 1 RunParserJob упали с:
```
ReflectionException: Class "App\Providers\ReleaseParserLockOnFinished" does not exist
```

**Причина:** В `AppServiceProvider.php` строка:
```php
Event::listen(ParserFinished::class, ReleaseParserLockOnFinished::class);
```
Класс `ReleaseParserLockOnFinished` не импортирован. PHP ищет его в текущем namespace (`App\Providers`), а реальный класс — `App\Listeners\ReleaseParserLockOnFinished`.

**Проверка на сервере:**
- `use App\Listeners\ReleaseParserLockOnFinished`: **НЕТ**
- Ссылка на ReleaseParserLockOnFinished: **ДА**

**Последствия:** При срабатывании `ParserFinished` Laravel пытается загрузить несуществующий класс, задача падает. Это затрагивает ParseCategoryJob (при завершении категории) и RunParserJob.

---

### 2.2 getOrCreateSellerForProduct — исправление есть, но были старые ошибки

**Симптом:** В ParserLog (до 2026-03-07 05:29) ошибки:
```
Return value must be of type ?App\Models\Seller, bool returned
```

**Проверка на сервере:** В `DatabaseParserService.php`:
- `return null`: **ЕСТЬ**
- Явный `return false`: **НЕТ**

Обработка `Cache::lock()->get()` возвращающей `false` в коде присутствует. Новых ошибок сегодня нет (0 за сегодня и за последний час). Старые записи — до деплоя фикса.

---

### 2.3 Несоответствие saved_products и Products total

- `saved_products` (ParserJob): 1 399 877  
- `Products::count()`: 46 722  

`saved_products` считает каждое успешное сохранение (create/update). Многие вызовы — обновления одних и тех же товаров, поэтому прирост записей в `products` небольшой. 26 220 товаров с `parsed_at` сегодня — реалистичное число для парсинга.

---

### 2.4 ParseProductJob — логика отмены

- В файле есть комментарий «Не прерываем по статусу job»
- В коде встречается упоминание `isCancelled` (в т.ч. в комментариях)

Задача `ParseProductJob` должна продолжаться до конца очереди при остановленном парсере. Состояние на сервере соответствует ранее задеплоенным изменениям.

---

## 3. Почему очереди «растут и падают»

1. **Падают:** 9 воркеров обрабатывают ~180k задач в `photos` — очередь уменьшается.
2. **Растут:** 187 задач в `parser` — это ParseCategoryJob. При выполнении они добавляют ParseProductJob в `photos`, затем доходят до `ParserFinished` (или другой точки), Laravel вызывает слушатель `ReleaseParserLockOnFinished` → ReflectionException → задача падает, при ретрае снова пытается выполниться. Часть ParseCategoryJob успевает поставить новые ParseProductJob до падения, поэтому число задач в `photos` может временно увеличиваться.

---

## 4. Почему «товары не растут»

- `Products total` и `products_today` — это количество строк в `products` с учётом `parsed_at`.
- Большинство задач в очереди — обновление уже существующих товаров.
- `products_today` (26 220) и `products last hour` (2 996) показывают, что сохранение идёт.
- Визуально «не растёт» может означать:
  - стабильное или медленное изменение прироста за счёт updates;
  - отображение `products_per_minute` из скользящего окна за последний час, а не текущей скорости.

---

## 5. Рекомендации (без внесения правок в этой сессии)

| № | Проблема | Рекомендация |
|---|----------|--------------|
| 1 | ReleaseParserLockOnFinished | Добавить `use App\Listeners\ReleaseParserLockOnFinished;` в `AppServiceProvider.php` |
| 2 | 110 failed jobs | После исправления (1) очистить `failed_jobs` или разобрать вручную |
| 3 | 187 ParseCategoryJob в parser | После исправления (1) они смогут отрабатывать без падения на ParserFinished |

---

## 6. Структура Failed Jobs

| Job | Count |
|-----|-------|
| App\Jobs\ParseCategoryJob | 109 |
| App\Jobs\RunParserJob | 1 |

Все они связаны с отсутствующим классом `App\Providers\ReleaseParserLockOnFinished`.

---

*Отчёт сформирован скриптом `scripts/audit-parser.php` без внесения изменений в код или данные на сервере.*
