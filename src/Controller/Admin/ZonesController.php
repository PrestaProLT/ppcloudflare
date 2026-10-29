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

use PrestaShop\Module\PpCloudflare\Config\Settings;
use PrestaShop\Module\PpCloudflare\Zone\ShopDomainResolver;
use PrestaShop\Module\PpCloudflare\Zone\ShopZoneMapping;
use PrestaShop\Module\PpCloudflare\Zone\ShopZoneRepository;
use PrestaShop\Module\PpCloudflare\Zone\ZoneMatcher;
use PrestaShopBundle\Security\Attribute\AdminSecurity;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shops & zones: links every shop (all shops, whatever the current shop context)
 * to a Cloudflare zone and a purge scope.
 */
class ZonesController extends AbstractPpCloudflareController
{
    #[AdminSecurity("is_granted('read', request.get('_legacy_controller'))")]
    public function index(): Response
    {
        $zones = Settings::getCachedZones();
        $mappings = (new ShopZoneRepository())->findAll();
        $resolver = new ShopDomainResolver();
        $matcher = new ZoneMatcher($resolver);

        $groups = [];
        foreach (\ShopGroup::getShopGroups(false) as $group) {
            $groups[(int) $group->id] = (string) $group->name;
        }

        $zoneIds = array_column($zones, 'id');
        $rows = [];
        foreach (\Shop::getShops(false) as $shop) {
            $idShop = (int) $shop['id_shop'];
            $mapping = $mappings[$idShop] ?? null;
            $suggestion = $zones ? $matcher->suggest($zones, $idShop) : null;
            $rows[] = [
                'id_shop' => $idShop,
                'name' => (string) $shop['name'],
                'group' => $groups[(int) $shop['id_shop_group']] ?? '',
                'active' => (bool) $shop['active'],
                'uri' => (string) ($shop['uri'] ?? '/'),
                'hosts' => $resolver->getHosts($idShop),
                'zone_id' => $mapping?->zoneId ?? '',
                'zone_name' => $mapping?->zoneName ?? '',
                'scope' => $mapping?->purgeScope ?? ($suggestion['scope'] ?? ShopZoneMapping::SCOPE_ZONE),
                'zone_missing' => $mapping !== null && $zones && !in_array($mapping->zoneId, $zoneIds, true),
                'suggested_zone_id' => (string) ($suggestion['zone']['id'] ?? ''),
                'suggested_zone_name' => (string) ($suggestion['zone']['name'] ?? ''),
                'suggested_scope' => (string) ($suggestion['scope'] ?? ''),
            ];
        }

        return $this->renderPage('zones.html.twig', 'zones', [
            'shops' => $rows,
            'zones' => $zones,
            'zonesFetchedAt' => Settings::getZonesFetchedAt(),
            'sharedZoneWarnings' => $this->findSharedZoneConflicts($mappings, $rows),
            'scopes' => ShopZoneMapping::SCOPES,
        ]);
    }

    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", redirectRoute: 'ps_ppcloudflare_zones')]
    public function fetch(Request $request): RedirectResponse
    {
        if (!$this->isValidPost($request) || null === ($client = $this->createClient())) {
            return $this->redirectToRoute('ps_ppcloudflare_zones');
        }

        try {
            $zones = $client->listZones();
            Settings::setCachedZones($zones);
            $this->addFlash('success', $this->trans('%count% zone(s) fetched from Cloudflare.', ['%count%' => count($zones)], 'Modules.Ppcloudflare.Admin'));
            if ($zones === []) {
                $this->addFlash('warning', $this->trans('The token cannot see any zone. Check "Zone Resources" in the token settings.', [], 'Modules.Ppcloudflare.Admin'));
            }
        } catch (\Throwable $e) {
            $this->flashCloudflareError($e);
        }

        return $this->redirectToRoute('ps_ppcloudflare_zones');
    }

    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", redirectRoute: 'ps_ppcloudflare_zones')]
    public function autodetect(Request $request): RedirectResponse
    {
        if (!$this->isValidPost($request)) {
            return $this->redirectToRoute('ps_ppcloudflare_zones');
        }

        $zones = Settings::getCachedZones();
        if ($zones === []) {
            $this->addFlash('warning', $this->trans('Fetch the zones from Cloudflare first.', [], 'Modules.Ppcloudflare.Admin'));

            return $this->redirectToRoute('ps_ppcloudflare_zones');
        }

        $repository = new ShopZoneRepository();
        $mappings = $repository->findAll();
        $matcher = new ZoneMatcher();
        $linked = 0;
        $unmatched = [];
        foreach (\Shop::getShops(false) as $shop) {
            $idShop = (int) $shop['id_shop'];
            if (isset($mappings[$idShop])) {
                continue;
            }
            $suggestion = $matcher->suggest($zones, $idShop);
            if ($suggestion === null) {
                $unmatched[] = (string) $shop['name'];
                continue;
            }
            $repository->save(new ShopZoneMapping(
                $idShop,
                (string) $suggestion['zone']['id'],
                (string) $suggestion['zone']['name'],
                (string) $suggestion['scope']
            ));
            ++$linked;
        }

        $this->addFlash($linked > 0 ? 'success' : 'info', $this->trans('%count% shop(s) linked automatically. Already linked shops were not changed.', ['%count%' => $linked], 'Modules.Ppcloudflare.Admin'));
        if ($unmatched !== []) {
            $this->addFlash('warning', $this->trans('No matching zone for: %shops%', ['%shops%' => implode(', ', $unmatched)], 'Modules.Ppcloudflare.Admin'));
        }

        return $this->redirectToRoute('ps_ppcloudflare_zones');
    }

    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", redirectRoute: 'ps_ppcloudflare_zones')]
    public function save(Request $request): RedirectResponse
    {
        if (!$this->isValidPost($request)) {
            return $this->redirectToRoute('ps_ppcloudflare_zones');
        }

        $zoneInput = $request->request->all('zone');
        $scopeInput = $request->request->all('scope');
        $repository = new ShopZoneRepository();
        $existing = $repository->findAll();
        $zonesById = [];
        foreach (Settings::getCachedZones() as $zone) {
            $zonesById[(string) $zone['id']] = (string) $zone['name'];
        }

        $saved = 0;
        $removed = 0;
        foreach (\Shop::getShops(false) as $shop) {
            $idShop = (int) $shop['id_shop'];
            if (!array_key_exists($idShop, $zoneInput)) {
                continue;
            }
            $zoneId = trim((string) $zoneInput[$idShop]);
            if ($zoneId === '') {
                if (isset($existing[$idShop])) {
                    $repository->delete($idShop);
                    ++$removed;
                }
                continue;
            }
            if (preg_match('/^[a-f0-9]{32}$/', $zoneId) !== 1) {
                continue;
            }
            // Keep the stored name when the zone is no longer in the cached list.
            $zoneName = $zonesById[$zoneId] ?? ($existing[$idShop]->zoneName ?? '');
            if ($zoneName === '') {
                continue;
            }
            $scope = (string) ($scopeInput[$idShop] ?? ShopZoneMapping::SCOPE_ZONE);
            $repository->save(new ShopZoneMapping($idShop, $zoneId, $zoneName, $scope));
            ++$saved;
        }

        $this->addFlash('success', $this->trans('Mapping saved: %saved% shop(s) linked, %removed% unlinked.', [
            '%saved%' => $saved,
            '%removed%' => $removed,
        ], 'Modules.Ppcloudflare.Admin'));

        return $this->redirectToRoute('ps_ppcloudflare_zones');
    }

    /**
     * Zones used by more than one shop where at least one of them purges the whole zone:
     * purging that shop also empties the cache of the others.
     *
     * @param array<int, ShopZoneMapping> $mappings
     *
     * @return array<int, array{zone:string, shops:string[]}>
     */
    private function findSharedZoneConflicts(array $mappings, array $rows): array
    {
        $names = array_column($rows, 'name', 'id_shop');
        $byZone = [];
        foreach ($mappings as $mapping) {
            $byZone[$mapping->zoneId][] = $mapping;
        }

        $warnings = [];
        foreach ($byZone as $list) {
            if (count($list) < 2) {
                continue;
            }
            $wholeZone = array_filter($list, static fn (ShopZoneMapping $m): bool => $m->purgeScope === ShopZoneMapping::SCOPE_ZONE);
            if ($wholeZone === []) {
                continue;
            }
            $warnings[] = [
                'zone' => $list[0]->zoneName,
                'shops' => array_map(static fn (ShopZoneMapping $m): string => (string) ($names[$m->idShop] ?? '#' . $m->idShop), $list),
                'zone_scope_shops' => array_map(static fn (ShopZoneMapping $m): string => (string) ($names[$m->idShop] ?? '#' . $m->idShop), array_values($wholeZone)),
            ];
        }

        return $warnings;
    }
}
