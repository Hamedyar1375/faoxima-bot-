<?php
/**
 * FX-RES-VISIBILITY: per-reseller product show/hide for their own customers.
 * Table: fx_res_visibility (product_id, reseller_id, hidden TINYINT)
 * hidden=1 → the product is NOT shown/sold to THIS reseller's customers.
 *
 * FX-RES-OPTIN (2026-10-25): reseller visibility is OPT-IN. A product is also
 * treated as hidden when this reseller has no positive retail price row in
 * fx_retail_prices. Newly created/synced products therefore start hidden for
 * every reseller until that reseller explicitly sets a selling price.
 *
 * Main bot and other resellers are unaffected (reseller-scoped). Filtering
 * happens in fx_res_visibility_filter_rows() called from KeyboardProduct() —
 * and purchase resolve is blocked via fx_res_visibility_blocked() checks.
 * Loaded live from pricing_fx.php (is_file-guarded require_once).
 */

if (!defined('FX_RES_VISIBILITY_LOADED')) {
    define('FX_RES_VISIBILITY_LOADED', true);

    /** True when this reseller has a positive custom selling price. */
    function fx_res_product_has_retail_price(int $pid, int $rid): bool {
        if ($pid <= 0 || $rid <= 0) return false;
        if (function_exists('fx_get_retail_price')) {
            $price = fx_get_retail_price($pid, $rid);
            return $price !== null && (int)$price > 0;
        }
        global $pdo;
        if (!($pdo instanceof PDO)) return false;
        try {
            $stmt = $pdo->prepare("SELECT retail_price FROM fx_retail_prices WHERE product_id = :pid AND reseller_id = :rid LIMIT 1");
            $stmt->execute([':pid' => $pid, ':rid' => $rid]);
            $price = $stmt->fetchColumn();
            return $price !== false && (int)$price > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    function fx_res_visibility_hidden_ids(int $rid): array {
        global $pdo;
        if (!$pdo || $rid <= 0) return [];
        try {
            $scope = function_exists('fx_res_product_scope_sql')
                ? fx_res_product_scope_sql('p', ':scope_rid')
                : "(FIND_IN_SET(:scope_rid, REPLACE(p.agent, ' ', '')) OR p.agent IN ('all', 'allusers'))";
            $stmt = $pdo->prepare(
                "SELECT DISTINCT p.id FROM product p " .
                "LEFT JOIN fx_res_visibility v ON v.product_id = p.id AND v.reseller_id = :rid_v " .
                "LEFT JOIN fx_retail_prices r ON r.product_id = p.id AND r.reseller_id = :rid_r AND r.retail_price > 0 " .
                "WHERE {$scope} AND (v.hidden = 1 OR r.id IS NULL)"
            );
            $stmt->execute([':rid_v' => $rid, ':rid_r' => $rid, ':scope_rid' => (string)$rid]);
            return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
        } catch (\Throwable $e) {
            // Fail back to explicit hide rows only; do not take the catalog down.
            try {
                $stmt = $pdo->prepare("SELECT product_id FROM fx_res_visibility WHERE reseller_id = :rid AND hidden = 1");
                $stmt->execute([':rid' => $rid]);
                return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
            } catch (\Throwable $ignored) {
                return [];
            }
        }
    }

    /** @param array $rows product rows (each with 'id') @param bool $keepRows false=remove hidden */
    function fx_res_visibility_filter_rows(array $rows, bool $keepVisibleOnly = true): array {
        if (!defined('FX_RESELLER_ID') || !FX_RESELLER_ID) return $rows;
        $hidden = fx_res_visibility_hidden_ids((int)FX_RESELLER_ID);
        if (!$hidden) return $rows;
        $out = [];
        foreach ($rows as $r) {
            if ($keepVisibleOnly && in_array((int)($r['id'] ?? 0), $hidden, true)) continue;
            $out[] = $r;
        }
        return $out;
    }

    /** true when this product is hidden for this reseller's customers */
    function fx_res_visibility_blocked(int $pid): bool {
        if (!defined('FX_RESELLER_ID') || !FX_RESELLER_ID) return false;
        return in_array($pid, fx_res_visibility_hidden_ids((int)FX_RESELLER_ID), true);
    }

    function fx_res_visibility_blocked_row($row): bool {
        if (!defined('FX_RESELLER_ID') || !FX_RESELLER_ID) return false;
        if (!is_array($row)) return false;
        return fx_res_visibility_blocked((int)($row['id'] ?? 0));
    }

    function fx_res_visibility_reject_if_blocked($row, $fromId, $keyboard = null): bool {
        if (!fx_res_visibility_blocked_row($row)) return false;
        sendmessage($fromId,
            "🚫 این محصول در حال حاضر برای فروش در ربات شما فعال نیست (پنهان است یا قیمت فروش تعیین نشده).",
            $keyboard, 'HTML');
        return true;
    }

    function fx_res_visibility_set(int $pid, int $rid, bool $hidden): bool {
        global $pdo;
        if (!$pdo || $pid <= 0 || $rid <= 0) return false;
        try {
            $sql = "INSERT INTO fx_res_visibility (product_id, reseller_id, hidden)
                    VALUES (:pid, :rid, :h)
                    ON DUPLICATE KEY UPDATE hidden = :h2";
            $stmt = $pdo->prepare($sql);
            return $stmt->execute([':pid' => $pid, ':rid' => $rid, ':h' => $hidden ? 1 : 0, ':h2' => $hidden ? 1 : 0]);
        } catch (\Throwable $e) { return false; }
    }
}
