<?php
/**
 * Cloudflare integration for PrestaShop 9.
 *
 * Minimal stand-ins for the PrestaShop classes used by the unit tests.
 *
 * @author    PrestaPro
 * @copyright PrestaPro
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit;
}

/** In-memory configuration store. */
class Configuration
{
    /** @var array<string, string> */
    public static array $values = [];

    public static function reset(): void
    {
        self::$values = [];
    }

    public static function getGlobalValue($key)
    {
        return self::$values[$key] ?? false;
    }

    public static function updateGlobalValue($key, $value): bool
    {
        self::$values[$key] = (string) $value;

        return true;
    }

    public static function get($key, $idLang = null, $idShopGroup = null, $idShop = null)
    {
        return self::$values[$key] ?? false;
    }
}
