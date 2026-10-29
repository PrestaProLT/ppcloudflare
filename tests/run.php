<?php
/**
 * Cloudflare integration for PrestaShop 9.
 *
 * Plain-PHP unit tests (no PHPUnit, no PrestaShop install, no network).
 * Run: php tests/run.php
 *
 * @author    PrestaPro
 * @copyright PrestaPro
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit;
}

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/stubs.php';

use PrestaShop\Module\PpCloudflare\Api\CloudflareClient;
use PrestaShop\Module\PpCloudflare\Api\CloudflareException;
use PrestaShop\Module\PpCloudflare\Api\HttpTransportInterface;
use PrestaShop\Module\PpCloudflare\Config\Settings;
use PrestaShop\Module\PpCloudflare\Hook\HookHandler;
use PrestaShop\Module\PpCloudflare\Purge\EntityUrlCollector;
use PrestaShop\Module\PpCloudflare\Log\LogRepository;
use PrestaShop\Module\PpCloudflare\Purge\PurgeQueue;
use PrestaShop\Module\PpCloudflare\Purge\PurgeResult;
use PrestaShop\Module\PpCloudflare\Purge\PurgeService;
use PrestaShop\Module\PpCloudflare\Zone\ShopDomainResolver;
use PrestaShop\Module\PpCloudflare\Zone\ShopZoneMapping;
use PrestaShop\Module\PpCloudflare\Zone\ShopZoneRepository;
use PrestaShop\Module\PpCloudflare\Zone\ZoneMatcher;

// ------------------------------------------------------------------ fakes

final class FakeTransport implements HttpTransportInterface
{
    /** @var array<int, array{method:string, url:string, headers:array, body:?string}> */
    public array $calls = [];

    /** @var callable(string, string, ?string): array{status:int, body:string} */
    private $responder;

    public function __construct(?callable $responder = null)
    {
        $this->responder = $responder ?? static fn (): array => ['status' => 200, 'body' => json_encode(['success' => true, 'errors' => [], 'result' => ['id' => 'x']])];
    }

    public function request(string $method, string $url, array $headers, ?string $body, int $timeout): array
    {
        $this->calls[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];

        return ($this->responder)($method, $url, $body);
    }
}

final class FakeResolver extends ShopDomainResolver
{
    /** @param array<int, array> $shops */
    public function __construct(private array $shops)
    {
    }

    public function getShops(): array
    {
        return $this->shops;
    }
}

final class FakeZoneRepository extends ShopZoneRepository
{
    /** @param array<int, ShopZoneMapping> $mappings */
    public function __construct(private array $mappings)
    {
    }

    public function findAll(): array
    {
        return $this->mappings;
    }
}

final class FakeLog extends LogRepository
{
    public array $rows = [];

    public function add(?int $idShop, string $zoneId, string $action, string $trigger, array $items, bool $success, string $message): void
    {
        $this->rows[] = compact('idShop', 'zoneId', 'action', 'trigger', 'items', 'success', 'message');
    }
}

/** Records PurgeQueue::flush() calls without touching the API. */
final class RecordingService extends PurgeService
{
    public array $calls = [];

    public function __construct()
    {
    }

    public function purgeShop(int $idShop, string $trigger, bool $force = false): PurgeResult
    {
        $this->calls[] = ['shop', $idShop, $trigger];

        return new PurgeResult(true, '', PurgeResult::ACTION_EVERYTHING, 'ok');
    }

    public function purgeAllShops(string $trigger, bool $force = false): array
    {
        $this->calls[] = ['all', $trigger];

        return [new PurgeResult(true, '', PurgeResult::ACTION_EVERYTHING, 'ok')];
    }

    public function purgeUrls(int $idShop, array $urls, string $trigger): PurgeResult
    {
        $this->calls[] = ['urls', $idShop, $urls, $trigger];

        return new PurgeResult(true, '', PurgeResult::ACTION_FILES, 'ok');
    }
}

/**
 * Simulates the database: URLs come from the "current" link_rewrite and are stored in the real
 * EntityUrlCollector request cache, so forget()/caching behave exactly as in production.
 */
final class RenamingCollector extends EntityUrlCollector
{
    public string $slug = 'old-name';

    public function __construct()
    {
    }

    public function forEntity(string $entity, int $id): array
    {
        $cache = new ReflectionProperty(EntityUrlCollector::class, 'cache');
        $data = $cache->getValue();
        $key = $entity . ':' . $id;
        if (!isset($data[$key])) {
            $data[$key] = [1 => ["https://www.example.com/$id-{$this->slug}"]];
            $cache->setValue(null, $data);
        }

        return $data[$key];
    }
}

function shopFixture(int $id, array $hosts, string $uri = '/', bool $ssl = true): array
{
    $entries = [];
    foreach ($hosts as $host) {
        $entries[] = ['host' => $host, 'domain' => $host, 'uri' => $uri, 'main' => $entries === []];
    }

    return [
        'id_shop' => $id, 'id_shop_group' => 1, 'group_name' => 'Default', 'name' => 'Shop ' . $id,
        'hosts' => $hosts, 'uri' => $uri, 'active' => true, 'ssl' => $ssl, 'entries' => $entries,
    ];
}

function zone(string $name, string $id): array
{
    return ['id' => $id, 'name' => $name, 'status' => 'active', 'plan' => 'Free', 'account_id' => 'a', 'account_name' => 'A', 'paused' => false];
}

function ok(array $result = [], array $resultInfo = []): array
{
    $body = ['success' => true, 'errors' => [], 'messages' => [], 'result' => $result];
    if ($resultInfo !== []) {
        $body['result_info'] = $resultInfo;
    }

    return ['status' => 200, 'body' => json_encode($body)];
}

// ------------------------------------------------------------------ mini runner

$passed = 0;
$failed = 0;

function check(bool $condition, string $label): void
{
    global $passed, $failed;
    if ($condition) {
        ++$passed;
        echo "  ok   $label\n";
    } else {
        ++$failed;
        echo "  FAIL $label\n";
    }
}

function test(string $name, callable $fn): void
{
    global $failed;
    echo "\n$name\n";
    try {
        \Configuration::reset();
        PurgeQueue::reset();
        PurgeQueue::setService(null);
        $fn();
    } catch (\Throwable $e) {
        ++$failed;
        echo '  FAIL uncaught ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n";
    }
}

const Z1 = '023e105f4ecef8ad9ca31a8372d0c353';
const Z2 = '1111105f4ecef8ad9ca31a8372d0c353';
const Z3 = '2222105f4ecef8ad9ca31a8372d0c353';

// ------------------------------------------------------------------ API client

test('Zone listing follows pagination', function (): void {
    $transport = new FakeTransport(function (string $method, string $url): array {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        $page = (int) ($q['page'] ?? 1);
        $zones = [];
        $count = $page < 3 ? 50 : 7;
        for ($i = 0; $i < $count; ++$i) {
            $zones[] = ['id' => sprintf('%032x', $page * 1000 + $i), 'name' => "Z$page-$i.example.com", 'status' => 'active',
                'plan' => ['name' => 'Pro'], 'account' => ['id' => 'acc', 'name' => 'Acme'], 'paused' => false];
        }

        return ok($zones, ['page' => $page, 'per_page' => 50, 'total_pages' => 3, 'count' => $count, 'total_count' => 107]);
    });
    $zones = (new CloudflareClient('tok', $transport))->listZones();
    check(count($transport->calls) === 3, '3 requests for 3 pages');
    check(count($zones) === 107, '107 zones returned');
    check(str_contains($transport->calls[2]['url'], 'page=3') && str_contains($transport->calls[0]['url'], 'per_page=50'), 'page/per_page params');
    check($zones[0]['plan'] === 'Pro' && $zones[0]['account_name'] === 'Acme', 'plan/account normalized');
    check($zones[0]['name'] === strtolower($zones[0]['name']), 'zone names lowercased');
    check($transport->calls[0]['headers']['Authorization'] === 'Bearer tok', 'bearer auth header');
});

test('Purge files is chunked by 30', function (): void {
    $transport = new FakeTransport(fn () => ok(['id' => Z1]));
    $urls = [];
    for ($i = 0; $i < 65; ++$i) {
        $urls[] = "https://www.example.com/p$i";
    }
    $urls[] = 'https://www.example.com/p0'; // duplicate
    (new CloudflareClient('tok', $transport))->purgeFiles(Z1, $urls);
    check(count($transport->calls) === 3, '65 unique URLs -> 3 calls');
    $sizes = array_map(fn ($c) => count(json_decode($c['body'], true)['files']), $transport->calls);
    check($sizes === [30, 30, 5], 'chunks 30/30/5');
    check($transport->calls[0]['method'] === 'POST' && str_ends_with($transport->calls[0]['url'], '/zones/' . Z1 . '/purge_cache'), 'POST purge_cache endpoint');
    check(!str_contains($transport->calls[0]['body'], '\/'), 'slashes not escaped in JSON body');
});

test('Purge everything / prefixes payloads', function (): void {
    $transport = new FakeTransport(fn () => ok());
    $client = new CloudflareClient('tok', $transport);
    $client->purgeEverything(Z1);
    $client->purgePrefixes(Z1, ['https://www.example.com/shop2/', 'www.example.com/shop3/']);
    check(json_decode($transport->calls[0]['body'], true) === ['purge_everything' => true], 'purge_everything body');
    check(json_decode($transport->calls[1]['body'], true)['prefixes'] === ['www.example.com/shop2/', 'www.example.com/shop3/'], 'prefixes without scheme');
});

test('Error parsing', function (): void {
    $transport = new FakeTransport(fn () => ['status' => 403, 'body' => json_encode([
        'success' => false, 'errors' => [['code' => 10000, 'message' => 'Authentication error']], 'result' => null,
    ])]);
    try {
        (new CloudflareClient('secret-token-value', $transport))->purgeEverything(Z1);
        check(false, 'exception thrown');
    } catch (CloudflareException $e) {
        check($e->getHttpStatus() === 403, 'http status 403');
        check($e->getCloudflareCode() === 10000, 'cloudflare code 10000');
        check(str_contains($e->getMessage(), 'Authentication error'), 'message contains CF error');
        check(!str_contains($e->getMessage(), 'secret-token-value'), 'token never in message');
        check(count($e->getErrors()) === 1, 'errors array');
    }

    $transport = new FakeTransport(fn () => ['status' => 429, 'body' => '{"success":false,"errors":[{"code":971,"message":"Please wait"}]}']);
    try {
        (new CloudflareClient('tok', $transport))->purgeEverything(Z1);
        check(false, '429 exception thrown');
    } catch (CloudflareException $e) {
        check($e->getHttpStatus() === 429 && str_contains($e->getMessage(), 'rate limit'), '429 -> rate limit message');
    }

    $transport = new FakeTransport(fn () => ['status' => 502, 'body' => '<html>Bad gateway</html>']);
    try {
        (new CloudflareClient('tok', $transport))->getSetting(Z1, 'development_mode');
        check(false, 'non-JSON exception thrown');
    } catch (CloudflareException $e) {
        check(str_contains($e->getMessage(), 'HTTP 502'), 'non-JSON body -> HTTP status message');
    }

    $transport = new FakeTransport(fn () => ['status' => 200, 'body' => '{"success":false,"errors":[{"code":1003,"message":"Invalid zone","error_chain":[{"code":7003,"message":"No route"}]}]}']);
    try {
        (new CloudflareClient('tok', $transport))->purgeEverything(Z1);
        check(false, 'success=false exception thrown');
    } catch (CloudflareException $e) {
        check(str_contains($e->getMessage(), 'Invalid zone') && str_contains($e->getMessage(), 'No route'), 'HTTP 200 with success=false + error_chain');
    }

    try {
        (new CloudflareClient('tok', new FakeTransport()))->purgeEverything('../../user');
        check(false, 'invalid zone id rejected');
    } catch (CloudflareException $e) {
        check(str_contains($e->getMessage(), 'Invalid Cloudflare zone ID'), 'invalid zone id rejected before request');
    }
});

test('Token verification + account-token fallback', function (): void {
    $transport = new FakeTransport(fn () => ok(['id' => 't', 'status' => 'active', 'expires_on' => '2030-01-01T00:00:00Z']));
    $r = (new CloudflareClient('tok', $transport))->verifyToken();
    check($r['valid'] && $r['status'] === 'active' && $r['expires_on'] === '2030-01-01T00:00:00Z', 'user token active');

    $transport = new FakeTransport(function (string $m, string $url): array {
        if (str_contains($url, '/user/tokens/verify')) {
            return ['status' => 401, 'body' => '{"success":false,"errors":[{"code":1000,"message":"Invalid API Token"}]}'];
        }

        return ok([], ['total_pages' => 1]);
    });
    $r = (new CloudflareClient('tok', $transport))->verifyToken();
    check($r['valid'] && count($transport->calls) === 2, 'account token valid via /zones fallback');

    $transport = new FakeTransport(fn () => ['status' => 401, 'body' => '{"success":false,"errors":[{"code":1000,"message":"Invalid API Token"}]}']);
    $r = (new CloudflareClient('tok', $transport))->verifyToken();
    check(!$r['valid'] && str_contains($r['message'], 'Invalid API Token'), 'invalid token reported, no exception');

    $r = (new CloudflareClient('', new FakeTransport()))->verifyToken();
    check(!$r['valid'], 'empty token invalid');
});

test('Zone settings get/set', function (): void {
    $transport = new FakeTransport(fn (string $m) => ok(['id' => 'development_mode', 'value' => $m === 'PATCH' ? 'on' : 'off']));
    $client = new CloudflareClient('tok', $transport);
    check($client->getSetting(Z1, 'development_mode') === 'off', 'get returns result.value');
    check($client->setSetting(Z1, 'development_mode', 'on') === 'on', 'set returns new value');
    check($transport->calls[1]['method'] === 'PATCH' && json_decode($transport->calls[1]['body'], true) === ['value' => 'on'], 'PATCH {"value":"on"}');
});

// ------------------------------------------------------------------ zone matcher

test('ZoneMatcher suggestions', function (): void {
    $zones = [zone('example.com', Z1), zone('shop.example.org', Z2), zone('example.org', Z3)];
    $resolver = new FakeResolver([
        1 => shopFixture(1, ['example.com', 'www.example.com']),        // apex, alone in zone
        2 => shopFixture(2, ['shop.example.org']),                       // own zone = longest match
        3 => shopFixture(3, ['blog.example.org']),                       // subdomain of example.org
        4 => shopFixture(4, ['www.example.net']),                        // no zone
    ]);
    $m = new ZoneMatcher($resolver);
    $s1 = $m->suggest($zones, 1);
    check($s1 !== null && $s1['zone']['id'] === Z1 && $s1['scope'] === 'zone', 'apex shop -> zone scope');
    $s2 = $m->suggest($zones, 2);
    check($s2 !== null && $s2['zone']['id'] === Z2 && $s2['scope'] === 'zone', 'longest zone wins (shop.example.org)');
    $s3 = $m->suggest($zones, 3);
    check($s3 !== null && $s3['zone']['id'] === Z3 && $s3['scope'] === 'host', 'subdomain -> host scope');
    check($m->suggest($zones, 4) === null, 'unknown domain -> null');
    check($m->suggest($zones, 99) === null, 'unknown shop -> null');

    $resolver = new FakeResolver([1 => shopFixture(1, ['www.example.com'])]);
    $s = (new ZoneMatcher($resolver))->suggest($zones, 1);
    check($s !== null && $s['scope'] === 'zone', 'www.<apex> alone -> zone scope');

    // Two shops on the same domain, the second in a sub-folder.
    $resolver = new FakeResolver([
        1 => shopFixture(1, ['www.example.com']),
        2 => shopFixture(2, ['www.example.com'], '/shop2/'),
    ]);
    $m = new ZoneMatcher($resolver);
    check($m->suggest($zones, 2)['scope'] === 'prefix', 'sub-folder shop -> prefix scope');
    check($m->suggest($zones, 1)['scope'] === 'host', 'shared zone (main shop) -> host scope');

    // Two shops on different hosts of one zone.
    $resolver = new FakeResolver([
        1 => shopFixture(1, ['example.com']),
        2 => shopFixture(2, ['b2b.example.com']),
    ]);
    $m = new ZoneMatcher($resolver);
    check($m->suggest($zones, 1)['scope'] === 'host' && $m->suggest($zones, 2)['scope'] === 'host', 'shared zone -> host scope for both');
    check(count($m->suggestAll($zones)) === 2, 'suggestAll');

    // Alias host matches when the main host does not; port stripped.
    $resolver = new FakeResolver([1 => shopFixture(1, ['localhost', 'example.com'])]);
    check((new ZoneMatcher($resolver))->suggest($zones, 1)['zone']['id'] === Z1, 'alias domain used when main does not match');
    check(ZoneMatcher::hostBelongsToZone('www.example.com:8080', 'example.com'), 'port stripped');
    check(!ZoneMatcher::hostBelongsToZone('badexample.com', 'example.com'), 'no false suffix match');
});

// ------------------------------------------------------------------ purge service

function service(FakeTransport $transport, array $mappings, array $shops, FakeLog $log): PurgeService
{
    return new PurgeService(new CloudflareClient('tok', $transport), new FakeZoneRepository($mappings), new FakeResolver($shops), $log);
}

test('PurgeService scopes, dedup across shops and logging', function (): void {
    $transport = new FakeTransport(fn () => ok());
    $log = new FakeLog();
    $shops = [
        1 => shopFixture(1, ['www.example.com']),
        2 => shopFixture(2, ['www.example.com'], '/shop2/'),
        3 => shopFixture(3, ['shop.example.org']),
        4 => shopFixture(4, ['example.net']),
        5 => shopFixture(5, ['www.example.com'], '/shop5/'),
    ];
    $mappings = [
        1 => new ShopZoneMapping(1, Z1, 'example.com', 'zone'),
        2 => new ShopZoneMapping(2, Z1, 'example.com', 'zone'),
        3 => new ShopZoneMapping(3, Z3, 'example.org', 'host'),
        4 => new ShopZoneMapping(4, Z2, 'example.net', 'prefix'),
    ];
    $results = service($transport, $mappings, $shops, $log)->purgeAllShops('test', true);
    check(count($results) === 3 && count($transport->calls) === 3, 'two shops sharing a zone-scope zone -> 1 call (3 total)');
    $bodies = array_map(fn ($c) => json_decode($c['body'], true), $transport->calls);
    check($bodies[0] === ['purge_everything' => true], 'zone scope -> purge_everything');
    check($bodies[1] === ['hosts' => ['shop.example.org']], 'host scope -> hosts');
    check($bodies[2] === ['prefixes' => ['example.net/']], 'prefix scope -> prefixes');
    check(count($log->rows) === 3 && $log->rows[0]['success'] && $log->rows[0]['idShop'] === null, 'one log row per API call; shared zone logged without shop');

    // host/prefix shops inside a zone that is purged entirely are skipped
    $transport = new FakeTransport(fn () => ok());
    $mappings = [1 => new ShopZoneMapping(1, Z1, 'example.com', 'zone'), 5 => new ShopZoneMapping(5, Z1, 'example.com', 'prefix')];
    service($transport, $mappings, $shops, new FakeLog())->purgeAllShops('test', true);
    check(count($transport->calls) === 1, 'prefix shop inside fully purged zone skipped');

    // host-scope shops of one zone -> one hosts call
    $transport = new FakeTransport(fn () => ok());
    $log = new FakeLog();
    $shops3 = [1 => shopFixture(1, ['example.com']), 2 => shopFixture(2, ['b2b.example.com']), 3 => shopFixture(3, ['shop.example.com', 'example.com'])];
    $mappings = [
        1 => new ShopZoneMapping(1, Z1, 'example.com', 'host'),
        2 => new ShopZoneMapping(2, Z1, 'example.com', 'host'),
        3 => new ShopZoneMapping(3, Z1, 'example.com', 'host'),
    ];
    service($transport, $mappings, $shops3, $log)->purgeAllShops('test', true);
    check(count($transport->calls) === 1, 'three host-scope shops in one zone -> 1 call');
    check(json_decode($transport->calls[0]['body'], true) === ['hosts' => ['example.com', 'b2b.example.com', 'shop.example.com']], 'hosts merged and deduped');
    check($log->rows[0]['idShop'] === null, 'merged purge logged without shop');
});

test('PurgeService debounce', function (): void {
    \Configuration::$values[Settings::DEBOUNCE_SECONDS] = '30';
    $transport = new FakeTransport(fn () => ok());
    $mappings = [1 => new ShopZoneMapping(1, Z1, 'example.com', 'zone')];
    $shops = [1 => shopFixture(1, ['example.com'])];
    $svc = service($transport, $mappings, $shops, new FakeLog());
    $a = $svc->purgeShop(1, 'hook:actionClearCompileCache');
    $b = $svc->purgeShop(1, 'hook:actionClearSf2Cache');
    check($a->success && $a->action === 'purge_everything', 'first purge executed');
    check($b->success && $b->action === 'skipped', 'second purge within 30s skipped');
    check(count($transport->calls) === 1, 'only one API call');
    $c = $svc->purgeShop(1, 'admin', true);
    check($c->action === 'purge_everything' && count($transport->calls) === 2, 'force bypasses debounce');

    // failures are not remembered
    \Configuration::reset();
    $failing = new FakeTransport(fn () => ['status' => 500, 'body' => '{"success":false,"errors":[{"code":1,"message":"boom"}]}']);
    $svc = service($failing, $mappings, $shops, $log = new FakeLog());
    $svc->purgeShop(1, 't');
    $svc->purgeShop(1, 't');
    check(count($failing->calls) === 2 && !$log->rows[0]['success'], 'failed purge is retried and logged as failure');
    check(str_contains($log->rows[0]['message'], 'boom'), 'failure message logged');

    \Configuration::$values[Settings::DEBOUNCE_SECONDS] = '0';
    $transport = new FakeTransport(fn () => ok());
    $svc = service($transport, $mappings, $shops, new FakeLog());
    $svc->purgeShop(1, 't');
    $svc->purgeShop(1, 't');
    check(count($transport->calls) === 2, 'debounce 0 disables skipping');
});

test('PurgeService URL purge filters foreign hosts and chunks', function (): void {
    $transport = new FakeTransport(fn () => ok());
    $log = new FakeLog();
    $mappings = [1 => new ShopZoneMapping(1, Z1, 'example.com', 'zone')];
    $svc = service($transport, $mappings, [1 => shopFixture(1, ['www.example.com'])], $log);
    $urls = ['https://evil.example.org/x', 'not a url'];
    for ($i = 0; $i < 40; ++$i) {
        $urls[] = "https://www.example.com/item-$i";
    }
    $r = $svc->purgeUrls(1, $urls, 'product');
    check($r->success && $r->count === 40, '40 valid URLs purged');
    check(count($transport->calls) === 2, '40 URLs -> 2 calls');
    check(str_contains($r->message, '2 URL(s) outside'), 'ignored URLs reported');
    check(count($log->rows) === 1 && count($log->rows[0]['items']) === 40, 'one log row with items');

    $r = $svc->purgeUrls(7, ['https://www.example.com/'], 'product');
    check(!$r->success && str_contains($r->message, 'not linked'), 'unmapped shop -> failure');

    $noToken = new PurgeService(null, new FakeZoneRepository($mappings), new FakeResolver([]), new FakeLog());
    check(!$noToken->purgeShop(1, 't')->success && $noToken->purgeAllShops('t')[0]->message === 'API token is not configured.', 'no token -> failed result');
});

test('PurgeService custom purge', function (): void {
    $transport = new FakeTransport(fn () => ok());
    $svc = service($transport, [], [], new FakeLog());
    check($svc->purgeCustom(Z1, 'tags', ['a', 'b', 'a', ' '], 'admin')->success, 'tags purge');
    check(json_decode($transport->calls[0]['body'], true) === ['tags' => ['a', 'b']], 'tags deduped/trimmed');
    check(!$svc->purgeCustom(Z1, 'files', ['/relative'], 'admin')->success, 'relative URL rejected');
    check(!$svc->purgeCustom(Z1, 'nope', ['x'], 'admin')->success, 'unknown type rejected');
    check(!$svc->purgeCustom(Z1, 'hosts', [], 'admin')->success, 'empty list rejected');
});

// ------------------------------------------------------------------ queue

test('PurgeQueue dedup', function (): void {
    $rec = new RecordingService();
    PurgeQueue::setService($rec);
    PurgeQueue::addUrls(1, ['https://www.example.com/a', 'https://www.example.com/b'], 'product');
    PurgeQueue::addUrls(1, ['https://www.example.com/a'], 'product');
    PurgeQueue::addUrls(2, ['https://shop.example.org/c'], 'category');
    PurgeQueue::addFullPurge(2, 'admin');
    PurgeQueue::addUrls(2, ['https://shop.example.org/d'], 'category');
    PurgeQueue::flush();
    check($rec->calls === [
        ['shop', 2, 'admin'],
        ['urls', 1, ['https://www.example.com/a', 'https://www.example.com/b'], 'product'],
    ], 'URLs deduped; full shop purge drops its URLs');

    $rec->calls = [];
    PurgeQueue::addUrls(1, ['https://www.example.com/a'], 'product');
    PurgeQueue::addFullPurgeAllShops('hook:actionClearCompileCache');
    PurgeQueue::addFullPurgeAllShops('hook:actionClearSf2Cache');
    PurgeQueue::addFullPurge(3, 'x');
    PurgeQueue::flush();
    check($rec->calls === [['all', 'hook:actionClearCompileCache']], 'both cache hooks -> one all-shops purge, rest dropped');

    $rec->calls = [];
    check(PurgeQueue::flush() === [] && $rec->calls === [] && PurgeQueue::isEmpty(), 'second flush is a no-op');
});

test('Both cache-clear hooks in one click -> one purge per zone (queue + debounce)', function (): void {
    \Configuration::$values[Settings::DEBOUNCE_SECONDS] = '30';
    $transport = new FakeTransport(fn () => ok());
    $mappings = [
        1 => new ShopZoneMapping(1, Z1, 'example.com', 'zone'),
        2 => new ShopZoneMapping(2, Z2, 'example.net', 'zone'),
    ];
    $shops = [1 => shopFixture(1, ['example.com']), 2 => shopFixture(2, ['example.net'])];
    PurgeQueue::setService(service($transport, $mappings, $shops, new FakeLog()));

    // actionClearCompileCache during the request, queue flushed ...
    PurgeQueue::addFullPurgeAllShops('hook:actionClearCompileCache');
    PurgeQueue::flush();
    // ... then actionClearSf2Cache from the later shutdown function.
    PurgeQueue::addFullPurgeAllShops('hook:actionClearSf2Cache');
    $second = PurgeQueue::flush();
    check(count($transport->calls) === 2, 'exactly one API call per zone');
    check(count($second) === 2 && $second[0]->action === 'skipped' && $second[1]->action === 'skipped', 'late hook debounced');
});

test('Rename: before-update queues old URL, after-update the new one', function (): void {
    \Configuration::$values[Settings::API_TOKEN] = 'encrypted';
    $collector = new RenamingCollector();
    $zones = new FakeZoneRepository([1 => new ShopZoneMapping(1, Z1, 'example.com', 'zone')]);
    $handler = new HookHandler($collector, $zones);
    $rec = new RecordingService();
    PurgeQueue::setService($rec);

    EntityUrlCollector::resetCache();
    $handler->onEntityBeforeUpdate('category', 5); // DB still has the old link_rewrite
    $collector->slug = 'new-name';                 // ObjectModel::update() writes the new one
    $handler->onEntityChange('category', 5);       // after-update must NOT reuse the cached old URL

    PurgeQueue::flush();
    $urls = $rec->calls[0][2] ?? [];
    check(in_array('https://www.example.com/5-old-name', $urls, true), 'old URL queued');
    check(in_array('https://www.example.com/5-new-name', $urls, true), 'new URL queued');
    check(count($rec->calls) === 1, 'one purge call for both');

    // entity toggle respected in the before-phase
    $rec->calls = [];
    \Configuration::$values[Settings::PURGE_SUPPLIER] = '0';
    $handler->onEntityBeforeUpdate('supplier', 9);
    check(PurgeQueue::isEmpty(), 'disabled entity -> nothing queued');
    PurgeQueue::reset();
});

test('EntityUrlCollector::forget drops only that entity', function (): void {
    $prop = new ReflectionProperty(EntityUrlCollector::class, 'cache');
    $prop->setValue(null, ['category:5' => [1 => ['a']], 'product:5' => [1 => ['b']]]);
    EntityUrlCollector::forget('category', 5);
    check(array_keys($prop->getValue()) === ['product:5'], 'category:5 forgotten, product:5 kept');
    EntityUrlCollector::resetCache();
});

// ------------------------------------------------------------------ settings / resolver helpers

test('Settings and helpers', function (): void {
    check(Settings::getCacheClearMode() === 'all' && Settings::isEnabled(), 'defaults when unset');
    Settings::setCacheClearMode('bogus');
    check(Settings::getCacheClearMode() === 'all', 'invalid mode falls back to all');
    Settings::setDebounceSeconds(99999);
    check(Settings::getDebounceSeconds() === 3600, 'debounce clamped');
    check(Settings::isEntityPurgeEnabled('product') && !Settings::isEntityPurgeEnabled('supplier') && !Settings::isEntityPurgeEnabled('x'), 'entity switches');
    Settings::setCachedZones([zone('example.com', Z1)]);
    check(Settings::findCachedZone(Z1)['name'] === 'example.com' && Settings::getZonesFetchedAt() !== '', 'zone cache');
    Settings::rememberFullPurge('old', time() - 90000);
    Settings::rememberFullPurge('new');
    check(array_keys(Settings::getLastFullPurges()) === ['new'], 'debounce memory pruned');
    check(ShopDomainResolver::normalizeUri('shop//en') === '/shop/en/' && ShopDomainResolver::normalizeUri('/') === '/', 'uri normalization');
    check((new ShopZoneMapping(1, Z1, 'Example.COM', 'weird'))->purgeScope === 'zone', 'invalid scope -> zone, name lowercased');
});

echo "\n" . ($failed === 0 ? 'ALL PASSED' : 'FAILURES') . ": $passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
