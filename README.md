# PrestaShop Cloudflare Module: Automatic Cloudflare Cache Purge for PrestaShop 9

[![PrestaShop 9](https://img.shields.io/badge/PrestaShop-9.0%2B-df0067)](https://www.prestashop-project.org/)
[![PHP 8.1+](https://img.shields.io/badge/PHP-8.1%2B-777bb4)](https://www.php.net/)
[![Cloudflare: all plans](https://img.shields.io/badge/Cloudflare-Free%20%7C%20Pro%20%7C%20Business%20%7C%20Enterprise-f38020)](https://www.cloudflare.com/)
[![License: AFL-3.0](https://img.shields.io/badge/License-AFL--3.0-blue)](LICENSE)
[![Multistore](https://img.shields.io/badge/Multistore-supported-success)](#prestashop-multistore-with-cloudflare-one-zone-per-shop)

**ppcloudflare** is a free, open-source **Cloudflare module for PrestaShop 9**. It **clears the Cloudflare cache automatically** whenever PrestaShop clears its own cache, and purges the right page URLs when you edit products, prices, categories, CMS pages, brands or suppliers. It links every shop of a **PrestaShop multistore** to its own **Cloudflare zone**. You can also switch Cloudflare **Development Mode** and **"I'm Under Attack" mode** on and off from the PrestaShop back office.

You no longer need to log in to the Cloudflare dashboard and click *Purge Everything* after every theme update, CSS/JS change or price change.

> **In one sentence:** you click *Clear cache* in PrestaShop, and Cloudflare is purged too, for the right domain of the right shop.

---

## Table of contents

- [Why PrestaShop needs a Cloudflare cache purge module](#why-prestashop-needs-a-cloudflare-cache-purge-module)
- [Features](#features)
- [Manual purge vs. this module](#manual-cloudflare-purge-vs-this-module)
- [Requirements](#requirements)
- [Installation](#installation)
- [How to create a Cloudflare API token for PrestaShop (permissions)](#how-to-create-a-cloudflare-api-token-for-prestashop)
- [Quick start: connect PrestaShop to Cloudflare in 5 minutes](#quick-start-connect-prestashop-to-cloudflare-in-5-minutes)
- [How the automatic Cloudflare cache purge works](#how-the-automatic-cloudflare-cache-purge-works)
- [PrestaShop multistore with Cloudflare: one zone per shop](#prestashop-multistore-with-cloudflare-one-zone-per-shop)
- [Cloudflare Development Mode, Under Attack mode and custom purge](#cloudflare-development-mode-under-attack-mode-and-custom-purge)
- [Purge log](#purge-log)
- [Purge Cloudflare from the command line (deploy scripts, CI/CD)](#purge-cloudflare-from-the-command-line-deploy-scripts-cicd)
- [Security](#security)
- [FAQ](#faq)
- [Roadmap](#roadmap)
- [License](#license)

---

## Why PrestaShop needs a Cloudflare cache purge module

Cloudflare is a CDN and reverse proxy. It keeps copies of your store's files in data centres around the world, which makes the shop faster and protects it from attacks. But Cloudflare does not know when PrestaShop changes something, so it keeps serving the old copy. Typical symptoms:

- you update the theme or clear the cache in **Advanced Parameters → Performance**, but customers still get the **old CSS and JavaScript**;
- PrestaShop regenerates its **CCC (Combine, Compress and Cache)** files, but Cloudflare keeps serving the previous versions and the layout breaks;
- you cache HTML with a Cloudflare **Cache Rule** ("Cache Everything"), and an **old price, description or stock status** stays visible after you edit a product;
- in a **multistore**, you never know which Cloudflare zone belongs to which shop, and *Purge Everything* on a shared domain also wipes the cache of your other sites.

This module purges Cloudflare at exactly the right moment, and only what is needed.

## Features

- **Purge the Cloudflare cache when PrestaShop clears its cache.** On every cache clear, or only when you click **Clear cache**.
- **Targeted URL purges when catalog content changes.** Covers products (including specific prices and discounts), categories, CMS pages, brands and suppliers, in every language of every shop. When a page is **renamed**, the old URL is purged as well as the new one.
- Optional **stock-change** and **homepage** purges.
- **PrestaShop multistore support.** Fetch your Cloudflare zones and link each shop to its zone. The purge scope is set per shop: whole zone, the shop's hostnames, or the shop's URL prefix (sub-folder shops).
- **Auto-detects** the right Cloudflare zone for every shop from its domain.
- **Cloudflare Development Mode** on/off from the back office.
- **"I'm Under Attack" mode** and the security level from the back office, for DDoS and bot floods.
- **Custom purge** by URL, hostname, prefix or cache tag.
- **Debounce:** a deploy that clears the PrestaShop cache five times sends one purge, not five.
- **Purge log** showing what was purged, why, by whom and Cloudflare's response.
- **Console command** `ppcloudflare:purge` for deploy scripts and CI/CD pipelines.
- **Secure:** scoped **Cloudflare API token** (never the Global API Key), stored encrypted.
- **Built-in setup guide** in the back office that explains which token permissions to allow.
- Works on **every Cloudflare plan, including Free**.
- Built for **PrestaShop 9** (Symfony admin pages, PHP 8.1+). No legacy code for older versions.

## Manual Cloudflare purge vs. this module

| | Cloudflare dashboard by hand | **ppcloudflare** |
|---|---|---|
| Purge after *Clear cache* in PrestaShop | You log in and click *Purge Everything* | Automatic |
| Purge only the edited product, category or CMS page | You copy every URL in every language | Automatic, all languages and shops |
| Multistore with several domains / zones | Find the right zone each time | Shop ↔ zone mapping with auto-detect |
| Shops in sub-folders or subdomains of one domain | *Purge Everything* hits every site | Purge by hostname or URL prefix |
| Development Mode / Under Attack | Cloudflare dashboard | One click in the PrestaShop back office |
| History of what was purged | None | Purge log with employee and trigger |
| Deploy pipelines | Write your own API calls | `php bin/console ppcloudflare:purge` |

## Requirements

| | |
|---|---|
| PrestaShop | **9.0 or newer** (PrestaShop 1.7 and 8 are not supported) |
| PHP | **8.1 or newer**, with the cURL extension |
| Cloudflare | Any account and any plan (Free, Pro, Business, Enterprise), with your shop domain(s) added as zones |
| Network | The server must reach `https://api.cloudflare.com` over HTTPS |

## Installation

### Option 1: upload the ZIP (recommended)

1. Download `ppcloudflare.zip` from the [Releases page](https://github.com/PrestaProLT/ppcloudflare/releases).
2. In the PrestaShop back office go to **Modules → Module Manager → Upload a module** and drop the ZIP file.
3. Click **Configure**.

> The ZIP must contain a single `ppcloudflare/` folder. GitHub's automatic "Source code (zip)" is named `ppcloudflare-main`, which PrestaShop does not recognise. Use the release asset, or rename the folder to `ppcloudflare` before zipping.

### Option 2: git clone

```bash
cd /path/to/prestashop/modules
git clone https://github.com/PrestaProLT/ppcloudflare.git ppcloudflare
php ../bin/console prestashop:module install ppcloudflare
```

Upgrade, reset and uninstall are covered in [docs/INSTALL.md](docs/INSTALL.md).

## How to create a Cloudflare API token for PrestaShop

The module uses a **scoped Cloudflare API token**. Create one with only these permissions:

| Permission (Group · Item · Access) | Needed for | Required? |
|---|---|---|
| **Zone · Zone · Read** | Test token, fetch zones, auto-detect | **Required** |
| **Zone · Cache Purge · Purge** | Automatic purges, Purge now, Custom purge, CLI | **Required** |
| **Zone · Zone Settings · Edit** | Development Mode and "I'm Under Attack" buttons | Optional |

Step by step:

1. Log in to **[dash.cloudflare.com](https://dash.cloudflare.com)**, click the **profile icon** (top right), then **My Profile → API Tokens**.
2. Click **Create Token**, scroll down and next to **Create Custom Token** click **Get started**.
3. **Token name:** e.g. `PrestaShop cache purge`.
4. **Permissions:** add the rows from the table above (use **+ Add more** for each row).
5. **Zone Resources:** **Include · All zones from an account** (easiest for multistore), or **Include · Specific zone** with one row per shop domain (most restrictive).
6. **Client IP Address Filtering** *(optional)*: your web server's outbound IPv4/IPv6 addresses.
7. **TTL** *(optional)*: if you set an end date, the module stops working when the token expires.
8. **Continue to summary → Create Token**, then **copy the token**. Cloudflare shows it only once.
9. In PrestaShop: **Modules → Module Manager → Cloudflare Cache & Zone Manager → Configure**, paste the token in **API token**, save, and click **Test token**.

Account-owned tokens (**Manage Account → Account API Tokens**) work too. **Do not use the Global API Key**: it gives full control of your whole Cloudflare account and cannot be limited. The module accepts API tokens only.

More detail, token rotation and troubleshooting: [docs/CLOUDFLARE_API_TOKEN.md](docs/CLOUDFLARE_API_TOKEN.md). The same guide is in the module's **Setup guide** tab.

## Quick start: connect PrestaShop to Cloudflare in 5 minutes

1. **Create the API token** (see above).
2. **Settings** tab: paste the token, save, click **Test token**.
3. **Shops & zones** tab: click **Fetch zones from Cloudflare**.
4. Click **Auto-detect** to link every shop to its zone and purge scope. Review the result and click **Save**.
5. **Tools** tab: click **Purge everything now** for a shop, then open **Purge log**. A green row means PrestaShop and Cloudflare are connected.

From now on, clearing the PrestaShop cache or saving a product purges Cloudflare automatically.

## How the automatic Cloudflare cache purge works

### When PrestaShop clears its cache

The **Cache clear mode** setting decides when this happens:

| Mode | Behaviour |
|---|---|
| `all` (default) | Every PrestaShop cache clear purges Cloudflare: the **Clear cache** button, module install/upgrade, theme changes, Performance settings, and so on. |
| `manual` | Only the **Clear cache** button in **Advanced Parameters → Performance** purges Cloudflare. |
| `off` | Cache clears never purge Cloudflare. Content purges and the tools still work. |

A cache clear triggers a **full purge** of every linked shop, using that shop's purge scope. One click fires several PrestaShop events, and deploys often clear the cache more than once. So a **debounce** applies: a repeated full purge of the same zone within *N* seconds (default **30**) is skipped and logged as skipped. **Purge everything now** and the console command are never debounced.

### When catalog content changes

When you save or delete a product, category, CMS page, brand or supplier, the module builds its public URLs **in every active language of every shop it belongs to** and purges just those URLs:

| Change | URLs purged | Default |
|---|---|---|
| Product, specific price / discount | Product page + its default category page | on |
| Category | Category page | on |
| CMS page | CMS page | on |
| Brand (manufacturer) | Brand page | on |
| Supplier | Supplier page | off |
| Stock quantity | Product page | **off** (every order changes stock) |
| Homepage | Added to every purge above | on |

Renamed pages (changed friendly URL) are purged under the **old and the new URL**. All URLs from one save are sent **once, at the end of the request**, in as few API calls as possible. A Cloudflare error never breaks the page you are saving; it is written to the purge log.

## PrestaShop multistore with Cloudflare: one zone per shop

Each shop is linked to one Cloudflare zone and one **purge scope**:

| Scope | A full purge removes | Typical setup |
|---|---|---|
| **Whole zone** | Everything in the zone (`purge_everything`) | One shop per domain: `example.com` |
| **Hostnames** | Only the shop's hostnames (all domains and aliases from the shop URL settings) | Shop on a subdomain, zone shared with other sites: `shop.example.com` |
| **URL prefix** | Only URLs under the shop's folder | Several shops in sub-folders of one domain: `example.com/shop2/` |

Examples:

- **`example.com`**, one shop, nothing else on the domain → **whole zone**.
- **`shop.example.com`** while `example.com` is your blog → **hostnames**. The blog keeps its cache.
- **`example.com/`** (shop 1) and **`example.com/shop2/`** (shop 2) on one zone → shop 2 **URL prefix**, shop 1 **hostnames**.
- **`example.com`**, **`example.net`** and **`example.org`** (three shops, three zones) → each shop **whole zone** on its own zone.

**Auto-detect** matches the longest zone name to each shop's domain and suggests the scope. The **Shops & zones** tab warns you when two shops share a zone with the *whole zone* scope. Shops on the same zone are purged with a single API call.

## Cloudflare Development Mode, Under Attack mode and custom purge

The **Tools** tab groups your shops by Cloudflare zone:

- **Purge everything now** (per shop) and **Purge all shops**.
- **Development Mode** on/off with its current state. Cloudflare bypasses its cache so you see theme changes immediately, and switches it off by itself after 3 hours.
- **"I'm Under Attack" mode**: raise the zone's security level during a DDoS or bot attack, then restore it to Essentially off, Low, Medium or High.
- **Custom purge** of exact items:

| Type | Example (one per line) |
|---|---|
| URLs | `https://example.com/themes/classic/assets/css/theme.css` |
| Hostnames | `shop.example.com` |
| Prefixes | `example.com/shop2/img/` |
| Cache tags | `product-42` (needs `Cache-Tag` response headers) |

Development Mode and Under Attack need the optional *Zone Settings · Edit* permission.

## Purge log

Every Cloudflare API call is logged: date, shop, zone, action (full / hosts / prefixes / URLs / tags / setting change), trigger (cache clear, product #42, manual, cli…), the first purged items, the employee, and Cloudflare's result. You can filter by result, shop and action. Rows older than the retention period (default **30 days**) are removed automatically.

## Purge Cloudflare from the command line (deploy scripts, CI/CD)

```bash
# Full purge of every linked shop (no debounce)
php bin/console ppcloudflare:purge

# One shop
php bin/console ppcloudflare:purge --shop=2

# Specific URLs (repeat --url)
php bin/console ppcloudflare:purge --shop=1 \
  --url=https://example.com/themes/classic/assets/css/theme.css \
  --url=https://example.com/themes/classic/assets/js/theme.js
```

Typical deploy step:

```bash
php bin/console cache:clear --no-warmup && php bin/console ppcloudflare:purge
```

Exit codes: `0` success, `1` a purge failed, `2` invalid input. Runs are written to the purge log with trigger `cli`.

## Security

- **Encrypted API token.** It is stored encrypted with the shop's own secret key and never shown again in the back office.
- **Least privilege.** The module needs only *Zone Read* + *Cache Purge* (+ *Zone Settings Edit* for the tools). Limit the token to your shop zones and, optionally, to your server IP.
- **No Global API Key.** It cannot be scoped, so the module does not accept it.
- Every back-office action checks PrestaShop employee permissions and a CSRF token.
- **Reset** in the Module Manager keeps your token, mapping, settings and log. **Uninstall** deletes them. Nothing in your Cloudflare account is ever changed by uninstalling.

## FAQ

### How do I clear the Cloudflare cache from PrestaShop?
Install this module and add your API token. After that, **Advanced Parameters → Performance → Clear cache** clears the Cloudflare cache too. You can also purge any shop by hand in the module's **Tools** tab.

### Which Cloudflare API token permissions does PrestaShop need?
**Zone · Zone · Read** and **Zone · Cache Purge · Purge**. Add **Zone · Zone Settings · Edit** only if you want the Development Mode and "I'm Under Attack" buttons. See [How to create a Cloudflare API token](#how-to-create-a-cloudflare-api-token-for-prestashop).

### Does it work with the Cloudflare Free plan?
Yes. Since April 2025, purge by URL, hostname, prefix, tag and "everything" is available on **all plans** ([Cloudflare changelog](https://developers.cloudflare.com/changelog/2025-04-01-purge-for-all/)). Plans differ only in rate limits. On Free, keep the debounce at 30 seconds or more.

### Does it support PrestaShop multistore with different domains?
Yes. Each shop is linked to its own Cloudflare zone, and the zones can belong to one or more Cloudflare accounts that the token can access. Shops on subdomains or in sub-folders of a shared domain are purged by hostname or URL prefix, so other sites keep their cache.

### Does it work with PrestaShop 8 or 1.7?
No. The module is written for **PrestaShop 9** only (Symfony 6.4 admin, PHP 8.1+).

### I saved a product but the page did not change. Why?
By default Cloudflare caches **static files** (CSS, JS, images, fonts), not HTML pages. If the page is old, it is usually PrestaShop's own cache or your browser. Product URL purges matter when you cache HTML with a Cloudflare **Cache Rule**. What goes stale by default is CSS and JS after a theme or CCC change, and the cache-clear purge handles that.

### I get "Authentication error" (code 10000).
The token is missing a permission: *Cache Purge · Purge* for purging, *Zone Settings · Edit* for the tools. Edit the token in Cloudflare and add it; the token value stays the same.

### My zone is missing after "Fetch zones from Cloudflare".
The token's **Zone Resources** do not include it. Add the zone, or use *Include · All zones from an account*, then fetch again.

### "Purge everything" removed the cache of my other website.
The **whole zone** scope purges the entire zone. Use **hostnames** or **URL prefix** for shops that share a zone with other sites.

### I see "Rate limited" (HTTP 429) in the log.
You hit Cloudflare's purge rate limit. Raise the debounce, switch the cache clear mode to `manual`, or turn off stock purges. Then repeat the purge with **Purge everything now**.

### Does it slow down the back office?
No noticeable delay. Purges run once, at the end of the request, and an API call usually takes well under a second.

### Is it free?
Yes. It is open source under AFL-3.0, the same license PrestaShop uses for its modules.

## Roadmap

Planned or proposed ideas: Cloudflare Turnstile CAPTCHA, real visitor IP from `CF-Connecting-IP`, Cache-Tag headers on HTML, a one-click Cache Rules setup, cache warm-up after purge, a Cloudflare analytics dashboard widget, WAF IP blocking from the back office, and maintenance mode sync. Full list: [docs/FEATURES.md](docs/FEATURES.md). Ideas and pull requests are welcome.

## Documentation

- [docs/CLOUDFLARE_API_TOKEN.md](docs/CLOUDFLARE_API_TOKEN.md): Cloudflare API token, permissions, rotation, troubleshooting
- [docs/INSTALL.md](docs/INSTALL.md): install, upgrade, reset, uninstall
- [docs/FEATURES.md](docs/FEATURES.md): features and roadmap
- [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md): how the module is built (for developers)
- [CHANGELOG.md](CHANGELOG.md)

## License

[Academic Free License 3.0 (AFL-3.0)](LICENSE).

## Author

Made by **PrestaPro**. Issues and pull requests: [github.com/PrestaProLT/ppcloudflare/issues](https://github.com/PrestaProLT/ppcloudflare/issues).

<sub>Cloudflare is a trademark of Cloudflare, Inc. PrestaShop is a trademark of PrestaShop SA. This project is not affiliated with or endorsed by either company.</sub>
