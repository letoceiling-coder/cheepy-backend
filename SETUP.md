# Настройка sadavod-laravel в OSPanel

## 1. Virtual Host в OSPanel

**Домен:** `sadavod.loc`
**Document Root:** `C:\OSPanel\domains\sadavod-laravel\public`
**PHP версия:** 8.2+ (рекомендуется 8.3)

В OSPanel Manager:
1. Добавить домен `sadavod.loc`
2. Установить корень: `C:\OSPanel\domains\sadavod-laravel\public`
3. Включить `mod_rewrite` (необходим для Laravel routing)

## 2. Первичная настройка

```bash
cd C:\OSPanel\domains\sadavod-laravel

composer install --no-dev --optimize-autoloader
php artisan key:generate
php artisan storage:link
php artisan migrate --force
php artisan db:seed --force
php artisan config:cache
php artisan route:cache
```

## 3. База данных MySQL

```
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=sadavod_pars
DB_USERNAME=root
DB_PASSWORD=
```

База `sadavod_pars` создаётся автоматически при первом `migrate`.

## 4. Текущий .env

```
APP_URL=http://sadavod.loc
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=sadavod_pars
DB_USERNAME=root
DB_PASSWORD=

SADAVOD_DONOR_URL=https://sadovodbaza.ru
SADAVOD_REQUEST_DELAY_MS=500
SADAVOD_VERIFY_SSL=false
SADAVOD_USER_AGENT="Mozilla/5.0 Chrome/120.0.0.0"

FRONTEND_URL=http://cheepy.loc
JWT_SECRET=sadavod_jwt_secret_key_production_32ch

ADMIN_EMAIL=admin@sadavod.loc
ADMIN_PASSWORD=admin123
```

## 5. Страницы и маршруты

### Веб-каталог (аналог старого sadavod)
| URL | Описание |
|-----|----------|
| `http://sadavod.loc/` | Каталог всех товаров |
| `http://sadavod.loc/?category={slug}` | Товары категории |
| `http://sadavod.loc/product/{external_id}` | Страница товара |

### Admin API (JWT required)
| URL | Описание |
|-----|----------|
| `POST /api/v1/auth/login` | Вход (email + password) |
| `GET /api/v1/dashboard` | Статистика |
| `GET /api/v1/parser/status` | Статус парсера |
| `POST /api/v1/parser/start` | Запуск парсера |
| `POST /api/v1/parser/stop` | Остановка |
| `GET /api/v1/parser/jobs` | История заданий |
| `GET /api/v1/products` | Список товаров (фильтры) |
| `GET /api/v1/categories` | Список категорий |
| `GET /api/v1/sellers` | Список продавцов |
| `GET /api/v1/brands` | Бренды CRUD |
| `GET /api/v1/excluded` | Правила исключений |
| `GET /api/v1/filters` | Настройки фильтров |
| `GET /api/v1/settings` | Настройки системы |
| `GET /api/v1/logs` | Логи парсера |

### Public API (без авторизации — для Cheepy frontend)
| URL | Описание |
|-----|----------|
| `GET /api/v1/public/menu` | Меню категорий |
| `GET /api/v1/public/categories/{slug}/products` | Товары категории + фильтры |
| `GET /api/v1/public/products/{id}` | Карточка товара |
| `GET /api/v1/public/sellers/{slug}` | Страница продавца |
| `GET /api/v1/public/search?q=...` | Поиск |
| `GET /api/v1/public/featured` | Рекомендованные товары |

## 6. Запуск парсера

### Через API
```bash
curl -X POST http://sadavod.loc/api/v1/parser/start \
  -H "Authorization: Bearer {JWT_TOKEN}" \
  -H "Content-Type: application/json" \
  -d '{
    "type": "menu_only",
    "save_to_db": true
  }'
```

### Типы парсинга
| type | Описание |
|------|----------|
| `menu_only` | Только категории (быстро, ~30 сек) |
| `category` | Одна категория (нужен `category_slug`) |
| `full` | Все категории (долго) |

### Параметры
```json
{
  "type": "category",
  "category_slug": "platya",
  "max_pages": 5,
  "products_per_category": 50,
  "no_details": false,
  "save_photos": false,
  "save_to_db": true
}
```

### Через Artisan (для cron/ручного запуска)
```bash
# Создать job вручную, потом запустить
php artisan parser:run {job_id}
```

## 7. Состояние БД (на момент настройки)
- **334 категории** из sadovodbaza.ru
- **3074+ товаров** (Платья, Женские костюмы, Искусственные цветы, Панамы)
- **2 продавца** (с деталями)
- **9 заданий парсера** в истории

## 8. Хранилище фотографий

Фотографии сохраняются в:
```
C:\OSPanel\domains\sadavod-laravel\storage\app\photos\{product_id}\
```

Доступны по URL: `http://sadavod.loc/storage/photos/{product_id}/{filename}`

Запуск загрузки фото:
```bash
curl -X POST http://sadavod.loc/api/v1/parser/photos \
  -H "Authorization: Bearer {TOKEN}" \
  -d '{"product_ids": [14371251, 13679582]}'
```

## 9. Frontend (Cheepy)

В `C:\OSPanel\domains\cheepy\.env.local`:
```
VITE_API_URL=http://sadavod.loc/api/v1
```
