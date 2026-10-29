<?php
/**
 * Cloudflare integration for PrestaShop 9.
 *
 * @author    PrestaPro
 * @copyright PrestaPro
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */
declare(strict_types=1);

namespace PrestaShop\Module\PpCloudflare\Purge;

use PrestaShop\Module\PpCloudflare\Api\CloudflareClient;
use PrestaShop\Module\PpCloudflare\Config\Settings;
use PrestaShop\Module\PpCloudflare\Log\LogRepository;
use PrestaShop\Module\PpCloudflare\Zone\ShopDomainResolver;
use PrestaShop\Module\PpCloudflare\Zone\ShopZoneMapping;
use PrestaShop\Module\PpCloudflare\Zone\ShopZoneRepository;
use PrestaShop\Module\PpCloudflare\Zone\ZoneMatcher;

/**
 * Executes purges against Cloudflare and writes one log row per API operation.
 * Never throws: every failure is returned as a failed PurgeResult.
 */
class PurgeService
{
    public const TYPE_FILES = 'files';
    public const TYPE_HOSTS = 'hosts';
    public const TYPE_PREFIXES = 'prefixes';
    public const TYPE_TAGS = 'tags';
    public const TYPES = [self::TYPE_FILES, self::TYPE_HOSTS, self::TYPE_PREFIXES, self::TYPE_TAGS];

    private const NO_TOKEN = 'API token is not configured.';

    private ?CloudflareClient $client;
    private ShopZoneRepository $zones;
    private ShopDomainResolver $domains;
    private LogRepository $log;

    public function __construct(
        ?CloudflareClient $client,
        ?ShopZoneRepository $zones = null,
        ?ShopDomainResolver $domains = null,
        ?LogRepository $log = null
    ) {
        $this->client = $client;
        $this->zones = $zones ?? new ShopZoneRepository();
        $this->domains = $domains ?? new ShopDomainResolver();
        $this->log = $log ?? new LogRepository();
    }

    /**
     * Service built from the stored settings. Works without a token: every call then
     * returns a failed result.
     */
    public static function create(): self
    {
        return new self(CloudflareClient::fromSettings());
    }

    public function getClient(): ?CloudflareClient
    {
        return $this->client;
    }

    /**
     * Full purge of one shop, according to its purge scope (zone / host / prefix).
     */
    public function purgeShop(int $idShop, string $trigger, bool $force = false): PurgeResult
    {
        if ($this->client === null) {
            return PurgeResult::failure(self::NO_TOKEN, '', PurgeResult::ACTION_NONE, $idShop);
        }
        $mapping = $this->zones->find($idShop);
        if ($mapping === null) {
            return PurgeResult::failure(
                sprintf('Shop #%d is not linked to a Cloudflare zone.', $idShop),
                '',
                PurgeResult::ACTION_NONE,
                $idShop
            );
        }

        return $this->fullPurge($mapping, $mapping->idShop, $trigger, $force);
    }

    /**
     * Full purge of every mapped shop. Shops sharing a zone with `zone` scope result in one
     * purge_everything call; host/prefix purges inside an already fully purged zone are skipped.
     *
     * @return PurgeResult[]
     */
    public function purgeAllShops(string $trigger, bool $force = false): array
    {
        if ($this->client === null) {
            return [PurgeResult::failure(self::NO_TOKEN)];
        }
        $mappings = $this->zones->findAll();
        if ($mappings === []) {
            return [PurgeResult::failure('No shop is linked to a Cloudflare zone.')];
        }

        $zoneWide = [];
        foreach ($mappings as $mapping) {
            if ($mapping->purgeScope === ShopZoneMapping::SCOPE_ZONE) {
                $zoneWide[$mapping->zoneId][] = $mapping;
            }
        }

        $results = [];
        foreach ($zoneWide as $shopMappings) {
            $first = $shopMappings[0];
            $results[] = $this->fullPurge($first, count($shopMappings) === 1 ? $first->idShop : null, $trigger, $force);
        }
        // host/prefix shops of the same zone and scope are merged into one request
        $partial = [];
        foreach ($mappings as $mapping) {
            if ($mapping->purgeScope !== ShopZoneMapping::SCOPE_ZONE && !isset($zoneWide[$mapping->zoneId])) {
                $partial[$mapping->zoneId . '|' . $mapping->purgeScope][] = $mapping;
            }
        }
        foreach ($partial as $group) {
            $first = array_shift($group);
            $results[] = $this->fullPurge($first, $group === [] ? $first->idShop : null, $trigger, $force, $group);
        }

        return $results;
    }

    /**
     * Purges individual URLs of a shop. URLs whose host is not part of the shop's zone are dropped.
     *
     * @param string[] $urls
     */
    public function purgeUrls(int $idShop, array $urls, string $trigger): PurgeResult
    {
        if ($this->client === null) {
            return PurgeResult::failure(self::NO_TOKEN, '', PurgeResult::ACTION_FILES, $idShop);
        }
        $mapping = $this->zones->find($idShop);
        if ($mapping === null) {
            return PurgeResult::failure(
                sprintf('Shop #%d is not linked to a Cloudflare zone.', $idShop),
                '',
                PurgeResult::ACTION_FILES,
                $idShop
            );
        }

        $valid = [];
        $dropped = 0;
        foreach (self::cleanItems($urls) as $url) {
            $host = self::urlHost($url);
            if ($host !== null && ZoneMatcher::hostBelongsToZone($host, $mapping->zoneName)) {
                $valid[] = $url;
            } else {
                ++$dropped;
            }
        }
        if ($valid === []) {
            return PurgeResult::failure(
                sprintf('None of the URLs belongs to the zone %s.', $mapping->zoneName),
                $mapping->zoneId,
                PurgeResult::ACTION_FILES,
                $idShop
            );
        }

        $message = sprintf('Purged %d URL(s).', count($valid));
        if ($dropped > 0) {
            $message .= sprintf(' %d URL(s) outside the zone %s were ignored.', $dropped, $mapping->zoneName);
        }

        return $this->execute(
            $idShop,
            $mapping->zoneId,
            PurgeResult::ACTION_FILES,
            $valid,
            $trigger,
            $message,
            fn () => $this->client->purgeFiles($mapping->zoneId, $valid)
        );
    }

    /**
     * Manual purge tool.
     *
     * @param string $type files|hosts|prefixes|tags
     * @param string[] $items
     */
    public function purgeCustom(string $zoneId, string $type, array $items, string $trigger): PurgeResult
    {
        $action = 'purge_' . $type;
        if (!in_array($type, self::TYPES, true)) {
            return PurgeResult::failure('Unknown purge type.', $zoneId);
        }
        if ($this->client === null) {
            return PurgeResult::failure(self::NO_TOKEN, $zoneId, $action);
        }
        $items = self::cleanItems($items);
        if ($items === []) {
            return PurgeResult::failure('Nothing to purge: the list is empty.', $zoneId, $action);
        }
        if ($type === self::TYPE_FILES) {
            foreach ($items as $url) {
                if (self::urlHost($url) === null) {
                    return PurgeResult::failure(sprintf('Invalid URL: %s', $url), $zoneId, $action);
                }
            }
        }

        $idShop = null;
        foreach ($this->zones->findAll() as $mapping) {
            if ($mapping->zoneId === $zoneId) {
                $idShop = $idShop === null ? $mapping->idShop : 0;
            }
        }

        $client = $this->client;

        return $this->execute(
            $idShop ?: null,
            $zoneId,
            $action,
            $items,
            $trigger,
            sprintf('Purged %d item(s) by %s.', count($items), $type),
            static function () use ($client, $zoneId, $type, $items): void {
                match ($type) {
                    self::TYPE_FILES => $client->purgeFiles($zoneId, $items),
                    self::TYPE_HOSTS => $client->purgeHosts($zoneId, $items),
                    self::TYPE_PREFIXES => $client->purgePrefixes($zoneId, $items),
                    self::TYPE_TAGS => $client->purgeTags($zoneId, $items),
                };
            }
        );
    }

    /**
     * @param ShopZoneMapping[] $sameZoneMappings further shops with the same zone and scope, merged into the same request
     */
    private function fullPurge(
        ShopZoneMapping $mapping,
        ?int $idShopForLog,
        string $trigger,
        bool $force,
        array $sameZoneMappings = []
    ): PurgeResult {
        $items = [];
        $all = array_merge([$mapping], $sameZoneMappings);
        switch ($mapping->purgeScope) {
            case ShopZoneMapping::SCOPE_HOST:
                $action = PurgeResult::ACTION_HOSTS;
                foreach ($all as $m) {
                    $items = array_merge($items, $this->domains->getHosts($m->idShop));
                }
                break;
            case ShopZoneMapping::SCOPE_PREFIX:
                $action = PurgeResult::ACTION_PREFIXES;
                foreach ($all as $m) {
                    $items = array_merge($items, $this->domains->getPrefixes($m->idShop));
                }
                break;
            default:
                $action = PurgeResult::ACTION_EVERYTHING;
        }
        $items = array_values(array_unique($items));

        if ($action !== PurgeResult::ACTION_EVERYTHING) {
            $items = array_values(array_filter(
                $items,
                static fn (string $item): bool => ZoneMatcher::hostBelongsToZone((string) strtok($item, '/'), $mapping->zoneName)
            ));
            if ($items === []) {
                return PurgeResult::failure(
                    sprintf('Shop #%d has no domain inside the zone %s.', $mapping->idShop, $mapping->zoneName),
                    $mapping->zoneId,
                    $action,
                    $idShopForLog
                );
            }
        }

        $debounceKey = $mapping->zoneId . '|' . $mapping->purgeScope . '|' . md5((string) json_encode($items));
        $debounce = Settings::getDebounceSeconds();
        if (!$force && $debounce > 0) {
            $last = Settings::getLastFullPurge($debounceKey);
            $age = time() - $last;
            if ($last > 0 && $age >= 0 && $age < $debounce) {
                return new PurgeResult(
                    true,
                    $mapping->zoneId,
                    PurgeResult::ACTION_SKIPPED,
                    sprintf('Skipped: the same purge ran %d second(s) ago.', $age),
                    0,
                    $idShopForLog
                );
            }
        }

        $client = $this->client;
        $message = $action === PurgeResult::ACTION_EVERYTHING
            ? sprintf('Purged everything in zone %s.', $mapping->zoneName)
            : sprintf('Purged %d %s in zone %s.', count($items), $action === PurgeResult::ACTION_HOSTS ? 'hostname(s)' : 'prefix(es)', $mapping->zoneName);

        $result = $this->execute(
            $idShopForLog,
            $mapping->zoneId,
            $action,
            $items,
            $trigger,
            $message,
            static function () use ($client, $action, $mapping, $items): void {
                match ($action) {
                    PurgeResult::ACTION_HOSTS => $client->purgeHosts($mapping->zoneId, $items),
                    PurgeResult::ACTION_PREFIXES => $client->purgePrefixes($mapping->zoneId, $items),
                    default => $client->purgeEverything($mapping->zoneId),
                };
            }
        );

        if ($result->success) {
            Settings::rememberFullPurge($debounceKey);
        }

        return $result;
    }

    /**
     * Runs one API operation and logs it.
     *
     * @param string[] $items
     */
    private function execute(
        ?int $idShop,
        string $zoneId,
        string $action,
        array $items,
        string $trigger,
        string $successMessage,
        callable $operation
    ): PurgeResult {
        try {
            $operation();
            $result = new PurgeResult(true, $zoneId, $action, $successMessage, max(1, count($items)), $idShop);
        } catch (\Throwable $e) {
            $result = new PurgeResult(false, $zoneId, $action, $e->getMessage(), 0, $idShop);
        }

        try {
            $this->log->add($idShop, $zoneId, $action, $trigger, $items, $result->success, $result->message);
        } catch (\Throwable $e) {
            // Logging must never break a purge.
        }

        return $result;
    }

    /**
     * @return string[] trimmed, non-empty, unique
     */
    private static function cleanItems(array $items): array
    {
        $clean = [];
        foreach ($items as $item) {
            $item = trim((string) $item);
            if ($item !== '') {
                $clean[$item] = true;
            }
        }

        return array_map('strval', array_keys($clean));
    }

    private static function urlHost(string $url): ?string
    {
        if (!preg_match('#^https?://#i', $url)) {
            return null;
        }
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? strtolower($host) : null;
    }
}
