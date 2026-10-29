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
use PrestaShopBundle\Form\Admin\Type\SwitchType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Range;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Settings form. Extends the plain Symfony AbstractType (with the translator
 * autowired) instead of TranslatorAwareType, whose array $locales argument
 * cannot be autowired by the module's resource scan.
 */
class SettingsType extends AbstractType
{

    public function __construct(private readonly TranslatorInterface $translator)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $t = $this->translator;

        $builder
            ->add('api_token', PasswordType::class, [
                'label' => $t->trans('API token', [], 'Modules.Ppcloudflare.Admin'),
                'required' => false,
                'always_empty' => true,
                'attr' => ['autocomplete' => 'new-password', 'spellcheck' => 'false'],
                'help' => $t->trans('Cloudflare API Token (not the Global API Key). Leave empty to keep the saved token.', [], 'Modules.Ppcloudflare.Admin'),
            ])
            ->add('remove_token', SwitchType::class, [
                'label' => $t->trans('Remove saved token', [], 'Modules.Ppcloudflare.Admin'),
                'required' => false,
            ])
            ->add('enabled', SwitchType::class, [
                'label' => $t->trans('Automatic purges', [], 'Modules.Ppcloudflare.Admin'),
                'required' => false,
                'help' => $t->trans('Master switch. When off, nothing is purged automatically (the Tools tab still works).', [], 'Modules.Ppcloudflare.Admin'),
            ])
            ->add('cache_clear_mode', ChoiceType::class, [
                'label' => $t->trans('Purge everything when PrestaShop clears its cache', [], 'Modules.Ppcloudflare.Admin'),
                'expanded' => true,
                'multiple' => false,
                'choices' => [
                    $t->trans('Never', [], 'Modules.Ppcloudflare.Admin') => Settings::MODE_OFF,
                    $t->trans('Only when "Clear cache" is clicked (Advanced Parameters > Performance)', [], 'Modules.Ppcloudflare.Admin') => Settings::MODE_MANUAL,
                    $t->trans('On every PrestaShop cache clear (modules, themes, deploys, console)', [], 'Modules.Ppcloudflare.Admin') => Settings::MODE_ALL,
                ],
            ])
            ->add('purge_product', SwitchType::class, [
                'label' => $t->trans('Products and prices', [], 'Modules.Ppcloudflare.Admin'),
                'required' => false,
                'help' => $t->trans('Purge the product page (all languages) and its default category when a product or specific price changes.', [], 'Modules.Ppcloudflare.Admin'),
            ])
            ->add('purge_category', SwitchType::class, [
                'label' => $t->trans('Categories', [], 'Modules.Ppcloudflare.Admin'),
                'required' => false,
            ])
            ->add('purge_cms', SwitchType::class, [
                'label' => $t->trans('CMS pages', [], 'Modules.Ppcloudflare.Admin'),
                'required' => false,
            ])
            ->add('purge_manufacturer', SwitchType::class, [
                'label' => $t->trans('Brands', [], 'Modules.Ppcloudflare.Admin'),
                'required' => false,
            ])
            ->add('purge_supplier', SwitchType::class, [
                'label' => $t->trans('Suppliers', [], 'Modules.Ppcloudflare.Admin'),
                'required' => false,
            ])
            ->add('purge_stock', SwitchType::class, [
                'label' => $t->trans('Stock changes', [], 'Modules.Ppcloudflare.Admin'),
                'required' => false,
                'help' => $t->trans('Purge product pages on every quantity change, including orders. Keep it off on busy shops.', [], 'Modules.Ppcloudflare.Admin'),
            ])
            ->add('purge_homepage', SwitchType::class, [
                'label' => $t->trans('Also purge the homepage', [], 'Modules.Ppcloudflare.Admin'),
                'required' => false,
                'help' => $t->trans('Add the shop homepage(s) to every content purge.', [], 'Modules.Ppcloudflare.Admin'),
            ])
            ->add('debounce_seconds', IntegerType::class, [
                'label' => $t->trans('Debounce (seconds)', [], 'Modules.Ppcloudflare.Admin'),
                'required' => true,
                'constraints' => [new Range(['min' => 0, 'max' => 3600])],
                'attr' => ['min' => 0, 'max' => 3600],
                'help' => $t->trans('A repeated automatic full purge of the same zone within this time is skipped. 0 disables it.', [], 'Modules.Ppcloudflare.Admin'),
            ])
            ->add('log_retention_days', IntegerType::class, [
                'label' => $t->trans('Keep the purge log for (days)', [], 'Modules.Ppcloudflare.Admin'),
                'required' => true,
                'constraints' => [new Range(['min' => 1, 'max' => 3650])],
                'attr' => ['min' => 1, 'max' => 3650],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        // Labels are already translated above.
        $resolver->setDefaults(['translation_domain' => false]);
    }
}
