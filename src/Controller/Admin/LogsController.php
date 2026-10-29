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

use PrestaShop\Module\PpCloudflare\Log\LogRepository;
use PrestaShopBundle\Security\Attribute\AdminSecurity;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Read-only purge log (GET filters, pagination) plus a "Clear log" POST action.
 */
class LogsController extends AbstractPpCloudflareController
{
    private const PER_PAGE = 50;

    #[AdminSecurity("is_granted('read', request.get('_legacy_controller'))")]
    public function index(Request $request): Response
    {
        $success = (string) $request->query->get('success', '');
        $filters = [
            'success' => in_array($success, ['0', '1'], true) ? $success : '',
            'id_shop' => $request->query->getInt('id_shop') ?: '',
            'action' => preg_replace('/[^a-z_]/', '', (string) $request->query->get('action', '')),
        ];
        $page = max(1, $request->query->getInt('page', 1));

        $repositoryFilters = [];
        if ($filters['success'] !== '') {
            $repositoryFilters['success'] = (int) $filters['success'];
        }
        if ($filters['id_shop'] !== '') {
            $repositoryFilters['id_shop'] = (int) $filters['id_shop'];
        }
        if ($filters['action'] !== '') {
            $repositoryFilters['action'] = $filters['action'];
        }

        $repository = new LogRepository();
        $result = $repository->paginate($page, self::PER_PAGE, $repositoryFilters);
        $totalPages = max(1, (int) ceil($result['total'] / self::PER_PAGE));

        $shops = [];
        foreach (\Shop::getShops(false) as $shop) {
            $shops[(int) $shop['id_shop']] = (string) $shop['name'];
        }

        return $this->renderPage('logs.html.twig', 'logs', [
            'rows' => $result['rows'],
            'total' => (int) $result['total'],
            'page' => min($page, $totalPages),
            'totalPages' => $totalPages,
            'filters' => $filters,
            'query' => array_filter($filters, static fn ($v): bool => $v !== ''),
            'shops' => $shops,
            'actions' => $repository->getActions(),
        ]);
    }

    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", redirectRoute: 'ps_ppcloudflare_logs')]
    public function clear(Request $request): RedirectResponse
    {
        if ($this->isValidPost($request)) {
            (new LogRepository())->clear();
            $this->addFlash('success', $this->trans('The purge log was cleared.', [], 'Modules.Ppcloudflare.Admin'));
        }

        return $this->redirectToRoute('ps_ppcloudflare_logs');
    }
}
