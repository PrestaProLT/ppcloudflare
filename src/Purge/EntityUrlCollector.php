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

use PrestaShop\Module\PpCloudflare\Config\Settings;
use PrestaShop\Module\PpCloudflare\Zone\ShopDomainResolver;
use PrestaShop\Module\PpCloudflare\Zone\ShopZoneRepository;

/**
 * Builds the front-office URLs to purge for a catalog entity, per mapped shop the entity
 * belongs to and per active language of that shop.
 */
class EntityUrlCollector
{
    /** entity => association table / primary key */
    private const SHOP_TABLES = [
        'product' => ['product_shop', 'id_product'],
        'category' => ['category_shop', 'id_category'],
        'cms' => ['cms_shop', 'id_cms'],
        'manufacturer' => ['manufacturer_shop', 'id_manufacturer'],
        'supplier' => ['supplier_shop', 'id_supplier'],
    ];

    private ShopZoneRepository $zones;
    private ShopDomainResolver $domains;
    private ?\Link $link = null;

    /** @var array<string, array<int, string[]>> request cache */
    private static array $cache = [];

    public function __construct(?ShopZoneRepository $zones = null, ?ShopDomainResolver $domains = null)
    {
        $this->zones = $zones ?? new ShopZoneRepository();
        $this->domains = $domains ?? new ShopDomainResolver();
    }

    /**
     * @return array<int, string[]> id_shop => urls
     */
    public function forEntity(string $entity, int $id): array
    {
        return match ($entity) {
            'product' => $this->forProduct($id),
            'category' => $this->forCategory($id),
            'cms' => $this->forCms($id),
            'manufacturer' => $this->forManufacturer($id),
            'supplier' => $this->forSupplier($id),
            default => [],
        };
    }

    /**
     * Product page in every language, plus its default category page (per shop).
     *
     * @return array<int, string[]>
     */
    public function forProduct(int $id): array
    {
        return $this->collect('product', $id, function (int $idShop, int $idLang) use ($id): array {
            $link = $this->link();
            $urls = [$link->getProductLink($id, null, null, null, $idLang, $idShop)];

            $idCategory = (int) \Db::getInstance()->getValue(
                'SELECT `id_category_default` FROM `' . _DB_PREFIX_ . 'product_shop`
                WHERE `id_product` = ' . (int) $id . ' AND `id_shop` = ' . (int) $idShop,
                false
            );
            if ($idCategory > 0 && !$this->isRootCategory($idCategory, $idShop)) {
                $urls[] = $link->getCategoryLink($idCategory, null, $idLang, null, $idShop);
            }

            return $urls;
        });
    }

    /**
     * @return array<int, string[]>
     */
    public function forCategory(int $id): array
    {
        return $this->collect('category', $id, function (int $idShop, int $idLang) use ($id): array {
            if ($this->isRootCategory($id, $idShop)) {
                return [];
            }

            return [$this->link()->getCategoryLink($id, null, $idLang, null, $idShop)];
        });
    }

    /**
     * @return array<int, string[]>
     */
    public function forCms(int $id): array
    {
        return $this->collect('cms', $id, function (int $idShop, int $idLang) use ($id): array {
            $cms = new \CMS($id, $idLang, $idShop);
            if (!\Validate::isLoadedObject($cms)) {
                return [];
            }

            return [$this->link()->getCMSLink($cms, null, null, $idLang, $idShop)];
        });
    }

    /**
     * @return array<int, string[]>
     */
    public function forManufacturer(int $id): array
    {
        return $this->collect('manufacturer', $id, function (int $idShop, int $idLang) use ($id): array {
            $manufacturer = new \Manufacturer($id, $idLang);
            if (!\Validate::isLoadedObject($manufacturer)) {
                return [];
            }

            return [$this->link()->getManufacturerLink($manufacturer, null, $idLang, $idShop)];
        });
    }

    /**
     * @return array<int, string[]>
     */
    public function forSupplier(int $id): array
    {
        return $this->collect('supplier', $id, function (int $idShop, int $idLang) use ($id): array {
            $supplier = new \Supplier($id, $idLang);
            if (!\Validate::isLoadedObject($supplier)) {
                return [];
            }

            return [$this->link()->getSupplierLink($supplier, null, $idLang, $idShop)];
        });
    }

    /**
     * Homepage URLs of a shop: base URL(s) plus the index page of every active language.
     *
     * @return string[]
     */
    public function homepageUrls(int $idShop): array
    {
        $urls = [];
        $base = $this->domains->getBaseUrls($idShop);
        if ($base !== []) {
            $urls[] = $base[0];
        }
        foreach ($this->languageIds($idShop) as $idLang) {
            try {
                $urls[] = $this->link()->getPageLink('index', null, $idLang, null, false, $idShop);
            } catch (\Throwable $e) {
                // ignore a single broken language
            }
        }

        return $this->normalize($idShop, $urls);
    }

    public static function resetCache(): void
    {
        self::$cache = [];
    }

    /**
     * Drops the cached URLs of one entity, so the next call rebuilds them from the database.
     */
    public static function forget(string $entity, int $id): void
    {
        unset(self::$cache[$entity . ':' . $id]);
    }

    /**
     * @param callable(int $idShop, int $idLang): string[] $builder
     *
     * @return array<int, string[]>
     */
    private function collect(string $entity, int $id, callable $builder): array
    {
        $cacheKey = $entity . ':' . $id;
        if (isset(self::$cache[$cacheKey])) {
            return self::$cache[$cacheKey];
        }

        $result = [];
        if ($id > 0) {
            $withHomepage = Settings::isHomepagePurgeEnabled();
            foreach ($this->mappedShopsOf($entity, $id) as $idShop) {
                $urls = [];
                foreach ($this->languageIds($idShop) as $idLang) {
                    try {
                        foreach ($builder($idShop, $idLang) as $url) {
                            $urls[] = (string) $url;
                        }
                    } catch (\Throwable $e) {
                        // a missing translation or broken route must not stop the other languages
                    }
                }
                if ($urls === []) {
                    continue;
                }
                $urls = $this->normalize($idShop, $urls);
                if ($withHomepage) {
                    $urls = array_values(array_unique(array_merge($urls, $this->homepageUrls($idShop))));
                }
                $result[$idShop] = $urls;
            }
        }

        return self::$cache[$cacheKey] = $result;
    }

    /**
     * Shops the entity is associated to AND that are linked to a zone.
     *
     * @return int[]
     */
    private function mappedShopsOf(string $entity, int $id): array
    {
        $mapped = array_keys($this->zones->findAll());
        if ($mapped === [] || !isset(self::SHOP_TABLES[$entity])) {
            return [];
        }
        [$table, $key] = self::SHOP_TABLES[$entity];
        $rows = \Db::getInstance()->executeS(
            'SELECT `id_shop` FROM `' . _DB_PREFIX_ . $table . '`
            WHERE `' . $key . '` = ' . (int) $id . '
            AND `id_shop` IN (' . implode(',', array_map('intval', $mapped)) . ')',
            true,
            false
        );

        return array_map(static fn (array $r): int => (int) $r['id_shop'], is_array($rows) ? $rows : []);
    }

    /**
     * @return int[]
     */
    private function languageIds(int $idShop): array
    {
        return array_map(
            static fn (array $l): int => (int) $l['id_lang'],
            \Language::getLanguages(true, $idShop)
        );
    }

    private function isRootCategory(int $idCategory, int $idShop): bool
    {
        if ($idCategory === (int) \Configuration::get('PS_ROOT_CATEGORY')) {
            return true;
        }
        $shop = $this->domains->getShop($idShop);
        $idGroup = $shop !== null ? (int) $shop['id_shop_group'] : null;

        return $idCategory === (int) \Configuration::get('PS_HOME_CATEGORY', null, $idGroup, $idShop)
            || (bool) \Db::getInstance()->getValue(
                'SELECT `is_root_category` FROM `' . _DB_PREFIX_ . 'category` WHERE `id_category` = ' . (int) $idCategory,
                false
            );
    }

    /**
     * Forces the shop's scheme (https when PS_SSL_ENABLED for that shop), drops anchors and duplicates.
     *
     * @param string[] $urls
     *
     * @return string[]
     */
    private function normalize(int $idShop, array $urls): array
    {
        $scheme = $this->domains->getScheme($idShop);
        $clean = [];
        foreach ($urls as $url) {
            $url = trim($url);
            if ($url === '') {
                continue;
            }
            $url = (string) preg_replace('/#.*$/', '', $url);
            $url = (string) preg_replace('#^(https?:)?//#i', $scheme . '://', $url);
            if (preg_match('#^https?://#', $url)) {
                $clean[$url] = true;
            }
        }

        return array_map('strval', array_keys($clean));
    }

    private function link(): \Link
    {
        return $this->link ??= new \Link();
    }
}
