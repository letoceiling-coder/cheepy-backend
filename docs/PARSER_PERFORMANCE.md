# Parser Performance (Queue Pipeline)

After the parallel queue pipeline upgrade:

- **RunParserJob** → dispatches **ParseCategoryJob** per category (queue: `parser`)
- **ParseCategoryJob** → fetches category pages, dispatches **ParseProductJob** per product (queue: `parser`)
- **ParseProductJob** → parses product, saves, dispatches **DownloadPhotoJob** if enabled (queue: `photos`)
- **DownloadPhotoJob** → downloads images, updates `product_photos` and `photos_count` (queue: `photos`)

## Configuration

- `config/sadovod.request_delay_ms` = 200 (default)
- `config/parser_rate.max_requests_per_second` = 5
- `config/parser_rate.retry_count` = 3, exponential backoff [2, 5, 10] seconds

## Workers

- **parser** queue: 4 workers (recommended)
- **photos** queue: 2 workers (recommended)

## Performance test (Step 14)

Run parser on 5 categories and measure:

| Metric              | Old (sequential) | New (pipeline) |
|---------------------|------------------|-----------------|
| Products per minute | _fill after test_| _fill after test_|
| Workers used        | 1                | 4 parser + 2 photo |

To test locally:

```bash
# Start workers
php artisan queue:work redis --queue=parser &
php artisan queue:work redis --queue=photos &

# Start parser (e.g. 5 category IDs via API)
# POST /api/v1/parser/start with body: {"type":"full","categories":[1,2,3,4,5]}

# Measure time and count products from GET /api/v1/parser/status or parser_jobs table
```

Document actual results after first production run.
