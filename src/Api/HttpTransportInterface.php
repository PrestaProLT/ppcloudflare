<?php
/**
 * Cloudflare integration for PrestaShop 9.
 *
 * @author    PrestaPro
 * @copyright PrestaPro
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */
declare(strict_types=1);

namespace PrestaShop\Module\PpCloudflare\Api;

interface HttpTransportInterface
{
    /**
     * Sends one HTTP request.
     *
     * @param array<string, string> $headers header name => value
     *
     * @return array{status:int, body:string}
     *
     * @throws CloudflareException on network/transport failure (no HTTP response)
     */
    public function request(string $method, string $url, array $headers, ?string $body, int $timeout): array;
}
