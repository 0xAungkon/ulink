# ULink

ULink gives changing Cloudflare Tunnel URLs one stable public address. Anonymous users receive a token ID and a one-time secret, then use those credentials to update the destination or inspect redirect analytics.

## Features

- Anonymous links with a maximum one-year lifetime
- Redirect and server-side proxy delivery modes
- Stable, random 10-character public URL
- Secret keys stored as keyed one-way hashes
- Destination updates without changing the public URL
- Total/failed hits and the latest 100 visitor records
- Cloudflare IP/country headers plus lightweight browser/device detection
- Vue interface for creating, copying, updating, and inspecting links
- Admin-configurable public domains/subdomains with a creation-time dropdown
- Admin dashboard protected by credentials from `.env`
- API rate limits and expiration enforcement

## Local setup

Requirements: PHP 8.3+, Composer, Node.js 20+, and the PHP SQLite extension (or another Laravel-supported database).

```bash
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate
npm install
npm run build
php artisan serve
```

Set secure admin credentials before exposing the service:

```dotenv
APP_URL=https://links.example.com
ULINK_ADMIN_USERNAME=your-admin-name
ULINK_ADMIN_PASSWORD=a-long-random-password
ULINK_ADMIN_PATH=a-long-random-private-path
```

Every custom public domain or subdomain must point to this Laravel deployment in DNS (and be covered by HTTPS). Adding it in the admin panel controls ULink generation; it does not create the DNS record automatically.

For MySQL/PostgreSQL, update the standard `DB_*` variables in `.env`. In production, serve Laravel over HTTPS, set `APP_ENV=production` and `APP_DEBUG=false`, run `php artisan config:cache`, and configure the web server document root as `public/`.

## API

### Create

```http
POST /ulink/api/v1/links
Content-Type: application/json

{
  "url": "https://current.trycloudflare.com",
  "expire_at": "2027-07-31T12:00:00Z",
  "type": "anonymous"
}
```

The response includes `token_id`, `secret_key`, `expire_at`, and the stable `url`. The plaintext secret cannot be recovered later.

Set `type` to `redirect` (the default) to send visitors to the current destination, or `proxy` to fetch upstream responses through ULink while keeping the public URL in the browser. Proxy mode rewrites common relative HTML/CSS asset paths, keeps upstream cookies in encrypted link-scoped server sessions, never forwards ULink's own cookies or authorization headers, and blocks localhost, private, reserved, and configured ULink hosts to reduce SSRF risk. Highly dynamic applications that build cross-origin absolute URLs in JavaScript may still require upstream proxy-awareness.

Recognized browsers see a one-time safety notice for each individual link before redirecting or loading proxied content. Curl, custom user agents, bots, and API clients are not interrupted; clients may also send `X-ULink-No-Screen` with any value to explicitly bypass the HTML notice. Proxy mode injects routing support for root-relative Fetch/XHR/form requests and uses encrypted server-side upstream cookie jars keyed by both browser and link. Upstream `Set-Cookie` headers are also mirrored into link-path-scoped browser cookies and translated back on later requests, including JavaScript-visible cookies through the injected cookie adapter. This allows one browser to stay signed in to multiple proxied links independently without leaking cookies between them.

The administrator link-detail view exposes the safe request metadata actually provided by the client: IP/location headers, full user agent, detected browser/device/OS, request method and path, query-free referrer, language and Accept headers, and available browser client hints. Cookies, authorization headers, query strings, and request bodies are deliberately excluded from analytics.

`domain_id` may be supplied to choose one of the active entries returned by `GET /ulink/api/v1/domains`. If the administrator has not configured any domains yet, the API and creation form use the main `APP_URL` domain. A link snapshots its selected base URL so later domain configuration changes do not alter existing addresses.

### Update

```http
PUT /ulink/api/v1/links
Content-Type: application/json

{
  "token_id": "...",
  "secret_key": "...",
  "url": "https://new.trycloudflare.com"
}
```

The misspelled `secreat_key` is also accepted for compatibility with the original proposal.

### Inspect analytics

```http
GET /ulink/api/v1/links/{token_id}
Authorization: Bearer {token_id}:{secret_key}
```

`POST /ulink/api/v1/links/info` is also supported with `token_id` and `secret_key` in the JSON body. The same credential may be supplied through an `ulink_token` cookie or `X-Link-Token` header.

### Admin

Admin endpoints use HTTP Basic authentication with the `.env` credentials:

- `POST /ulink/api/v1/admin/login`
- `GET /ulink/api/v1/admin/dashboard`
- `GET /ulink/api/v1/admin/links/{id}`
- `DELETE /ulink/api/v1/admin/links/{id}`
- `POST /ulink/api/v1/admin/domains`
- `PATCH /ulink/api/v1/admin/domains/{id}`
- `DELETE /ulink/api/v1/admin/domains/{id}`

The browser admin UI is available at the private path configured by `ULINK_ADMIN_PATH`. The conventional `/admin` route is not registered unless it is explicitly selected.

For example, with `ULINK_ADMIN_PATH=a-long-random-private-path`, workspaces use `/a-long-random-private-path/dashboard`, `/a-long-random-private-path/links`, `/a-long-random-private-path/link/{id}`, and `/a-long-random-private-path/domains`. Invalid administrator credentials return an in-form JSON error without sending an HTTP Basic challenge that would trigger a native browser login dialog.

## Testing

```bash
php artisan test
npm run build
```

Location detail depends on headers provided by Cloudflare. Country is normally available as `CF-IPCountry`; city and region are recorded when `CF-IPCity` and `CF-Region` are present. No third-party geolocation request is made.
