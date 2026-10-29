<?php
/**
 * Cloudflare integration for PrestaShop 9.
 *
 * @author    PrestaPro
 * @copyright PrestaPro
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */
declare(strict_types=1);
namespace PrestaShop\Module\PpCloudflare\Zone;

/**
 * Reads shops and their domains (`shop_url`, every row including aliases).
 */
class ShopDomainResolver
{
    /** @var array<int, array>|null request cache */
    private static ?array $shops = null;

    /**
     * Every shop (active or not), keyed by id_shop.
     *
     * @return array<int, array{id_shop:int, id_shop_group:int, group_name:string, name:string, hosts:string[], uri:string, active:bool, ssl:bool, entries:array<int, array{host:string, domain:string, uri:string, main:bool}>}>
     */
    public function getShops(): array
    {
        if (self::$shops !== null) {
            return self::$shops;
        }

        $groups = [];
        foreach (\ShopGroup::getShopGroups(false) as $group) {
            $groups[(int) $group->id] = (string) $group->name;
        }

        $urlRows = \Db::getInstance()->executeS(
            'SELECT `id_shop`, `domain`, `domain_ssl`, `physical_uri`, `virtual_uri`, `main`
            FROM `' . _DB_PREFIX_ . 'shop_url` ORDER BY `id_shop`, `main` DESC, `id_shop_url`',
            true,
            false
        );
        $urlsByShop = [];
        foreach (is_array($urlRows) ? $urlRows : [] as $row) {
            $urlsByShop[(int) $row['id_shop']][] = $row;
        }

        $shops = [];
        foreach (\Shop::getShops(false) as $shop) {
            $idShop = (int) $shop['id_shop'];
            $idGroup = (int) $shop['id_shop_group'];
            $ssl = (bool) \Configuration::get('PS_SSL_ENABLED', null, $idGroup, $idShop);

            $entries = [];
            foreach ($urlsByShop[$idShop] ?? [] as $row) {
                $uri = self::normalizeUri((string) $row['physical_uri'] . '/' . (string) $row['virtual_uri']);
                $domains = $ssl ? [$row['domain_ssl'], $row['domain']] : [$row['domain'], $row['domain_ssl']];
                foreach ($domains as $domain) {
                    $domain = strtolower(trim((string) $domain));
                    if ($domain === '') {
                        continue;
                    }
                    $key = $domain . $uri;
                    if (!isset($entries[$key])) {
                        $entries[$key] = [
                            'host' => self::stripPort($domain),
                            'domain' => $domain,
                            'uri' => $uri,
                            'main' => (bool) $row['main'],
                        ];
                    }
                }
            }
            $entries = array_values($entries);
            $hosts = array_values(array_unique(array_column($entries, 'host')));

            $shops[$idShop] = [
                'id_shop' => $idShop,
                'id_shop_group' => $idGroup,
                'group_name' => $groups[$idGroup] ?? '',
                'name' => (string) $shop['name'],
                'hosts' => $hosts,
                'uri' => $entries[0]['uri'] ?? '/',
                'active' => (bool) $shop['active'],
                'ssl' => $ssl,
                'entries' => $entries,
            ];
        }

        return self::$shops = $shops;
    }

    public function getShop(int $idShop): ?array
    {
        return $this->getShops()[$idShop] ?? null;
    }

    /**
     * Hostnames of a shop (no port), main domain first.
     *
     * @return string[]
     */
    public function getHosts(int $idShop): array
    {
        return $this->getShop($idShop)['hosts'] ?? [];
    }

    /**
     * Base URLs of a shop (`https://host/uri/`, scheme from the shop's PS_SSL_ENABLED).
     *
     * @return string[]
     */
    public function getBaseUrls(int $idShop): array
    {
        $shop = $this->getShop($idShop);
        if ($shop === null) {
            return [];
        }
        $scheme = $shop['ssl'] ? 'https' : 'http';
        $urls = [];
        foreach ($shop['entries'] as $entry) {
            $urls[] = $scheme . '://' . $entry['domain'] . $entry['uri'];
        }

        return array_values(array_unique($urls));
    }

    /**
     * Purge prefixes of a shop (`host/uri/`, no scheme, no port).
     *
     * @return string[]
     */
    public function getPrefixes(int $idShop): array
    {
        $shop = $this->getShop($idShop);
        if ($shop === null) {
            return [];
        }
        $prefixes = [];
        foreach ($shop['entries'] as $entry) {
            $prefixes[] = $entry['host'] . $entry['uri'];
        }

        return array_values(array_unique($prefixes));
    }

    /**
     * URL scheme used by a shop ("https" when PS_SSL_ENABLED for that shop).
     */
    public function getScheme(int $idShop): string
    {
        return !empty($this->getShop($idShop)['ssl']) ? 'https' : 'http';
    }

    /**
     * Forgets the request cache (tests, long CLI runs).
     */
    public static function resetCache(): void
    {
        self::$shops = null;
    }

    /**
     * "shop//en" → "/shop/en/", "" → "/".
     */
    public static function normalizeUri(string $uri): string
    {
        $trimmed = trim((string) preg_replace('#/+#', '/', $uri), '/');

        return $trimmed === '' ? '/' : '/' . $trimmed . '/';
    }

    public static function stripPort(string $host): string
    {
        return (string) preg_replace('/:\d+$/', '', strtolower(trim($host)));
    }
}
