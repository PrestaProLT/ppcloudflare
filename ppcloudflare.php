<?php
/**
 * Cloudflare integration for PrestaShop 9.
 *
 * @author    PrestaPro
 * @copyright PrestaPro
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */
declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

use PrestaShop\Module\PpCloudflare\Hook\HookHandler;
use PrestaShop\Module\PpCloudflare\Module\Installer;
use PrestaShop\Module\PpCloudflare\Module\Uninstaller;

class PpCloudflare extends Module
{
    public const HOOKS = [
        'actionClearSf2Cache',
        'actionClearCompileCache',
        'actionObjectProductUpdateBefore',
        'actionObjectProductUpdateAfter',
        'actionObjectProductDeleteBefore',
        'actionObjectCategoryUpdateBefore',
        'actionObjectCategoryUpdateAfter',
        'actionObjectCategoryDeleteBefore',
        'actionObjectCmsUpdateBefore',
        'actionObjectCmsUpdateAfter',
        'actionObjectCmsDeleteBefore',
        'actionObjectManufacturerUpdateBefore',
        'actionObjectManufacturerUpdateAfter',
        'actionObjectManufacturerDeleteBefore',
        'actionObjectSupplierUpdateBefore',
        'actionObjectSupplierUpdateAfter',
        'actionObjectSupplierDeleteBefore',
        'actionObjectSpecificPriceAddAfter',
        'actionObjectSpecificPriceUpdateAfter',
        'actionObjectSpecificPriceDeleteAfter',
        'actionUpdateQuantity',
        'actionBeforeResetModule',
    ];

    private ?HookHandler $hookHandler = null;

    public function __construct()
    {
        $this->name = 'ppcloudflare';
        $this->tab = 'administration';
        $this->version = '1.0.0';
        $this->author = 'PrestaPro';
        $this->need_instance = 0;
        $this->bootstrap = true;
        $this->ps_versions_compliancy = ['min' => '9.0.0', 'max' => _PS_VERSION_];

        parent::__construct();

        $this->displayName = $this->trans('Cloudflare Cache & Zone Manager', [], 'Modules.Ppcloudflare.Admin');
        $this->description = $this->trans(
            'Purges the Cloudflare cache when PrestaShop clears its cache or catalog content changes, maps every shop to its Cloudflare zone and adds Development Mode and Under Attack tools.',
            [],
            'Modules.Ppcloudflare.Admin'
        );
        $this->confirmUninstall = $this->trans(
            'Are you sure? Uninstalling permanently deletes the Cloudflare API token, the shop to zone mapping, all settings and the purge log. This cannot be undone.',
            [],
            'Modules.Ppcloudflare.Admin'
        );

        $this->tabs = [
            [
                'route_name' => 'ps_ppcloudflare_configure',
                'class_name' => 'AdminPpCloudflareSettings',
                'visible' => false,
                'name' => 'Cloudflare',
                'icon' => 'cloud',
            ],
            [
                'route_name' => 'ps_ppcloudflare_zones',
                'class_name' => 'AdminPpCloudflareZones',
                'visible' => false,
                'name' => 'Cloudflare zones',
            ],
            [
                'route_name' => 'ps_ppcloudflare_tools',
                'class_name' => 'AdminPpCloudflareTools',
                'visible' => false,
                'name' => 'Cloudflare tools',
            ],
            [
                'route_name' => 'ps_ppcloudflare_logs',
                'class_name' => 'AdminPpCloudflareLogs',
                'visible' => false,
                'name' => 'Cloudflare log',
            ],
            [
                'route_name' => 'ps_ppcloudflare_guide',
                'class_name' => 'AdminPpCloudflareGuide',
                'visible' => false,
                'name' => 'Cloudflare setup guide',
            ],
        ];
    }

    public function isUsingNewTranslationSystem(): bool
    {
        return true;
    }

    public function install(): bool
    {
        return parent::install() && (new Installer($this))();
    }

    public function uninstall(): bool
    {
        return (new Uninstaller($this))() && parent::uninstall();
    }

    /**
     * Restores hooks and missing defaults without touching the mapping, token or log.
     */
    public function reset(): bool
    {
        return (new Installer($this))();
    }

    public function getContent(): void
    {
        Tools::redirectAdmin($this->get('router')->generate('ps_ppcloudflare_configure'));
    }

    private function handler(): HookHandler
    {
        return $this->hookHandler ??= new HookHandler();
    }

    public function hookActionClearSf2Cache(array $params): void
    {
        $this->handler()->onCacheClear('actionClearSf2Cache');
    }

    public function hookActionClearCompileCache(array $params): void
    {
        $this->handler()->onCacheClear('actionClearCompileCache');
    }

    public function hookActionObjectProductUpdateBefore(array $params): void
    {
        $this->onObjectBeforeUpdate('product', $params);
    }

    public function hookActionObjectProductUpdateAfter(array $params): void
    {
        $this->onObject('product', $params);
    }

    public function hookActionObjectProductDeleteBefore(array $params): void
    {
        $this->onObject('product', $params);
    }

    public function hookActionObjectCategoryUpdateBefore(array $params): void
    {
        $this->onObjectBeforeUpdate('category', $params);
    }

    public function hookActionObjectCategoryUpdateAfter(array $params): void
    {
        $this->onObject('category', $params);
    }

    public function hookActionObjectCategoryDeleteBefore(array $params): void
    {
        $this->onObject('category', $params);
    }

    public function hookActionObjectCmsUpdateBefore(array $params): void
    {
        $this->onObjectBeforeUpdate('cms', $params);
    }

    public function hookActionObjectCmsUpdateAfter(array $params): void
    {
        $this->onObject('cms', $params);
    }

    public function hookActionObjectCmsDeleteBefore(array $params): void
    {
        $this->onObject('cms', $params);
    }

    public function hookActionObjectManufacturerUpdateBefore(array $params): void
    {
        $this->onObjectBeforeUpdate('manufacturer', $params);
    }

    public function hookActionObjectManufacturerUpdateAfter(array $params): void
    {
        $this->onObject('manufacturer', $params);
    }

    public function hookActionObjectManufacturerDeleteBefore(array $params): void
    {
        $this->onObject('manufacturer', $params);
    }

    public function hookActionObjectSupplierUpdateBefore(array $params): void
    {
        $this->onObjectBeforeUpdate('supplier', $params);
    }

    public function hookActionObjectSupplierUpdateAfter(array $params): void
    {
        $this->onObject('supplier', $params);
    }

    public function hookActionObjectSupplierDeleteBefore(array $params): void
    {
        $this->onObject('supplier', $params);
    }

    public function hookActionObjectSpecificPriceAddAfter(array $params): void
    {
        $this->onSpecificPrice($params);
    }

    public function hookActionObjectSpecificPriceUpdateAfter(array $params): void
    {
        $this->onSpecificPrice($params);
    }

    public function hookActionObjectSpecificPriceDeleteAfter(array $params): void
    {
        $this->onSpecificPrice($params);
    }

    /**
     * The back-office "Reset" button uninstalls then reinstalls the module without calling
     * reset(); keep the token, mapping, settings and log during that uninstall.
     */
    public function hookActionBeforeResetModule(array $params): void
    {
        if (($params['moduleName'] ?? null) === $this->name) {
            Uninstaller::$keepData = true;
        }
    }

    public function hookActionUpdateQuantity(array $params): void
    {
        if (!empty($params['id_product'])) {
            $this->handler()->onStockChange((int) $params['id_product']);
        }
    }

    /**
     * Queues the URLs as they are before the save (old link_rewrite still in the database),
     * so renamed pages are purged too.
     */
    private function onObjectBeforeUpdate(string $entity, array $params): void
    {
        $object = $params['object'] ?? null;
        if ($object instanceof ObjectModel && (int) $object->id > 0) {
            $this->handler()->onEntityBeforeUpdate($entity, (int) $object->id);
        }
    }

    private function onObject(string $entity, array $params): void
    {
        $object = $params['object'] ?? null;
        if ($object instanceof ObjectModel && (int) $object->id > 0) {
            $this->handler()->onEntityChange($entity, (int) $object->id);
        }
    }

    private function onSpecificPrice(array $params): void
    {
        $object = $params['object'] ?? null;
        if ($object instanceof SpecificPrice) {
            $this->handler()->onSpecificPriceChange($object);
        }
    }
}
