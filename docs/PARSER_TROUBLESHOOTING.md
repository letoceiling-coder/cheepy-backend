# Диагностика парсера: товары не добавляются

## Причина (найдена 06.03.2026)

### 1. Орфанные задачи в очереди

**Симптом:** Парсер "работает", в очереди 1000+ задач, но `saved_products = 0`.

**Причина:** В очереди лежат задачи `ParseProductJob` и `ParseCategoryJob` с `parser_job_id = 1`, но ParserJob #1 удалён или не существует. При обработке:
```
ParserJob::find(1) → null → Log::warning('ParserJob not found') → return (ничего не сохраняется)
```

### 2. Отсутствие воркеров

**Симптом:** `parser:diagnostics` показывает Workers: 0 при running процессах.

**Проверка:**
```bash
supervisorctl status
ps aux | grep "queue:work"
```

### 3. RunParserJob failed

Ошибка `RunParserJob has been attempted too many times` — старый RunParserJob исчерпал попытки.

---

## Инструкция по восстановлению

### Шаг 1. Остановить парсер и очистить очереди

```bash
ssh root@85.117.235.93
cd /var/www/online-parser.siteaacess.store

# Сброс: остановить jobs, очистить очереди, перезапустить воркеры
php artisan parser:reset --force
```

### Шаг 2. Проверить воркеры

```bash
supervisorctl status
# parser-worker_00..05 — RUNNING
# parser-worker-photos_00..01 — RUNNING

# Если не RUNNING:
supervisorctl start parser-worker:*
supervisorctl start parser-worker-photos:*
```

### Шаг 3. Запустить парсер заново

Через админку: **Управление парсером** → **Полное управление** → **Запустить**

Или через API/CLI:
```bash
php artisan parser:start
```

---

## Полное логирование

### Логи Laravel

```bash
tail -f /var/www/online-parser.siteaacess.store/storage/logs/laravel.log
```

### Диагностика

```bash
php artisan parser:diagnostics
```

Вывод: очереди, failed jobs, lock, workers, продукты.

### Failed jobs

```bash
php artisan queue:failed
```

### Очереди Redis (если установлен redis-cli)

```bash
redis-cli
LLEN sadavodparser-database-queues:parser
LLEN sadavodparser-database-queues:photos
LLEN sadavodparser-database-queues:default
```

### Parser jobs в БД

```bash
php artisan tinker
>>> \App\Models\ParserJob::latest()->take(5)->get(['id','status','parsed_categories','saved_products']);
```

---

## Включение подробных логов

В `config/logging.php` уровень `LOG_LEVEL=debug` для канала `stack`.

Или в `.env`:
```
LOG_LEVEL=debug
```

После отладки вернуть `LOG_LEVEL=info` или `warning`.

---

## Чеклист при "парсер работает, но товары не добавляются"

| # | Проверка | Команда | Ожидание |
|---|----------|---------|----------|
| 1 | Очереди | `php artisan parser:diagnostics` | parser > 0, workers > 0 |
| 2 | Воркеры | `supervisorctl status` | RUNNING |
| 3 | Failed jobs | `php artisan queue:failed` | Пусто или разобрать ошибки |
| 4 | ParserJob в БД | `parser_jobs` | Есть running/pending с нужным id |
| 5 | Орфанные задачи | Лог "ParserJob not found" | Очистить очередь, сброс, новый старт |
| 6 | Lock | `Redis::get('parser_lock')` | Освободить при необходимости: `parser:release-lock` |
