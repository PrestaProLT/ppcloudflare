# Install, upgrade, uninstall, reset

Requirements: PrestaShop **9.0+**, PHP **8.1+** with cURL, outbound HTTPS to `api.cloudflare.com`.

## Install

### From a release ZIP

1. Download `ppcloudflare.zip` from [GitHub Releases](https://github.com/PrestaProLT/ppcloudflare/releases).
2. Back office → **Modules → Module Manager → Upload a module** → select the ZIP.
3. Click **Configure** and follow the [Quick start](../README.md#quick-start).

The archive must be named `ppcloudflare.zip` and contain one top-level folder named exactly `ppcloudflare/`, with `ppcloudflare.php` inside it. GitHub's auto-generated *Source code* archives use a folder like `ppcloudflare-1.0.0/` and will not install as-is. To build a correct ZIP yourself:

```bash
git clone https://github.com/PrestaProLT/ppcloudflare.git ppcloudflare
zip -r ppcloudflare.zip ppcloudflare -x 'ppcloudflare/.git*'
```

### From git

```bash
cd /path/to/prestashop/modules
git clone https://github.com/PrestaProLT/ppcloudflare.git ppcloudflare
cd /path/to/prestashop
php bin/console prestashop:module install ppcloudflare
```

(Or install it from **Module Manager**, where it appears as "Cloudflare Cache & Zone Manager".)

The `vendor/` folder (autoloader only, no third-party libraries) is committed, so `composer install` is not required.

### What installation does

- Creates two database tables: the shop ↔ zone mapping and the purge log.
- Saves the default settings (automatic purging on, cache-clear mode `all`, product/category/CMS/brand purges on, supplier and stock purges off, homepage purge on, debounce 30 s, log retention 30 days).
- Registers the module on PrestaShop's cache-clear and catalog-change events.
- Adds hidden back-office pages under the module's **Configure** button. No new sidebar menu entries are added.

Nothing is sent to Cloudflare until you save an API token and link at least one shop to a zone.

## Upgrade

1. Download the new release ZIP and upload it via **Module Manager → Upload a module** (it replaces the files), or run `git pull` in `modules/ppcloudflare`.
2. If Module Manager shows **Upgrade** for the module, click it. From the command line:
   ```bash
   php bin/console prestashop:module upgrade ppcloudflare
   ```
3. Clear the PrestaShop cache (**Advanced Parameters → Performance → Clear cache**).

Upgrades keep your token, mapping, settings and log. Version-specific notes are in [CHANGELOG.md](../CHANGELOG.md).

## Uninstall

Module Manager → **⋮ → Uninstall**, or:

```bash
php bin/console prestashop:module uninstall ppcloudflare
```

**Uninstalling permanently deletes:**

- the encrypted API token,
- the shop ↔ zone mapping table,
- all module settings (every `PPCLOUDFLARE_*` configuration value),
- the purge log table.

**Not affected:** your Cloudflare account, zones, cached content, Development Mode and security level. If you switched on Development Mode or I'm Under Attack, switch it off **before** uninstalling, or switch it off in the Cloudflare dashboard. The API token also stays in Cloudflare; delete it there if you no longer need it (see [CLOUDFLARE_API_TOKEN.md](CLOUDFLARE_API_TOKEN.md#rotate-or-revoke-the-token)).

To remove the files as well, use **Delete** in Module Manager or remove `modules/ppcloudflare/`.

## Reset

Module Manager → **⋮ → Reset**, or:

```bash
php bin/console prestashop:module reset ppcloudflare
```

Reset is safe and **keeps your data**: the API token, shop ↔ zone mapping, purge log and your existing settings stay. It re-creates missing tables, re-registers the module's hooks and adds default values for any missing settings. Use it if automatic purges stopped after a migration or a manual database change.

Only **Uninstall** deletes the module's data (see above).

## Disable / enable

Disabling the module in Module Manager stops all automatic purges and hides the configuration pages. All data is kept. To pause automatic purges but keep the tools, turn off the **Automatic purges** switch in the module's Settings tab instead.
