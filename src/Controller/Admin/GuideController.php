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

use PrestaShopBundle\Security\Attribute\AdminSecurity;
use Symfony\Component\HttpFoundation\Response;

/**
 * Static step-by-step guide: how to create the Cloudflare API token.
 */
class GuideController extends AbstractPpCloudflareController
{
    #[AdminSecurity("is_granted('read', request.get('_legacy_controller'))")]
    public function index(): Response
    {
        return $this->renderPage('guide.html.twig', 'guide', [
            'consoleCommand' => 'php bin/console ppcloudflare:purge --shop=all',
        ]);
    }
}
