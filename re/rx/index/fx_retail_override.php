<?php
/**
 * Faoxima Reseller Retail Price Override
 * Allows resellers to set their own retail price per product.
 * Table: fx_retail_prices (product_id, reseller_id, retail_price)
 */

if (!defined('FX_RETAIL_OVERRIDE_LOADED')) {
    define('FX_RETAIL_OVERRIDE_LOADED', true);

    /**
     * FX-RESELLER (2026-10-16): SQL fragment for "products a reseller's
     * customers can actually see/buy". Union of:
     *   - rows exclusive to this reseller (agent CSV contains the rid)
     *   - shared rows ('all','allusers')
     *   - main customer groups ('f','n','n2' — the only groups the buy flow
     *     accepts, see rx_product_allows_agent)
     *   - rows without an agent
     * $ridParam must be bound exactly once by the caller.
     */
    function fx_res_product_scope_sql(string $alias = 'p', string $ridParam = ':rid'): string {
        $a = preg_replace('/[^A-Za-z0-9_]/', '', (string)$alias);
        if ($a === '') { $a = 'p'; }
        $param = preg_match('/^:[A-Za-z0-9_]+$/', (string)$ridParam) ? (string)$ridParam : ':rid';
        return "(FIND_IN_SET({$param}, REPLACE({$a}.agent, ' ', '')) OR {$a}.agent IN ('all', 'allusers', 'f', 'n', 'n2') OR {$a}.agent = '')";
    }

    function fx_apply_retail_override(array &$product): void {
        if (!defined('FX_RESELLER_ID') || !FX_RESELLER_ID) return;
        if (empty($product['id'])) return;
        global $pdo;
        if (!$pdo) return;
        try {
            $stmt = $pdo->prepare("SELECT retail_price FROM fx_retail_prices WHERE product_id = :pid AND reseller_id = :rid LIMIT 1");
            $stmt->execute([':pid' => (int)$product['id'], ':rid' => (int)FX_RESELLER_ID]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row && isset($row['retail_price']) && (int)$row['retail_price'] > 0) {
                $product['price_product'] = (int)$row['retail_price'];
                $product['fx_retail_applied'] = true;
            }
        } catch (\Throwable $e) {}
    }

    function fx_set_retail_price(int $productId, int $resellerId, int $price): bool {
        global $pdo;
        if (!$pdo) return false;
        try {
            $sql = "INSERT INTO fx_retail_prices (product_id, reseller_id, retail_price) VALUES (:pid, :rid, :price) ON DUPLICATE KEY UPDATE retail_price = :price2";
            $stmt = $pdo->prepare($sql);
            return $stmt->execute([':pid' => $productId, ':rid' => $resellerId, ':price' => $price, ':price2' => $price]);
        } catch (\Throwable $e) { return false; }
    }

    function fx_get_retail_price(int $productId, int $resellerId): ?int {
        global $pdo;
        if (!$pdo) return null;
        try {
            $stmt = $pdo->prepare("SELECT retail_price FROM fx_retail_prices WHERE product_id = :pid AND reseller_id = :rid LIMIT 1");
            $stmt->execute([':pid' => $productId, ':rid' => $resellerId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ? (int)$row['retail_price'] : null;
        } catch (\Throwable $e) { return null; }
    }

    function fx_delete_retail_price(int $productId, int $resellerId): bool {
        global $pdo;
        if (!$pdo) return false;
        try {
            $stmt = $pdo->prepare("DELETE FROM fx_retail_prices WHERE product_id = :pid AND reseller_id = :rid");
            return $stmt->execute([':pid' => $productId, ':rid' => $resellerId]);
        } catch (\Throwable $e) { return false; }
    }

    function fx_get_all_retail_prices(int $resellerId): array {
        global $pdo;
        if (!$pdo) return [];
        try {
            $scope = function_exists('fx_res_product_scope_sql')
                ? fx_res_product_scope_sql('p', ':rid2')
                : "(FIND_IN_SET(:rid2, REPLACE(p.agent, ' ', '')) OR p.agent IN ('all', 'allusers', 'f', 'n', 'n2') OR p.agent = '')";
            $sql = "SELECT p.id, p.name_product, p.price_product AS base_price, p.fx_wholesale_price AS wholesale, r.retail_price FROM product p LEFT JOIN fx_retail_prices r ON p.id = r.product_id AND r.reseller_id = :rid WHERE {$scope}";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':rid' => $resellerId, ':rid2' => (string)$resellerId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) { return []; }
    }
}
