# Admin Parser Controls — Requirements

**Date:** 2026-03-06

---

## Required UI Controls

| Control | API Endpoint | Purpose |
|---------|--------------|---------|
| Start parser | POST /api/v1/parser/start | Start single run |
| Stop parser | POST /api/v1/parser/stop | Stop running jobs |
| Restart parser | POST /api/v1/parser/restart | Stop + restart workers |
| Clear queue | POST /api/v1/parser/queue-clear | Clear parser queue |
| Restart workers | POST /api/v1/parser/queue-restart | Restart queue workers |
| Start daemon | POST /api/v1/parser/start-daemon | Continuous parsing |
| Stop daemon | POST /api/v1/parser/stop-daemon | Disable daemon |

**Flush Redis:** Omitted from API (destructive). Use CLI: `php artisan parser:queue-flush`

---

## Required Metrics Display

| Metric | Source |
|--------|--------|
| Queue size | GET /api/v1/parser/status → queue_parser_size, queue_photos_size |
| Workers active | Not in API; run `parser:workers-status` on server |
| Products parsed | GET /api/v1/parser/stats → products_total, products_today |
| Errors | GET /api/v1/parser/stats → errors_today |
| Parser running | GET /api/v1/parser/status → is_running |
| Daemon enabled | GET /api/v1/parser/status → daemon_enabled |

---

## Frontend Implementation

Add to ParserPage.tsx:

1. **Restart** button → POST parser/restart
2. **Clear queue** button → POST parser/queue-clear (with confirm)
3. **Restart workers** button → POST parser/queue-restart
4. **Metrics section:** Queue size, products total, errors (from status/stats)
5. **Products/min:** Requires server-side calculation or derived from progress delta over time
