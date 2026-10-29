# Creating the Cloudflare API token

The module talks to Cloudflare through a scoped **API token**. This page shows how to create one with only the permissions the module needs.

> Menu names below match the Cloudflare dashboard and the official guide [Create API token](https://developers.cloudflare.com/fundamentals/api/get-started/create-token/) (checked October 2026). Cloudflare sometimes renames menu items. If a label differs slightly, look for the closest match.

## Permissions at a glance

| Module feature | Permission (Group · Item · Access) | Required? |
|---|---|---|
| Test token, fetch zones, auto-detect | **Zone · Zone · Read** | **Required** |
| Automatic purges, Purge now, Custom purge, console command | **Zone · Cache Purge · Purge** | **Required** |
| Development Mode on/off and state | **Zone · Zone Settings · Edit** | Optional |
| I'm Under Attack mode (security level) on/off and state | **Zone · Zone Settings · Edit** | Optional |

*Zone Settings · Edit* also gives read access to zone settings. Without it the module works normally, and only the Development Mode and Under Attack buttons report a permission error.

## Step by step

1. Log in to **[dash.cloudflare.com](https://dash.cloudflare.com)**.
2. Click the **profile icon** in the top-right corner, then **My Profile**.
3. Open **API Tokens** in the left menu.
4. Click **Create Token**.
5. Scroll to the bottom and, next to **Create Custom Token**, click **Get started**.
   (Do not use the "Edit zone DNS" or other templates: they grant more than needed.)
6. **Token name:** something you will recognise later, e.g. `PrestaShop example.com – cache purge`.
7. **Permissions:** add one row per permission (use **+ Add more**):

   | Group | Item | Access |
   |---|---|---|
   | Zone | Zone | Read |
   | Zone | Cache Purge | Purge |
   | Zone | Zone Settings | Edit *(optional)* |

8. **Zone Resources:** choose one of:
   - **Include · All zones from an account** → pick your account. Easiest for multistore setups, and new zones work without editing the token.
   - **Include · Specific zone** → pick a zone. Add **one row per shop domain** (e.g. `example.com`, `example.net`). This is the most restrictive option.
9. **Client IP Address Filtering** *(optional)*: set the operator to *Is in* and enter the **outbound IP addresses of your web server**, both IPv4 and IPv6 if it has both. Requests from any other address are rejected, even with the token.
   You can find the outbound address by running `curl -4 https://ifconfig.co` and `curl -6 https://ifconfig.co` on the server. Remember to update the filter when you move servers.
10. **TTL** *(optional)*: a start and end date for the token. **The module stops working the moment the token expires.** Automatic purges will then fail and be logged as errors. Set a calendar reminder if you use this.
11. Click **Continue to summary**, check the list, then click **Create Token**.
12. **Copy the token now.** Cloudflare shows it **only once**. (Cloudflare also shows a `curl` test command; you do not need it.)
13. In PrestaShop open **Modules → Module Manager → Cloudflare Cache & Zone Manager → Configure → Settings**, paste the token into **API token**, save, and click **Test token**. You should see *"The API token is valid and active."*
14. Go to the **Shops & zones** tab → **Fetch zones from Cloudflare**. Every zone covered by the token's Zone Resources should be listed.

## Account-owned tokens

Cloudflare also offers **account-owned** tokens, which belong to the account instead of a person. They keep working when the employee who created them leaves. Create them under **Manage Account → Account API Tokens** (from the account home, not from *My Profile*), with the same permissions and zone resources as above.

The module supports both kinds. Account tokens cannot be checked with Cloudflare's user-token verify call, so for them **Test token** confirms the token by listing one zone.

## Why not the Global API Key?

The **Global API Key** (also under *My Profile → API Tokens*) is a legacy credential with **full, unrestricted access to your entire Cloudflare account**: DNS, SSL, firewall, Workers, billing and every zone. It cannot be limited to specific permissions, zones, IP addresses or a lifetime, and it is tied to your login email. If it leaked through a database dump or a compromised server, an attacker could take over all your domains.

A scoped API token limits the damage to "can purge cache and read zone names". The module therefore accepts **API tokens only**.

## Rotate or revoke the token

**Rotate** (recommended every year, or when someone with access leaves):

1. In Cloudflare: **My Profile → API Tokens**, click **⋯** next to the token → **Roll** (generates a new secret, same permissions). Or create a new token as above.
2. Paste the new token in the module's **Settings** tab, save, and click **Test token**.
3. If you created a new token rather than rolling, delete the old one.

**Revoke** (if you think the token leaked): **My Profile → API Tokens → ⋯ → Delete**. The token stops working immediately. Then create a new one and paste it in the module.

To **change permissions or zones** later, use **⋯ → Edit**. The token secret stays the same, so nothing changes in PrestaShop.

## Cloudflare purge limits (for reference)

Since **April 2025** every Cloudflare plan, **including Free**, can purge by URL, hostname, cache tag, prefix and "purge everything" ([changelog](https://developers.cloudflare.com/changelog/2025-04-01-purge-for-all/)). Plans differ in rate limits. Figures from [Purge cache – availability and limits](https://developers.cloudflare.com/cache/how-to/purge-cache/#availability-and-limits), October 2026:

| Plan | Hostname / tag / prefix / everything | Single-file (URL) purge | Items per request |
|---|---|---|---|
| Free | 5 requests per minute | 800 URLs per second | 100 |
| Pro | 5 requests per second | 1,500 URLs per second | 100 |
| Business | 10 requests per second | 1,500 URLs per second | 100 |
| Enterprise | 50 requests per second | 3,000 URLs per second | 500 |

Limits are shared by all zones of the same plan in one account. The module sends at most 30 items per request (well below every plan's limit) and batches long lists automatically. On the Free plan, keep the debounce at 30 seconds or more so repeated cache clears do not hit the five-per-minute limit.

## Troubleshooting

| Message in the module | Cause | Fix |
|---|---|---|
| *Authentication error* (403, code 10000) on purge | Missing **Cache Purge · Purge** | Edit the token and add the permission |
| *Authentication error* on Development Mode / Under Attack | Missing **Zone Settings · Edit** | Add it, or don't use those tools |
| Zone missing after Fetch zones | Not in the token's **Zone Resources** | Add the zone, or use *All zones from an account* |
| *Invalid API Token* (code 1000) | Typo, revoked, or extra spaces while pasting | Paste again. Create a new token if it was revoked |
| Token valid but every call fails | **IP filtering** excludes the server's current IP, or the token **expired** | Update the IP list or the TTL |
