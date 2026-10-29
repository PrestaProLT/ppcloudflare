# Cloudflare Cache & Zone Manager for PrestaShop 9

**ppcloudflare** is a free, open-source **PrestaShop 9 Cloudflare module**. It purges the Cloudflare cache automatically when PrestaShop clears its own cache or when you edit products, categories and CMS pages. It links every shop of a **multistore** installation to its Cloudflare zone, and lets you switch **Development Mode** and **"I'm Under Attack"** mode on and off from the PrestaShop back office.

You no longer need to open the Cloudflare dashboard after every theme change, CSS/JS rebuild or price update to see your changes live.

---

## Contents

- [Why you need it](#why-you-need-it)
- [Features](#features)
- [Requirements](#requirements)
- [Installation](#installation)
- [Quick start](#quick-start)
- [How automatic purging works](#how-automatic-purging-works)
- [Multistore and purge scopes](#multistore-and-purge-scopes)
- [Tools](#tools)
- [Purge log](#purge-log)
- [Command line (deploy scripts)](#command-line-deploy-scripts)
- [Security](#security)
- [FAQ and troubleshooting](#faq-and-troubleshooting)
- [Uninstalling](#uninstalling)
- [License](#license)

---

## Why you need it

Cloudflare sits in front of your store as a CDN and keeps copies of your files on its servers around the world. That makes the store faster, but Cloudflare does not know when PrestaShop changes something. Common symptoms:

- you rebuild the theme or clear the cache in **Advanced Parameters → Performance**, but visitors still get the old CSS and JavaScript;
- PrestaShop's "Combine, Compress and Cache" (CCC) files are regenerated, but Cloudflare keeps serving the previous versions;
- if you cache HTML pages with a Cache Rule, an old price or description stays visible after you edit a product.

This module tells Cloudflare to drop its copy at the right moment, so the next visitor gets the fresh version.

## Features

- **Cloudflare cache purge when PrestaShop clears its cache.** You choose whether this happens on every cache clear or only when you click **Clear cache** in the back office.
- **Targeted URL purges when catalog content changes.** Products (including specific prices), categories, CMS pages, brands (manufacturers) and suppliers. Every language is covered, and the homepage can be included.
- **Stock-change purges**, optional and off by default.
- **Multistore support.** Each shop is linked to its own Cloudflare zone, with a purge scope per shop: whole zone, the shop's hostnames only, or the shop's URL prefix only.
- **Auto-detection** of the right zone and scope for every shop from its domain.
- **Zone tools:** Purge everything now, Development Mode on/off, "I'm Under Attack" mode on/off.
- **Custom purge** by URL, hostname, prefix or cache tag.
- **Debounce:** repeated full purges of the same zone within a short window are skipped, so a deploy that clears the cache five times sends one purge, not five.
- **Purge log** with filters, showing what was purged, why, by whom, and the Cloudflare result.
- **Console command** for deploy scripts and CI pipelines.
- **Large purges are split automatically** into batches Cloudflare accepts.
- **API token encrypted at rest.** Only scoped API tokens are used; the Global API Key is never needed.
- **Built-in setup guide** in the back office that explains which token permissions to allow.
- Works with **any Cloudflare plan**, including Free.

## Requirements

| | |
|---|---|
| PrestaShop | **9.0 or newer** (older versions are not supported) |
| PHP | **8.1 or newer**, with the cURL extension |
| Cloudflare | Any account and any plan (Free, Pro, Business, Enterprise), with your shop domain(s) added as zones |
| Network | The server must be able to reach `https://api.cloudflare.com` over HTTPS |

## Installation

### Option 1: ZIP from GitHub Releases (recommended)

1. Download `ppcloudflare.zip` from the [Releases page](https://github.com/PrestaProLT/ppcloudflare/releases).
2. In your PrestaShop back office go to **Modules → Module Manager → Upload a module** and drop the ZIP file.
3. Click **Configure**.

> The ZIP must be named `ppcloudflare.zip` and contain a single `ppcloudflare/` folder. GitHub's automatic "Source code (zip)" archive is named `ppcloudflare-main` or `ppcloudflare-1.0.0`, so PrestaShop would not recognise it. Use the release asset, or rename the folder inside before uploading.

### Option 2: git clone

```bash
cd /path/to/prestashop/modules
git clone https://github.com/PrestaProLT/ppcloudflare.git ppcloudflare
```

Then install it from **Modules → Module Manager** (search for "Cloudflare"), or from the command line:

```bash
php bin/console prestashop:module install ppcloudflare
```

Upgrading, uninstalling and resetting are covered in [docs/INSTALL.md](docs/INSTALL.md).

## Quick start

1. **Create a Cloudflare API token** with *Zone · Zone · Read* and *Zone · Cache Purge · Purge* permissions (add *Zone · Zone Settings · Edit* if you want the Development Mode and Under Attack buttons). Step-by-step instructions: [docs/CLOUDFLARE_API_TOKEN.md](docs/CLOUDFLARE_API_TOKEN.md). The same guide is in the module's **Setup guide** tab.
2. **Paste the token** in the **Settings** tab and click **Test token**.
3. Open the **Shops & zones** tab and click **Fetch zones from Cloudflare**.
4. Click **Auto-detect** to link every shop to its zone and purge scope. Check the result, change anything you need, and click **Save**.
5. In the **Tools** tab, click **Purge everything now** for a shop and check the **Purge log** tab. A green row means everything works.

From now on, clearing the PrestaShop cache or saving a product purges Cloudflare automatically.

## How automatic purging works

### When PrestaShop clears its cache

The **Cache clear mode** setting controls what happens:

| Mode | Behaviour |
|---|---|
| `all` (default) | Every PrestaShop cache clear purges Cloudflare: the **Clear cache** button, module install/upgrade, theme changes, Performance settings changes, and so on. |
| `manual` | Only the **Clear cache** button in **Advanced Parameters → Performance** purges Cloudflare. Other internal cache clears are ignored. |
| `off` | Cache clears never purge Cloudflare. Entity purges and the manual tools still work. |

A cache clear triggers a **full purge** of every linked shop, using that shop's purge scope. A single cache clear can fire several PrestaShop events, and a deploy often clears the cache more than once, so a **debounce** applies: a second full purge of the same zone and scope within *N* seconds (default **30**) is skipped and logged as skipped. **Purge everything now** and the console command always go through.

### When catalog content changes

When you save or delete a product, category, CMS page, brand or supplier, the module works out its public URLs **in every active language of every shop it belongs to** and purges just those URLs:

| Change | URLs purged | Default |
|---|---|---|
| Product (and its specific prices / discounts) | product page in every language + its default category page | on |
| Category | category page | on |
| CMS page | CMS page | on |
| Brand (manufacturer) | brand page | on |
| Supplier | supplier page | off |
| Stock quantity | product page | **off**. On a busy shop every order changes stock; turn it on only if you cache HTML and show stock levels. |
| Homepage | added to every purge above | on |

All URLs changed during one back-office action are collected and sent **once, at the end of the request**, in as few API calls as possible. Cloudflare limits the number of URLs per call, so larger lists are split into batches automatically. If a full purge of a shop is queued in the same request, its individual URL purges are dropped, since the full purge covers them.

A purge never breaks the page you are saving. If Cloudflare is unreachable or returns an error, the problem is written to the purge log and PrestaShop carries on as normal.

## Multistore and purge scopes

Each shop is linked to one Cloudflare zone and one **purge scope**, which decides what a full purge removes:

| Scope | What a full purge removes | Typical setup |
|---|---|---|
| **Whole zone** (`zone`) | Everything in the zone (`purge_everything`) | One shop per domain: `example.com` |
| **Hostnames** (`host`) | Only the shop's hostnames (every domain and alias in the shop's URL settings) | Shop on its own subdomain, zone shared with other sites: `shop.example.com` |
| **URL prefix** (`prefix`) | Only URLs under the shop's folder | Several shops in sub-folders of one domain: `example.com/shop2/` |

Examples:

- **`example.com`**, one shop, nothing else on the domain → scope **zone** on zone `example.com`.
- **`shop.example.com`**, while `example.com` (zone `example.com`) is your blog → scope **host**. Only `shop.example.com` is purged and the blog keeps its cache.
- **`example.com/`** (shop 1) and **`example.com/shop2/`** (shop 2) on the same zone → shop 2 gets scope **prefix** (`example.com/shop2/`). Shop 1 gets scope **host**, because the zone is used by more than one shop.

**Auto-detect** picks the longest zone name that matches each shop's domain (so `shop.example.com` is matched to a `shop.example.com` zone before `example.com`). It suggests **prefix** for shops in a sub-folder and **host** when a zone is shared or the shop is on a subdomain. In every other case it suggests **zone**. You can always change the suggestion before saving.

Shops that use the **zone** scope on the same zone are purged with one API call, not one per shop. Shops with no zone linked are skipped.

## Tools

The **Tools** tab lists every linked shop with:

- **Purge everything now** (per shop) and **Purge all shops**: an immediate full purge using the shop's scope (no debounce).
- **Development Mode**: shows the current state and turns it on or off. While it is on, Cloudflare bypasses its cache for the whole zone so you see changes immediately. Cloudflare turns it off by itself after 3 hours.
- **I'm Under Attack mode**: switches the zone's security level to "I'm Under Attack!", or back to a level you choose (Essentially off, Low, Medium, High). Visitors see a short browser check before reaching the shop. Use it during a DDoS or bot flood, and switch it off afterwards.

**Custom purge** removes exactly what you list from any fetched zone:

| Type | Example input (one per line) |
|---|---|
| URLs | `https://example.com/themes/classic/assets/css/theme.css` |
| Hostnames | `shop.example.com` |
| Prefixes | `example.com/shop2/img/` (no `https://`) |
| Cache tags | `product-42` (only works if your pages send `Cache-Tag` headers) |

> Development Mode and Under Attack need the optional *Zone Settings · Edit* permission. Without it, these buttons report a permission error and everything else keeps working.

## Purge log

Every call to Cloudflare is recorded: date, shop, zone, action (full / hosts / prefixes / URLs / tags / setting change), trigger (cache clear, product #42, manual, cli…), the first purged items, the employee, success or failure and Cloudflare's message. You can filter by result, shop and action, and clear the log.

Rows older than the retention period (default **30 days**) are deleted automatically.

## Command line (deploy scripts)

Purge Cloudflare at the end of a deploy, right after clearing the PrestaShop cache:

```bash
# Full purge of every linked shop (debounce ignored)
php bin/console ppcloudflare:purge

# Only shop 2
php bin/console ppcloudflare:purge --shop=2

# Explicitly all shops
php bin/console ppcloudflare:purge --shop=all

# Specific URLs only (repeat --url)
php bin/console ppcloudflare:purge --shop=1 \
  --url=https://example.com/themes/classic/assets/css/theme.css \
  --url=https://example.com/themes/classic/assets/js/theme.js
```

Example deploy step:

```bash
php bin/console cache:clear --no-warmup && php bin/console ppcloudflare:purge
```

The command writes to the same purge log, with trigger `cli`.

## Security

- **Encrypted token.** The API token is stored encrypted with your shop's own secret key (`_NEW_COOKIE_KEY_`). The back office never shows it again: the field stays blank, and leaving it blank keeps the saved token.
- **Least privilege.** The module needs only *Zone Read* and *Cache Purge*, plus *Zone Settings Edit* if you use the zone tools. Limit the token to your shop zones, and optionally to your server's IP addresses.
- **Never use the Global API Key.** It gives full control of your whole Cloudflare account, including DNS, SSL and billing, and cannot be scoped. This module does not accept it.
- Every back-office action is protected by PrestaShop's employee permissions and CSRF tokens.
- If the token leaks, revoke it in Cloudflare (**My Profile → API Tokens → ⋯ → Delete**) and create a new one. See [docs/CLOUDFLARE_API_TOKEN.md](docs/CLOUDFLARE_API_TOKEN.md#rotate-or-revoke-the-token).

## FAQ and troubleshooting

**"Authentication error" (HTTP 403, code 10000) when purging or using a tool**
The token lacks a permission. Purge needs *Zone · Cache Purge · Purge*. Development Mode and Under Attack need *Zone · Zone Settings · Edit*. Edit the token in Cloudflare and add the permission; you do not need a new token.

**My zone is not in the list after "Fetch zones"**
The token's **Zone Resources** do not include it. Edit the token and either add a row for that zone or use *Include · All zones from an account*. If the zone belongs to another Cloudflare account, the token must be created by a user who has access to that account. Then click **Fetch zones** again.

**"Purge everything" removed the cache of my other website**
Scope **zone** purges the entire zone, including every other site on that domain or its subdomains. Use scope **host** or **prefix** for shops that share a zone with something else.

**I saved a product but the page did not change**
By default Cloudflare does **not** cache HTML pages. It caches static files only: CSS, JavaScript, images and fonts. If you see an old page, it is usually PrestaShop's own cache or your browser. Product purges only matter if you created a **Cache Rule** that caches HTML ("Cache Everything"). What *does* go stale by default is static assets, which is exactly what changes after a theme update or a CCC regeneration. The cache-clear purge handles that.

**Do I need a paid Cloudflare plan?**
No. Purge by URL, hostname, prefix, tag and "everything" is available on all plans, including Free, since April 2025 ([Cloudflare changelog](https://developers.cloudflare.com/changelog/2025-04-01-purge-for-all/)). Plans differ only in rate limits.

**Is there a limit on how many URLs I can purge?**
Cloudflare accepts a limited number of items per API request. The module splits long lists into batches automatically, so you can paste hundreds of URLs into Custom purge.

**"Rate limited" / HTTP 429 in the log**
Cloudflare limits how often you can purge. On the Free plan, hostname, tag, prefix and "everything" purges are limited to a few requests per minute per account ([limits per plan](https://developers.cloudflare.com/cache/how-to/purge-cache/#availability-and-limits)). Raise the debounce, use **manual** cache-clear mode, or turn off stock purges. The purge that hit the limit is logged as failed and can be repeated with **Purge everything now**.

**The token stopped working**
It may have expired (if you set a TTL), been revoked, or your server's IP changed while IP filtering is on. Click **Test token** in Settings to see Cloudflare's answer.

**Does it slow down the back office?**
No noticeable delay. Purges are sent once, at the very end of the request, after PrestaShop has done its work. A single API call usually takes well under a second.

**Does it work with account-owned API tokens?**
Yes. Tokens created under **Manage Account → Account API Tokens** work as well as user tokens.

## Uninstalling

Uninstalling **permanently deletes** the API token, the shop-to-zone mapping, all settings and the purge log. Nothing is changed in your Cloudflare account. **Reset** in the Module Manager is safe: it keeps your token, mapping, settings and log, and only restores the hooks and any missing default settings. Details in [docs/INSTALL.md](docs/INSTALL.md).

## More documentation

- [docs/INSTALL.md](docs/INSTALL.md): install, upgrade, uninstall, reset
- [docs/CLOUDFLARE_API_TOKEN.md](docs/CLOUDFLARE_API_TOKEN.md): creating the API token
- [docs/FEATURES.md](docs/FEATURES.md): feature list and roadmap
- [CHANGELOG.md](CHANGELOG.md)

## License

Released under the [Academic Free License 3.0 (AFL-3.0)](LICENSE), the same license PrestaShop uses for its modules.

## Author

Made by **PrestaPro**. Issues and pull requests are welcome on [GitHub](https://github.com/PrestaProLT/ppcloudflare/issues).

*Cloudflare is a trademark of Cloudflare, Inc. This module is not affiliated with or endorsed by Cloudflare, Inc. PrestaShop is a trademark of PrestaShop SA.*
