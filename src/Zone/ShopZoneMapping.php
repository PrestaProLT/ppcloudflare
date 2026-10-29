<?php
/**
 * Cloudflare integration for PrestaShop 9.
 *
 * @author    PrestaPro
 * @copyright PrestaPro
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */
declare(strict_types=1);
namespace PrestaShop\Module\PpCloudflare\Zone;

/**
 * Link between one shop and its Cloudflare zone.
 */
final class ShopZoneMapping
{
    public const SCOPE_ZONE = 'zone';
    public const SCOPE_HOST = 'host';
    public const SCOPE_PREFIX = 'prefix';
    public const SCOPES = [self::SCOPE_ZONE, self::SCOPE_HOST, self::SCOPE_PREFIX];

    public readonly int $idShop;
    public readonly string $zoneId;
    public readonly string $zoneName;
    public readonly string $purgeScope;

    public function __construct(int $idShop, string $zoneId, string $zoneName, string $purgeScope = self::SCOPE_ZONE)
    {
        $this->idShop = $idShop;
        $this->zoneId = trim($zoneId);
        $this->zoneName = strtolower(trim($zoneName));
        $this->purgeScope = in_array($purgeScope, self::SCOPES, true) ? $purgeScope : self::SCOPE_ZONE;
    }
}
