<?php
/**
 * Cloudflare integration for PrestaShop 9.
 *
 * @author    PrestaPro
 * @copyright PrestaPro
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */
declare(strict_types=1);

namespace PrestaShop\Module\PpCloudflare\Config;

/**
 * Typed access to the module configuration. Every key is stored global (id_shop NULL).
 *
 * Plain PHP only: reachable from hooks that run after the Symfony cache was deleted.
 */
class Settings
{
    public const ENABLED = 'PPCLOUDFLARE_ENABLED';
    public const API_TOKEN = 'PPCLOUDFLARE_API_TOKEN';
    public const ZONES_CACHE = 'PPCLOUDFLARE_ZONES_CACHE';
    public const ZONES_FETCHED_AT = 'PPCLOUDFLARE_ZONES_FETCHED_AT';
    public const CACHE_CLEAR_MODE = 'PPCLOUDFLARE_CACHE_CLEAR_MODE';
    public const PURGE_PRODUCT = 'PPCLOUDFLARE_PURGE_PRODUCT';
    public const PURGE_CATEGORY = 'PPCLOUDFLARE_PURGE_CATEGORY';
    public const PURGE_CMS = 'PPCLOUDFLARE_PURGE_CMS';
    public const PURGE_MANUFACTURER = 'PPCLOUDFLARE_PURGE_MANUFACTURER';
    public const PURGE_SUPPLIER = 'PPCLOUDFLARE_PURGE_SUPPLIER';
    public const PURGE_STOCK = 'PPCLOUDFLARE_PURGE_STOCK';
    public const PURGE_HOMEPAGE = 'PPCLOUDFLARE_PURGE_HOMEPAGE';
    public const DEBOUNCE_SECONDS = 'PPCLOUDFLARE_DEBOUNCE_SECONDS';
    public const LOG_RETENTION_DAYS = 'PPCLOUDFLARE_LOG_RETENTION_DAYS';
    public const LAST_FULL_PURGE = 'PPCLOUDFLARE_LAST_FULL_PURGE';
    /** Internal: date (Y-m-d) of the last log retention cleanup. */
    public const LOG_PRUNED_AT = 'PPCLOUDFLARE_LOG_PRUNED_AT';

    public const MODE_OFF = 'off';
    public const MODE_MANUAL = 'manual';
    public const MODE_ALL = 'all';
    public const MODES = [self::MODE_OFF, self::MODE_MANUAL, self::MODE_ALL];

    /** Entity name => purge trigger key. */
    public const ENTITY_KEYS = [
        'product' => self::PURGE_PRODUCT,
        'category' => self::PURGE_CATEGORY,
        'cms' => self::PURGE_CMS,
        'manufacturer' => self::PURGE_MANUFACTURER,
        'supplier' => self::PURGE_SUPPLIER,
    ];

    /** Debounce entries older than this are dropped when the memory is written. */
    private const DEBOUNCE_MEMORY_TTL = 86400;

    /**
     * User-editable keys and their defaults (token, zone cache and debounce memory excluded).
     *
     * @return array<string, string|int>
     */
    public static function defaults(): array
    {
        return [
            self::ENABLED => 1,
            self::CACHE_CLEAR_MODE => self::MODE_ALL,
            self::PURGE_PRODUCT => 1,
            self::PURGE_CATEGORY => 1,
            self::PURGE_CMS => 1,
            self::PURGE_MANUFACTURER => 1,
            self::PURGE_SUPPLIER => 0,
            self::PURGE_STOCK => 0,
            self::PURGE_HOMEPAGE => 1,
            self::DEBOUNCE_SECONDS => 30,
            self::LOG_RETENTION_DAYS => 30,
        ];
    }

    /**
     * Internal keys created on install (not restored by reset when already present).
     *
     * @return array<string, string>
     */
    public static function internalDefaults(): array
    {
        return [
            self::API_TOKEN => '',
            self::ZONES_CACHE => '[]',
            self::ZONES_FETCHED_AT => '',
            self::LAST_FULL_PURGE => '{}',
            self::LOG_PRUNED_AT => '',
        ];
    }

    /**
     * Every key owned by the module.
     *
     * @return string[]
     */
    public static function allKeys(): array
    {
        return array_merge(array_keys(self::defaults()), array_keys(self::internalDefaults()));
    }

    // ---------------------------------------------------------------- generic

    public static function get(string $key): string
    {
        $value = \Configuration::getGlobalValue($key);
        if ($value === false || $value === null) {
            $defaults = self::defaults() + self::internalDefaults();

            return isset($defaults[$key]) ? (string) $defaults[$key] : '';
        }

        return (string) $value;
    }

    public static function set(string $key, string $value): bool
    {
        return (bool) \Configuration::updateGlobalValue($key, $value);
    }

    public static function getBool(string $key): bool
    {
        return (bool) (int) self::get($key);
    }

    public static function setBool(string $key, bool $value): bool
    {
        return self::set($key, $value ? '1' : '0');
    }

    public static function getInt(string $key): int
    {
        return (int) self::get($key);
    }

    public static function setInt(string $key, int $value): bool
    {
        return self::set($key, (string) $value);
    }

    // ---------------------------------------------------------------- typed

    public static function isEnabled(): bool
    {
        return self::getBool(self::ENABLED);
    }

    public static function setEnabled(bool $enabled): bool
    {
        return self::setBool(self::ENABLED, $enabled);
    }

    public static function getCacheClearMode(): string
    {
        $mode = self::get(self::CACHE_CLEAR_MODE);

        return in_array($mode, self::MODES, true) ? $mode : self::MODE_ALL;
    }

    public static function setCacheClearMode(string $mode): bool
    {
        if (!in_array($mode, self::MODES, true)) {
            $mode = self::MODE_ALL;
        }

        return self::set(self::CACHE_CLEAR_MODE, $mode);
    }

    /**
     * Whether URL purges are enabled for an entity (product|category|cms|manufacturer|supplier).
     */
    public static function isEntityPurgeEnabled(string $entity): bool
    {
        return isset(self::ENTITY_KEYS[$entity]) && self::getBool(self::ENTITY_KEYS[$entity]);
    }

    public static function isStockPurgeEnabled(): bool
    {
        return self::getBool(self::PURGE_STOCK);
    }

    public static function isHomepagePurgeEnabled(): bool
    {
        return self::getBool(self::PURGE_HOMEPAGE);
    }

    public static function getDebounceSeconds(): int
    {
        return max(0, self::getInt(self::DEBOUNCE_SECONDS));
    }

    public static function setDebounceSeconds(int $seconds): bool
    {
        return self::setInt(self::DEBOUNCE_SECONDS, max(0, min(3600, $seconds)));
    }

    public static function getLogRetentionDays(): int
    {
        return max(1, self::getInt(self::LOG_RETENTION_DAYS));
    }

    public static function setLogRetentionDays(int $days): bool
    {
        return self::setInt(self::LOG_RETENTION_DAYS, max(1, min(3650, $days)));
    }

    // ---------------------------------------------------------------- token

    /**
     * Decrypted API token, '' when none is stored or it cannot be decrypted
     * (for example after the shop cookie key changed).
     */
    public static function getToken(): string
    {
        $stored = self::get(self::API_TOKEN);
        if ($stored === '') {
            return '';
        }
        try {
            $plain = (new \PhpEncryption(_NEW_COOKIE_KEY_))->decrypt($stored);
        } catch (\Throwable $e) {
            return '';
        }

        return is_string($plain) ? trim($plain) : '';
    }

    /**
     * Stores the token encrypted. An empty string removes it.
     */
    public static function setToken(string $token): bool
    {
        $token = trim($token);
        if ($token === '') {
            return self::set(self::API_TOKEN, '');
        }

        return self::set(self::API_TOKEN, (string) (new \PhpEncryption(_NEW_COOKIE_KEY_))->encrypt($token));
    }

    public static function hasToken(): bool
    {
        return self::getToken() !== '';
    }

    /**
     * Last 4 characters of the token for display ("…abcd"), '' when none.
     */
    public static function getTokenHint(): string
    {
        $token = self::getToken();

        return $token === '' ? '' : substr($token, -4);
    }

    // ---------------------------------------------------------------- zones cache

    /**
     * @return array<int, array{id:string, name:string, status:string, plan:string, account_id:string, account_name:string, paused:bool}>
     */
    public static function getCachedZones(): array
    {
        $zones = json_decode(self::get(self::ZONES_CACHE), true);

        return is_array($zones) ? array_values(array_filter($zones, 'is_array')) : [];
    }

    /**
     * Stores the zone list and sets ZONES_FETCHED_AT to now.
     */
    public static function setCachedZones(array $zones): bool
    {
        return self::set(self::ZONES_CACHE, (string) json_encode(array_values($zones)))
            && self::set(self::ZONES_FETCHED_AT, date('Y-m-d H:i:s'));
    }

    public static function getZonesFetchedAt(): string
    {
        return self::get(self::ZONES_FETCHED_AT);
    }

    /**
     * Cached zone by id, null when unknown.
     */
    public static function findCachedZone(string $zoneId): ?array
    {
        foreach (self::getCachedZones() as $zone) {
            if (($zone['id'] ?? '') === $zoneId) {
                return $zone;
            }
        }

        return null;
    }

    // ---------------------------------------------------------------- debounce memory

    /**
     * @return array<string, int>
     */
    public static function getLastFullPurges(): array
    {
        $data = json_decode(self::get(self::LAST_FULL_PURGE), true);

        return is_array($data) ? array_map('intval', $data) : [];
    }

    public static function getLastFullPurge(string $key): int
    {
        return self::getLastFullPurges()[$key] ?? 0;
    }

    public static function rememberFullPurge(string $key, ?int $timestamp = null): bool
    {
        $now = $timestamp ?? time();
        $data = array_filter(
            self::getLastFullPurges(),
            static fn (int $ts): bool => $ts > $now - self::DEBOUNCE_MEMORY_TTL
        );
        $data[$key] = $now;

        return self::set(self::LAST_FULL_PURGE, (string) json_encode($data));
    }
}
