<?php
/**
 * Cloudflare integration for PrestaShop 9.
 *
 * @author    PrestaPro
 * @copyright PrestaPro
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */
declare(strict_types=1);

namespace PrestaShop\Module\PpCloudflare\Controller\Admin;

use PrestaShop\Module\PpCloudflare\Api\CloudflareClient;
use PrestaShop\Module\PpCloudflare\Api\CloudflareException;
use PrestaShop\Module\PpCloudflare\Config\Settings;
use PrestaShopBundle\Controller\Admin\PrestaShopAdminController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shared helpers for every ppcloudflare admin page: the in-page tab navigation,
 * CSRF checks for the plain POST buttons and Cloudflare client creation.
 *
 * Note: PrestaShopAdminController::trans() uses the Symfony order
 * (id, parameters, domain) — unlike the deprecated FrameworkBundleAdminController.
 */
abstract class AbstractPpCloudflareController extends PrestaShopAdminController
{
    public const CSRF_ID = 'ppcloudflare';

    /**
     * Renders a module page inside the shared layout (tab navigation).
     */
    protected function renderPage(string $template, string $currentTab, array $parameters = []): Response
    {
        return $this->render('@Modules/ppcloudflare/views/templates/admin/' . $template, $parameters + [
            'layoutTitle' => $this->trans('Cloudflare', [], 'Modules.Ppcloudflare.Admin'),
            'enableSidebar' => false,
            'ppcfTabs' => $this->getTabs(),
            'ppcfCurrentTab' => $currentTab,
            'ppcfHasToken' => Settings::hasToken(),
            'ppcfCsrfId' => self::CSRF_ID,
        ]);
    }

    /**
     * @return array<int, array{key:string, route:string, title:string, icon:string}>
     */
    protected function getTabs(): array
    {
        return [
            ['key' => 'settings', 'route' => 'ps_ppcloudflare_configure', 'icon' => 'settings', 'title' => $this->trans('Settings', [], 'Modules.Ppcloudflare.Admin')],
            ['key' => 'zones', 'route' => 'ps_ppcloudflare_zones', 'icon' => 'dns', 'title' => $this->trans('Shops & zones', [], 'Modules.Ppcloudflare.Admin')],
            ['key' => 'tools', 'route' => 'ps_ppcloudflare_tools', 'icon' => 'build', 'title' => $this->trans('Tools', [], 'Modules.Ppcloudflare.Admin')],
            ['key' => 'logs', 'route' => 'ps_ppcloudflare_logs', 'icon' => 'history', 'title' => $this->trans('Purge log', [], 'Modules.Ppcloudflare.Admin')],
            ['key' => 'guide', 'route' => 'ps_ppcloudflare_guide', 'icon' => 'help_outline', 'title' => $this->trans('Setup guide', [], 'Modules.Ppcloudflare.Admin')],
        ];
    }

    /**
     * Validates the CSRF token posted by the plain (non-Symfony-form) action buttons.
     * Adds an error flash when invalid.
     */
    protected function isValidPost(Request $request): bool
    {
        if ($this->isCsrfTokenValid(self::CSRF_ID, (string) $request->request->get('_csrf_token', ''))) {
            return true;
        }
        $this->addFlash('error', $this->trans('Invalid security token. Please reload the page and try again.', [], 'Modules.Ppcloudflare.Admin'));

        return false;
    }

    /**
     * Returns a client for the saved token, or null (with an error flash) when no token is saved.
     */
    protected function createClient(): ?CloudflareClient
    {
        if (!Settings::hasToken()) {
            $this->addFlash('error', $this->trans('The Cloudflare API token is not configured. Add it on the Settings tab first.', [], 'Modules.Ppcloudflare.Admin'));

            return null;
        }

        return new CloudflareClient(Settings::getToken());
    }

    protected function flashCloudflareError(\Throwable $e): void
    {
        // CloudflareException messages are already readable ("Cloudflare API error: … [code N] hint").
        if ($e instanceof CloudflareException) {
            $this->addFlash('error', $e->getMessage());

            return;
        }
        $this->addFlash('error', $this->trans('Unexpected error: %message%', ['%message%' => $e->getMessage()], 'Modules.Ppcloudflare.Admin'));
    }

    /**
     * Splits a textarea into trimmed, non-empty, unique lines.
     *
     * @return string[]
     */
    protected function linesFromText(string $text): array
    {
        $lines = preg_split('/\R/', $text) ?: [];

        return array_values(array_unique(array_filter(array_map('trim', $lines), static fn (string $l): bool => $l !== '')));
    }
}
