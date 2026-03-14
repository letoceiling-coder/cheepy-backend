# Category Parser Diagnostics

**Issue:** Each category only dispatches ~40–50 products (one page) instead of thousands.

**Example:** Category `jenskie-vodolazki` queued 47 products; real category size is thousands.

---

## 1. Pagination Logic (ParseCategoryJob)

**Location:** `app/Jobs/ParseCategoryJob.php`

**Variables:**
- `$page` — current 1-based page number; starts at 1, incremented at end of loop (`$page++`).
- `$maxPages` — from `$options['max_pages'] ?? $category->parser_max_pages ?? 0`. **0 = no limit.**
- `$productLimit` — from `$options['products_per_category'] ?? $category->parser_products_limit ?? 0`. **0 = no limit.**

**Stopping conditions (in order):**
1. `if (empty($products))` → break (no products on this page).
2. After dispatching: `if (!$hasMore || ($maxPages > 0 && $page >= $maxPages))` → break.
3. `if ($productLimit > 0 && $savedCount >= $productLimit)` → break.
4. Then `$page++` and next iteration.

**Pagination URL:** Built in `CatalogParser::parseCategoryPage()` as:
`$catalogPath . '?page=' . $pageNumber` (e.g. `/catalog/jenskie-vodolazki?page=1`, `?page=2`, …).  
So the loop does request page 2, 3, … as long as `$hasMore` is true and limits are not hit.

---

## 2. Category DB Limits

**SQL to run on server:**

```sql
SELECT slug, parser_products_limit, parser_max_pages
FROM categories
WHERE enabled = 1
LIMIT 20;
```

**Expected for “no limit”:** `parser_products_limit = 0`, `parser_max_pages = 0` (migration default is 0).

**If non-zero:**  
- `parser_max_pages = 1` → only first page is parsed (~47 products).  
- `parser_products_limit = 50` → parsing stops after 50 products.

Run the SQL and fix any categories that should have no limit:

```sql
UPDATE categories SET parser_products_limit = 0, parser_max_pages = 0 WHERE enabled = 1;
```

---

## 3. Likely Cause: `hasNextPage()` Returns False

After the first page, **`$hasMore`** comes from `CatalogParser::hasNextPage($crawler)`.

**Current implementation** (`CatalogParser::hasNextPage`):
- Looks for `a[href*="page="]` with `page=(\d+)` and value > 1.
- Or link text containing “след” / “next”.
- Or `a[rel="next"]`.

If the donor site uses different markup (e.g. “Следующая”, “»”, data attributes, or JS-only “next”), the crawler never sees a “next” link and **returns false after page 1**, so the loop exits and only ~47 products (one page) are dispatched.

---

## 4. Diagnostic Logging Added

**ParseCategoryJob:**
- **On start:** `ParseCategoryJob limits` — category, `max_pages`, `product_limit`, and whether they came from job options or category model.
- **Each page:** `Category parse debug` — category, page, max_pages, product_limit, products_found, has_more.
- **On exit:**  
  - `Category parser finished (empty page)` — reason: empty_products.  
  - `Category parser finished (pagination)` — reason: has_more_false or max_pages_reached, with has_more and max_pages.  
  - `Category parser finished (product limit)` — reason: product_limit, saved_count.  
  - `Category parser finished` — last_page, total_dispatched.

**CatalogParser:**
- **Each page:** `Products on page` — path, page, count, has_more.

**Where to look:** `storage/logs/laravel.log` (and worker log if parser runs in queue). After a run, search for:
- `Category parse debug` → see `products_found` and **has_more** per page.
- `Category parser finished (pagination)` → if reason is **has_more_false**, pagination detection is the cause.
- `Products on page` → confirms path and product count per page.

---

## 5. Summary Table

| Check | Where | Expected / Action |
|-------|--------|-------------------|
| Category page count | Logs: `Category parse debug` → page | Should increase 1, 2, 3… if multiple pages. |
| Products per page | Logs: `products_found` | ~24–50 per page typical. |
| Stopping condition | Logs: `Category parser finished (…)` → reason | If **has_more_false** → fix `hasNextPage()` or donor markup. |
| DB limits | SQL above | `parser_products_limit` and `parser_max_pages` = 0 for no limit. |
| Pagination URL | Code + logs: `path` in `Products on page` | `/catalog/{slug}?page=1`, `?page=2`, … |
| `$page++` | ParseCategoryJob line ~165 | Present; page increments each iteration. |

---

## 6. Next Steps

1. Run the SQL and ensure category limits are 0 where appropriate.
2. Run a single category (e.g. jenskie-vodolazki) and inspect `storage/logs/laravel.log` for the new messages.
3. If logs show **has_more = false** after page 1: open the donor category page 2 in a browser, find the “next” link (HTML and text), and update `CatalogParser::hasNextPage()` to match (e.g. extra selectors or text like “Следующая”, “»”, or `rel="next"`).
4. Optionally add a fallback: if page N returns a non‑empty list and N+1 is not explicitly “last page” in HTML, try requesting `?page=N+1` once and treat “no products” as end of list.
