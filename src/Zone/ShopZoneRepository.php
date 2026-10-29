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
 * CRUD for the `ppcloudflare_shop_zone` table.
 */
class ShopZoneRepository
{
    public const TABLE = 'ppcloudflare_shop_zone';

    /** @var array<int, ShopZoneMapping>|null request cache */
    private static ?array $cache = null;

    public function find(int $idShop): ?ShopZoneMapping
    {
        return $this->findAll()[$idShop] ?? null;
    }

    /**
     * @return array<int, ShopZoneMapping> keyed by id_shop
     */
    public function findAll(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        $rows = \Db::getInstance()->executeS(
            'SELECT `id_shop`, `zone_id`, `zone_name`, `purge_scope` FROM `' . _DB_PREFIX_ . self::TABLE . '` ORDER BY `id_shop`',
            true,
            false
        );
        $mappings = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $mapping = new ShopZoneMapping(
                (int) $row['id_shop'],
                (string) $row['zone_id'],
                (string) $row['zone_name'],
                (string) $row['purge_scope']
            );
            if ($mapping->zoneId !== '') {
                $mappings[$mapping->idShop] = $mapping;
            }
        }

        return self::$cache = $mappings;
    }

    public function save(ShopZoneMapping $mapping): bool
    {
        self::$cache = null;

        return (bool) \Db::getInstance()->execute(
            'INSERT INTO `' . _DB_PREFIX_ . self::TABLE . '` (`id_shop`, `zone_id`, `zone_name`, `purge_scope`, `date_upd`)
            VALUES (' . (int) $mapping->idShop . ", '" . pSQL($mapping->zoneId) . "', '" . pSQL($mapping->zoneName) . "', '"
            . pSQL($mapping->purgeScope) . "', '" . pSQL(date('Y-m-d H:i:s')) . "')
            ON DUPLICATE KEY UPDATE `zone_id` = VALUES(`zone_id`), `zone_name` = VALUES(`zone_name`),
                `purge_scope` = VALUES(`purge_scope`), `date_upd` = VALUES(`date_upd`)"
        );
    }

    public function delete(int $idShop): bool
    {
        self::$cache = null;

        return (bool) \Db::getInstance()->delete(self::TABLE, '`id_shop` = ' . (int) $idShop);
    }

    /**
     * Forgets the request cache (tests, long CLI runs).
     */
    public static function resetCache(): void
    {
        self::$cache = null;
    }
}
