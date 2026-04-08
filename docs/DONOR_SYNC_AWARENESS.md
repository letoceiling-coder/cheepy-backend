# Donor → System Sync Awareness

## Goal
Detect when a donor product (parser data) changes and flag linked system products for review.

## Flow

```
Parser updates product (products table)
    → ProductObserver::updated()
    → DonorSyncAwarenessService::onDonorProductUpdated()
    → Compare products.updated_at with product_sources.donor_updated_at
    → IF donor changed: system_product.status = needs_review
    → Update product_sources.donor_updated_at = products.updated_at
```

## Logic

1. **donor_updated_at** — timestamp of last known donor state for this link.
2. When `products.updated_at` > `donor_updated_at` (or `donor_updated_at` is null):
   - Set `system_product.status = needs_review`
3. Update `product_sources.donor_updated_at = products.updated_at` so future updates are compared against this.

## Files

| File | Role |
|------|------|
| `database/migrations/2026_03_22_100000_add_donor_updated_at_to_product_sources_table.php` | Adds `donor_updated_at` column |
| `app/Services/Catalog/DonorSyncAwarenessService.php` | Compare logic, mark needs_review |
| `app/Observers/ProductObserver.php` | Calls service on Product updated |
| `app/Models/SystemProduct.php` | Added `STATUS_NEEDS_REVIEW` |
| `app/Models/ProductSource.php` | Added `donor_updated_at` fillable/cast |
| `app/Providers/AppServiceProvider.php` | Registers ProductObserver |

## Baseline

When creating a `ProductSource` (store or createFromDonor), we set `donor_updated_at = donor.updated_at` so the first parser update after linking will correctly detect a change.
