# ppcloudflare — Architecture

PrestaShop 9 module (PHP >= 8.1, PS >= 9.0.0, no support for older PrestaShop) that connects
PrestaShop to Cloudflare: purges the Cloudflare cache whenever PrestaShop clears its cache,
purges individual page URLs when catalog content changes, maps every multistore shop to its
Cloudflare zone, and exposes a few zone tools (Development Mode, "I'm Under Attack" mode,
manual purge) in the back office.

- Module name: `ppcloudflare`, main class `PpCloudflare`, author `PrestaPro`.
- PSR-4 namespace: `PrestaShop\Module\PpCloudflare\` → `src/`.
- Translation domain: `Modules.Ppcloudflare.Admin` (all source strings in English).
- DB tables (with `_DB_PREFIX_`): `ppcloudflare_shop_zone`, `ppcloudflare_log`.
- Config key prefix: `PPCLOUDFLARE_`.

## 1. Cloudflare API

Base URL `https://api.cloudflare.com/client/v4`. Auth header `Authorization: Bearer <token>`.
Only **API Tokens** are supported (not the legacy Global API Key).

| Purpose | Call |
|---|---|
| Verify token | `GET /user/tokens/verify` (user-owned tokens). Account-owned tokens fail here → fall back to `GET /zones?per_page=1`; success = valid. |
| List zones | `GET /zones?per_page=50&page=N` until `result_info.total_pages` reached |
| Purge everything | `POST /zones/{id}/purge_cache` `{"purge_everything":true}` |
| Purge URLs | `POST /zones/{id}/purge_cache` `{"files":[...]}` — max **30 per call** (chunk) |
| Purge hosts | `{"hosts":[...]}` — max 30 per call |
| Purge prefixes | `{"prefixes":[...]}` — max 30 per call, no scheme (`www.example.com/shop2/`) |
| Purge tags | `{"tags":[...]}` — max 30 per call |
| Read setting | `GET /zones/{id}/settings/{name}` (`development_mode`, `security_level`) |
| Write setting | `PATCH /zones/{id}/settings/{name}` `{"value":...}` |

Required token permissions (custom token):
- Zone → Zone → Read (required; lists zones)
- Zone → Cache Purge → Purge (required)
- Zone → Zone Settings → Edit (optional; Development Mode and security level tools)
- Zone Resources: Include → All zones from an account (or specific zones).

## 2. Request-time constraint (IMPORTANT)

`actionClearSf2Cache` fires inside a `register_shutdown_function` **after `var/cache` was deleted**.
Code reachable from hooks (`Hook\HookHandler`, `Purge\*`, `Api\*`, `Zone\*`, `Log\*`, `Config\*`)
therefore must be **plain PHP**:
- no Symfony container / DI services, no `$module->get()`, no Twig;
- no `$this->trans()` / translator — log messages are stored in plain English;
- legacy APIs only: `Configuration`, `Db`, `Shop`, `ShopUrl`, `Link`, `Language`, `Context`;
- never throw out of a hook: catch `\Throwable`, write a log row, continue.
Those classes are built with plain `new` / static factories and are **excluded from DI scanning**.
Admin controllers instantiate them the same way (via `Factory::...`).

## 3. Configuration keys (`Config\Settings`)

All keys are stored **global** (id_shop NULL) — the per-shop part of the setup is the
shop↔zone mapping table.

| Key | Type | Default | Meaning |
|---|---|---|---|
| `PPCLOUDFLARE_ENABLED` | bool | 1 | master switch for automatic purges |
| `PPCLOUDFLARE_API_TOKEN` | string | '' | API token, stored encrypted with `\PhpEncryption(_NEW_COOKIE_KEY_)` |
| `PPCLOUDFLARE_ZONES_CACHE` | json | '[]' | last fetched zone list |
| `PPCLOUDFLARE_ZONES_FETCHED_AT` | datetime | '' | |
| `PPCLOUDFLARE_CACHE_CLEAR_MODE` | string | `all` | `off` / `manual` (only BO Performance → Clear cache, route `admin_clear_cache`) / `all` (any PrestaShop cache clear) |
| `PPCLOUDFLARE_PURGE_PRODUCT` | bool | 1 | purge product URLs on product/specific price change |
| `PPCLOUDFLARE_PURGE_CATEGORY` | bool | 1 | |
| `PPCLOUDFLARE_PURGE_CMS` | bool | 1 | |
| `PPCLOUDFLARE_PURGE_MANUFACTURER` | bool | 1 | |
| `PPCLOUDFLARE_PURGE_SUPPLIER` | bool | 0 | |
| `PPCLOUDFLARE_PURGE_STOCK` | bool | 0 | purge product URLs on stock change (busy shops: keep off) |
| `PPCLOUDFLARE_PURGE_HOMEPAGE` | bool | 1 | add the shop homepage(s) to every entity URL purge |
| `PPCLOUDFLARE_DEBOUNCE_SECONDS` | int | 30 | skip a repeated full purge of the same zone/scope within N seconds |
| `PPCLOUDFLARE_LOG_RETENTION_DAYS` | int | 30 | rows older are deleted (on each log write, at most once/day) |
| `PPCLOUDFLARE_LAST_FULL_PURGE` | json | '{}' | `{ "<zone>|<scope>|<hash>": unix_ts }` debounce memory |
| `PPCLOUDFLARE_LOG_PRUNED_AT` | int | 0 | last log retention cleanup (runs at most once a day) |

`Config\Settings` exposes typed static getters/setters and `Settings::defaults(): array`
(key => default, excluding the token/cache/debounce keys) used by the Installer and `reset()`.

## 4. Shop ↔ zone mapping

Table `ppcloudflare_shop_zone`:
```
id_shop INT UNSIGNED NOT NULL PRIMARY KEY,
zone_id VARCHAR(32) NOT NULL,
zone_name VARCHAR(255) NOT NULL,
purge_scope VARCHAR(16) NOT NULL DEFAULT 'zone',   -- zone | host | prefix
date_upd DATETIME NOT NULL
```
- `zone`   → `purge_everything` on the whole zone.
- `host`   → purge by hostnames of the shop (for shops on their own subdomain when the zone also
  serves other sites).
- `prefix` → purge by URL prefix `host/physical_uri/virtual_uri` (multistore shops in a
  sub-folder of the same domain).

Auto-detect (`Zone\ZoneMatcher::suggest`): longest zone name that equals the shop host or is a
suffix `.<zone>` of it. Suggested scope: `prefix` if the shop URI is not `/`; else `host` if the
zone is matched by more than one shop or the host differs from the zone apex and `www.<apex>`;
else `zone`.

Shops are read from `Shop::getShops(false)` (all shops, active or not), domains from
`shop_url` (`domain`, `domain_ssl`, every row incl. non-main aliases).

## 5. Classes (namespace `PrestaShop\Module\PpCloudflare\`)

### Api (core)
- `Api\HttpTransportInterface::request(string $method, string $url, array $headers, ?string $body, int $timeout): array{status:int, body:string}`
- `Api\CurlTransport implements HttpTransportInterface` (curl, TLS verify on, throws `CloudflareException` on network error).
- `Api\CloudflareException extends \RuntimeException` — `getHttpStatus(): int`, `getErrors(): array` (Cloudflare `errors[]`), `getCloudflareCode(): ?int`.
- `Api\CloudflareClient`
  - `__construct(string $token, ?HttpTransportInterface $transport = null, int $timeout = 15)`
  - `verifyToken(): array{valid:bool, status:string, expires_on:?string, message:string}`
  - `listZones(): array<int, array{id:string, name:string, status:string, plan:string, account_id:string, account_name:string, paused:bool}>`
  - `purgeEverything(string $zoneId): void`
  - `purgeFiles(string $zoneId, array $urls): void` (chunks of 30)
  - `purgeHosts(string $zoneId, array $hosts): void`
  - `purgePrefixes(string $zoneId, array $prefixes): void`
  - `purgeTags(string $zoneId, array $tags): void`
  - `getSetting(string $zoneId, string $name): mixed` (returns `result.value`)
  - `setSetting(string $zoneId, string $name, mixed $value): mixed`
  - All failing calls throw `CloudflareException` with a readable message (`errors[].message` joined).

### Config (core)
- `Config\Settings` — constants for every key, static getters/setters, `getToken(): string` (decrypts), `setToken(string)` (encrypts; empty string clears), `hasToken(): bool`, `getCachedZones(): array`, `setCachedZones(array)`, `defaults()`.

### Zone (core)
- `Zone\ShopZoneMapping` — readonly value object: `idShop`, `zoneId`, `zoneName`, `purgeScope`.
- `Zone\ShopZoneRepository` — `find(int $idShop): ?ShopZoneMapping`, `findAll(): array<int, ShopZoneMapping>` (keyed by id_shop), `save(ShopZoneMapping)`, `delete(int $idShop)`.
- `Zone\ShopDomainResolver` — `getHosts(int $idShop): string[]`, `getBaseUrls(int $idShop): string[]` (`https://host/uri`), `getPrefixes(int $idShop): string[]` (`host/uri`, no scheme), `getShops(): array` (`[id_shop, name, hosts[], uri, active]`).
- `Zone\ZoneMatcher` — `suggest(array $zones, int $idShop): ?array{zone:array, scope:string}`.

### Purge (core)
- `Purge\PurgeResult` — `success:bool`, `zoneId`, `action`, `message`, `count`.
- `Purge\PurgeService`
  - `static create(): self` (builds client from Settings; works without token → every call returns a failed result "API token is not configured").
  - `purgeShop(int $idShop, string $trigger, bool $force = false): PurgeResult` — scope-aware full purge; debounced unless `$force`.
  - `purgeAllShops(string $trigger, bool $force = false): PurgeResult[]` — dedups shops sharing a `zone`-scope zone into one call.
  - `purgeUrls(int $idShop, array $urls, string $trigger): PurgeResult` (drops URLs whose host does not belong to the zone).
  - `purgeCustom(string $zoneId, string $type, array $items, string $trigger): PurgeResult` — `type` ∈ files|hosts|prefixes|tags (manual tool).
  - Every API call writes one `ppcloudflare_log` row (success or failure).
- `Purge\PurgeQueue` — request-scoped static queue: `addFullPurge(int $idShop, string $trigger)`, `addFullPurgeAllShops(string $trigger)`, `addUrls(int $idShop, array $urls, string $trigger)`, `flush()`. First add registers `register_shutdown_function([PurgeQueue::class, 'flush'])`; adds after a flush register a new one. A full purge of a shop drops its queued URLs.
- `Purge\EntityUrlCollector` — `forProduct(int $id)`, `forCategory(int $id)`, `forCms(int $id)`, `forManufacturer(int $id)`, `forSupplier(int $id)` → `array<int idShop, string[] urls>` for every mapped shop the entity belongs to, every active language of that shop (`Link` with `$idShop`), plus homepage URLs when `PPCLOUDFLARE_PURGE_HOMEPAGE`. Product also adds its default category URL.

### Log (core)
- Table `ppcloudflare_log`: `id_log INT UNSIGNED AI PK, id_shop INT UNSIGNED NULL, zone_id VARCHAR(32) NOT NULL DEFAULT '', action VARCHAR(32) NOT NULL, trigger_source VARCHAR(64) NOT NULL, items TEXT NULL, success TINYINT(1) NOT NULL, message TEXT NULL, id_employee INT UNSIGNED NULL, date_add DATETIME NOT NULL, KEY date_add (date_add)`.
- `Log\LogRepository` — `add(?int $idShop, string $zoneId, string $action, string $trigger, array $items, bool $success, string $message): void` (items JSON, max 50 entries stored; employee from `Context`), `paginate(int $page, int $perPage, array $filters = []): array{rows:array, total:int}` (filters: `success`, `id_shop`, `action`), `deleteOlderThan(int $days): int`, `clear(): void`.

### Hook (core)
- `Hook\HookHandler` — called from `PpCloudflare::hook*`:
  - `onCacheClear(string $hookName): void` — honors `CACHE_CLEAR_MODE` (`manual` → only when the current request is the BO route `admin_clear_cache`, detected from `$_GET['_route']`/request URI or the Symfony request stack attribute when available, never by loading the container).
  - `onEntityChange(string $entity, int $id): void` (entity: product|category|cms|manufacturer|supplier).
  - `onStockChange(int $idProduct): void`.
  - `onSpecificPriceChange(\SpecificPrice $sp): void` (id_product > 0 → product URLs).
  All no-op when `PPCLOUDFLARE_ENABLED` is off, no token, or no mapping.

### Module (core)
- `Module\Installer::__invoke(): bool` — create tables (`IF NOT EXISTS`), set defaults (only if not set), register hooks (`PpCloudflare::HOOKS`). Idempotent (used by `reset()`).
- `Module\Uninstaller::__invoke(): bool` — drop tables, delete all `PPCLOUDFLARE_%` config.

### Command (core)
- `Command\PurgeCommand` — `bin/console ppcloudflare:purge [--shop=ID|all] [--url=URL ...]`. Full purge by default for all mapped shops (`force`), URL purge when `--url` given. Useful in deploy scripts. Registered in `config/services.yml`.

### Admin (admin)
Symfony controllers in `src/Controller/Admin/`, Twig in `views/templates/admin/`, forms in `src/Form/`.
One configuration screen with internal tab navigation (no visible sidebar entries):

| Route | Path | Purpose |
|---|---|---|
| `ps_ppcloudflare_configure` | `/modules/ppcloudflare/settings` | API token (password field, blank = keep), test token, master switch, cache-clear mode, triggers, debounce, retention (Symfony form architecture) |
| `ps_ppcloudflare_zones` | `/modules/ppcloudflare/zones` | shops list with hosts, zone dropdown + scope per shop, Fetch zones from Cloudflare, Auto-detect, Save |
| `ps_ppcloudflare_tools` | `/modules/ppcloudflare/tools` | per mapped shop: Purge now, Development Mode on/off + state, Security level (I'm Under Attack on/off); custom purge (URLs / hosts / prefixes / tags) |
| `ps_ppcloudflare_logs` | `/modules/ppcloudflare/logs` | paginated purge log, filters, Clear log |
| `ps_ppcloudflare_guide` | `/modules/ppcloudflare/guide` | step-by-step: create the API token and which permissions to allow |

POST action routes (CSRF-protected, `AdminSecurity` on every action) under the same paths.
Hidden tabs (`visible => false`) declared in `PpCloudflare::__construct` (`$this->tabs`).

## 6. Hooks registered (`PpCloudflare::HOOKS`)

`actionClearSf2Cache`, `actionClearCompileCache`,
`actionObject{Product,Category,Cms,Manufacturer,Supplier}UpdateBefore` (old URLs, so renamed pages are purged too),
`actionObject{Product,Category,Cms,Manufacturer,Supplier}UpdateAfter`,
`actionObject{Product,Category,Cms,Manufacturer,Supplier}DeleteBefore`,
`actionObjectSpecificPriceAddAfter`, `actionObjectSpecificPriceUpdateAfter`, `actionObjectSpecificPriceDeleteAfter`,
`actionUpdateQuantity`, `actionBeforeResetModule`.

Delete hooks use `*DeleteBefore` so URLs can still be built.

## 7. Reset keeps data

PrestaShop 9's back-office Reset calls `ModuleManager::reset($name)` with `keepData = false`, i.e.
`uninstall()` + `install()`, and never calls the module's `reset()`. Core fires
`actionBeforeResetModule` first; the module sets `Uninstaller::$keepData = true`, so that uninstall
keeps tables and configuration. The idempotent Installer then restores hooks and missing defaults.
