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

use PrestaShop\Module\PpCloudflare\Log\LogRepository;

/**
 * Request-scoped purge queue, flushed once in a shutdown function.
 *
 * Several hooks firing in one request (product saved N times, both cache-clear hooks, ...)
 * collapse into one API call per shop/zone. A full purge of a shop drops its queued URLs;
 * an "all shops" purge drops everything else. Repeated full purges across flushes/requests
 * are absorbed by the debounce in PurgeService.
 */
final class PurgeQueue
{
    private static bool $allShops = false;
    private static string $allShopsTrigger = '';

    /** @var array<int, string> id_shop => trigger */
    private static array $fullPurges = [];

    /** @var array<int, array<string, true>> id_shop => [url => true] */
    private static array $urls = [];

    /** @var array<int, string> id_shop => trigger of the first URL batch */
    private static array $urlTriggers = [];

    private static bool $registered = false;

    private static ?PurgeService $service = null;

    public static function addFullPurge(int $idShop, string $trigger): void
    {
        if ($idShop <= 0) {
            return;
        }
        if (!isset(self::$fullPurges[$idShop])) {
            self::$fullPurges[$idShop] = $trigger;
        }
        unset(self::$urls[$idShop], self::$urlTriggers[$idShop]);
        self::register();
    }

    public static function addFullPurgeAllShops(string $trigger): void
    {
        if (!self::$allShops) {
            self::$allShops = true;
            self::$allShopsTrigger = $trigger;
        }
        self::$fullPurges = [];
        self::$urls = [];
        self::$urlTriggers = [];
        self::register();
    }

    /**
     * @param string[] $urls
     */
    public static function addUrls(int $idShop, array $urls, string $trigger): void
    {
        if ($idShop <= 0 || self::$allShops || isset(self::$fullPurges[$idShop])) {
            return;
        }
        $added = false;
        foreach ($urls as $url) {
            $url = trim((string) $url);
            if ($url !== '') {
                self::$urls[$idShop][$url] = true;
                $added = true;
            }
        }
        if ($added) {
            self::$urlTriggers[$idShop] ??= $trigger;
            self::register();
        }
    }

    /**
     * Executes everything queued. Called automatically at shutdown; never throws.
     *
     * @return PurgeResult[]
     */
    public static function flush(): array
    {
        $allShops = self::$allShops;
        $allShopsTrigger = self::$allShopsTrigger;
        $fullPurges = self::$fullPurges;
        $urls = self::$urls;
        $urlTriggers = self::$urlTriggers;
        self::reset();

        if (!$allShops && $fullPurges === [] && $urls === []) {
            return [];
        }

        $results = [];
        try {
            $service = self::$service ?? PurgeService::create();

            if ($allShops) {
                return $service->purgeAllShops($allShopsTrigger);
            }
            foreach ($fullPurges as $idShop => $trigger) {
                $results[] = $service->purgeShop((int) $idShop, $trigger);
            }
            foreach ($urls as $idShop => $set) {
                $results[] = $service->purgeUrls(
                    (int) $idShop,
                    array_map('strval', array_keys($set)),
                    $urlTriggers[$idShop] ?? 'queue'
                );
            }
        } catch (\Throwable $e) {
            try {
                (new LogRepository())->add(null, '', 'error', 'queue', [], false, 'Purge queue failed: ' . $e->getMessage());
            } catch (\Throwable $ignored) {
            }
        }

        return $results;
    }

    /**
     * Whether anything is waiting to be flushed.
     */
    public static function isEmpty(): bool
    {
        return !self::$allShops && self::$fullPurges === [] && self::$urls === [];
    }

    /**
     * Snapshot of the pending queue (diagnostics/tests).
     *
     * @return array{all_shops:bool, full:array<int, string>, urls:array<int, string[]>}
     */
    public static function pending(): array
    {
        $urls = [];
        foreach (self::$urls as $idShop => $set) {
            $urls[$idShop] = array_map('strval', array_keys($set));
        }

        return ['all_shops' => self::$allShops, 'full' => self::$fullPurges, 'urls' => $urls];
    }

    /**
     * Replaces the service used by flush() (tests). Null restores the default.
     */
    public static function setService(?PurgeService $service): void
    {
        self::$service = $service;
    }

    /**
     * Drops the queue without purging and allows a new shutdown registration.
     */
    public static function reset(): void
    {
        self::$allShops = false;
        self::$allShopsTrigger = '';
        self::$fullPurges = [];
        self::$urls = [];
        self::$urlTriggers = [];
        self::$registered = false;
    }

    private static function register(): void
    {
        if (self::$registered) {
            return;
        }
        self::$registered = true;
        // Registering from inside another shutdown function (actionClearSf2Cache) is fine:
        // PHP runs shutdown functions added during shutdown after the current ones.
        register_shutdown_function([self::class, 'flush']);
    }
}
