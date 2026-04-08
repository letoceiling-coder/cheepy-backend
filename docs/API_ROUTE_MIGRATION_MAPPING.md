# API Route Migration — Parser vs CRM Separation

## New Routes Structure

### 1) PARSER (Admin Panel) — `/api/v1/admin/parser`

| Method | Path | Controller | Description |
|--------|------|------------|-------------|
| GET | /admin/parser/products | ProductController@index | List donor products |
| GET | /admin/parser/products/{id} | ProductController@show | Donor product detail |
| PATCH | /admin/parser/products/{id} | ProductController@update | Update donor product |
| DELETE | /admin/parser/products/{id} | ProductController@destroy | Delete donor product |
| POST | /admin/parser/products/bulk | ProductController@bulk | Bulk action |
| GET | /admin/parser/sellers | SellerController@index | List donor sellers |
| GET | /admin/parser/sellers/{slug} | SellerController@show | Donor seller detail |
| GET | /admin/parser/sellers/{slug}/products | SellerController@products | Seller's products |
| PATCH | /admin/parser/sellers/{id} | SellerController@update | Update donor seller |
| GET | /admin/parser/status | ParserController@status | Parser status |
| GET | /admin/parser/state | ParserController@state | Parser state |
| GET | /admin/parser/settings | ParserController@settings | Parser settings |
| POST | /admin/parser/settings | ParserController@updateSettings | Update parser settings |
| GET | /admin/parser/stats | ParserController@stats | Parser stats |
| ... | ... | ... | (all other parser/* routes) |

### 2) CRM — `/api/v1/crm`

| Method | Path | Controller | Description |
|--------|------|------------|-------------|
| GET | /crm/system-products | SystemProductController@index | List system products |
| POST | /crm/system-products | SystemProductController@store | Create system product |
| POST | /crm/system-products/create-from-donor | SystemProductController@createFromDonor | Create from donor |
| GET | /crm/system-products/{id} | SystemProductController@show | System product detail |
| PATCH | /crm/system-products/{id} | SystemProductController@update | Update system product |
| DELETE | /crm/system-products/{id} | SystemProductController@destroy | Delete system product |

---

## Mapping: Old → New Endpoints

### Parser Admin Panel (must update api.ts)

| Old (deprecated) | New |
|------------------|-----|
| GET /products | GET /admin/parser/products |
| GET /products/{id} | GET /admin/parser/products/{id} |
| PATCH /products/{id} | PATCH /admin/parser/products/{id} |
| DELETE /products/{id} | DELETE /admin/parser/products/{id} |
| POST /products/bulk | POST /admin/parser/products/bulk |
| GET /sellers | GET /admin/parser/sellers |
| GET /sellers/{slug} | GET /admin/parser/sellers/{slug} |
| GET /sellers/{slug}/products | GET /admin/parser/sellers/{slug}/products |
| PATCH /sellers/{id} | PATCH /admin/parser/sellers/{id} |
| GET /parser/status | GET /admin/parser/status |
| GET /parser/state | GET /admin/parser/state |
| GET /parser/settings | GET /admin/parser/settings |
| POST /parser/settings | POST /admin/parser/settings |
| GET /parser/stats | GET /admin/parser/stats |
| POST /parser/start | POST /admin/parser/start |
| ... (all /parser/*) | ... (all /admin/parser/*) |

### CRM (must use crm paths)

| Old (deprecated) | New |
|------------------|-----|
| GET /system-products | GET /crm/system-products |
| POST /system-products | POST /crm/system-products |
| POST /system-products/create-from-donor | POST /crm/system-products/create-from-donor |
| GET /system-products/{id} | GET /crm/system-products/{id} |
| PATCH /system-products/{id} | PATCH /crm/system-products/{id} |
| DELETE /system-products/{id} | DELETE /crm/system-products/{id} |

---

## Access Rules

| Panel | MUST use | MUST NOT use |
|-------|----------|--------------|
| **Parser (Admin)** | /admin/parser/products, /admin/parser/sellers, /admin/parser/* | /crm/system-products |
| **CRM** | /crm/system-products | /admin/parser/products, /admin/parser/sellers |

---

## Unchanged Routes (shared)

- GET /dashboard
- GET /admin/ai/metrics
- /categories, /brands, /excluded, /filters, /logs, /settings
- /admin/catalog/* (from admin_catalog.php)

---

## Frontend api.ts Updates (cheepy)

| Module | Change |
|--------|--------|
| parserApi | All paths: `/parser/*` → `/admin/parser/*` |
| productsApi | All paths: `/products*` → `/admin/parser/products*` |
| sellersApi | All paths: `/sellers*` → `/admin/parser/sellers*` |
| crmApi (NEW) | System products: `/crm/system-products*` |
