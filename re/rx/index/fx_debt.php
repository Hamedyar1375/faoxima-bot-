<?php
/**
 * FX-DEBT — reseller credit (debt/credit-sales) mode + two-tier pricing helpers.
 * Loaded via require_once at the TOP of user_flow.php (outside the .compiled bundle).
 * 100% inert for the main bot: every function no-ops when FX_RESELLER_ID is undefined.
 *
 * Data model (migration fx_migrate_debt.php, idempotent):
 *   botsaz.debt_mode         'balance' | 'debt'
 *   botsaz.debt_ceiling      BIGINT toman (credit ceiling)
 *   botsaz.current_debt      BIGINT toman (current owed)
 *   botsaz.debt_alert_80     TINYINT per-cycle alert flags
 *   botsaz.debt_alert_95     TINYINT
 *   botsaz.retail_overrides  JSON {"custom_volume": N, "custom_time": N} — retail unit prices
 *                            the reseller charges its customers for custom-volume services
 *   product.fx_wholesale_price BIGINT NULL — reseller's purchase cost per unit
 *                            (NULL/0 => defaults to the retail price, i.e. no margin)
 *   fx_debt_log              ledger (direction in/out)
 *
 * Business model (two-tier):
 *   - The CUSTOMER of the reseller bot pays the RETAIL price from their own wallet
 *     (existing flow, unchanged).
 *   - The RESELLER's cost for each sold service = wholesale (fx_wholesale_price).
 *     In debt_mode='debt' that cost is added to current_debt (on credit) instead of
 *     requiring the reseller's balance, gated by debt_ceiling. 80%/95% threshold
 *     alerts are sent to the reseller owner (once per cycle).
 *   - In debt mode the customer's wallet deduction skips the non-negative guard
 *     (bounded exposure: every sale is gated by the reseller's remaining ceiling).
 */

/**
 * FX-ROUTE (2026-10-05): resolve the reseller bot's own token from botsaz.
 * Agent-facing notifications (ceiling 🏦 / settlement 💳 / debt alerts) must be
 * sent through the RESELLER bot, not the main bot. When called from inside the
 * reseller bot's own process FX_RESELLER_TOKEN is already the correct token —
 * return it to avoid the DB read. Returns null when there is no reseller context
 * or no stored token (sendmessage then falls back to the ambient token, i.e.
 * the previous behaviour — safe no-op for the main bot).
 */
if (!function_exists('fx_reseller_bot_token')) {
    function fx_reseller_bot_token(int $rid): ?string {
        if (defined('FX_RESELLER_ID') && (int)FX_RESELLER_ID === $rid
            && defined('FX_RESELLER_TOKEN') && FX_RESELLER_TOKEN !== '') {
            return FX_RESELLER_TOKEN;
        }
        global $pdo;
        try {
            $stmt = $pdo->prepare("SELECT bot_token FROM botsaz WHERE id_user = :rid LIMIT 1");
            $stmt->execute([':rid' => (string)$rid]);
            $t = trim((string)$stmt->fetchColumn());
            return $t !== '' ? $t : null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('fx_debt_state')) {
    function fx_debt_state(int $rid): array {
        global $pdo;
        $out = ['mode' => 'balance', 'ceiling' => 0, 'current' => 0, 'bot' => null];
        try {
            $stmt = $pdo->prepare("SELECT * FROM botsaz WHERE id_user = :rid LIMIT 1");
            $stmt->execute([':rid' => (string)$rid]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            $row = null;
        }
        if (!is_array($row)) {
            return $out;
        }
        $out['bot'] = $row;
        $out['mode'] = (($row['debt_mode'] ?? 'balance') === 'debt') ? 'debt' : 'balance';
        $out['ceiling'] = (int)($row['debt_ceiling'] ?? 0);
        $out['current'] = (int)($row['current_debt'] ?? 0);
        return $out;
    }
}

if (!function_exists('fx_debt_gate')) {
    /**
     * Pre-purchase check. Returns [ok, messageHtml].
     */
    function fx_debt_gate(int $rid, int $amount): array {
        $st = fx_debt_state($rid);
        if ($st['mode'] !== 'debt') {
            return [true, ''];
        }
        if ($st['ceiling'] <= 0) {
            return [false, '⛔ خرید در حالت اعتباری امکان‌پذیر نیست؛ سقف اعتبار تنظیم نشده است.\nلطفاً با مدیریت تماس بگیرید.'];
        }
        if ($st['current'] + $amount > $st['ceiling']) {
            return [false, '⛔ سقف اعتبار برای این سرویس کافی نیست.\n💵 بدهی فعلی: ' . number_format($st['current']) . ' تومان\n🏦 سقف اعتبار: ' . number_format($st['ceiling']) . ' تومان\n💶 هزینه این سرویس: ' . number_format($amount) . ' تومان\nبرای ادامه خرید، ابتدا بدهی خود را تسویه کنید.'];
        }
        return [true, ''];
    }
}

if (!function_exists('fx_debt_alert_eval')) {
    /**
     * Shared 80%/95% ceiling alert evaluation + flag re-arm (once per cycle).
     * Returns the alert HTML for the agent or '' when no alert fires.
     */
    function fx_debt_alert_eval(int $rid, int $after, array $row): string {
        $ceiling = (int)($row['debt_ceiling'] ?? 0);
        if ($ceiling <= 0) {
            return '';
        }
        $pct = $after * 100 / $ceiling;
        if ($pct < 80) {
            @update("botsaz", "debt_alert_80", 0, "id_user", $rid);
            @update("botsaz", "debt_alert_95", 0, "id_user", $rid);
            return '';
        }
        if ($pct >= 95 && !(int)($row['debt_alert_95'] ?? 0)) {
            @update("botsaz", "debt_alert_95", 1, "id_user", $rid);
            @update("botsaz", "debt_alert_80", 1, "id_user", $rid);
            return '🚨 <b>هشدار: سقف اعتبار شما پر شد</b>' . "\n"
                . 'بدهی فعلی شما ' . number_format($after) . ' تومان از سقف ' . number_format($ceiling) . ' تومان است (بیش از ۹۵٪).' . "\n"
                . 'تا تسویه بدهی، خرید جدید به‌حساب امکان‌پذیر نیست.';
        }
        if ($pct >= 80 && !(int)($row['debt_alert_80'] ?? 0)) {
            @update("botsaz", "debt_alert_80", 1, "id_user", $rid);
            return '⚠️ <b>هشدار اعتبار</b>' . "\n"
                . 'بدهی فعلی شما ' . number_format($after) . ' تومان از سقف ' . number_format($ceiling) . ' تومان است (بیش از ۸۰٪).' . "\n"
                . 'لطفاً بدهی خود را به‌زودی تسویه کنید.';
        }
        return '';
    }
}

if (!function_exists('fx_debt_reserve')) {
    /**
     * BR-01: atomically pre-charge the reseller's debt BEFORE service creation.
     * Single UPDATE with the ceiling in the WHERE clause closes the gate→create
     * race (two concurrent purchases can no longer overshoot the ceiling).
     * Returns [ok, reason, debtAfter]. reason: '' | 'ceiling' | 'db'.
     */
    function fx_debt_reserve(int $rid, int $amount): array {
        global $pdo;
        if ($amount <= 0) {
            return [true, '', 0];
        }
        try {
            $stmt = $pdo->prepare("UPDATE botsaz SET current_debt = current_debt + :amt WHERE id_user = :rid AND debt_mode = 'debt' AND debt_ceiling > 0 AND current_debt + :amt2 <= debt_ceiling");
            $stmt->execute([':amt' => $amount, ':amt2' => $amount, ':rid' => (string)$rid]);
            if ($stmt->rowCount() > 0) {
                return [true, '', fx_debt_state($rid)['current']];
            }
            $st = fx_debt_state($rid);
            if ($st['mode'] !== 'debt') {
                return [true, '', $st['current']];
            }
            return [false, 'ceiling', $st['current']];
        } catch (\Throwable $e) {
            error_log('[FX_DEBT] reserve failed rid=' . $rid . ' amt=' . $amount . ': ' . $e->getMessage());
            return [false, 'db', 0];
        }
    }
}

if (!function_exists('fx_debt_commit')) {
    /**
     * Ledger row for an amount already added by fx_debt_reserve (service created).
     * Returns [ok, reason, debtAfter, alertHtml].
     */
    function fx_debt_commit(int $rid, int $amount, string $method, string $ref): array {
        global $pdo;
        if ($amount <= 0) {
            return [true, '', 0, ''];
        }
        try {
            $st = fx_debt_state($rid);
            $after = $st['current'];
            $pdo->prepare("INSERT INTO fx_debt_log (reseller_id, amount, direction, method, invoice_ref, debt_after, time) VALUES (:rid, :amt, 'in', :m, :ref, :after, NOW())")
                ->execute([':rid' => $rid, ':amt' => $amount, ':m' => $method, ':ref' => $ref, ':after' => $after]);
        } catch (\Throwable $e) {
            error_log('[FX_DEBT] commit failed rid=' . $rid . ' amt=' . $amount . ' ref=' . $ref . ': ' . $e->getMessage());
            return [false, 'db', 0, ''];
        }
        $row = is_array($st['bot']) ? $st['bot'] : [];
        return [true, '', $after, fx_debt_alert_eval($rid, $after, $row)];
    }
}

if (!function_exists('fx_debt_release')) {
    /**
     * Roll back a reservation after a FAILED service creation (no ledger 'in'
     * was written for this ref). Decrements debt and logs an 'out' revert row.
     */
    function fx_debt_release(int $rid, int $amount, string $ref): void {
        global $pdo;
        if ($amount <= 0) {
            return;
        }
        try {
            $pdo->prepare("UPDATE botsaz SET current_debt = GREATEST(0, current_debt - :amt) WHERE id_user = :rid")
                ->execute([':amt' => $amount, ':rid' => (string)$rid]);
            $st = fx_debt_state($rid);
            $after = $st['current'];
            $pdo->prepare("INSERT INTO fx_debt_log (reseller_id, amount, direction, method, invoice_ref, debt_after, time) VALUES (:rid, :amt, 'out', 'revert', :ref, :after, NOW())")
                ->execute([':rid' => $rid, ':amt' => $amount, ':ref' => $ref, ':after' => $after]);
            if ($st['ceiling'] > 0 && $after * 100 < $st['ceiling'] * 80) {
                @update("botsaz", "debt_alert_80", 0, "id_user", $rid);
                @update("botsaz", "debt_alert_95", 0, "id_user", $rid);
            }
        } catch (\Throwable $e) {
            error_log('[FX_DEBT] release failed rid=' . $rid . ' amt=' . $amount . ' ref=' . $ref . ': ' . $e->getMessage());
        }
    }
}

if (!function_exists('fx_debt_inc')) {
    /**
     * Add to the reseller's debt after a successful sale (legacy non-reserving
     * path; kept for API compatibility). Returns [ok, reason, debtAfter, alertHtml].
     */
    function fx_debt_inc(int $rid, int $amount, string $method, string $ref): array {
        global $pdo;
        if ($amount <= 0) {
            return [true, '', 0, ''];
        }
        try {
            $pdo->prepare("UPDATE botsaz SET current_debt = current_debt + :amt WHERE id_user = :rid")
                ->execute([':amt' => $amount, ':rid' => (string)$rid]);
            $st = fx_debt_state($rid);
            $after = $st['current'];
            $pdo->prepare("INSERT INTO fx_debt_log (reseller_id, amount, direction, method, invoice_ref, debt_after, time) VALUES (:rid, :amt, 'in', :m, :ref, :after, NOW())")
                ->execute([':rid' => $rid, ':amt' => $amount, ':m' => $method, ':ref' => $ref, ':after' => $after]);
        } catch (\Throwable $e) {
            error_log('[FX_DEBT] inc failed rid=' . $rid . ' amt=' . $amount . ' ref=' . $ref . ': ' . $e->getMessage());
            return [false, 'db', 0, ''];
        }
        $row = is_array($st['bot']) ? $st['bot'] : [];
        return [true, '', $after, fx_debt_alert_eval($rid, $after, $row)];
    }
}

if (!function_exists('fx_debt_record_once')) {
    /**
     * FX-RESELLER (2026-10-15): idempotent debt increment for flows that can be
     * re-run (gateway fulfillment / reconcile). Refuses to double-charge when an
     * 'in' ledger row with the same ref+method already exists.
     * Returns [ok, reason, debtAfter, alertHtml].
     */
    function fx_debt_record_once(int $rid, int $amount, string $method, string $ref): array {
        global $pdo;
        if ($amount <= 0) {
            return [true, '', 0, ''];
        }
        if ($ref !== '') {
            try {
                $stmt = $pdo->prepare("SELECT 1 FROM fx_debt_log WHERE reseller_id = :rid AND invoice_ref = :ref AND method = :m AND direction = 'in' LIMIT 1");
                $stmt->execute([':rid' => $rid, ':ref' => $ref, ':m' => $method]);
                if ($stmt->fetchColumn() !== false) {
                    return [true, 'duplicate', fx_debt_state($rid)['current'], ''];
                }
            } catch (\Throwable $e) {
                error_log('[FX_DEBT] record_once dedupe check failed rid=' . $rid . ' ref=' . $ref . ': ' . $e->getMessage());
            }
        }
        return fx_debt_inc($rid, $amount, $method, $ref);
    }
}

if (!function_exists('fx_debt_revert')) {
    /**
     * Roll the debt back after a failed service creation.
     */
    function fx_debt_revert(int $rid, int $amount, string $ref): void {
        global $pdo;
        if ($amount <= 0) {
            return;
        }
        try {
            $pdo->prepare("UPDATE botsaz SET current_debt = GREATEST(0, current_debt - :amt) WHERE id_user = :rid")
                ->execute([':amt' => $amount, ':rid' => (string)$rid]);
            $stmt = $pdo->prepare("SELECT current_debt FROM botsaz WHERE id_user = :rid LIMIT 1");
            $stmt->execute([':rid' => (string)$rid]);
            $after = (int)$stmt->fetchColumn();
            $pdo->prepare("INSERT INTO fx_debt_log (reseller_id, amount, direction, method, invoice_ref, debt_after, time) VALUES (:rid, :amt, 'out', :m, :ref, :after, NOW())")
                ->execute([':rid' => $rid, ':amt' => $amount, ':m' => 'revert', ':ref' => $ref, ':after' => $after]);
            // re-arm alert flags when debt fell back below the thresholds
            $stmt2 = $pdo->prepare("SELECT debt_ceiling FROM botsaz WHERE id_user = :rid LIMIT 1");
            $stmt2->execute([':rid' => (string)$rid]);
            $ceiling = (int)$stmt2->fetchColumn();
            if ($ceiling > 0 && $after * 100 < $ceiling * 80) {
                @update("botsaz", "debt_alert_80", 0, "id_user", $rid);
                @update("botsaz", "debt_alert_95", 0, "id_user", $rid);
            }
        } catch (\Throwable $e) {
            error_log('[FX_DEBT] revert failed rid=' . $rid . ' amt=' . $amount . ' ref=' . $ref . ': ' . $e->getMessage());
        }
    }
}

if (!function_exists('fx_reseller_load_retail_units')) {
    /**
     * Two-tier pricing (custom-volume services): apply the reseller's RETAIL unit
     * prices (botsaz.retail_overrides) to the panel's per-agent unit price JSON,
     * so the customer pays the reseller's retail unit price for custom volume/time.
     * $panel is passed by reference and mutated in place (request-local copy).
     * No-op for the main bot.
     */
    function fx_reseller_load_retail_units(array &$panel, string $agent): void {
        if (!defined('FX_RESELLER_ID')) {
            return;
        }
        $st = fx_debt_state((int)FX_RESELLER_ID);
        $row = $st['bot'];
        if (!is_array($row) || empty($row['retail_overrides'])) {
            return;
        }
        $ov = json_decode((string)$row['retail_overrides'], true);
        if (!is_array($ov)) {
            return;
        }
        $map = ['custom_volume' => 'pricecustomvolume', 'custom_time' => 'pricecustomtime'];
        $panelKey = (string)($panel['code_panel'] ?? $panel['name_panel'] ?? '');
        foreach ($map as $k => $col) {
            // FX-MARGIN: stash the BASE (wholesale) unit price before the retail
            // override rewrites the JSON — the debt charge for custom-volume sales
            // must use the main panel's unit price, not the reseller's retail.
            $baseArr = json_decode((string)($panel[$col] ?? ''), true);
            $baseVal = null;
            if (is_array($baseArr)) {
                if (isset($baseArr[(string)$agent]) && is_numeric($baseArr[(string)$agent])) {
                    $baseVal = (float)$baseArr[(string)$agent];
                } elseif (isset($baseArr['f']) && is_numeric($baseArr['f'])) {
                    $baseVal = (float)$baseArr['f'];
                } elseif (isset($baseArr['n']) && is_numeric($baseArr['n'])) {
                    $baseVal = (float)$baseArr['n'];
                }
            }
            $GLOBALS['__fx_reseller_base_units'][$panelKey . '|' . $col] = $baseVal;
            if (!isset($ov[$k]) || !is_numeric($ov[$k]) || (float)$ov[$k] <= 0) {
                continue;
            }
            $arr = is_array($baseArr) ? $baseArr : [];
            // The reseller's customers carry agent = reseller id (or 'f'); the panel
            // JSON is tier-keyed, so set the customer key(s) to the retail unit price.
            foreach (array_keys($arr) as $tier) {
                $arr[$tier] = (float)$ov[$k];
            }
            $arr['f'] = (float)$ov[$k];
            $arr[(string)$agent] = (float)$ov[$k];
            $arr[(string)(int)FX_RESELLER_ID] = (float)$ov[$k];
            $panel[$col] = json_encode($arr);
        }
    }
}

if (!function_exists('fx_reseller_base_unit')) {
    /**
     * FX-MARGIN: base (wholesale) unit price stashed by fx_reseller_load_retail_units
     * before retail overrides were applied. null when unknown.
     */
    function fx_reseller_base_unit(array $panel, string $column): ?float {
        $key = (string)($panel['code_panel'] ?? $panel['name_panel'] ?? '') . '|' . $column;
        $v = $GLOBALS['__fx_reseller_base_units'][$key] ?? null;
        return is_numeric($v) ? (float)$v : null;
    }
}

if (!function_exists('fx_reseller_custom_wholesale')) {
    /**
     * FX-MARGIN: the reseller's COST for a custom-volume/days service, computed from
     * the stashed BASE unit prices. null when no base is known (caller falls back to
     * the legacy behaviour: cost = customer price).
     */
    function fx_reseller_custom_wholesale(array $panel, $volume, $days): ?int {
        $uv = fx_reseller_base_unit($panel, 'pricecustomvolume');
        $ut = fx_reseller_base_unit($panel, 'pricecustomtime');
        if ($uv === null && $ut === null) {
            return null;
        }
        return (int)round(((float)$volume * ($uv ?? 0.0)) + ((float)$days * ($ut ?? 0.0)));
    }
}

if (!function_exists('fx_apply_reseller_retail')) {
    /**
     * Two-tier pricing (catalog products): make sure the reseller's wholesale cost
     * is present. When the admin has not set product.fx_wholesale_price, default it
     * to the (retail) product price — i.e. zero margin until the admin sets a lower
     * wholesale price from the central reseller-management UI.
     * No-op for the main bot. $product by reference.
     */
    function fx_apply_reseller_retail(array &$product, array $panel): void {
        if (!defined('FX_RESELLER_ID')) {
            return;
        }
        if (empty($product['fx_wholesale_price']) || (int)$product['fx_wholesale_price'] <= 0) {
            // FX-EDGE 2026-10-10: default the reseller's cost to the BASE price, not
            // price_product — on reseller bots price_product may already carry the
            // reseller's OVERRIDDEN retail (fx_retail_override), which would zero
            // the margin against the reseller's own price.
            $product['fx_wholesale_price'] = (int)($product['fx_base_price'] ?? ($product['price_product'] ?? 0));
        }
    }
}

if (!function_exists('fx_reseller_reprice_custom')) {
    /**
     * Two-tier pricing (custom-volume): when the reseller defined retail unit
     * prices, recompute the customer's unit price = volume*unitVol + days*unitTime.
     * $price by reference. No-op for the main bot and when no overrides exist.
     */
    function fx_reseller_reprice_custom(&$price, array $panel, $volume, $days, $agent = null): void {
        if (!defined('FX_RESELLER_ID')) {
            return;
        }
        $st = fx_debt_state((int)FX_RESELLER_ID);
        $row = $st['bot'];
        if (!is_array($row) || empty($row['retail_overrides'])) {
            return;
        }
        $ov = json_decode((string)$row['retail_overrides'], true);
        if (!is_array($ov)) {
            return;
        }
        $uv = (isset($ov['custom_volume']) && is_numeric($ov['custom_volume']) && (float)$ov['custom_volume'] > 0) ? (float)$ov['custom_volume'] : null;
        $ut = (isset($ov['custom_time']) && is_numeric($ov['custom_time']) && (float)$ov['custom_time'] > 0) ? (float)$ov['custom_time'] : null;
        if ($uv === null && $ut === null) {
            return;
        }
        // FX-MARGIN: a one-sided override must not zero the other side — fall back
        // to the panel's (already retail-adjusted) unit price for the missing side.
        if ($uv === null) {
            $arr = json_decode((string)($panel['pricecustomvolume'] ?? ''), true);
            $uv = (is_array($arr) && is_numeric($arr[(string)$agent] ?? null)) ? (float)$arr[(string)$agent] : 0.0;
        }
        if ($ut === null) {
            $arr = json_decode((string)($panel['pricecustomtime'] ?? ''), true);
            $ut = (is_array($arr) && is_numeric($arr[(string)$agent] ?? null)) ? (float)$arr[(string)$agent] : 0.0;
        }
        $newPrice = ((int)$volume * $uv) + ((int)$days * $ut);
        $price = $newPrice;
    }
}
