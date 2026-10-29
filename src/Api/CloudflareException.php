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

/**
 * Error returned by the Cloudflare API or raised by the transport.
 * Messages never contain the API token.
 */
class CloudflareException extends \RuntimeException
{
    private int $httpStatus;

    /** @var array<int, array{code:int|null, message:string}> */
    private array $errors;

    /**
     * @param array<int, array{code:int|null, message:string}> $errors
     */
    public function __construct(string $message, int $httpStatus = 0, array $errors = [], ?\Throwable $previous = null)
    {
        parent::__construct($message, $httpStatus, $previous);
        $this->httpStatus = $httpStatus;
        $this->errors = $errors;
    }

    /**
     * HTTP status of the response, 0 when no response was received.
     */
    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }

    /**
     * Cloudflare `errors[]` entries.
     *
     * @return array<int, array{code:int|null, message:string}>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Code of the first Cloudflare error, null when none.
     */
    public function getCloudflareCode(): ?int
    {
        return $this->errors[0]['code'] ?? null;
    }
}
