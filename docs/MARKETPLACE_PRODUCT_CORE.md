# Marketplace Product Core

## Structure

### system_products (extended)
- `id`, `name`, `description`, `price`, `price_raw`, `status`
- `seller_id` (nullable FK → sellers)
- `category_id` (nullable FK → catalog_categories)
- `brand_id` (nullable FK → brands)

### system_product_attributes
- Relational attributes. NO JSON.
- `attr_name`, `attr_value`, `attr_type` (text|int|float)
- Indexes: (attr_name, attr_value), system_product_id — ready for filters

### system_product_photos
- `url`, `is_primary`, `sort_order`
- Index: (system_product_id, sort_order)

## Relationships

```
SystemProduct
  ├── belongsTo Seller
  ├── belongsTo CatalogCategory (category)
  ├── belongsTo Brand
  ├── hasMany SystemProductAttribute
  ├── hasMany SystemProductPhoto
  └── hasMany ProductSource → donor products
```

## createFromDonor Logic

**Copied from products (donor):**
- title → name
- description, price, price_raw, seller_id, brand_id

**Resolved:**
- category_id: product.category_id (parser) → DonorCategory.external_id → CategoryMapping → catalog_category_id

**Copied relations:**
- product_attributes → system_product_attributes (attr_name, attr_value, attr_type)
- product_photos → system_product_photos (url from cdn_url or local_path or original_url)
- Fallback: product.photos (JSON) → system_product_photos if no photoRecords

**Not copied:** external_id, source_url, category_slugs, characteristics, parse_error, parsed_at, etc.

## Filters Ready

- `system_product_attributes` with (attr_name, attr_value) index supports:
  - `WHERE attr_name = 'Цвет' AND attr_value = 'красный'`
  - `GROUP BY attr_name, attr_value` for filter facets
