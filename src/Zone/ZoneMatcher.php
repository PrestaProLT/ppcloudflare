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
 * Suggests the Cloudflare zone and purge scope for a shop.
 *
 * Zone: the longest zone name equal to a shop host or a suffix ".<zone>" of it (main host first).
 * Scope: `prefix` when the shop lives in a sub-folder; else `host` when several shops match the
 * same zone or the shop host is neither the zone apex nor www.<apex>; else `zone`.
 */
class ZoneMatcher
{
    private ShopDomainResolver $resolver;

    public function __construct(?ShopDomainResolver $resolver = null)
    {
        $this->resolver = $resolver ?? new ShopDomainResolver();
    }

    /**
     * @param array<int, array{id:string, name:string}> $zones zone list (Settings::getCachedZones())
     *
     * @return array{zone:array, scope:string}|null
     */
    public function suggest(array $zones, int $idShop): ?array
    {
        $shops = $this->resolver->getShops();
        if (!isset($shops[$idShop])) {
            return null;
        }
        $shop = $shops[$idShop];

        $match = $this->matchShop($zones, $shop['hosts']);
        if ($match === null) {
            return null;
        }
        [$zone, $host] = $match;

        if (($shop['uri'] ?? '/') !== '/') {
            return ['zone' => $zone, 'scope' => ShopZoneMapping::SCOPE_PREFIX];
        }

        $sharing = 0;
        foreach ($shops as $other) {
            $otherMatch = $this->matchShop($zones, $other['hosts']);
            if ($otherMatch !== null && $otherMatch[0]['id'] === $zone['id']) {
                ++$sharing;
            }
        }

        $apex = strtolower((string) $zone['name']);
        if ($sharing > 1 || ($host !== $apex && $host !== 'www.' . $apex)) {
            return ['zone' => $zone, 'scope' => ShopZoneMapping::SCOPE_HOST];
        }

        return ['zone' => $zone, 'scope' => ShopZoneMapping::SCOPE_ZONE];
    }

    /**
     * Suggestions for every shop that has a match.
     *
     * @return array<int, array{zone:array, scope:string}> keyed by id_shop
     */
    public function suggestAll(array $zones): array
    {
        $result = [];
        foreach (array_keys($this->resolver->getShops()) as $idShop) {
            $suggestion = $this->suggest($zones, (int) $idShop);
            if ($suggestion !== null) {
                $result[(int) $idShop] = $suggestion;
            }
        }

        return $result;
    }

    /**
     * Longest zone matching a hostname, null when none.
     */
    public static function findZoneForHost(array $zones, string $host): ?array
    {
        $host = ShopDomainResolver::stripPort($host);
        $best = null;
        foreach ($zones as $zone) {
            $name = strtolower(trim((string) ($zone['name'] ?? '')));
            if ($name === '' || empty($zone['id'])) {
                continue;
            }
            if (self::hostBelongsToZone($host, $name)
                && ($best === null || strlen($name) > strlen((string) $best['name']))) {
                $best = $zone;
            }
        }

        return $best;
    }

    /**
     * True when $host equals $zoneName or is a subdomain of it.
     */
    public static function hostBelongsToZone(string $host, string $zoneName): bool
    {
        $host = ShopDomainResolver::stripPort($host);
        $zoneName = strtolower(trim($zoneName));
        if ($host === '' || $zoneName === '') {
            return false;
        }

        return $host === $zoneName || str_ends_with($host, '.' . $zoneName);
    }

    /**
     * @param string[] $hosts main host first
     *
     * @return array{0:array, 1:string}|null [zone, matched host]
     */
    private function matchShop(array $zones, array $hosts): ?array
    {
        foreach ($hosts as $host) {
            $zone = self::findZoneForHost($zones, (string) $host);
            if ($zone !== null) {
                return [$zone, ShopDomainResolver::stripPort((string) $host)];
            }
        }

        return null;
    }
}
