# System Products Architecture

## Separation of Parser Data from System Products

### Current Usage (CONFIRMED)

| Usage | File | Method |
|-------|------|--------|
| **Parser WRITES** to products | `app/Services/DatabaseParserService.php` | Line 368: `Product::upsertFromParser($pData, $category?->id, $seller?->id)` |
| **Admin EDITS** products | `app/Http/Controllers/Api/ProductController.php` | Lines 96-100: `update()`, 106-109: `destroy()`, 116-130: `bulk()` |
| **Admin UI** uses products | `cheepy/src/admin/pages/ProductsPage.tsx` | `productsApi.list()`, `productsApi.bulk()` |
| | `cheepy/src/admin/pages/ProductDetailPage.tsx` | `productsApi.get()`, `productsApi.update()` |
| | `cheepy/src/lib/api.ts` | `productsApi` → `/products` |

---

## New Structure

### Tables

**system_products**
- `id` bigint PK
- `name` varchar(500)
- `description` text nullable
- `price` varchar(100) nullable
- `price_raw` int nullable
- `status` varchar(20) default `draft` — draft | pending | approved | published
- `created_at`, `updated_at`

**product_sources**
- `id` bigint PK
- `system_product_id` FK → system_products (cascade delete)
- `donor_product_id` FK → products (cascade delete)
- `source` varchar(50) default `parser`
- `created_at`, `updated_at`
- UNIQUE(system_product_id, donor_product_id, source)

---

## Rules

- **Parser** continues writing ONLY to `products` (unchanged).
- **products** becomes READ-ONLY for admin (ProductController remains for backward compatibility; future admin UI should use system-products).
- **Admin** works ONLY with `system_products` via SystemProductController.

---

## Moderation Flow

```
┌─────────────────────────────────────────────────────────────────────┐
│  DONOR (products table)                                              │
│  - Written by DatabaseParserService                                 │
│  - READ-ONLY for admin                                              │
└──────────────────────────────────┬──────────────────────────────────┘
                                   │
                                   │ create system_product
                                   │ (manual or auto)
                                   ▼
┌─────────────────────────────────────────────────────────────────────┐
│  product_sources                                                    │
│  - system_product_id ←→ donor_product_id (products.id)              │
│  - source = 'parser'                                                │
└──────────────────────────────────┬──────────────────────────────────┘
                                   │
                                   ▼
┌─────────────────────────────────────────────────────────────────────┐
│  SYSTEM PRODUCT (system_products table)                              │
│  - name, description, price, status                                 │
│  - status: draft → pending → approved → published                   │
└──────────────────────────────────┬──────────────────────────────────┘
                                   │
                                   │ moderation
                                   ▼
┌─────────────────────────────────────────────────────────────────────┐
│  PUBLISH                                                            │
│  - status = 'published'                                             │
│  - (future: sync to frontend catalog, etc.)                         │
└─────────────────────────────────────────────────────────────────────┘
```

**Flow steps:**
1. **donor product** (`products`) — parser writes here.
2. **create system_product** — manual (admin picks donor) or auto (job/scheduler).
3. **moderation** — admin edits name/description/price, changes status.
4. **publish** — status = published; system product is ready for catalog.

---

## API Endpoints

| Method | Path | Description |
|--------|------|-------------|
| GET | /api/v1/system-products | List system products (filters: search, status) |
| GET | /api/v1/system-products/{id} | Get system product with donor sources |
| POST | /api/v1/system-products | Create (optional donor_product_id) |
| PATCH | /api/v1/system-products/{id} | Update name, description, price, status |
| DELETE | /api/v1/system-products/{id} | Delete system product |
| POST | /api/v1/system-products/create-from-donor | Create from donor product (body: donor_product_id, status?) |

---

## Models & Relationships

```
SystemProduct
  ├── productSources(): HasMany ProductSource
  └── donorProducts(): BelongsToMany Product (via product_sources)

ProductSource
  ├── systemProduct(): BelongsTo SystemProduct
  └── donorProduct(): BelongsTo Product
```

---

## What Was Added (No Existing Code Changed)

- `database/migrations/2026_03_21_100000_create_system_products_table.php`
- `database/migrations/2026_03_21_100001_create_product_sources_table.php`
- `app/Models/SystemProduct.php`
- `app/Models/ProductSource.php`
- `app/Http/Controllers/Api/SystemProductController.php`
- Routes: `/api/v1/system-products/*`

**ProductController** — NOT changed.
**DatabaseParserService** — NOT changed.
**products** table — NOT modified.
