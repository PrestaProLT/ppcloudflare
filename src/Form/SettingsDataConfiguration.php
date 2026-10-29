<?php
/**
 * Cloudflare integration for PrestaShop 9.
 *
 * @author    PrestaPro
 * @copyright PrestaPro
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */
declare(strict_types=1);

namespace PrestaShop\Module\PpCloudflare\Form;

use PrestaShop\Module\PpCloudflare\Config\Settings;
use PrestaShop\PrestaShop\Core\Configuration\DataConfigurationInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Maps the settings form to Config\Settings (all values are global).
 */
final class SettingsDataConfiguration implements DataConfigurationInterface
{
    /** Form field => Settings key for the boolean switches. */
    private const SWITCHES = [
        'enabled' => Settings::ENABLED,
        'purge_product' => Settings::PURGE_PRODUCT,
        'purge_category' => Settings::PURGE_CATEGORY,
        'purge_cms' => Settings::PURGE_CMS,
        'purge_manufacturer' => Settings::PURGE_MANUFACTURER,
        'purge_supplier' => Settings::PURGE_SUPPLIER,
        'purge_stock' => Settings::PURGE_STOCK,
        'purge_homepage' => Settings::PURGE_HOMEPAGE,
    ];

    public function __construct(private readonly TranslatorInterface $translator)
    {
    }

    public function getConfiguration(): array
    {
        $data = [
            'api_token' => '',
            'remove_token' => false,
            'cache_clear_mode' => Settings::getCacheClearMode(),
            'debounce_seconds' => Settings::getDebounceSeconds(),
            'log_retention_days' => Settings::getLogRetentionDays(),
        ];
        foreach (self::SWITCHES as $field => $key) {
            $data[$field] = Settings::getBool($key);
        }

        return $data;
    }

    public function updateConfiguration(array $configuration): array
    {
        if (!$this->validateConfiguration($configuration)) {
            return [$this->translator->trans('The submitted settings are invalid.', [], 'Modules.Ppcloudflare.Admin')];
        }

        $token = trim((string) ($configuration['api_token'] ?? ''));
        if (!empty($configuration['remove_token'])) {
            Settings::setToken('');
        } elseif ($token !== '') {
            if (preg_match('/^\S{20,}$/', $token) !== 1) {
                return [$this->translator->trans('The API token format looks wrong. Copy the token value shown once by Cloudflare (not the Global API Key).', [], 'Modules.Ppcloudflare.Admin')];
            }
            Settings::setToken($token);
        }

        foreach (self::SWITCHES as $field => $key) {
            Settings::setBool($key, !empty($configuration[$field]));
        }
        Settings::setCacheClearMode((string) $configuration['cache_clear_mode']);
        Settings::setDebounceSeconds((int) $configuration['debounce_seconds']);
        Settings::setLogRetentionDays((int) $configuration['log_retention_days']);

        return [];
    }

    public function validateConfiguration(array $configuration): bool
    {
        return isset($configuration['cache_clear_mode'], $configuration['debounce_seconds'], $configuration['log_retention_days'])
            && in_array($configuration['cache_clear_mode'], Settings::MODES, true);
    }
}
