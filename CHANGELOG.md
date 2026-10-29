# Changelog

All notable changes to this module are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the module uses [Semantic Versioning](https://semver.org/).

## [1.0.0] – 2026-10-29

First public release.

### Added
- Cloudflare API token connection (user-owned and account-owned tokens), stored encrypted; **Test token** button.
- Zone list fetched from Cloudflare (all pages).
- Shop ↔ zone mapping for every shop, with `zone` / `host` / `prefix` purge scopes and **Auto-detect**.
- Automatic Cloudflare purge on PrestaShop cache clear, with `off` / `manual` / `all` modes and debounce.
- URL purges on product, specific price, category, CMS, manufacturer, supplier and (optional) stock changes, in every language, plus the homepage.
- Request-scoped purge queue, sent once at the end of the request, with automatic batching.
- Tools: Purge now, Development Mode on/off, I'm Under Attack on/off, custom purge by URL / hostname / prefix / tag.
- Purge log with filters, Clear log button and automatic retention.
- Console command `ppcloudflare:purge [--shop=ID|all] [--url=...]`.
- Built-in setup guide for the API token.
- Lithuanian (lt-LT) back-office translation.

[1.0.0]: https://github.com/PrestaProLT/ppcloudflare/releases/tag/v1.0.0
