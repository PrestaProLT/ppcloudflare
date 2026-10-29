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
use PrestaShop\Module\PpCloudflare\Config\Settings;
use PrestaShop\Module\PpCloudflare\Log\LogRepository;
use PrestaShop\Module\PpCloudflare\Purge\PurgeResult;
use PrestaShop\Module\PpCloudflare\Purge\PurgeService;
use PrestaShop\Module\PpCloudflare\Zone\ShopZoneRepository;
use PrestaShopBundle\Security\Attribute\AdminSecurity;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tools: manual purges, Development Mode and the security level ("I'm Under Attack").
 */
class ToolsController extends AbstractPpCloudflareController
{
    public const TRIGGER = 'manual';

    private const CUSTOM_TYPES = PurgeService::TYPES;
    private const RESTORE_LEVELS = ['essentially_off', 'low', 'medium', 'high'];
    private const MAX_CUSTOM_ITEMS = 500;

    #[AdminSecurity("is_granted('read', request.get('_legacy_controller'))")]
    public function index(): Response
    {
        $mappings = (new ShopZoneRepository())->findAll();
        $shopNames = [];
        foreach (\Shop::getShops(false) as $shop) {
            $shopNames[(int) $shop['id_shop']] = (string) $shop['name'];
        }

        // Zone settings are zone-wide, so the page groups shops by zone.
        $zones = [];
        foreach ($mappings as $mapping) {
            if (!isset($zones[$mapping->zoneId])) {
                $cached = Settings::findCachedZone($mapping->zoneId);
                $zones[$mapping->zoneId] = [
                    'id' => $mapping->zoneId,
                    'name' => $mapping->zoneName,
                    'status' => (string) ($cached['status'] ?? ''),
                    'plan' => (string) ($cached['plan'] ?? ''),
                    'shops' => [],
                ];
            }
            $zones[$mapping->zoneId]['shops'][] = [
                'id_shop' => $mapping->idShop,
                'name' => $shopNames[$mapping->idShop] ?? '#' . $mapping->idShop,
                'scope' => $mapping->purgeScope,
            ];
        }

        $customZones = [];
        foreach (Settings::getCachedZones() as $zone) {
            $customZones[(string) $zone['id']] = (string) $zone['name'];
        }
        foreach ($zones as $zone) {
            $customZones[$zone['id']] ??= $zone['name'];
        }
        asort($customZones);

        return $this->renderPage('tools.html.twig', 'tools', [
            'zones' => array_values($zones),
            'customZones' => $customZones,
            'restoreLevels' => self::RESTORE_LEVELS,
            'customTypes' => self::CUSTOM_TYPES,
        ]);
    }

    /**
     * Lazy JSON read of the zone settings shown on the Tools page.
     */
    #[AdminSecurity("is_granted('read', request.get('_legacy_controller'))")]
    public function status(Request $request): JsonResponse
    {
        $zoneId = (string) $request->query->get('zone_id', '');
        if (!$this->isKnownZone($zoneId)) {
            return new JsonResponse(['error' => $this->trans('Unknown zone.', [], 'Modules.Ppcloudflare.Admin')], 404);
        }
        if (!Settings::hasToken()) {
            return new JsonResponse(['error' => $this->trans('The Cloudflare API token is not configured. Add it on the Settings tab first.', [], 'Modules.Ppcloudflare.Admin')], 400);
        }

        $client = new CloudflareClient(Settings::getToken());
        $data = [];
        foreach (['development_mode', 'security_level'] as $setting) {
            try {
                $data[$setting] = ['value' => $client->getSetting($zoneId, $setting)];
            } catch (\Throwable $e) {
                $data[$setting] = ['error' => $e->getMessage()];
            }
        }

        return new JsonResponse($data);
    }

    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", redirectRoute: 'ps_ppcloudflare_tools')]
    public function purgeShop(Request $request): RedirectResponse
    {
        if (!$this->isValidPost($request)) {
            return $this->redirectToRoute('ps_ppcloudflare_tools');
        }
        $idShop = $request->request->getInt('id_shop');
        if ((new ShopZoneRepository())->find($idShop) === null) {
            $this->addFlash('error', $this->trans('This shop is not linked to a Cloudflare zone.', [], 'Modules.Ppcloudflare.Admin'));

            return $this->redirectToRoute('ps_ppcloudflare_tools');
        }

        try {
            $this->flashResult(PurgeService::create()->purgeShop($idShop, self::TRIGGER, true));
        } catch (\Throwable $e) {
            $this->flashCloudflareError($e);
        }

        return $this->redirectToRoute('ps_ppcloudflare_tools');
    }

    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", redirectRoute: 'ps_ppcloudflare_tools')]
    public function purgeAll(Request $request): RedirectResponse
    {
        if (!$this->isValidPost($request)) {
            return $this->redirectToRoute('ps_ppcloudflare_tools');
        }

        try {
            $results = PurgeService::create()->purgeAllShops(self::TRIGGER, true);
            if ($results === []) {
                $this->addFlash('warning', $this->trans('No shop is linked to a Cloudflare zone yet.', [], 'Modules.Ppcloudflare.Admin'));
            }
            foreach ($results as $result) {
                $this->flashResult($result);
            }
        } catch (\Throwable $e) {
            $this->flashCloudflareError($e);
        }

        return $this->redirectToRoute('ps_ppcloudflare_tools');
    }

    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", redirectRoute: 'ps_ppcloudflare_tools')]
    public function developmentMode(Request $request): RedirectResponse
    {
        $zoneId = (string) $request->request->get('zone_id', '');
        $value = (string) $request->request->get('value', '');
        if (!$this->isValidPost($request) || !$this->isKnownZone($zoneId) || !in_array($value, ['on', 'off'], true)) {
            return $this->redirectToRoute('ps_ppcloudflare_tools');
        }

        $this->changeSetting($zoneId, 'development_mode', $value, $value === 'on'
            ? $this->trans('Development Mode is on for %zone%. Cloudflare turns it off automatically after 3 hours.', ['%zone%' => $this->zoneName($zoneId)], 'Modules.Ppcloudflare.Admin')
            : $this->trans('Development Mode is off for %zone%.', ['%zone%' => $this->zoneName($zoneId)], 'Modules.Ppcloudflare.Admin'));

        return $this->redirectToRoute('ps_ppcloudflare_tools');
    }

    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", redirectRoute: 'ps_ppcloudflare_tools')]
    public function securityLevel(Request $request): RedirectResponse
    {
        $zoneId = (string) $request->request->get('zone_id', '');
        $value = (string) $request->request->get('value', '');
        if (!$this->isValidPost($request) || !$this->isKnownZone($zoneId)
            || !in_array($value, array_merge(['under_attack'], self::RESTORE_LEVELS), true)) {
            return $this->redirectToRoute('ps_ppcloudflare_tools');
        }

        $this->changeSetting($zoneId, 'security_level', $value, $value === 'under_attack'
            ? $this->trans('"I\'m Under Attack" mode is on for %zone%. Visitors see a browser check before the shop loads.', ['%zone%' => $this->zoneName($zoneId)], 'Modules.Ppcloudflare.Admin')
            : $this->trans('Security level of %zone% set to "%level%".', ['%zone%' => $this->zoneName($zoneId), '%level%' => $value], 'Modules.Ppcloudflare.Admin'));

        return $this->redirectToRoute('ps_ppcloudflare_tools');
    }

    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", redirectRoute: 'ps_ppcloudflare_tools')]
    public function customPurge(Request $request): RedirectResponse
    {
        if (!$this->isValidPost($request)) {
            return $this->redirectToRoute('ps_ppcloudflare_tools');
        }
        $zoneId = (string) $request->request->get('zone_id', '');
        $type = (string) $request->request->get('type', '');
        $items = $this->linesFromText((string) $request->request->get('items', ''));

        if (!$this->isKnownZone($zoneId) || !in_array($type, self::CUSTOM_TYPES, true)) {
            $this->addFlash('error', $this->trans('Choose a zone and what to purge.', [], 'Modules.Ppcloudflare.Admin'));

            return $this->redirectToRoute('ps_ppcloudflare_tools');
        }
        if ($items === []) {
            $this->addFlash('error', $this->trans('Enter at least one item, one per line.', [], 'Modules.Ppcloudflare.Admin'));

            return $this->redirectToRoute('ps_ppcloudflare_tools');
        }
        if (count($items) > self::MAX_CUSTOM_ITEMS) {
            $this->addFlash('error', $this->trans('Too many items: at most %max% per request.', ['%max%' => self::MAX_CUSTOM_ITEMS], 'Modules.Ppcloudflare.Admin'));

            return $this->redirectToRoute('ps_ppcloudflare_tools');
        }
        if ($type === 'files') {
            $invalid = array_filter($items, static fn (string $url): bool => !preg_match('#^https?://[^/\s]+#i', $url));
            if ($invalid !== []) {
                $this->addFlash('error', $this->trans('URLs must start with http:// or https://. Invalid: %items%', ['%items%' => implode(', ', array_slice($invalid, 0, 5))], 'Modules.Ppcloudflare.Admin'));

                return $this->redirectToRoute('ps_ppcloudflare_tools');
            }
        }
        if ($type === 'prefixes') {
            // Cloudflare expects prefixes without the scheme.
            $items = array_map(static fn (string $p): string => (string) preg_replace('#^https?://#i', '', $p), $items);
        }

        try {
            $this->flashResult(PurgeService::create()->purgeCustom($zoneId, $type, $items, self::TRIGGER));
        } catch (\Throwable $e) {
            $this->flashCloudflareError($e);
        }

        return $this->redirectToRoute('ps_ppcloudflare_tools');
    }

    private function changeSetting(string $zoneId, string $setting, string $value, string $successMessage): void
    {
        if (null === ($client = $this->createClient())) {
            return;
        }
        $log = new LogRepository();
        try {
            $client->setSetting($zoneId, $setting, $value);
            $log->add(null, $zoneId, $setting, self::TRIGGER, [$value], true, 'Set ' . $setting . ' to ' . $value);
            $this->addFlash('success', $successMessage);
        } catch (\Throwable $e) {
            $log->add(null, $zoneId, $setting, self::TRIGGER, [$value], false, $e->getMessage());
            $this->flashCloudflareError($e);
        }
    }

    private function flashResult(PurgeResult $result): void
    {
        $zone = $this->zoneName($result->zoneId);
        if ($result->success) {
            $this->addFlash('success', $this->trans('Cloudflare purge done for %zone% (%action%, %count% item(s)). %message%', [
                '%zone%' => $zone,
                '%action%' => $result->action,
                '%count%' => $result->count,
                '%message%' => $result->message,
            ], 'Modules.Ppcloudflare.Admin'));

            return;
        }
        $this->addFlash('error', $this->trans('Cloudflare purge failed for %zone%: %message%', [
            '%zone%' => $zone !== '' ? $zone : '-',
            '%message%' => $result->message,
        ], 'Modules.Ppcloudflare.Admin'));
    }

    /**
     * Only zones the merchant linked or fetched may be targeted.
     */
    private function isKnownZone(string $zoneId): bool
    {
        if (preg_match('/^[a-f0-9]{32}$/', $zoneId) !== 1) {
            return false;
        }
        if (Settings::findCachedZone($zoneId) !== null) {
            return true;
        }
        foreach ((new ShopZoneRepository())->findAll() as $mapping) {
            if ($mapping->zoneId === $zoneId) {
                return true;
            }
        }

        return false;
    }

    private function zoneName(string $zoneId): string
    {
        $cached = Settings::findCachedZone($zoneId);
        if ($cached !== null) {
            return (string) $cached['name'];
        }
        foreach ((new ShopZoneRepository())->findAll() as $mapping) {
            if ($mapping->zoneId === $zoneId) {
                return $mapping->zoneName;
            }
        }

        return $zoneId;
    }
}
