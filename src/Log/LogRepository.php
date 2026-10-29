<?php
/**
 * Cloudflare integration for PrestaShop 9.
 *
 * @author    PrestaPro
 * @copyright PrestaPro
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */
declare(strict_types=1);
namespace PrestaShop\Module\PpCloudflare\Log;

use PrestaShop\Module\PpCloudflare\Config\Settings;

/**
 * Purge log (`ppcloudflare_log`). Messages are plain English; the API token is never logged.
 */
class LogRepository
{
    public const TABLE = 'ppcloudflare_log';

    /** Maximum number of items stored per row. */
    public const MAX_ITEMS = 50;

    public function add(
        ?int $idShop,
        string $zoneId,
        string $action,
        string $trigger,
        array $items,
        bool $success,
        string $message
    ): void {
        $items = array_values(array_map('strval', $items));
        $total = count($items);
        if ($total > self::MAX_ITEMS) {
            $items = array_slice($items, 0, self::MAX_ITEMS);
            $items[] = sprintf('... and %d more', $total - self::MAX_ITEMS);
        }

        $context = \Context::getContext();
        $idEmployee = isset($context->employee) && $context->employee && (int) $context->employee->id > 0
            ? (int) $context->employee->id
            : null;

        \Db::getInstance()->insert(self::TABLE, [
            'id_shop' => $idShop !== null && $idShop > 0 ? $idShop : null,
            'zone_id' => pSQL(substr($zoneId, 0, 32)),
            'action' => pSQL(substr($action, 0, 32)),
            'trigger_source' => pSQL(substr($trigger, 0, 64)),
            'items' => $items === [] ? null : pSQL((string) json_encode($items, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), true),
            'success' => $success ? 1 : 0,
            'message' => pSQL(mb_substr($message, 0, 2000), true),
            'id_employee' => $idEmployee,
            'date_add' => date('Y-m-d H:i:s'),
        ], true);

        $this->pruneDaily();
    }

    /**
     * @param array{success?:int|string|bool|null, id_shop?:int|string|null, action?:string|null} $filters
     *
     * @return array{rows:array<int, array>, total:int}
     */
    public function paginate(int $page, int $perPage, array $filters = []): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(500, $perPage));
        $where = $this->buildWhere($filters);
        $db = \Db::getInstance();

        $total = (int) $db->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . self::TABLE . '` l' . $where, false);
        $rows = $db->executeS(
            'SELECT l.*, s.`name` AS shop_name, CONCAT(e.`firstname`, \' \', e.`lastname`) AS employee_name
            FROM `' . _DB_PREFIX_ . self::TABLE . '` l
            LEFT JOIN `' . _DB_PREFIX_ . 'shop` s ON s.`id_shop` = l.`id_shop`
            LEFT JOIN `' . _DB_PREFIX_ . 'employee` e ON e.`id_employee` = l.`id_employee`'
            . $where . '
            ORDER BY l.`id_log` DESC
            LIMIT ' . (int) (($page - 1) * $perPage) . ', ' . (int) $perPage,
            true,
            false
        );

        $result = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $items = $row['items'] !== null ? json_decode((string) $row['items'], true) : [];
            $row['items'] = is_array($items) ? $items : [];
            $row['id_log'] = (int) $row['id_log'];
            $row['id_shop'] = $row['id_shop'] !== null ? (int) $row['id_shop'] : null;
            $row['id_employee'] = $row['id_employee'] !== null ? (int) $row['id_employee'] : null;
            $row['success'] = (bool) $row['success'];
            $row['employee_name'] = $row['employee_name'] !== null ? trim((string) $row['employee_name']) : '';
            $row['shop_name'] = (string) ($row['shop_name'] ?? '');
            $result[] = $row;
        }

        return ['rows' => $result, 'total' => $total];
    }

    /**
     * Distinct action names present in the log (for filters).
     *
     * @return string[]
     */
    public function getActions(): array
    {
        $rows = \Db::getInstance()->executeS(
            'SELECT DISTINCT `action` FROM `' . _DB_PREFIX_ . self::TABLE . '` ORDER BY `action`',
            true,
            false
        );

        return array_map(static fn (array $r): string => (string) $r['action'], is_array($rows) ? $rows : []);
    }

    public function deleteOlderThan(int $days): int
    {
        $db = \Db::getInstance();
        $db->delete(
            self::TABLE,
            "`date_add` < '" . pSQL(date('Y-m-d H:i:s', time() - max(1, $days) * 86400)) . "'"
        );

        return (int) $db->Affected_Rows();
    }

    public function clear(): void
    {
        \Db::getInstance()->execute('TRUNCATE TABLE `' . _DB_PREFIX_ . self::TABLE . '`');
    }

    private function pruneDaily(): void
    {
        $today = date('Y-m-d');
        if (Settings::get(Settings::LOG_PRUNED_AT) === $today) {
            return;
        }
        Settings::set(Settings::LOG_PRUNED_AT, $today);
        $this->deleteOlderThan(Settings::getLogRetentionDays());
    }

    private function buildWhere(array $filters): string
    {
        $conditions = [];
        if (isset($filters['success']) && $filters['success'] !== '' && $filters['success'] !== null) {
            $conditions[] = 'l.`success` = ' . ((int) (bool) $filters['success']);
        }
        if (isset($filters['id_shop']) && $filters['id_shop'] !== '' && $filters['id_shop'] !== null) {
            $conditions[] = 'l.`id_shop` = ' . (int) $filters['id_shop'];
        }
        if (!empty($filters['action'])) {
            $conditions[] = "l.`action` = '" . pSQL((string) $filters['action']) . "'";
        }

        return $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions);
    }
}
