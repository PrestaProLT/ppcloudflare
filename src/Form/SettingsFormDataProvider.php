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

use PrestaShop\PrestaShop\Core\Form\FormDataProviderInterface;

/**
 * Bridges the core form Handler and SettingsDataConfiguration.
 * Type-hinted on the concrete class so the resource scan can autowire it.
 */
class SettingsFormDataProvider implements FormDataProviderInterface
{
    public function __construct(private readonly SettingsDataConfiguration $dataConfiguration)
    {
    }

    public function getData(): array
    {
        return $this->dataConfiguration->getConfiguration();
    }

    public function setData(array $data): array
    {
        return $this->dataConfiguration->updateConfiguration($data);
    }
}
