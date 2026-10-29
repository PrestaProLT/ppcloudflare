<?php
/**
 * Cloudflare integration for PrestaShop 9.
 *
 * @author    PrestaPro
 * @copyright PrestaPro
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */
declare(strict_types=1);
namespace PrestaShop\Module\PpCloudflare\Module;

use PrestaShop\Module\PpCloudflare\Log\LogRepository;
use PrestaShop\Module\PpCloudflare\Zone\ShopZoneRepository;

/**
 * Removes every trace of the module: tables and all PPCLOUDFLARE_* configuration rows.
 * Hooks and tabs are removed by Module::uninstall().
 */
class Uninstaller
{
    /**
     * Set by PpCloudflare::hookActionBeforeResetModule(): the back-office "Reset" button runs
     * uninstall() + install() (keepData = false) without calling reset(), so the data is kept
     * for that one uninstall.
     */
    public static bool $keepData = false;

    private \Module $module;

    public function __construct(\Module $module)
    {
        $this->module = $module;
    }

    public function __invoke(): bool
    {
        if (self::$keepData) {
            // Module reset: keep tables and configuration; install() runs next and is idempotent.
            self::$keepData = false;

            return true;
        }

        $db = \Db::getInstance();
        foreach ([ShopZoneRepository::TABLE, LogRepository::TABLE] as $table) {
            if (!$db->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . $table . '`')) {
                $this->fail(sprintf('Could not drop the table %s.', $table));

                return false;
            }
        }

        $ids = $db->executeS(
            "SELECT `id_configuration` FROM `" . _DB_PREFIX_ . "configuration` WHERE `name` LIKE 'PPCLOUDFLARE\\_%'",
            true,
            false
        );
        $ids = array_map(static fn (array $r): int => (int) $r['id_configuration'], is_array($ids) ? $ids : []);
        if ($ids !== []) {
            $in = implode(',', $ids);
            $db->execute('DELETE FROM `' . _DB_PREFIX_ . 'configuration_lang` WHERE `id_configuration` IN (' . $in . ')');
            $db->execute('DELETE FROM `' . _DB_PREFIX_ . 'configuration` WHERE `id_configuration` IN (' . $in . ')');
        }
        \Configuration::loadConfiguration();

        return true;
    }

    private function fail(string $message): void
    {
        \PrestaShopLogger::addLog('ppcloudflare: ' . $message, 3, null, 'Module', (int) $this->module->id, true);
    }
}
