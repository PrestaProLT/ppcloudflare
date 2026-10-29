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

use PrestaShop\Module\PpCloudflare\Config\Settings;
use PrestaShop\Module\PpCloudflare\Log\LogRepository;
use PrestaShop\Module\PpCloudflare\Zone\ShopZoneRepository;

/**
 * Idempotent installer (also used by reset()): never drops data, only fills what is missing.
 */
class Installer
{
    private \Module $module;

    public function __construct(\Module $module)
    {
        $this->module = $module;
    }

    public function __invoke(): bool
    {
        if (empty($this->module->id)) {
            $this->module->id = (int) \Module::getModuleIdByName($this->module->name);
        }

        return $this->tables() && $this->configuration() && $this->hooks();
    }

    /**
     * @return string[]
     */
    public static function tableQueries(): array
    {
        $suffix = ' ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4';

        return [
            'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . ShopZoneRepository::TABLE . '` (
                `id_shop` INT UNSIGNED NOT NULL,
                `zone_id` VARCHAR(32) NOT NULL,
                `zone_name` VARCHAR(255) NOT NULL,
                `purge_scope` VARCHAR(16) NOT NULL DEFAULT \'zone\',
                `date_upd` DATETIME NOT NULL,
                PRIMARY KEY (`id_shop`)
            )' . $suffix,
            'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . LogRepository::TABLE . '` (
                `id_log` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `id_shop` INT UNSIGNED NULL,
                `zone_id` VARCHAR(32) NOT NULL DEFAULT \'\',
                `action` VARCHAR(32) NOT NULL,
                `trigger_source` VARCHAR(64) NOT NULL,
                `items` TEXT NULL,
                `success` TINYINT(1) NOT NULL,
                `message` TEXT NULL,
                `id_employee` INT UNSIGNED NULL,
                `date_add` DATETIME NOT NULL,
                PRIMARY KEY (`id_log`),
                KEY `date_add` (`date_add`)
            )' . $suffix,
        ];
    }

    private function tables(): bool
    {
        foreach (self::tableQueries() as $sql) {
            if (!\Db::getInstance()->execute($sql)) {
                $this->fail('Could not create the module database tables.');

                return false;
            }
        }

        return true;
    }

    private function configuration(): bool
    {
        foreach (Settings::defaults() + Settings::internalDefaults() as $key => $default) {
            if (\Configuration::getGlobalValue($key) !== false) {
                continue;
            }
            if (!Settings::set($key, (string) $default)) {
                $this->fail(sprintf('Could not save the configuration value %s.', $key));

                return false;
            }
        }

        return true;
    }

    private function hooks(): bool
    {
        if (empty($this->module->id)) {
            $this->fail('Could not resolve the module ID to register hooks.');

            return false;
        }
        if (!$this->module->registerHook(\PpCloudflare::HOOKS)) {
            $this->fail('Could not register the module hooks.');

            return false;
        }

        return true;
    }

    private function fail(string $message): void
    {
        \PrestaShopLogger::addLog('ppcloudflare: ' . $message, 3, null, 'Module', (int) $this->module->id, true);
    }
}
