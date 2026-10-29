<?php
/**
 * Cloudflare integration for PrestaShop 9.
 *
 * @author    PrestaPro
 * @copyright PrestaPro
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */
declare(strict_types=1);
namespace PrestaShop\Module\PpCloudflare\Purge;

/**
 * Outcome of one purge operation.
 */
final class PurgeResult
{
    public const ACTION_EVERYTHING = 'purge_everything';
    public const ACTION_FILES = 'purge_files';
    public const ACTION_HOSTS = 'purge_hosts';
    public const ACTION_PREFIXES = 'purge_prefixes';
    public const ACTION_TAGS = 'purge_tags';
    public const ACTION_SKIPPED = 'skipped';
    public const ACTION_NONE = 'none';

    public readonly bool $success;
    public readonly string $zoneId;
    public readonly string $action;
    public readonly string $message;
    public readonly int $count;
    public readonly ?int $idShop;

    public function __construct(
        bool $success,
        string $zoneId,
        string $action,
        string $message,
        int $count = 0,
        ?int $idShop = null
    ) {
        $this->success = $success;
        $this->zoneId = $zoneId;
        $this->action = $action;
        $this->message = $message;
        $this->count = $count;
        $this->idShop = $idShop;
    }

    public static function failure(string $message, string $zoneId = '', string $action = self::ACTION_NONE, ?int $idShop = null): self
    {
        return new self(false, $zoneId, $action, $message, 0, $idShop);
    }

    /**
     * @return array{success:bool, zone_id:string, action:string, message:string, count:int, id_shop:?int}
     */
    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'zone_id' => $this->zoneId,
            'action' => $this->action,
            'message' => $this->message,
            'count' => $this->count,
            'id_shop' => $this->idShop,
        ];
    }
}
