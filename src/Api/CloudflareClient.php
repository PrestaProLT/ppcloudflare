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

use PrestaShop\Module\PpCloudflare\Config\Settings;

/**
 * Minimal Cloudflare API v4 client (API Tokens only).
 */
class CloudflareClient
{
    public const BASE_URL = 'https://api.cloudflare.com/client/v4';

    /** Maximum items per purge_cache call (files, hosts, prefixes, tags). */
    public const PURGE_BATCH = 30;

    private const ZONES_PER_PAGE = 50;
    private const MAX_ZONE_PAGES = 200;

    private string $token;
    private HttpTransportInterface $transport;
    private int $timeout;

    public function __construct(string $token, ?HttpTransportInterface $transport = null, int $timeout = 15)
    {
        $this->token = trim($token);
        $this->transport = $transport ?? new CurlTransport();
        $this->timeout = $timeout;
    }

    /**
     * Client built from the stored (decrypted) token, null when no token is configured.
     */
    public static function fromSettings(int $timeout = 15): ?self
    {
        $token = Settings::getToken();

        return $token === '' ? null : new self($token, null, $timeout);
    }

    /**
     * Checks the token. User-owned tokens are verified with /user/tokens/verify; account-owned
     * tokens fail there, so a zone listing is tried as a fallback.
     *
     * @return array{valid:bool, status:string, expires_on:?string, message:string}
     */
    public function verifyToken(): array
    {
        try {
            $result = $this->call('GET', '/user/tokens/verify')['result'] ?? [];
            $status = is_array($result) ? (string) ($result['status'] ?? '') : '';
            $expires = is_array($result) && !empty($result['expires_on']) ? (string) $result['expires_on'] : null;

            return [
                'valid' => $status === 'active',
                'status' => $status !== '' ? $status : 'unknown',
                'expires_on' => $expires,
                'message' => $status === 'active'
                    ? 'The API token is valid and active.'
                    : sprintf('The API token is not active (status: %s).', $status !== '' ? $status : 'unknown'),
            ];
        } catch (CloudflareException $userTokenError) {
            if ($userTokenError->getHttpStatus() === 0 || $userTokenError->getHttpStatus() === 429) {
                return [
                    'valid' => false,
                    'status' => 'error',
                    'expires_on' => null,
                    'message' => $userTokenError->getMessage(),
                ];
            }
        }

        try {
            $this->call('GET', '/zones', ['per_page' => 1]);

            return [
                'valid' => true,
                'status' => 'active',
                'expires_on' => null,
                'message' => 'The API token is valid (account-owned token).',
            ];
        } catch (CloudflareException $e) {
            return [
                'valid' => false,
                'status' => 'invalid',
                'expires_on' => null,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * All zones the token can read (follows pagination).
     *
     * @return array<int, array{id:string, name:string, status:string, plan:string, account_id:string, account_name:string, paused:bool}>
     */
    public function listZones(): array
    {
        $zones = [];
        $page = 1;
        do {
            $response = $this->call('GET', '/zones', ['per_page' => self::ZONES_PER_PAGE, 'page' => $page]);
            foreach ((array) ($response['result'] ?? []) as $zone) {
                if (!is_array($zone) || empty($zone['id'])) {
                    continue;
                }
                $zones[] = [
                    'id' => (string) $zone['id'],
                    'name' => strtolower((string) ($zone['name'] ?? '')),
                    'status' => (string) ($zone['status'] ?? ''),
                    'plan' => (string) ($zone['plan']['name'] ?? ''),
                    'account_id' => (string) ($zone['account']['id'] ?? ''),
                    'account_name' => (string) ($zone['account']['name'] ?? ''),
                    'paused' => (bool) ($zone['paused'] ?? false),
                ];
            }
            $totalPages = (int) ($response['result_info']['total_pages'] ?? 1);
            ++$page;
        } while ($page <= $totalPages && $page <= self::MAX_ZONE_PAGES);

        usort($zones, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return $zones;
    }

    public function purgeEverything(string $zoneId): void
    {
        $this->call('POST', $this->zonePath($zoneId) . '/purge_cache', [], ['purge_everything' => true]);
    }

    /**
     * @param string[] $urls absolute URLs
     */
    public function purgeFiles(string $zoneId, array $urls): void
    {
        $this->purgeBatched($zoneId, 'files', $urls);
    }

    /**
     * @param string[] $hosts hostnames
     */
    public function purgeHosts(string $zoneId, array $hosts): void
    {
        $this->purgeBatched($zoneId, 'hosts', array_map('strtolower', $hosts));
    }

    /**
     * @param string[] $prefixes "host/path" without scheme
     */
    public function purgePrefixes(string $zoneId, array $prefixes): void
    {
        $clean = array_map(
            static fn (string $p): string => (string) preg_replace('#^https?://#i', '', trim($p)),
            $prefixes
        );
        $this->purgeBatched($zoneId, 'prefixes', $clean);
    }

    /**
     * @param string[] $tags cache tags
     */
    public function purgeTags(string $zoneId, array $tags): void
    {
        $this->purgeBatched($zoneId, 'tags', $tags);
    }

    /**
     * Value of a zone setting (`result.value`), e.g. development_mode → "on"|"off".
     */
    public function getSetting(string $zoneId, string $name): mixed
    {
        $response = $this->call('GET', $this->zonePath($zoneId) . '/settings/' . $this->settingName($name));

        return $response['result']['value'] ?? null;
    }

    /**
     * Updates a zone setting and returns the new value.
     */
    public function setSetting(string $zoneId, string $name, mixed $value): mixed
    {
        $response = $this->call(
            'PATCH',
            $this->zonePath($zoneId) . '/settings/' . $this->settingName($name),
            [],
            ['value' => $value]
        );

        return $response['result']['value'] ?? null;
    }

    /**
     * @param string[] $items
     */
    private function purgeBatched(string $zoneId, string $field, array $items): void
    {
        $items = array_values(array_unique(array_filter(
            array_map(static fn ($i): string => trim((string) $i), $items),
            static fn (string $i): bool => $i !== ''
        )));
        if ($items === []) {
            return;
        }
        $path = $this->zonePath($zoneId) . '/purge_cache';
        foreach (array_chunk($items, self::PURGE_BATCH) as $chunk) {
            $this->call('POST', $path, [], [$field => $chunk]);
        }
    }

    private function zonePath(string $zoneId): string
    {
        $zoneId = trim($zoneId);
        if (!preg_match('/^[a-f0-9]{32}$/i', $zoneId)) {
            throw new CloudflareException('Invalid Cloudflare zone ID.');
        }

        return '/zones/' . $zoneId;
    }

    private function settingName(string $name): string
    {
        if (!preg_match('/^[a-z0-9_]+$/', $name)) {
            throw new CloudflareException('Invalid Cloudflare setting name.');
        }

        return $name;
    }

    /**
     * Performs a request and returns the decoded envelope.
     *
     * @param array<string, scalar> $query
     *
     * @throws CloudflareException
     */
    private function call(string $method, string $path, array $query = [], ?array $payload = null): array
    {
        if ($this->token === '') {
            throw new CloudflareException('The Cloudflare API token is not configured.');
        }

        $url = self::BASE_URL . $path . ($query !== [] ? '?' . http_build_query($query) : '');
        $headers = [
            'Authorization' => 'Bearer ' . $this->token,
            'Accept' => 'application/json',
        ];
        $body = null;
        if ($payload !== null) {
            $headers['Content-Type'] = 'application/json';
            $body = (string) json_encode($payload, JSON_UNESCAPED_SLASHES);
        }

        $response = $this->transport->request($method, $url, $headers, $body, $this->timeout);
        $status = (int) $response['status'];
        $decoded = json_decode((string) $response['body'], true);

        if ($status === 429) {
            throw new CloudflareException(
                'Cloudflare rate limit reached (HTTP 429). Too many API requests; wait a few minutes and try again.',
                429,
                is_array($decoded) ? self::parseErrors($decoded) : []
            );
        }

        if (!is_array($decoded)) {
            throw new CloudflareException(sprintf('Unexpected response from Cloudflare (HTTP %d).', $status), $status);
        }

        if ($status >= 400 || empty($decoded['success'])) {
            $errors = self::parseErrors($decoded);
            throw new CloudflareException(self::errorMessage($errors, $status), $status, $errors);
        }

        return $decoded;
    }

    /**
     * @return array<int, array{code:int|null, message:string}>
     */
    private static function parseErrors(array $decoded): array
    {
        $errors = [];
        foreach ((array) ($decoded['errors'] ?? []) as $error) {
            if (!is_array($error)) {
                continue;
            }
            $message = trim((string) ($error['message'] ?? ''));
            foreach ((array) ($error['error_chain'] ?? []) as $chained) {
                if (is_array($chained) && !empty($chained['message'])) {
                    $message .= ' (' . trim((string) $chained['message']) . ')';
                }
            }
            $errors[] = [
                'code' => isset($error['code']) ? (int) $error['code'] : null,
                'message' => $message,
            ];
        }

        return $errors;
    }

    /**
     * @param array<int, array{code:int|null, message:string}> $errors
     */
    private static function errorMessage(array $errors, int $status): string
    {
        $parts = [];
        foreach ($errors as $error) {
            if ($error['message'] === '') {
                continue;
            }
            $parts[] = $error['message'] . ($error['code'] !== null ? ' [code ' . $error['code'] . ']' : '');
        }
        $message = $parts !== []
            ? 'Cloudflare API error: ' . implode('; ', $parts)
            : sprintf('Cloudflare API request failed (HTTP %d).', $status);

        if ($status === 401 || $status === 403) {
            $message .= ' Check that the API token is valid and has the required permissions.';
        }

        return $message;
    }
}
