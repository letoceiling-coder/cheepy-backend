# Reverb WebSocket Setup & Verification

Real-time parser updates use Laravel Reverb (WebSocket server).

---

## 1. Backend — Reverb Server

### Environment (.env)

```env
BROADCAST_CONNECTION=reverb

REVERB_APP_ID=parser-app
REVERB_APP_KEY=your-app-key
REVERB_APP_SECRET=your-app-secret

REVERB_HOST=localhost
REVERB_PORT=8080
REVERB_SCHEME=http

REVERB_SERVER_HOST=0.0.0.0
REVERB_SERVER_PORT=8080
```

### Start Reverb

```bash
php artisan reverb:start
```

### Queue (required for broadcasting)

```bash
php artisan queue:work
```

---

## 2. Nginx WebSocket Proxy

Add to your nginx server block (for the domain serving the admin panel / API):

```nginx
location /app {
    proxy_pass http://127.0.0.1:8080;
    proxy_http_version 1.1;
    proxy_set_header Host $http_host;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
}
```

For production (HTTPS), `REVERB_HOST` and `VITE_REVERB_HOST` must match your domain.

---

## 3. Frontend — Environment Variables

Set in `.env`, `.env.local`, or `.env.production` **before** build:

```env
VITE_REVERB_APP_KEY=your-app-key
VITE_REVERB_HOST=localhost
VITE_REVERB_PORT=8080
VITE_REVERB_SCHEME=http
```

**Production (wss via nginx proxy):**

```env
VITE_REVERB_APP_KEY=your-app-key
VITE_REVERB_HOST=online-parser.siteaacess.store
VITE_REVERB_PORT=443
VITE_REVERB_SCHEME=https
```

### Rebuild after changing env

```bash
npm install
npm run build
```

---

## 4. Verification Checklist

| Step | Action | Expected |
|------|--------|----------|
| 1 | `php artisan reverb:start` | Server starts on port 8080 |
| 2 | DevTools → Network → WS | Connection to `ws(s)://HOST/app/KEY` appears |
| 3 | Start parser | `ParserStarted` event |
| 4 | Parser running | `ParserProgressUpdated`, `ProductParsed` events |
| 5 | Parser completes | `ParserFinished` event |

### If WebSocket fails

- Polling runs every 30 seconds as fallback.
- Check: `VITE_REVERB_APP_KEY` set and matches backend `REVERB_APP_KEY`.
- Check: Nginx proxies `/app` to Reverb port.
- Check: CORS / `allowed_origins` in Reverb config.

---

## Broadcast Events

| Event | When |
|-------|------|
| ParserStarted | Parsing begins |
| ParserProgressUpdated | Every 10 products |
| ProductParsed | Each product saved |
| ParserFinished | Success or failure |
| ParserError | Parse/save errors |

Channel: `parser` (public)
