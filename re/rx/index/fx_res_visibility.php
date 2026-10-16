<?php
/**
 * FX-RES-VISIBILITY: per-reseller product show/hide for their own customers.
 * Table: fx_res_visibility (product_id, reseller_id, hidden TINYINT)
 * hidden=1 → the product is NOT shown/sold to THIS reseller's customers.
 * Main bot and other resellers are unaffected (reseller-scoped).
 *
 * Filtering happens in fx_res_visibility_filter_rows() called from
 * KeyboardProduct() (catalog list) — and purchase resolve is blocked via
 * fx_res_visibility_blocked() checks in user_flow paths (defense in depth).
 * Loaded live from pricing_fx.php (is_file-guarded require_once).
 */

if (!defined('FX_RES_VISIBILITY_LOADED')) {
    define('FX_RES_VISIBILITY_LOADED', true);

    function fx_res_visibility_hidden_ids(int $rid): array {
        global $pdo;
        if (!$pdo || $rid <= 0) return [];
        try {
            $stmt = $pdo->prepare("SELECT product_id FROM fx_res_visibility WHERE reseller_id = :rid AND hidden = 1");
            $stmt->execute([':rid' => $rid]);
            return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
        } catch (\Throwable $e) { return []; }
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
