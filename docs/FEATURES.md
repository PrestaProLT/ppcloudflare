# Features

## Implemented (1.0.0)

### Connection
- Scoped Cloudflare **API token** (user-owned or account-owned), stored encrypted with the shop's secret key. The token is never shown again in the back office.
- **Test token** button that shows the token status and expiry date.
- **Fetch zones** lists every zone the token can see (all pages), with plan, status and account name.

### Automatic purging
- **Purge on PrestaShop cache clear**, three modes: `off`, `manual` (only the back-office **Clear cache** button), `all` (any cache clear).
- **Debounce** of repeated full purges of the same zone/scope (default 30 s).
- **URL purges on catalog changes**: products, specific prices, categories, CMS pages, manufacturers, suppliers, and optionally stock quantity.
- URLs are built for **every active language of every shop** the entity belongs to. A product also purges its default category page.
- Homepage purge added to every entity purge (optional).
- Request-scoped **purge queue**: everything changed in one request is sent once, at the end. A queued full purge replaces that shop's URL purges.
- Automatic batching into requests Cloudflare accepts.
- URLs whose host does not belong to the shop's zone are dropped instead of being sent.
- Fail-safe: Cloudflare or network errors are logged and never break the back office.
- Master on/off switch.

### Multistore
- Shop ↔ zone mapping for every shop, active or not, including domain aliases.
- Three **purge scopes** per shop: `zone` (purge everything), `host` (shop hostnames), `prefix` (shop sub-folder).
- **Auto-detect**: longest matching zone, with a scope suggested from the shop URL and from zone sharing.
- Shops that share a `zone`-scope zone are purged with one call.

### Tools
- **Purge everything now** per shop and **Purge all shops** (both ignore the debounce).
- **Development Mode** on/off, with its current state.
- **I'm Under Attack** (security level) on/off, with its current state.
- **Custom purge** by URLs, hostnames, prefixes or cache tags on any zone.

### Logging and operations
- **Purge log** of every API call: shop, zone, action, trigger, items, employee, result and message. Paginated and filterable, with a Clear log button.
- Automatic log retention (default 30 days).
- **Console command** `ppcloudflare:purge [--shop=ID|all] [--url=…]` for deploy scripts.
- Built-in **setup guide** page covering token creation and permissions.
- Lithuanian translation included. All source strings are in English and can be translated in PrestaShop's translation system.

## Roadmap / ideas

Further ways to connect PrestaShop and Cloudflare. Contributions are welcome.

1. **Turnstile CAPTCHA** on contact, registration, login and password-reset forms: privacy-friendly bot protection with no puzzles for real customers.
2. **Real visitor IP** from `CF-Connecting-IP` (only when the request comes from Cloudflare's IP ranges): correct IPs on orders, connection logs, fraud checks and geolocation.
3. **Maintenance mode sync**: when PrestaShop maintenance is enabled, turn on a Cloudflare custom rule (or Under Attack) so maintenance traffic is filtered at the edge, with allowed IPs passed through.
4. **Block/allow IPs from the back office** into Cloudflare WAF IP Access Rules, e.g. one-click block from repeated failed logins, card-testing orders or spam contact messages.
5. **Cache-Tag headers on HTML** (`product-42`, `category-7`, `shop-1`), so a product change purges every page that shows the product (listings, search, homepage blocks) by tag.
6. **Cache warm-up after a purge**: request the homepage, top categories and best sellers so the first real visitors hit a warm cache.
7. **Analytics dashboard widget** (requests, cached %, bandwidth saved, threats blocked) from the Cloudflare GraphQL Analytics API.
8. **Scheduled purges** via cron or the console command (e.g. nightly full purge, or purge after a supplier stock import).
9. **Polish / Cloudflare Images / Mirage toggles**: image optimisation settings from the back office, with a note on what each does.
10. **Always Online and Cache Reserve hints**: check zone settings and recommend safe values for a shop (e.g. never cache cart/checkout).
11. **Cache Rules helper**: create a recommended rule set (cache static assets long, bypass cart/checkout/my-account and logged-in customers, optional HTML caching for guests) in one click.
12. **Cloudflare Notifications webhook**: receive DDoS, origin-error and SSL-expiry alerts as back-office notifications or emails to shop employees.
13. **Purge on theme and module events**: theme switch, module enable/disable and CCC regeneration as explicit triggers, independent of cache-clear mode.
14. **Bulk "purge a category tree"** tool: purge a category and every product in it after a mass price import.
15. **Per-employee permissions** for the tools, so only some employees can switch Under Attack or Development Mode.
16. **Token expiry warning** shown in the back office some days before the token's TTL ends.
