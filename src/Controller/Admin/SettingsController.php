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
use PrestaShop\PrestaShop\Core\Form\FormHandlerInterface;
use PrestaShopBundle\Security\Attribute\AdminSecurity;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class SettingsController extends AbstractPpCloudflareController
{
    #[AdminSecurity("is_granted('read', request.get('_legacy_controller'))")]
    public function index(
        Request $request,
        #[Autowire(service: 'prestashop.module.ppcloudflare.form.settings_form_handler')]
        FormHandlerInterface $formHandler,
    ): Response {
        $form = $formHandler->getForm();
        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            if (!$this->isGranted('update', $request->get('_legacy_controller'))) {
                $this->addFlash('error', $this->trans('You do not have permission to edit this.', [], 'Admin.Notifications.Error'));

                return $this->redirectToRoute('ps_ppcloudflare_configure');
            }
            if ($form->isValid()) {
                $errors = $formHandler->save($form->getData());
                if (empty($errors)) {
                    $this->addFlash('success', $this->trans('Successful update', [], 'Admin.Notifications.Success'));

                    return $this->redirectToRoute('ps_ppcloudflare_configure');
                }
                $this->addFlashErrors($errors);
            }
            // Invalid fields are shown inline by the form theme.
        }

        return $this->renderPage('settings.html.twig', 'settings', [
            'settingsForm' => $form->createView(),
            'tokenHint' => Settings::getTokenHint(),
            'tokenStoredButUnreadable' => !Settings::hasToken() && Settings::get(Settings::API_TOKEN) !== '',
        ]);
    }

    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", redirectRoute: 'ps_ppcloudflare_configure')]
    public function testToken(Request $request): RedirectResponse
    {
        if (!$this->isValidPost($request) || null === ($client = $this->createClient())) {
            return $this->redirectToRoute('ps_ppcloudflare_configure');
        }

        try {
            $result = $client->verifyToken();
            if (empty($result['valid'])) {
                $this->addFlash('error', $this->trans('The token is not valid (status: %status%). %message%', [
                    '%status%' => (string) ($result['status'] ?? ''),
                    '%message%' => (string) ($result['message'] ?? ''),
                ], 'Modules.Ppcloudflare.Admin'));

                return $this->redirectToRoute('ps_ppcloudflare_configure');
            }

            $zones = $client->listZones();
            $message = $this->trans('The token is valid (status: %status%). It can read %count% zone(s).', [
                '%status%' => (string) ($result['status'] ?? 'active'),
                '%count%' => count($zones),
            ], 'Modules.Ppcloudflare.Admin');
            if (!empty($result['expires_on'])) {
                $message .= ' ' . $this->trans('Expires on %date%.', ['%date%' => (string) $result['expires_on']], 'Modules.Ppcloudflare.Admin');
            }
            $this->addFlash('success', $message);
            if (count($zones) === 0) {
                $this->addFlash('warning', $this->trans('The token cannot see any zone. Add the "Zone · Zone · Read" permission and include your zones in "Zone Resources".', [], 'Modules.Ppcloudflare.Admin'));
            }
        } catch (\Throwable $e) {
            $this->flashCloudflareError($e);
        }

        return $this->redirectToRoute('ps_ppcloudflare_configure');
    }
}
