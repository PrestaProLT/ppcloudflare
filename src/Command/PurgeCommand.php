<?php
/**
 * Cloudflare integration for PrestaShop 9.
 *
 * @author    PrestaPro
 * @copyright PrestaPro
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */
declare(strict_types=1);

namespace PrestaShop\Module\PpCloudflare\Command;

use PrestaShop\Module\PpCloudflare\Purge\PurgeResult;
use PrestaShop\Module\PpCloudflare\Purge\PurgeService;
use PrestaShop\Module\PpCloudflare\Zone\ShopDomainResolver;
use PrestaShop\Module\PpCloudflare\Zone\ShopZoneRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * php bin/console ppcloudflare:purge [--shop=ID|all] [--url=URL ...]
 */
#[AsCommand(name: 'ppcloudflare:purge', description: 'Purges the Cloudflare cache of mapped shops (full purge, or the given URLs).')]
class PurgeCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('ppcloudflare:purge')
            ->setDescription('Purges the Cloudflare cache of mapped shops (full purge, or the given URLs).')
            ->addOption('shop', null, InputOption::VALUE_REQUIRED, 'Shop ID or "all"', 'all')
            ->addOption('url', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'URL to purge (repeatable). Without it a full purge is done.')
            ->setHelp(
                "Full purge of every shop linked to a Cloudflare zone (ignores the debounce):\n"
                . "  <info>php bin/console ppcloudflare:purge</info>\n\n"
                . "Full purge of one shop:\n"
                . "  <info>php bin/console ppcloudflare:purge --shop=2</info>\n\n"
                . "Purge single URLs (the shop is detected from each URL host when --shop=all):\n"
                . "  <info>php bin/console ppcloudflare:purge --url=https://www.example.com/ --url=https://www.example.com/contact-us</info>"
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->bootstrapContext();

        $shopOption = strtolower(trim((string) $input->getOption('shop')));
        $urls = array_values(array_filter(array_map('trim', (array) $input->getOption('url'))));
        $service = PurgeService::create();

        if ($service->getClient() === null) {
            $output->writeln('<error>API token is not configured. Set it in the module settings first.</error>');

            return Command::FAILURE;
        }

        if ($shopOption !== 'all' && !ctype_digit($shopOption)) {
            $output->writeln('<error>--shop must be a shop ID or "all".</error>');

            return Command::INVALID;
        }

        if ($urls === []) {
            $results = $shopOption === 'all'
                ? $service->purgeAllShops('cli', true)
                : [$service->purgeShop((int) $shopOption, 'cli', true)];
        } else {
            $results = [];
            $byShop = $shopOption === 'all' ? $this->groupUrlsByShop($urls, $output) : [(int) $shopOption => $urls];
            foreach ($byShop as $idShop => $shopUrls) {
                $results[] = $service->purgeUrls((int) $idShop, $shopUrls, 'cli');
            }
            if ($results === []) {
                $output->writeln('<error>None of the URLs belongs to a shop linked to a Cloudflare zone.</error>');

                return Command::FAILURE;
            }
        }

        return $this->report($results, $output);
    }

    /**
     * Assigns every URL to the mapped shop whose host (and longest URI) matches it.
     *
     * @param string[] $urls
     *
     * @return array<int, string[]>
     */
    private function groupUrlsByShop(array $urls, OutputInterface $output): array
    {
        $mapped = (new ShopZoneRepository())->findAll();
        $shops = (new ShopDomainResolver())->getShops();
        $groups = [];
        foreach ($urls as $url) {
            $host = strtolower((string) parse_url($url, PHP_URL_HOST));
            $path = (string) (parse_url($url, PHP_URL_PATH) ?? '/');
            $path = $path === '' ? '/' : $path;
            $best = null;
            $bestLength = -1;
            foreach ($mapped as $idShop => $mapping) {
                foreach ($shops[$idShop]['entries'] ?? [] as $entry) {
                    if ($entry['host'] === $host && str_starts_with(rtrim($path, '/') . '/', $entry['uri'])
                        && strlen($entry['uri']) > $bestLength) {
                        $best = $idShop;
                        $bestLength = strlen($entry['uri']);
                    }
                }
            }
            if ($best === null) {
                $output->writeln(sprintf('<comment>Skipped (no linked shop for this host): %s</comment>', $url));
                continue;
            }
            $groups[$best][] = $url;
        }

        return $groups;
    }

    /**
     * @param PurgeResult[] $results
     */
    private function report(array $results, OutputInterface $output): int
    {
        $ok = true;
        foreach ($results as $result) {
            $ok = $ok && $result->success;
            $output->writeln(sprintf(
                '%s %s%s%s',
                $result->success ? '<info>[OK]</info>' : '<error>[FAILED]</error>',
                $result->idShop !== null ? 'shop #' . $result->idShop . ' ' : '',
                $result->zoneId !== '' ? '(zone ' . $result->zoneId . ') ' : '',
                $result->message
            ));
        }

        return $ok ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * bin/console may run without a fully initialised legacy context: make sure a shop and a
     * language exist so Link/Configuration calls behave.
     */
    private function bootstrapContext(): void
    {
        $context = \Context::getContext();
        if (!isset($context->shop) || !\Validate::isLoadedObject($context->shop)) {
            $context->shop = new \Shop((int) \Configuration::get('PS_SHOP_DEFAULT'));
        }
        if (\Shop::isFeatureActive()) {
            \Shop::setContext(\Shop::CONTEXT_ALL);
        }
        if (!isset($context->language) || !\Validate::isLoadedObject($context->language)) {
            $context->language = new \Language((int) \Configuration::get('PS_LANG_DEFAULT'));
        }
    }
}
