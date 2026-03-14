# Anti-Blocking Implementation Report

**Target scale:** 100,000–300,000 products per day  

---

## 1. Random Delay Logic

**Config:** `config/parser_rate.php`

- `delay_min_ms`: 500  
- `delay_max_ms`: 2000  

**Implementation:** `HttpClient::applyDelay()`

- Uses `random_int($delayMinMs, $delayMaxMs)` for each request  
- `usleep($delayMs * 1000)` between requests  

---

## 2. User Agent Rotation

**Config:** `config/parser_user_agents.php`

**Agents:**
- Chrome (Windows, Mac)
- Firefox
- Safari (Mac, iOS)
- Mobile Chrome (Android)
- Edge

**Implementation:** `HttpClient::getNextUserAgent()`

- Rotates through the list on each request  
- Index incremented for every `get()` call  

---

## 3. Retry Logic

**Config:** `config/parser_rate.php`

- `retry_count`: 3  
- `retry_backoff_seconds`: [2, 5, 10]  

**Implementation:** `HttpClient::get()`

- On `RequestException`: retry up to 3 times  
- Delays: 2s, 5s, 10s before each retry  
- `ParserMetricsService::incrementRetries()` on each retry  

---

## 4. Error Detection

**Block detection:**

- HTTP codes: 403, 429  
- Captcha patterns: `captcha`, `капча`, `recaptcha`, `cloudflare`, `access denied`, `доступ запрещён`, `blocked`  

**Actions on block:**

- `ParserMetricsService::incrementBlocked()`  
- Log via `Log::warning('Parser: block detected', ...)`  
- Extra delay: `min(delayMaxMs * 2, 10000)` ms  
- Throw exception (job fails; effectively pauses parser)  

---

## 5. Rate Limit Control

**Config:** `config/parser_rate.php`

- `max_requests_per_minute`: 60  

**Implementation:** `HttpClient::applyRateLimit()`

- Minimum interval: `60 / max_requests_per_minute` seconds  
- `lastRequestAt` used to enforce spacing  
- If too soon, `usleep()` until next allowed time  

---

## 6. Metrics

**Service:** `App\Services\ParserMetricsService`

**Storage:** Redis keys

- `parser:metrics:requests:{Y-m-d-H-i}` — requests per minute (rolling)  
- `parser:metrics:blocked` — blocked requests  
- `parser:metrics:retries` — retries  

**API:** `GET /api/v1/system/status`

- `requests_per_minute`  
- `blocked_requests`  
- `retry_count`  

**Dashboard:** Admin dashboard shows RPM, Блоки, Повторы.

---

## Files Created/Modified

| File | Action |
|------|--------|
| `config/parser_user_agents.php` | Created |
| `config/parser_rate.php` | Created |
| `app/Services/ParserMetricsService.php` | Created |
| `app/Services/SadovodParser/HttpClient.php` | Modified |
| `routes/api.php` | Modified (system/status) |
| `src/lib/api.ts` | Modified (SystemStatus) |
| `src/admin/pages/DashboardPage.tsx` | Modified (metrics display) |

---

## Environment Variables

```
PARSER_MAX_REQUESTS_PER_MINUTE=60
PARSER_DELAY_MIN_MS=500
PARSER_DELAY_MAX_MS=2000
PARSER_RETRY_COUNT=3
```
