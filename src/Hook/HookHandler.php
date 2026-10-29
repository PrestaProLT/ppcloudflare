<?php
/**
 * Cloudflare integration for PrestaShop 9.
 *
 * @author    PrestaPro
 * @copyright PrestaPro
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */
declare(strict_types=1);

namespace PrestaShop\Module\PpCloudflare\Hook;

use PrestaShop\Module\PpCloudflare\Config\Settings;
use PrestaShop\Module\PpCloudflare\Log\LogRepository;
use PrestaShop\Module\PpCloudflare\Purge\EntityUrlCollector;
use PrestaShop\Module\PpCloudflare\Purge\PurgeQueue;
use PrestaShop\Module\PpCloudflare\Zone\ShopZoneRepository;

/**
 * Hook entry points. Plain PHP only (actionClearSf2Cache runs after var/cache was deleted):
 * no container, no translator, and nothing is ever thrown back to PrestaShop.
 */
class HookHandler
{
    /** Path of the Symfony route `admin_clear_cache` (BO Performance → Clear cache). */
    public const CLEAR_CACHE_PATH = '/configure/advanced/performance/clear-cache';

    private ?EntityUrlCollector $collector;
    private ?ShopZoneRepository $zones;

    public function __construct(?EntityUrlCollector $collector = null, ?ShopZoneRepository $zones = null)
    {
        $this->collector = $collector;
        $this->zones = $zones;
    }

    public function onCacheClear(string $hookName): void
    {
        $this->safely('hook:' . $hookName, function () use ($hookName): void {
            $mode = Settings::getCacheClearMode();
            if ($mode === Settings::MODE_OFF || !$this->isActive()) {
                return;
            }
            if ($mode === Settings::MODE_MANUAL && !self::isManualClearRequest()) {
                return;
            }
            PurgeQueue::addFullPurgeAllShops($mode === Settings::MODE_MANUAL ? 'clear_cache_button' : 'hook:' . $hookName);
        });
    }

    /**
     * @param string $entity product|category|cms|manufacturer|supplier
     */
    public function onEntityChange(string $entity, int $id): void
    {
        $this->safely($entity, function () use ($entity, $id): void {
            if ($id <= 0 || !Settings::isEntityPurgeEnabled($entity) || !$this->isActive()) {
                return;
            }
            foreach ($this->collector()->forEntity($entity, $id) as $idShop => $urls) {
                PurgeQueue::addUrls((int) $idShop, $urls, $entity);
            }
        });
    }

    /**
     * Before an entity is saved: queue its current (pre-save) URLs, so a changed link_rewrite
     * also purges the old address. The cached entry is dropped before and after, so neither
     * an earlier collect nor this one is reused by the after-save call.
     *
     * @param string $entity product|category|cms|manufacturer|supplier
     */
    public function onEntityBeforeUpdate(string $entity, int $id): void
    {
        $this->safely($entity, function () use ($entity, $id): void {
            if ($id <= 0 || !Settings::isEntityPurgeEnabled($entity) || !$this->isActive()) {
                return;
            }
            EntityUrlCollector::forget($entity, $id);
            try {
                foreach ($this->collector()->forEntity($entity, $id) as $idShop => $urls) {
                    PurgeQueue::addUrls((int) $idShop, $urls, $entity);
                }
            } finally {
                EntityUrlCollector::forget($entity, $id);
            }
        });
    }

    public function onStockChange(int $idProduct): void
    {
        $this->safely('stock', function () use ($idProduct): void {
            if ($idProduct <= 0 || !Settings::isStockPurgeEnabled() || !$this->isActive()) {
                return;
            }
            foreach ($this->collector()->forProduct($idProduct) as $idShop => $urls) {
                PurgeQueue::addUrls((int) $idShop, $urls, 'stock');
            }
        });
    }

    public function onSpecificPriceChange(\SpecificPrice $specificPrice): void
    {
        $idProduct = (int) $specificPrice->id_product;
        $this->safely('specific_price', function () use ($idProduct): void {
            if ($idProduct <= 0 || !Settings::isEntityPurgeEnabled('product') || !$this->isActive()) {
                return;
            }
            foreach ($this->collector()->forProduct($idProduct) as $idShop => $urls) {
                PurgeQueue::addUrls((int) $idShop, $urls, 'specific_price');
            }
        });
    }

    /**
     * True when the current request is the back-office "Clear cache" action
     * (Symfony route `admin_clear_cache`, or its legacy AdminPerformance link).
     * Detected from the raw request only, never through the container.
     */
    public static function isManualClearRequest(): bool
    {
        if (PHP_SAPI === 'cli') {
            return false;
        }
        if (($_GET['_route'] ?? null) === 'admin_clear_cache') {
            return true;
        }
        $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        if ($path !== '' && str_contains(rtrim($path, '/'), self::CLEAR_CACHE_PATH)) {
            return true;
        }

        return is_string($_GET['controller'] ?? null)
            && strcasecmp($_GET['controller'], 'AdminPerformance') === 0
            && isset($_GET['empty_smarty_cache']);
    }

    /**
     * Enabled, a token is stored and at least one shop is linked to a zone.
     */
    private function isActive(): bool
    {
        return Settings::isEnabled()
            && Settings::get(Settings::API_TOKEN) !== ''
            && ($this->zones ??= new ShopZoneRepository())->findAll() !== [];
    }

    private function collector(): EntityUrlCollector
    {
        return $this->collector ??= new EntityUrlCollector();
    }

    private function safely(string $trigger, callable $callback): void
    {
        try {
            $callback();
        } catch (\Throwable $e) {
            try {
                (new LogRepository())->add(null, '', 'error', $trigger, [], false, 'Hook failed: ' . $e->getMessage());
            } catch (\Throwable $ignored) {
                // nothing else we can do without risking the request
            }
        }
    }
}
