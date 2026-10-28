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
 *                            (NULL/0 => sale blocked until a positive wholesale is set)
 *   fx_debt_log              ledger (direction in/out, scope 'bot'|'main')
 *                            scope='bot' = this file's writers (tenant botsaz debt)
 *                            scope='main' = NM module writers (main-bot agent balance;
 *                            see scripts/fx_migrate_debt_scope.php)
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

if (!function_exists('fx_n2_credit_limit')) {
    /**
     * FX-ACCOUNT (2026-10-17): active credit limit for the MAIN-bot representative
     * (agent='n2'): maxbuyagent when > 0, otherwise the NM default (env NM_DEBT_LIMIT
     * or 12,000,000). nm is main-bot only — callers must guard with
     * !defined('FX_RESELLER_ID').
     */
    function fx_n2_credit_limit(array $user): int {
        $m = (int)($user['maxbuyagent'] ?? 0);
        if (!function_exists('nm_agent_purchase_limit')) {
            $f = dirname(__DIR__) . '/nm_manager.php';
            if (is_file($f)) {
                require_once $f;
            }
        }
        if (function_exists('nm_agent_purchase_limit')) {
            return (int)nm_agent_purchase_limit($user);
        }
        if ($m > 0) {
            return $m;
        }
        $env = getenv('NM_DEBT_LIMIT');
        if ($env !== false && $env !== '' && ctype_digit((string)$env)) {
            return (int)$env;
        }
        return 12000000;
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
        // WALLET-FIRST (2026-10-17): the ceiling only constrains the DEBT part;
        // the wallet part is consumed atomically at reserve/record time.
        $walletPart = 0;
        $debtPart = $amount;
        if ($amount > 0 && fx_debt_wallet_first($rid)) {
            $walletPart = min($amount, max(0, fx_debt_wallet_balance($rid)));
            $debtPart = $amount - $walletPart;
        }
        if ($debtPart <= 0) {
            return [true, ''];
        }
        if ($st['ceiling'] <= 0) {
            return [false, '⛔ خرید در حالت اعتباری امکان‌پذیر نیست؛ سقف اعتبار تنظیم نشده است.\nلطفاً با مدیریت تماس بگیرید.'];
        }
        if ($st['current'] + $debtPart > $st['ceiling']) {
            $walletTxt = $walletPart > 0
                ? "\n💰 از اعتبار کیف‌پول شما: " . number_format($walletPart) . " تومان (ابتدا کسر می‌شود)"
                . "\n💸 سهم بدهی این سرویس: " . number_format($debtPart) . " تومان"
                : '';
            return [false, '⛔ سقف اعتبار برای این سرویس کافی نیست.\n💵 بدهی فعلی: ' . number_format($st['current']) . ' تومان\n🏦 سقف اعتبار: ' . number_format($st['ceiling']) . ' تومان\n💶 هزینه این سرویس: ' . number_format($amount) . ' تومان' . $walletTxt . '\nبرای ادامه خرید، ابتدا بدهی خود را تسویه کنید.'];
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

if (!function_exists('fx_debt_idem_column')) {
    /**
     * FX-DEBT-P1 (2026-10-17): true when fx_debt_log.idempotency_key exists (migration applied).
     * Cached per request. Code stays compatible when the column does not exist yet.
     */
    function fx_debt_idem_column(): bool {
        static $has = null;
        if ($has !== null) {
            return $has;
        }
        global $pdo;
        $has = false;
        try {
            if ($pdo instanceof PDO) {
                $q = $pdo->query("SHOW COLUMNS FROM fx_debt_log LIKE 'idempotency_key'");
                $has = ($q && $q->fetch(PDO::FETCH_ASSOC)) ? true : false;
            }
        } catch (\Throwable $e) {
            $has = false;
        }
        return $has;
    }
}

if (!function_exists('fx_debt_is_duplicate')) {
    function fx_debt_is_duplicate(\Throwable $e): bool {
        $info = ($e instanceof \PDOException) ? $e->errorInfo : null;
        return is_array($info) && (int)($info[1] ?? 0) === 1062;
    }
}

if (!function_exists('fx_debt_ref_committed')) {
    /**
     * FX-DEBT-P1: an 'in' ledger row already exists for this ref (optionally method).
     * Used to make commit/release safe against retries. Read-only.
     */
    function fx_debt_ref_committed(int $rid, string $ref, string $method = ''): bool {
        global $pdo;
        if ($ref === '' || !($pdo instanceof PDO)) {
            return false;
        }
        try {
            $sql = "SELECT 1 FROM fx_debt_log WHERE reseller_id = :rid AND invoice_ref = :ref AND direction = 'in'";
            $args = [':rid' => $rid, ':ref' => $ref];
            if ($method !== '') {
                $sql .= " AND method = :m";
                $args[':m'] = $method;
            }
            $sql .= " LIMIT 1";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($args);
            return $stmt->fetchColumn() !== false;
        } catch (\Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('fx_debt_insert_in')) {
    /**
     * FX-DEBT-P1: single writer for scope='bot' 'in' ledger rows. When the
     * idempotency_key column exists the INSERT is race-safe (unique key 1062 =
     * duplicate replay, caught by callers via fx_debt_is_duplicate()).
     */
    function fx_debt_insert_in(int $rid, int $amount, string $method, string $ref, int $after): void {
        global $pdo;
        if ($ref !== '' && fx_debt_idem_column()) {
            $pdo->prepare("INSERT INTO fx_debt_log (reseller_id, amount, direction, method, invoice_ref, debt_after, time, idempotency_key) VALUES (:rid, :amt, 'in', :m, :ref, :after, NOW(), :k)")
                ->execute([':rid' => $rid, ':amt' => $amount, ':m' => $method, ':ref' => $ref, ':after' => $after, ':k' => 'bot|' . $method . '|' . $ref]);
            return;
        }
        $pdo->prepare("INSERT INTO fx_debt_log (reseller_id, amount, direction, method, invoice_ref, debt_after, time) VALUES (:rid, :amt, 'in', :m, :ref, :after, NOW())")
            ->execute([':rid' => $rid, ':amt' => $amount, ':m' => $method, ':ref' => $ref, ':after' => $after]);
    }
}

if (!function_exists('fx_credit_log_stop')) {
    /**
     * FX-DEBT-P1: traceable record of a SAFELY STOPPED credit purchase
     * (missing/untraceable wholesale price). No financial side effect.
     */
    function fx_credit_log_stop(string $where, int $rid, array $ctx = []): void {
        $payload = array_merge(['where' => $where, 'reseller_id' => $rid], $ctx);
        if (function_exists('rx_log_event')) {
            rx_log_event('FX_CREDIT_WHOLESALE_MISSING', 'credit purchase blocked: no traceable wholesale price', $payload);
        }
        @error_log('[FX_CREDIT] stop at ' . $where . ' rid=' . $rid . ' ' . json_encode($payload, JSON_UNESCAPED_UNICODE));
    }
}

if (!function_exists('fx_credit_wholesale_product')) {
    /**
     * FX-DEBT-P1: authoritative wholesale for a catalog/custom product.
     * NO fallback to retail or zero: missing price => safe stop.
     * Returns ['ok'=>bool, 'amount'=>int, 'reason'=>string].
     */
    function fx_credit_wholesale_product(array $product, ?array $panel = null, string $agent = 'f'): array {
        $wp = (int)($product['fx_wholesale_price'] ?? 0);
        if ($wp > 0) {
            return ['ok' => true, 'amount' => $wp, 'reason' => ''];
        }
        $code = (string)($product['code_product'] ?? '');
        $name = (string)($product['name_product'] ?? '');
        $isCustom = ($code === 'customvolume' || $code === 'custom_volume' || $code === 'custom'
            || mb_strpos($name, 'حجم دلخواه') !== false || mb_strpos($name, 'سرویس دلخواه') !== false);
        if ($isCustom && is_array($panel)) {
            $r = fx_credit_wholesale_custom($panel, $agent, (float)($product['Volume_constraint'] ?? 0), (float)($product['Service_time'] ?? 0));
            if (!empty($r['ok'])) {
                return $r;
            }
        }
        return ['ok' => false, 'amount' => 0, 'reason' => 'no_wholesale'];
    }
}

if (!function_exists('fx_credit_wholesale_custom')) {
    /**
     * FX-DEBT-P1: wholesale for custom volume/time = base unit prices (stash first,
     * raw panel JSON second). Returns failure when no traceable base exists.
     */
    function fx_credit_wholesale_custom(array $panel, string $agent, $volume, $days): array {
        $vol = (float)$volume;
        $day = (float)$days;
        if ($vol <= 0 && $day <= 0) {
            return ['ok' => false, 'amount' => 0, 'reason' => 'no_custom_qty'];
        }
        if (function_exists('fx_reseller_custom_wholesale')) {
            $stash = fx_reseller_custom_wholesale($panel, $vol, $day);
            if ($stash !== null && (int)$stash > 0) {
                return ['ok' => true, 'amount' => (int)$stash, 'reason' => ''];
            }
        }
        if (function_exists('fx_custom_base_price')) {
            $raw = (float)fx_custom_base_price($panel, $agent, $vol, $day);
            if ($raw > 0) {
                return ['ok' => true, 'amount' => (int)round($raw), 'reason' => ''];
            }
        }
        return ['ok' => false, 'amount' => 0, 'reason' => 'no_wholesale_custom'];
    }
}

if (!function_exists('fx_credit_wholesale_unit')) {
    /**
     * FX-DEBT-P1: wholesale for unit-based operations (extra volume/time) =
     * qty × panel base unit price (agent key → 'f' → 'n'). No retail fallback.
     */
    function fx_credit_wholesale_unit(array $panel, string $column, string $agent, $qty): array {
        $qty = (float)$qty;
        if ($qty <= 0) {
            return ['ok' => false, 'amount' => 0, 'reason' => 'no_qty'];
        }
        if (!function_exists('fx_panel_raw_unit_price')) {
            return ['ok' => false, 'amount' => 0, 'reason' => 'no_unit_helper'];
        }
        $unit = null;
        foreach ([(string)$agent, 'f', 'n'] as $key) {
            if ($key === '') {
                continue;
            }
            $u = fx_panel_raw_unit_price($panel, $column, $key);
            if ($u !== null && (float)$u > 0) {
                $unit = (float)$u;
                break;
            }
        }
        if ($unit === null || $unit <= 0) {
            return ['ok' => false, 'amount' => 0, 'reason' => 'no_unit_price'];
        }
        $amt = (int)round($qty * $unit);
        if ($amt <= 0) {
            return ['ok' => false, 'amount' => 0, 'reason' => 'zero_amount'];
        }
        return ['ok' => true, 'amount' => $amt, 'reason' => ''];
    }
}

if (!function_exists('fx_debt_res_table')) {
    /**
     * FX-DEBT-P2: true when the fx_debt_reservations table exists (migration
     * applied). True is cached per request; while it is not true the check is
     * repeated (cheap) so the code keeps working, with a documented fallback,
     * before the migration is applied.
     */
    function fx_debt_res_table(): bool {
        static $has = null;
        if ($has === true) {
            return true;
        }
        global $pdo;
        $has = false;
        try {
            if ($pdo instanceof PDO) {
                $q = $pdo->query("SHOW TABLES LIKE 'fx_debt_reservations'");
                $has = ($q !== false && $q->fetchColumn() !== false);
            }
        } catch (\Throwable $e) {
            $has = false;
        }
        return $has;
    }
}

if (!function_exists('fx_debt_reservation_state')) {
    /** FX-DEBT-P2: read-only reservation state: '' | reserved | committed | released. */
    function fx_debt_reservation_state(string $ref, int $rid = 0): string {
        global $pdo;
        if ($ref === '' || !($pdo instanceof PDO) || !fx_debt_res_table()) {
            return '';
        }
        try {
            $sql = "SELECT state FROM fx_debt_reservations WHERE ref = :ref" . ($rid > 0 ? " AND reseller_id = :rid" : "") . " LIMIT 1";
            $stmt = $pdo->prepare($sql);
            $args = [':ref' => $ref];
            if ($rid > 0) {
                $args[':rid'] = $rid;
            }
            $stmt->execute($args);
            $v = $stmt->fetchColumn();
            return $v === false ? '' : (string)$v;
        } catch (\Throwable $e) {
            return '';
        }
    }
}

if (!function_exists('fx_debt_reservation_mark')) {
    /**
     * FX-DEBT-P2: transition a reservation reserved→committed/released exactly
     * once (conditional UPDATE). Best-effort: failures only log — the ledger
     * dedupe/marker logic remains the source of truth for money.
     */
    function fx_debt_reservation_mark(string $ref, string $state): bool {
        global $pdo;
        if ($ref === '' || !($pdo instanceof PDO) || !fx_debt_res_table()) {
            return false;
        }
        if ($state !== 'committed' && $state !== 'released') {
            return false;
        }
        try {
            $stmt = $pdo->prepare("UPDATE fx_debt_reservations SET state = :st, updated_at = NOW() WHERE ref = :ref AND state = 'reserved'");
            $stmt->execute([':st' => $state, ':ref' => $ref]);
            return $stmt->rowCount() > 0;
        } catch (\Throwable $e) {
            error_log('[FX_DEBT] reservation mark failed ref=' . $ref . ' state=' . $state . ': ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('fx_debt_reservations_pending')) {
    /**
     * FX-DEBT-P2: read-only list of reservations still in 'reserved' state after
     * $olderMinutes — the crash-orphan report (a reserve without commit/release).
     * No side effects; reconciliation/repair is a manual decision.
     */
    function fx_debt_reservations_pending(int $olderMinutes = 30, int $limit = 100): array {
        global $pdo;
        if (!($pdo instanceof PDO) || !fx_debt_res_table()) {
            return [];
        }
        try {
            $lim = max(1, min(500, $limit));
            // WALLET-FIRST: expose wallet_part (when the column exists) so manual
            // reconciliation sees which share was drawn from the wallet.
            $cols = fx_debt_wallet_column()
                ? 'ref, reseller_id, amount, wallet_part, state, created_at, updated_at'
                : 'ref, reseller_id, amount, state, created_at, updated_at';
            $stmt = $pdo->prepare("SELECT " . $cols . " FROM fx_debt_reservations WHERE state = 'reserved' AND created_at < (NOW() - INTERVAL :m MINUTE) ORDER BY created_at ASC LIMIT " . $lim);
            $stmt->execute([':m' => max(0, $olderMinutes)]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            error_log('[FX_DEBT] reservations_pending failed: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('fx_debt_wallet_column')) {
    /**
     * WALLET-FIRST (2026-10-17): true when fx_debt_reservations.wallet_part
     * exists (migration fx_migrate_fx_debt_reservations_wallet_part.php applied).
     * True is cached per request; while false the check is repeated (cheap) so
     * the code keeps working before the migration is applied.
     */
    function fx_debt_wallet_column(): bool {
        static $has = null;
        if ($has === true) {
            return true;
        }
        global $pdo;
        $has = false;
        try {
            if (($pdo instanceof PDO) && fx_debt_res_table()) {
                $q = $pdo->query("SHOW COLUMNS FROM fx_debt_reservations LIKE 'wallet_part'");
                $has = ($q && $q->fetch(PDO::FETCH_ASSOC)) ? true : false;
            }
        } catch (\Throwable $e) {
            $has = false;
        }
        return $has;
    }
}

if (!function_exists('fx_debt_wallet_first')) {
    /**
     * WALLET-FIRST (2026-10-17): wallet-first billing is active for this reseller.
     * Requires the full P1/P2 infrastructure (idempotency_key column + reservation
     * table + wallet_part column) and the per-reseller kill switch
     * botsaz.setting.wallet_first != '0' (default ON). While inactive, every
     * reserve/commit/release/record path is byte-for-byte the legacy behaviour.
     */
    function fx_debt_wallet_first(int $rid): bool {
        if ($rid <= 0) {
            return false;
        }
        if (!fx_debt_res_table() || !fx_debt_wallet_column() || !fx_debt_idem_column()) {
            return false;
        }
        $s = fx_res_setting_get($rid);
        return (string)($s['wallet_first'] ?? '1') !== '0';
    }
}

if (!function_exists('fx_debt_wallet_balance')) {
    /** WALLET-FIRST: read-only reseller wallet balance (0 when unknown/missing). */
    function fx_debt_wallet_balance(int $rid): int {
        global $pdo;
        if ($rid <= 0 || !($pdo instanceof PDO)) {
            return 0;
        }
        try {
            $stmt = $pdo->prepare("SELECT Balance FROM user WHERE id = :rid LIMIT 1");
            $stmt->execute([':rid' => (string)$rid]);
            $b = $stmt->fetchColumn();
            return $b === false ? 0 : (int)round((float)$b);
        } catch (\Throwable $e) {
            return 0;
        }
    }
}

if (!function_exists('fx_debt_reservation_row')) {
    /** WALLET-FIRST: reservation row incl. wallet_part (null when absent). */
    function fx_debt_reservation_row(string $ref, int $rid = 0): ?array {
        global $pdo;
        if ($ref === '' || !($pdo instanceof PDO) || !fx_debt_res_table()) {
            return null;
        }
        try {
            $cols = fx_debt_wallet_column() ? 'ref, reseller_id, amount, wallet_part, state' : 'ref, reseller_id, amount, state';
            $sql = "SELECT " . $cols . " FROM fx_debt_reservations WHERE ref = :ref" . ($rid > 0 ? " AND reseller_id = :rid" : "") . " LIMIT 1";
            $stmt = $pdo->prepare($sql);
            $args = [':ref' => $ref];
            if ($rid > 0) {
                $args[':rid'] = $rid;
            }
            $stmt->execute($args);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                return null;
            }
            $row['amount'] = (int)($row['amount'] ?? 0);
            $row['wallet_part'] = (int)($row['wallet_part'] ?? 0);
            return $row;
        } catch (\Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('fx_debt_reservation_wallet_part')) {
    /** WALLET-FIRST: stored wallet share of a reservation (0 for legacy rows). */
    function fx_debt_reservation_wallet_part(string $ref, int $rid, int $amount = 0): int {
        if ($ref === '' || !fx_debt_wallet_column()) {
            return 0;
        }
        $row = fx_debt_reservation_row($ref, $rid);
        if ($row === null) {
            return 0;
        }
        $wp = max(0, (int)$row['wallet_part']);
        if ($amount > 0 && $wp > $amount) {
            $wp = $amount;
        }
        return $wp;
    }
}

if (!function_exists('fx_debt_wallet_row_committed')) {
    /** WALLET-FIRST: main-scope wallet_purchase audit row exists for this ref. */
    function fx_debt_wallet_row_committed(int $rid, string $ref): bool {
        global $pdo;
        if ($ref === '' || !($pdo instanceof PDO)) {
            return false;
        }
        try {
            $stmt = $pdo->prepare("SELECT 1 FROM fx_debt_log WHERE reseller_id = :rid AND invoice_ref = :ref AND scope = 'main' AND method = 'wallet_purchase' AND direction = 'in' LIMIT 1");
            $stmt->execute([':rid' => $rid, ':ref' => $ref]);
            return $stmt->fetchColumn() !== false;
        } catch (\Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('fx_debt_insert_wallet_row')) {
    /**
     * WALLET-FIRST: audit row for the wallet share actually drawn at reserve.
     * Convergence-safe: a replay of the same ref is a no-op (unique
     * idempotency_key 'main|wallet_purchase|ref'), so a retried commit can never
     * write the wallet row twice.
     */
    function fx_debt_insert_wallet_row(int $rid, int $wp, string $ref): void {
        global $pdo;
        $pdo->prepare("INSERT INTO fx_debt_log (reseller_id, amount, direction, method, scope, invoice_ref, debt_after, time, idempotency_key) VALUES (:rid, :amt, 'in', 'wallet_purchase', 'main', :ref, 0, NOW(), :k) ON DUPLICATE KEY UPDATE id = id")
            ->execute([':rid' => $rid, ':amt' => $wp, ':ref' => $ref, ':k' => 'main|wallet_purchase|' . $ref]);
    }
}

if (!function_exists('fx_debt_reserve_legacy')) {
    /**
     * Pre-P2 behaviour: single conditional UPDATE with the ceiling in the WHERE
     * clause. No reservation ref (kept as the pre-migration fallback for
     * fx_debt_reserve when the reservations table does not exist).
     * Returns [ok, reason, debtAfter]. reason: '' | 'ceiling' | 'db'.
     */
    function fx_debt_reserve_legacy(int $rid, int $amount): array {
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

if (!function_exists('fx_debt_reserve_split')) {
    /**
     * WALLET-FIRST (2026-10-17): split-aware reserve. Under one tx and the
     * consistent botsaz→user lock order it computes
     *   wallet_part = min(amount, max(0, Balance))   (positive credit first)
     *   debt_part   = amount - wallet_part           (remainder on credit)
     * stores the split on the reservation row, consumes the wallet share with a
     * guarded UPDATE (Balance >= wp) and adds ONLY debt_part to current_debt
     * (ceiling checked under the same lock). Ceiling failure rolls back cleanly
     * with no reservation row — same contract as the legacy reserve.
     * Returns [ok, reason, debtAfter]: '' | 'ceiling' | 'db' | duplicate states.
     */
    function fx_debt_reserve_split(int $rid, int $amount, string $ref): array {
        global $pdo;
        // read-only fast path for replayed refs (no locks, no side effects)
        $state0 = fx_debt_reservation_state($ref, $rid);
        if ($state0 !== '') {
            $cur0 = fx_debt_state($rid)['current'];
            if ($state0 === 'reserved') {
                return [true, 'duplicate', $cur0];
            }
            if ($state0 === 'committed') {
                return [false, 'already_committed', $cur0];
            }
            if ($state0 === 'released') {
                return [false, 'already_released', $cur0];
            }
            return [false, 'duplicate_unknown', $cur0];
        }
        $ownTx = false;
        try {
            if (!$pdo->inTransaction()) {
                $pdo->beginTransaction();
                $ownTx = true;
            }
            // lock the reseller row first (botsaz→user order, same as settle)
            $lk = $pdo->prepare("SELECT * FROM botsaz WHERE id_user = :rid LIMIT 1 FOR UPDATE");
            $lk->execute([':rid' => (string)$rid]);
            $bot = $lk->fetch(PDO::FETCH_ASSOC);
            if (!is_array($bot)) {
                if ($ownTx) { $pdo->commit(); $ownTx = false; }
                return [true, '', 0];
            }
            $current = (int)($bot['current_debt'] ?? 0);
            if ((($bot['debt_mode'] ?? 'balance') === 'debt' ? 'debt' : 'balance') !== 'debt') {
                if ($ownTx) { $pdo->commit(); $ownTx = false; }
                return [true, '', $current];
            }
            $ceiling = (int)($bot['debt_ceiling'] ?? 0);
            // lock the wallet row and compute the split under lock
            $bal = 0;
            try {
                $lu = $pdo->prepare("SELECT Balance FROM user WHERE id = :rid LIMIT 1 FOR UPDATE");
                $lu->execute([':rid' => (string)$rid]);
                $b = $lu->fetchColumn();
                if ($b !== false) {
                    $bal = (int)round((float)$b);
                }
            } catch (\Throwable $eU) {
                $bal = 0; // no user table/row → pure debt (legacy-compatible)
            }
            $wp = min($amount, max(0, $bal));
            $dp = $amount - $wp;
            if ($dp > 0 && ($ceiling <= 0 || $current + $dp > $ceiling)) {
                if ($ownTx) { $pdo->rollBack(); $ownTx = false; }
                return [false, 'ceiling', $current];
            }
            // unique ref = concurrency arbiter
            try {
                $pdo->prepare("INSERT INTO fx_debt_reservations (ref, reseller_id, amount, wallet_part, state, created_at, updated_at) VALUES (:ref, :rid, :amt, :wp, 'reserved', NOW(), NOW())")
                    ->execute([':ref' => $ref, ':rid' => $rid, ':amt' => $amount, ':wp' => $wp]);
            } catch (\Throwable $eIns) {
                if (!fx_debt_is_duplicate($eIns)) {
                    throw $eIns;
                }
                if ($ownTx) { $pdo->commit(); $ownTx = false; }
                $state = fx_debt_reservation_state($ref, $rid);
                $cur = fx_debt_state($rid)['current'];
                if ($state === 'reserved') {
                    return [true, 'duplicate', $cur];
                }
                if ($state === 'committed') {
                    return [false, 'already_committed', $cur];
                }
                if ($state === 'released') {
                    return [false, 'already_released', $cur];
                }
                return [false, 'duplicate_unknown', $cur];
            }
            // consume the wallet share (row is locked → rowCount must be 1)
            if ($wp > 0) {
                $stw = $pdo->prepare("UPDATE user SET Balance = Balance - :wp WHERE id = :rid AND Balance >= :wp2");
                $stw->execute([':wp' => $wp, ':wp2' => $wp, ':rid' => (string)$rid]);
                if ($stw->rowCount() !== 1) {
                    throw new \RuntimeException('wallet draw affected ' . $stw->rowCount() . ' rows');
                }
            }
            // add only the debt part (ceiling verified under lock; guards kept)
            if ($dp > 0) {
                $st2 = $pdo->prepare("UPDATE botsaz SET current_debt = current_debt + :dp WHERE id_user = :rid AND debt_mode = 'debt' AND debt_ceiling > 0 AND current_debt + :dp2 <= debt_ceiling");
                $st2->execute([':dp' => $dp, ':dp2' => $dp, ':rid' => (string)$rid]);
                if ($st2->rowCount() === 0) {
                    if ($ownTx) { $pdo->rollBack(); $ownTx = false; }
                    return [false, 'ceiling', $current];
                }
            }
            if ($ownTx) {
                $pdo->commit();
                $ownTx = false;
            }
            return [true, '', fx_debt_state($rid)['current']];
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[FX_DEBT] reserve split failed rid=' . $rid . ' amt=' . $amount . ' ref=' . $ref . ': ' . $e->getMessage());
            return [false, 'db', 0];
        }
    }
}

if (!function_exists('fx_debt_reserve')) {
    /**
     * BR-01 + FX-DEBT-P2: atomically pre-charge the reseller's debt BEFORE
     * service creation, with a unique per-attempt $ref recorded in
     * fx_debt_reservations (state reserved|committed|released).
     *  - same ref retried while reserved  → [true,'duplicate',…] (caller may proceed);
     *  - ref already committed            → [false,'already_committed',…];
     *  - ref already released             → [false,'already_released',…].
     * The debt UPDATE keeps the ceiling in the WHERE clause (concurrency-safe);
     * on ceiling failure the reservation row is rolled back with the same tx.
     * Without a ref (or before the migration) this is the legacy single UPDATE.
     * Returns [ok, reason, debtAfter]. reason: '' | 'ceiling' | 'db' | states above.
     */
    function fx_debt_reserve(int $rid, int $amount, string $ref = ''): array {
        global $pdo;
        if ($amount <= 0) {
            return [true, '', 0];
        }
        if ($ref === '' || !fx_debt_res_table() || !($pdo instanceof PDO)) {
            return fx_debt_reserve_legacy($rid, $amount);
        }
        // WALLET-FIRST (2026-10-17): split-aware reserve when active for this reseller.
        if (fx_debt_wallet_first($rid)) {
            return fx_debt_reserve_split($rid, $amount, $ref);
        }
        $ownTx = false;
        try {
            if (!$pdo->inTransaction()) {
                $pdo->beginTransaction();
                $ownTx = true;
            }
            try {
                $pdo->prepare("INSERT INTO fx_debt_reservations (ref, reseller_id, amount, state, created_at, updated_at) VALUES (:ref, :rid, :amt, 'reserved', NOW(), NOW())")
                    ->execute([':ref' => $ref, ':rid' => $rid, ':amt' => $amount]);
            } catch (\Throwable $eIns) {
                if (fx_debt_is_duplicate($eIns)) {
                    if ($ownTx) {
                        $pdo->commit();
                        $ownTx = false;
                    }
                    $state = fx_debt_reservation_state($ref, $rid);
                    $cur = fx_debt_state($rid)['current'];
                    if ($state === 'reserved') {
                        return [true, 'duplicate', $cur];
                    }
                    if ($state === 'committed') {
                        return [false, 'already_committed', $cur];
                    }
                    if ($state === 'released') {
                        return [false, 'already_released', $cur];
                    }
                    return [false, 'duplicate_unknown', $cur];
                }
                throw $eIns;
            }
            $stmt = $pdo->prepare("UPDATE botsaz SET current_debt = current_debt + :amt WHERE id_user = :rid AND debt_mode = 'debt' AND debt_ceiling > 0 AND current_debt + :amt2 <= debt_ceiling");
            $stmt->execute([':amt' => $amount, ':amt2' => $amount, ':rid' => (string)$rid]);
            if ($stmt->rowCount() === 0) {
                if ($ownTx) {
                    $pdo->rollBack();
                    $ownTx = false;
                }
                $st = fx_debt_state($rid);
                if ($st['mode'] !== 'debt') {
                    return [true, '', $st['current']];
                }
                return [false, 'ceiling', $st['current']];
            }
            if ($ownTx) {
                $pdo->commit();
                $ownTx = false;
            }
            return [true, '', fx_debt_state($rid)['current']];
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[FX_DEBT] reserve failed rid=' . $rid . ' amt=' . $amount . ' ref=' . $ref . ': ' . $e->getMessage());
            return [false, 'db', 0];
        }
    }
}

if (!function_exists('fx_debt_commit_split')) {
    /**
     * WALLET-FIRST (2026-10-17): commit a reservation whose wallet share was
     * already drawn at reserve time. In one tx (unique-key arbitrated) writes:
     *   - bot-scope arbiter row with the DEBT part  (key 'bot|method|ref');
     *   - main-scope wallet audit row with the wallet part
     *     (key 'main|wallet_purchase|ref', debt_after=0).
     * The split is driven by the stored reservation row, never by the current
     * kill-switch state. Returns [ok, reason, debtAfter, alertHtml].
     */
    function fx_debt_commit_split(int $rid, int $amount, string $method, string $ref, int $wp): array {
        global $pdo;
        $dp = max(0, $amount - $wp);
        $ownTx = false;
        try {
            $st = fx_debt_state($rid);
            $after = (int)$st['current'];
            // a released reservation can never be committed (same guard as legacy)
            if (fx_debt_reservation_state($ref, $rid) === 'released') {
                if (function_exists('rx_log_event')) {
                    rx_log_event('FX_DEBT_COMMIT_AFTER_RELEASE', 'commit refused: reservation already released', ['rid' => $rid, 'amount' => $amount, 'ref' => $ref, 'method' => $method]);
                }
                error_log('[FX_DEBT] commit refused after release rid=' . $rid . ' ref=' . $ref);
                return [false, 'reserved_released', $after, ''];
            }
            if (fx_debt_ref_committed($rid, $ref, $method)) {
                // convergence: finish a split that stopped between the two rows
                if ($wp > 0 && !fx_debt_wallet_row_committed($rid, $ref)) {
                    try {
                        fx_debt_insert_wallet_row($rid, $wp, $ref);
                    } catch (\Throwable $eW) {
                        error_log('[FX_DEBT] wallet row convergence failed rid=' . $rid . ' ref=' . $ref . ': ' . $eW->getMessage());
                    }
                }
                fx_debt_reservation_mark($ref, 'committed');
                return [true, 'duplicate', $after, ''];
            }
            if (($pdo instanceof PDO) && !$pdo->inTransaction()) {
                $pdo->beginTransaction();
                $ownTx = true;
            }
            // bot arbiter row first (amount may be 0 for a pure-wallet charge)
            fx_debt_insert_in($rid, $dp, $method, $ref, $after);
            if ($wp > 0) {
                fx_debt_insert_wallet_row($rid, $wp, $ref);
            }
            fx_debt_reservation_mark($ref, 'committed');
            if ($ownTx) {
                $pdo->commit();
                $ownTx = false;
            }
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if (fx_debt_is_duplicate($e)) {
                return [true, 'duplicate', fx_debt_state($rid)['current'], ''];
            }
            error_log('[FX_DEBT] commit split failed rid=' . $rid . ' amt=' . $amount . ' ref=' . $ref . ': ' . $e->getMessage());
            return [false, 'db', 0, ''];
        }
        $row = is_array($st['bot']) ? $st['bot'] : [];
        $alert = fx_debt_alert_eval($rid, $after, $row);
        if (function_exists('fx_reseller_sale_notify')) {
            fx_reseller_sale_notify($rid, $amount, $method, (string)$ref, $after, $wp);
        }
        return [true, '', $after, $alert];
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
        // WALLET-FIRST (2026-10-17): reservations created by the split reserve
        // carry the wallet share — commit mirrors the stored row. Legacy rows
        // (wallet_part=0 / no column) keep the legacy single-row commit.
        if ($ref !== '' && fx_debt_wallet_column()) {
            $wpC = fx_debt_reservation_wallet_part($ref, $rid, $amount);
            if ($wpC > 0) {
                return fx_debt_commit_split($rid, $amount, $method, $ref, $wpC);
            }
        }
        try {
            $st = fx_debt_state($rid);
            $after = $st['current'];
            // FX-DEBT-P2: a released reservation can never be committed.
            if ($ref !== '' && fx_debt_reservation_state($ref, $rid) === 'released') {
                if (function_exists('rx_log_event')) {
                    rx_log_event('FX_DEBT_COMMIT_AFTER_RELEASE', 'commit refused: reservation already released', ['rid' => $rid, 'amount' => $amount, 'ref' => $ref, 'method' => $method]);
                }
                error_log('[FX_DEBT] commit refused after release rid=' . $rid . ' ref=' . $ref);
                return [false, 'reserved_released', $after, ''];
            }
            // FX-DEBT-P1: idempotent — a retry/duplicate can never write a second 'in' row.
            if ($ref !== '' && fx_debt_ref_committed($rid, $ref, $method)) {
                fx_debt_reservation_mark($ref, 'committed');
                return [true, 'duplicate', $after, ''];
            }
            fx_debt_insert_in($rid, $amount, $method, $ref, $after);
            fx_debt_reservation_mark($ref, 'committed');
        } catch (\Throwable $e) {
            if (fx_debt_is_duplicate($e)) {
                return [true, 'duplicate', fx_debt_state($rid)['current'], ''];
            }
            error_log('[FX_DEBT] commit failed rid=' . $rid . ' amt=' . $amount . ' ref=' . $ref . ': ' . $e->getMessage());
            return [false, 'db', 0, ''];
        }
        $row = is_array($st['bot']) ? $st['bot'] : [];
        $alert = fx_debt_alert_eval($rid, $after, $row);
        if (function_exists('fx_reseller_sale_notify')) {
            fx_reseller_sale_notify($rid, $amount, $method, (string)$ref, $after);
        }
        return [true, '', $after, $alert];
    }
}

if (!function_exists('fx_debt_release_split')) {
    /**
     * WALLET-FIRST (2026-10-17): release a split reservation after a FAILED
     * service creation. Refunds the wallet share actually drawn at reserve and
     * decrements the debt by the remainder only. Same guards/arbiter as the
     * legacy release (never after commit; zero-amount 'bot|revert|ref' marker;
     * no negative debt). The settle-then-release edge (debt part already
     * settled) keeps the legacy no-op semantics: the whole release rolls back
     * and the reservation stays 'reserved' for the orphan report.
     */
    function fx_debt_release_split(int $rid, int $amount, string $ref, int $wp): void {
        global $pdo;
        $dp = max(0, $amount - $wp);
        $ownTx = false;
        try {
            // If the sale was already committed (an 'in' row exists for this ref),
            // releasing would corrupt the account — refuse.
            if (fx_debt_ref_committed($rid, $ref) || fx_debt_reservation_state($ref, $rid) === 'committed') {
                if (function_exists('rx_log_event')) {
                    rx_log_event('FX_DEBT_RELEASE_AFTER_COMMIT', 'release skipped: debt already committed', ['rid' => $rid, 'amount' => $amount, 'ref' => $ref]);
                }
                return;
            }
            $useIdem = ($ref !== '' && fx_debt_idem_column() && ($pdo instanceof PDO));
            if (!$pdo->inTransaction()) {
                $pdo->beginTransaction();
                $ownTx = true;
            }
            // consistent botsaz→user lock order with reserve/settle (deadlock-safe)
            $lk = $pdo->prepare("SELECT current_debt FROM botsaz WHERE id_user = :rid LIMIT 1 FOR UPDATE");
            $lk->execute([':rid' => (string)$rid]);
            $lk->fetchColumn();
            if ($useIdem) {
                // reserve the release marker first: the unique key is the arbiter,
                // so a concurrent/second release hits 1062 and is a clean no-op.
                $pdo->prepare("INSERT INTO fx_debt_log (reseller_id, amount, direction, method, scope, invoice_ref, debt_after, time, idempotency_key) VALUES (:rid, 0, 'out', 'revert', 'bot', :ref, 0, NOW(), :k)")
                    ->execute([':rid' => $rid, ':ref' => $ref, ':k' => 'bot|revert|' . $ref]);
            } else {
                $dup = $pdo->prepare("SELECT 1 FROM fx_debt_log WHERE reseller_id = :rid AND invoice_ref = :ref AND method = 'revert' AND direction = 'out' LIMIT 1");
                $dup->execute([':rid' => $rid, ':ref' => $ref]);
                if ($dup->fetchColumn() !== false) {
                    // FX-DEBT-P2: an interrupted legacy release (marker written but the
                    // decrement never committed) leaves the reservation 'reserved' —
                    // in that case fall through and complete the release below.
                    if (!(fx_debt_res_table() && fx_debt_reservation_state($ref, $rid) === 'reserved')) {
                        if ($ownTx) { $pdo->rollBack(); $ownTx = false; }
                        return;
                    }
                }
                $pdo->prepare("INSERT INTO fx_debt_log (reseller_id, amount, direction, method, scope, invoice_ref, debt_after, time) VALUES (:rid, 0, 'out', 'revert', 'bot', :ref, 0, NOW())")
                    ->execute([':rid' => $rid, ':ref' => $ref]);
            }
            // refund the wallet share first (cannot legitimately fail; the row
            // is not locked here, but the guarded decrement below keeps the debt
            // part consistent, and a fully-settled debt keeps the legacy no-op).
            if ($wp > 0) {
                $str = $pdo->prepare("UPDATE user SET Balance = Balance + :wp WHERE id = :rid");
                $str->execute([':wp' => $wp, ':rid' => (string)$rid]);
                if ($str->rowCount() !== 1) {
                    throw new \RuntimeException('wallet refund affected ' . $str->rowCount() . ' rows');
                }
            }
            // FX-DEBT-P1: conditional single UPDATE — double/concurrent release can
            // never push the debt below zero (the second call updates 0 rows).
            if ($dp > 0) {
                $stmt = $pdo->prepare("UPDATE botsaz SET current_debt = current_debt - :amt WHERE id_user = :rid AND current_debt >= :amt2");
                $stmt->execute([':amt' => $dp, ':amt2' => $dp, ':rid' => (string)$rid]);
                if ($stmt->rowCount() === 0) {
                    if ($ownTx) {
                        $pdo->rollBack();
                        $ownTx = false;
                    } elseif ($wp > 0) {
                        // caller-owned tx: undo the wallet refund to keep money state unchanged
                        $stc = $pdo->prepare("UPDATE user SET Balance = Balance - :wp WHERE id = :rid AND Balance >= :wp2");
                        $stc->execute([':wp' => $wp, ':wp2' => $wp, ':rid' => (string)$rid]);
                    }
                    // nothing safe to release (never reserved, already released, or
                    // settlement moved the balance) — leave the debt untouched.
                    @error_log('[FX_DEBT] release split noop rid=' . $rid . ' amt=' . $amount . ' wp=' . $wp . ' ref=' . $ref . ' current=' . fx_debt_state($rid)['current']);
                    return;
                }
            }
            $st = fx_debt_state($rid);
            $after = (int)$st['current'];
            if ($useIdem) {
                $pdo->prepare("UPDATE fx_debt_log SET debt_after = :after WHERE reseller_id = :rid AND idempotency_key = :k")
                    ->execute([':after' => $after, ':rid' => $rid, ':k' => 'bot|revert|' . $ref]);
            }
            // FX-DEBT-P2: close the reservation (joins the tx above when one is open).
            if (fx_debt_res_table()) {
                fx_debt_reservation_mark($ref, 'released');
            }
            if ($ownTx) {
                $pdo->commit();
                $ownTx = false;
            }
            if (function_exists('rx_log_event')) {
                rx_log_event('FX_DEBT_RELEASE', 'debt reservation released (service creation failed; wallet-first)', ['rid' => $rid, 'amount' => $amount, 'wallet_refund' => $wp, 'debt_revert' => $dp, 'ref' => $ref, 'debt_after' => $after]);
            }
            if ($st['ceiling'] > 0 && $after * 100 < $st['ceiling'] * 80) {
                @update("botsaz", "debt_alert_80", 0, "id_user", $rid);
                @update("botsaz", "debt_alert_95", 0, "id_user", $rid);
            }
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if (fx_debt_is_duplicate($e)) {
                if (function_exists('rx_log_event')) {
                    rx_log_event('FX_DEBT_RELEASE_DUPLICATE', 'release replayed for the same ref — no-op', ['rid' => $rid, 'amount' => $amount, 'ref' => $ref]);
                }
                return;
            }
            error_log('[FX_DEBT] release split failed rid=' . $rid . ' amt=' . $amount . ' ref=' . $ref . ': ' . $e->getMessage());
        }
    }
}

if (!function_exists('fx_debt_release')) {
    /**
     * Roll back a reservation after a FAILED service creation (no ledger 'in'
     * was written for this ref). FX-DEBT-P1:
     *  - refuses when the ref was already committed (release-after-commit guard);
     *  - idempotent: a re-release of the same ref is a no-op (marker row variant);
     *  - never decrements below zero (conditional UPDATE);
     *  - a release is NOT a financial event — when the unique-key column exists a
     *    ZERO-amount marker row (amount=0, direction='out', method='revert',
     *    idempotency_key='bot|revert|ref') is used solely as a race-safe dedupe
     *    arbiter; it does not distort ledger sums (amount=0). Without the column,
     *    a SELECT-dedupe (documented small race) is used.
     */
    function fx_debt_release(int $rid, int $amount, string $ref): void {
        global $pdo;
        if ($amount <= 0) {
            return;
        }
        // WALLET-FIRST (2026-10-17): reservations created by the split reserve
        // carry the wallet share — release refunds it. Driven by the stored
        // reservation row, never by the current kill-switch state.
        if ($ref !== '' && fx_debt_wallet_column()) {
            $wpR = fx_debt_reservation_wallet_part($ref, $rid, $amount);
            if ($wpR > 0) {
                fx_debt_release_split($rid, $amount, $ref, $wpR);
                return;
            }
        }
        $ownTx = false;
        try {
            // If the sale was already committed (an 'in' row exists for this ref),
            // releasing would corrupt the account — refuse.
            if ($ref !== '' && (fx_debt_ref_committed($rid, $ref) || fx_debt_reservation_state($ref, $rid) === 'committed')) {
                if (function_exists('rx_log_event')) {
                    rx_log_event('FX_DEBT_RELEASE_AFTER_COMMIT', 'release skipped: debt already committed', ['rid' => $rid, 'amount' => $amount, 'ref' => $ref]);
                }
                return;
            }
            $useIdem = ($ref !== '' && fx_debt_idem_column() && ($pdo instanceof PDO));
            if ($useIdem) {
                if (!$pdo->inTransaction()) {
                    $pdo->beginTransaction();
                    $ownTx = true;
                }
                // reserve the release marker first: the unique key is the arbiter,
                // so a concurrent/second release hits 1062 and is a clean no-op.
                $pdo->prepare("INSERT INTO fx_debt_log (reseller_id, amount, direction, method, scope, invoice_ref, debt_after, time, idempotency_key) VALUES (:rid, 0, 'out', 'revert', 'bot', :ref, 0, NOW(), :k)")
                    ->execute([':rid' => $rid, ':ref' => $ref, ':k' => 'bot|revert|' . $ref]);
            } elseif ($ref !== '') {
                $dup = $pdo->prepare("SELECT 1 FROM fx_debt_log WHERE reseller_id = :rid AND invoice_ref = :ref AND method = 'revert' AND direction = 'out' LIMIT 1");
                $dup->execute([':rid' => $rid, ':ref' => $ref]);
                if ($dup->fetchColumn() !== false) {
                    // FX-DEBT-P2: an interrupted legacy release (marker written but the
                    // decrement never committed) leaves the reservation 'reserved' —
                    // in that case fall through and complete the release below.
                    if (!(fx_debt_res_table() && fx_debt_reservation_state($ref, $rid) === 'reserved')) {
                        return;
                    }
                }
                // marker without the unique-key column (legacy schema): SELECT-dedupe above.
                $pdo->prepare("INSERT INTO fx_debt_log (reseller_id, amount, direction, method, scope, invoice_ref, debt_after, time) VALUES (:rid, 0, 'out', 'revert', 'bot', :ref, 0, NOW())")
                    ->execute([':rid' => $rid, ':ref' => $ref]);
            }
            // FX-DEBT-P1: conditional single UPDATE — double/concurrent release can
            // never push the debt below zero (the second call updates 0 rows).
            $stmt = $pdo->prepare("UPDATE botsaz SET current_debt = current_debt - :amt WHERE id_user = :rid AND current_debt >= :amt2");
            $stmt->execute([':amt' => $amount, ':amt2' => $amount, ':rid' => (string)$rid]);
            if ($stmt->rowCount() === 0) {
                if ($ownTx) {
                    $pdo->rollBack();
                    $ownTx = false;
                }
                // nothing safe to release (never reserved, already released, or
                // settlement moved the balance) — log and leave the debt untouched.
                @error_log('[FX_DEBT] release noop rid=' . $rid . ' amt=' . $amount . ' ref=' . $ref . ' current=' . fx_debt_state($rid)['current']);
                return;
            }
            $st = fx_debt_state($rid);
            $after = (int)$st['current'];
            if ($useIdem) {
                $pdo->prepare("UPDATE fx_debt_log SET debt_after = :after WHERE reseller_id = :rid AND idempotency_key = :k")
                    ->execute([':after' => $after, ':rid' => $rid, ':k' => 'bot|revert|' . $ref]);
            }
            // FX-DEBT-P2: close the reservation (joins the tx above when one is open).
            if ($ref !== '' && fx_debt_res_table()) {
                fx_debt_reservation_mark($ref, 'released');
            }
            if ($ownTx) {
                $pdo->commit();
                $ownTx = false;
            }
            if (function_exists('rx_log_event')) {
                rx_log_event('FX_DEBT_RELEASE', 'debt reservation released (service creation failed)', ['rid' => $rid, 'amount' => $amount, 'ref' => $ref, 'debt_after' => $after]);
            }
            if ($st['ceiling'] > 0 && $after * 100 < $st['ceiling'] * 80) {
                @update("botsaz", "debt_alert_80", 0, "id_user", $rid);
                @update("botsaz", "debt_alert_95", 0, "id_user", $rid);
            }
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if (fx_debt_is_duplicate($e)) {
                if (function_exists('rx_log_event')) {
                    rx_log_event('FX_DEBT_RELEASE_DUPLICATE', 'release replayed for the same ref — no-op', ['rid' => $rid, 'amount' => $amount, 'ref' => $ref]);
                }
                return;
            }
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
        $ownTx = false;
        $st = null;
        try {
            $useIdem = ($ref !== '' && fx_debt_idem_column() && ($pdo instanceof PDO));
            if ($ref !== '' && fx_debt_ref_committed($rid, $ref, $method)) {
                return [true, 'duplicate', fx_debt_state($rid)['current'], ''];
            }
            if ($useIdem && !$pdo->inTransaction()) {
                $pdo->beginTransaction();
                $ownTx = true;
            }
            if ($useIdem) {
                // FX-DEBT-P1: serialize concurrent writers on this reseller row.
                // The second writer waits here, then sees the first writer's
                // committed ledger row in the dedupe check below.
                $lk = $pdo->prepare("SELECT current_debt FROM botsaz WHERE id_user = :rid LIMIT 1 FOR UPDATE");
                $lk->execute([':rid' => (string)$rid]);
                $lk->fetchColumn();
                if (fx_debt_ref_committed($rid, $ref, $method)) {
                    if ($ownTx) {
                        $pdo->commit();
                    }
                    return [true, 'duplicate', fx_debt_state($rid)['current'], ''];
                }
            }
            $pdo->prepare("UPDATE botsaz SET current_debt = current_debt + :amt WHERE id_user = :rid")
                ->execute([':amt' => $amount, ':rid' => (string)$rid]);
            $st = fx_debt_state($rid);
            fx_debt_insert_in($rid, $amount, $method, $ref, (int)$st['current']);
            if ($ownTx) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if (fx_debt_is_duplicate($e)) {
                return [true, 'duplicate', fx_debt_state($rid)['current'], ''];
            }
            error_log('[FX_DEBT] inc failed rid=' . $rid . ' amt=' . $amount . ' ref=' . $ref . ': ' . $e->getMessage());
            return [false, 'db', 0, ''];
        }
        $after = (int)$st['current'];
        $row = is_array($st['bot']) ? $st['bot'] : [];
        $alert = fx_debt_alert_eval($rid, $after, $row);
        if (function_exists('fx_reseller_sale_notify')) {
            fx_reseller_sale_notify($rid, $amount, $method, (string)$ref, $after);
        }
        return [true, '', $after, $alert];
    }
}

if (!function_exists('fx_debt_record_once_split')) {
    /**
     * WALLET-FIRST (2026-10-17): retry-safe record for flows WITHOUT a
     * pre-reserve (gateway callback + reconcile). Under the botsaz→user lock
     * order it computes the split, consumes the wallet share, adds only the
     * remainder to debt (ceiling-gated) and writes the bot arbiter row + main
     * wallet audit row in one tx. Reason 'ceiling' means the DEBT share would
     * exceed the ceiling and NOTHING was charged.
     * Returns [ok, reason, debtAfter, alertHtml].
     */
    function fx_debt_record_once_split(int $rid, int $amount, string $method, string $ref): array {
        global $pdo;
        $ownTx = false;
        $wp = 0;
        try {
            if (!$pdo->inTransaction()) {
                $pdo->beginTransaction();
                $ownTx = true;
            }
            $lk = $pdo->prepare("SELECT * FROM botsaz WHERE id_user = :rid LIMIT 1 FOR UPDATE");
            $lk->execute([':rid' => (string)$rid]);
            $bot = $lk->fetch(PDO::FETCH_ASSOC);
            if (!is_array($bot)) {
                if ($ownTx) { $pdo->commit(); $ownTx = false; }
                return [true, '', 0, ''];
            }
            $current = (int)($bot['current_debt'] ?? 0);
            $ceiling = (int)($bot['debt_ceiling'] ?? 0);
            if ((($bot['debt_mode'] ?? 'balance') === 'debt' ? 'debt' : 'balance') !== 'debt') {
                if ($ownTx) { $pdo->commit(); $ownTx = false; }
                return [true, '', $current, ''];
            }
            // dedupe under the lock: the second concurrent writer waits here,
            // then sees the first writer's committed ledger row.
            if ($ref !== '' && fx_debt_ref_committed($rid, $ref, $method)) {
                if ($ownTx) { $pdo->commit(); $ownTx = false; }
                return [true, 'duplicate', $current, ''];
            }
            // lock the wallet row and compute the split under lock
            $bal = 0;
            try {
                $lu = $pdo->prepare("SELECT Balance FROM user WHERE id = :rid LIMIT 1 FOR UPDATE");
                $lu->execute([':rid' => (string)$rid]);
                $b = $lu->fetchColumn();
                if ($b !== false) {
                    $bal = (int)round((float)$b);
                }
            } catch (\Throwable $eU) {
                $bal = 0; // no user table/row → pure debt (legacy-compatible)
            }
            $wp = min($amount, max(0, $bal));
            $dp = $amount - $wp;
            if ($dp > 0 && ($ceiling <= 0 || $current + $dp > $ceiling)) {
                if ($ownTx) { $pdo->rollBack(); $ownTx = false; }
                return [false, 'ceiling', $current, ''];
            }
            // bot arbiter row first (amount may be 0 for a pure-wallet charge)
            fx_debt_insert_in($rid, $dp, $method, $ref, $current);
            if ($dp > 0) {
                $st2 = $pdo->prepare("UPDATE botsaz SET current_debt = current_debt + :dp WHERE id_user = :rid AND debt_mode = 'debt' AND debt_ceiling > 0 AND current_debt + :dp2 <= debt_ceiling");
                $st2->execute([':dp' => $dp, ':dp2' => $dp, ':rid' => (string)$rid]);
                if ($st2->rowCount() === 0) {
                    if ($ownTx) { $pdo->rollBack(); $ownTx = false; }
                    return [false, 'ceiling', $current, ''];
                }
            }
            if ($wp > 0) {
                $stw = $pdo->prepare("UPDATE user SET Balance = Balance - :wp WHERE id = :rid AND Balance >= :wp2");
                $stw->execute([':wp' => $wp, ':wp2' => $wp, ':rid' => (string)$rid]);
                if ($stw->rowCount() !== 1) {
                    throw new \RuntimeException('wallet draw affected ' . $stw->rowCount() . ' rows');
                }
                fx_debt_insert_wallet_row($rid, $wp, $ref);
            }
            if ($ownTx) {
                $pdo->commit();
                $ownTx = false;
            }
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if (fx_debt_is_duplicate($e)) {
                return [true, 'duplicate', fx_debt_state($rid)['current'], ''];
            }
            error_log('[FX_DEBT] record_once split failed rid=' . $rid . ' amt=' . $amount . ' ref=' . $ref . ': ' . $e->getMessage());
            return [false, 'db', 0, ''];
        }
        $st = fx_debt_state($rid);
        $after = (int)$st['current'];
        $row = is_array($st['bot']) ? $st['bot'] : [];
        $alert = fx_debt_alert_eval($rid, $after, $row);
        if (function_exists('fx_reseller_sale_notify')) {
            fx_reseller_sale_notify($rid, $amount, $method, (string)$ref, $after, $wp);
        }
        return [true, '', $after, $alert];
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
        // WALLET-FIRST (2026-10-17): atomic split (wallet first, remainder to
        // debt with the ceiling gate) when active for this reseller.
        if ($ref !== '' && fx_debt_wallet_first($rid) && ($pdo instanceof PDO)) {
            return fx_debt_record_once_split($rid, $amount, $method, $ref);
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

if (!function_exists('fx_debt_gateway_record_once')) {
    /**
     * FX-DEBT-P2: record wholesale debt for a gateway-paid order whose delivery
     * has ALREADY happened. Callers MUST invoke this BEFORE rx_pf_complete so a
     * failure keeps the fulfillment retryable/manual instead of completing a
     * debt-less order. Idempotent by order ref (fx_debt_record_once).
     * Wholesale resolution order: $stash (pre-op value) → product resolver →
     * unit resolver ($unit = ['column' => …, 'qty' => …]).
     * Returns ['action' => recorded|duplicate|skipped|skipped_balance|
     *                     missing_wholesale|db_failed|ceiling_exceeded,
     *          'amount' => int, 'reason' => string].
     * WALLET-FIRST: with wallet-first active the wallet share is consumed first
     * and only the remainder is checked against the ceiling; 'ceiling_exceeded'
     * (reason 'ceiling') is a safe-failure contract — callers treat it exactly
     * like db_failed (manual review / reconcile) and NOTHING was charged.
     */
    function fx_debt_gateway_record_once(string $orderRef, string $method, $product, array $panel, string $agent, int $stash = 0, ?array $unit = null): array {
        if (!defined('FX_RESELLER_ID') || !function_exists('fx_debt_state') || !function_exists('fx_debt_record_once')) {
            return ['action' => 'skipped', 'amount' => 0, 'reason' => 'no_fx_context'];
        }
        $rid = (int)FX_RESELLER_ID;
        if (fx_debt_state($rid)['mode'] !== 'debt') {
            return ['action' => 'skipped_balance', 'amount' => 0, 'reason' => 'balance_mode'];
        }
        $amount = $stash;
        if ($amount <= 0 && is_array($product) && $product && function_exists('fx_credit_wholesale_product')) {
            $r = fx_credit_wholesale_product($product, $panel, $agent);
            if (!empty($r['ok'])) {
                $amount = (int)$r['amount'];
            }
        }
        if ($amount <= 0 && is_array($unit) && !empty($unit['column']) && function_exists('fx_credit_wholesale_unit')) {
            $r = fx_credit_wholesale_unit($panel, (string)$unit['column'], $agent, $unit['qty'] ?? 0);
            if (!empty($r['ok'])) {
                $amount = (int)$r['amount'];
            }
        }
        if ($amount <= 0) {
            return ['action' => 'missing_wholesale', 'amount' => 0, 'reason' => 'no_wholesale'];
        }
        list($ok, $reason) = fx_debt_record_once($rid, $amount, $method, $orderRef);
        if (empty($ok)) {
            // WALLET-FIRST: 'ceiling' = the debt share would exceed the ceiling;
            // callers treat it exactly like db_failed (manual review / reconcile).
            return ['action' => ($reason === 'ceiling' ? 'ceiling_exceeded' : 'db_failed'), 'amount' => $amount, 'reason' => (string)$reason];
        }
        return ['action' => ($reason === 'duplicate' ? 'duplicate' : 'recorded'), 'amount' => $amount, 'reason' => (string)$reason];
    }
}

if (!function_exists('fx_debt_revert')) {
    /**
     * Legacy alias kept for API compatibility. FX-DEBT-P1: delegates to
     * fx_debt_release (idempotent; zero-amount revert marker instead of orphan
     * financial 'out' rows).
     */
    function fx_debt_revert(int $rid, int $amount, string $ref): void {
        fx_debt_release($rid, $amount, $ref);
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

if (!function_exists('fx_res_setting_get')) {
    /**
     * FX-RESELLER (2026-10-16): read botsaz.setting JSON for a reseller.
     * Used by the reseller-facing features (sale notify toggle, bot stop flag,
     * weekly statement cadence) from both bot processes and cron.
     */
    function fx_res_setting_get(int $rid): array {
        global $pdo;
        if ($rid <= 0) {
            return [];
        }
        if ($pdo instanceof PDO) {
            try {
                $stmt = $pdo->prepare("SELECT setting FROM botsaz WHERE id_user = :rid LIMIT 1");
                $stmt->execute([':rid' => (string)$rid]);
                $raw = (string)$stmt->fetchColumn();
                $s = json_decode($raw, true);
                return is_array($s) ? $s : [];
            } catch (\Throwable $e) {
                return [];
            }
        }
        return [];
    }
}

if (!function_exists('fx_res_setting_patch')) {
    /**
     * Merge $patch keys into botsaz.setting (JSON). null value removes the key.
     * Returns true when the row was updated.
     */
    function fx_res_setting_patch(int $rid, array $patch): bool {
        global $pdo;
        if ($rid <= 0 || !($pdo instanceof PDO)) {
            return false;
        }
        $s = fx_res_setting_get($rid);
        foreach ($patch as $k => $v) {
            if ($v === null) {
                unset($s[$k]);
            } else {
                $s[$k] = $v;
            }
        }
        try {
            $stmt = $pdo->prepare("UPDATE botsaz SET setting = :s WHERE id_user = :rid");
            $stmt->execute([':s' => json_encode($s, JSON_UNESCAPED_UNICODE), ':rid' => (string)$rid]);
            return $stmt->rowCount() >= 0;
        } catch (\Throwable $e) {
            error_log('[FX_RES] setting patch failed rid=' . $rid . ': ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('fx_sale_notify_enabled')) {
    /** Sale notifications are ON by default; botsaz.setting.sale_notify='0' disables. */
    function fx_sale_notify_enabled(int $rid): bool {
        $s = fx_res_setting_get($rid);
        return (string)($s['sale_notify'] ?? '1') !== '0';
    }
}

if (!function_exists('fx_reseller_sale_notify')) {
    /**
     * FX-RESELLER (2026-10-16): notify the reseller (via their own bot token) about
     * every charged sale. Best-effort: never throws into the payment flow.
     * WALLET-FIRST (2026-10-17): $walletPart > 0 renders the wallet/debt breakdown.
     */
    function fx_reseller_sale_notify(int $rid, int $amount, string $method, string $ref, int $after, int $walletPart = 0): void {
        global $pdo;
        if ($rid <= 0 || $amount <= 0) {
            return;
        }
        try {
            if (!fx_sale_notify_enabled($rid)) {
                return;
            }
            $typeLabel = [
                'purchase' => '🛒 خرید سرویس جدید',
                'purchase_gateway' => '🛒 خرید سرویس جدید (درگاه)',
                'bulk_purchase' => '🛒 خرید عمده (چند سرویس)',
                'change_location' => '📍 تغییر موقعیت سرویس',
                'renewal' => '🔁 تمدید سرویس',
                'renewal_gateway' => '🔁 تمدید سرویس (درگاه)',
                'extra_volume' => '🔋 خرید حجم اضافه',
                'extra_volume_gateway' => '🔋 خرید حجم اضافه (درگاه)',
                'extra_time' => '⏳ خرید زمان اضافه',
                'extra_time_gateway' => '⏳ خرید زمان اضافه (درگاه)',
            ][$method] ?? ('🧾 فروش (' . $method . ')');

            $buyerId = '';
            $product = '';
            $paidTxt = '';
            if ($pdo instanceof PDO && $ref !== '') {
                try {
                    if (in_array($method, ['renewal_gateway', 'extra_volume_gateway', 'extra_time_gateway', 'purchase_gateway'], true)) {
                        $stmt = $pdo->prepare("SELECT id_user, price FROM Payment_report WHERE id_order = :ref LIMIT 1");
                        $stmt->execute([':ref' => $ref]);
                        $row = $stmt->fetch(PDO::FETCH_ASSOC);
                        if (is_array($row)) {
                            $buyerId = (string)$row['id_user'];
                            $paid = (int)round((float)$row['price']);
                            if ($paid > 0) $paidTxt = number_format($paid) . ' تومان';
                        }
                    } else {
                        $stmt = $pdo->prepare("SELECT id_user, name_product, price_product FROM invoice WHERE id_invoice = :ref LIMIT 1");
                        $stmt->execute([':ref' => $ref]);
                        $row = $stmt->fetch(PDO::FETCH_ASSOC);
                        if (is_array($row)) {
                            $buyerId = (string)$row['id_user'];
                            $product = trim((string)($row['name_product'] ?? ''));
                            $paid = (int)round((float)($row['price_product'] ?? 0));
                            if ($paid > 0) $paidTxt = number_format($paid) . ' تومان';
                        }
                    }
                } catch (\Throwable $e) { /* context is best-effort */ }
            }

            $st = fx_debt_state($rid);
            $ceiling = (int)($st['ceiling'] ?? 0);

            $msg = "🛒 <b>فروش جدید!</b>\n"
                . $typeLabel . "\n";
            if ($buyerId !== '') $msg .= "👤 خریدار: " . $buyerId . "\n";
            if ($product !== '') $msg .= "📦 محصول: " . $product . "\n";
            if ($paidTxt !== '') $msg .= "💵 پرداختی مشتری: " . $paidTxt . "\n";
            if ($walletPart > 0) {
                $debtPartN = max(0, $amount - $walletPart);
                $msg .= "🏷️ هزینه عمده: " . number_format($amount) . " تومان\n";
                $msg .= "💰 پرداخت از اعتبار کیف‌پول: " . number_format($walletPart) . " تومان\n";
                $msg .= "🏷️ افزوده به بدهی: " . ($debtPartN > 0 ? '+' . number_format($debtPartN) : '0') . " تومان\n";
            } else {
                $msg .= "🏷️ هزینه عمده (بدهی شما): +" . number_format($amount) . " تومان\n";
            }
            $msg .= "💸 بدهی فعلی: " . number_format($after) . " تومان\n";
            if ($ceiling > 0) {
                $remain = max(0, $ceiling - $after);
                $msg .= "🌟 اعتبار باقی‌مانده: " . number_format($remain) . " تومان";
            }

            $token = fx_reseller_bot_token($rid);
            if (function_exists('sendmessage')) {
                sendmessage($rid, $msg, null, 'HTML', $token);
            } elseif ($token) {
                @file_get_contents("https://api.telegram.org/bot{$token}/sendMessage?" . http_build_query([
                    'chat_id' => $rid, 'text' => $msg, 'parse_mode' => 'HTML',
                ]));
            }
        } catch (\Throwable $e) {
            error_log('[FX_RES] sale notify failed rid=' . $rid . ' ref=' . $ref . ': ' . $e->getMessage());
        }
    }
}

if (!function_exists('fx_reseller_statement_build')) {
    /**
     * FX-RESELLER (2026-10-16): weekly statement text for one reseller since $sinceTs.
     * Sales counted from invoice rows in tenant scope (agent OR bottype).
     */
    function fx_reseller_statement_build(int $rid, int $sinceTs): string {
        global $pdo;
        $st = fx_debt_state($rid);
        $cur = (int)($st['current'] ?? 0);
        $ceiling = (int)($st['ceiling'] ?? 0);

        $bal = 0;
        if ($pdo instanceof PDO) {
            try {
                $stmt = $pdo->prepare("SELECT Balance FROM user WHERE id = :rid LIMIT 1");
                $stmt->execute([':rid' => (string)$rid]);
                $bal = (int)round((float)$stmt->fetchColumn());
            } catch (\Throwable $e) {}
        }

        $salesCount = 0;
        $salesSum = 0;
        $settledSum = 0;
        $walletSum = 0;
        if ($pdo instanceof PDO) {
            try {
                $since = date('Y-m-d H:i:s', $sinceTs);
                $stmt = $pdo->prepare("SELECT COUNT(*) c, COALESCE(SUM(amount),0) s FROM fx_debt_log WHERE reseller_id = :rid AND scope = 'bot' AND direction = 'in' AND time >= :since AND method IN ('purchase','purchase_gateway','bulk_purchase','change_location','renewal','renewal_gateway','extra_volume','extra_volume_gateway','extra_time','extra_time_gateway')");
                $stmt->execute([':rid' => $rid, ':since' => $since]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if (is_array($row)) {
                    $salesCount = (int)$row['c'];
                    $salesSum = (int)$row['s'];
                }
                // WALLET-FIRST: the wallet share of split purchases is audited as
                // scope='main' + method='wallet_purchase' (separate rows — the
                // count above stays per bot row, one per sale).
                $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) s FROM fx_debt_log WHERE reseller_id = :rid AND scope = 'main' AND direction = 'in' AND method = 'wallet_purchase' AND time >= :since");
                $stmt->execute([':rid' => $rid, ':since' => $since]);
                $walletSum = (int)round((float)$stmt->fetchColumn());
                $salesSum += $walletSum;
                $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) s FROM fx_debt_log WHERE reseller_id = :rid AND scope = 'bot' AND direction = 'out' AND time >= :since AND method IN ('settlement','credit')");
                $stmt->execute([':rid' => $rid, ':since' => $since]);
                $settledSum = (int)round((float)$stmt->fetchColumn());

                $rtok = fx_reseller_bot_token($rid);
                if ($rtok) {
                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM invoice i JOIN user u ON u.id = i.id_user WHERE i.time_sell >= :since AND (u.agent = :rid OR u.bottype = :rtok)");
                    $stmt->execute([':rid' => (string)$rid, ':rtok' => $rtok, ':since' => $sinceTs]);
                    $salesCount = max($salesCount, (int)$stmt->fetchColumn());
                }
            } catch (\Throwable $e) { /* keep ledger-based numbers */ }
        }

        $fmtDate = function (int $ts): string {
            return function_exists('jdate') ? jdate('Y/m/d', $ts) : date('Y/m/d', $ts);
        };

        $msg = "🧾 <b>صورتحساب هفتگی نمایندگی</b>\n"
            . "🗓 از " . $fmtDate($sinceTs) . " تا " . $fmtDate(time()) . "\n"
            . "━━━━━━━━━━━━━━\n"
            . "🛒 فروش‌های هفته: " . $salesCount . " سرویس | " . number_format($salesSum) . " تومان (عمده)\n";
        if ($walletSum > 0) {
            $msg .= "💰 از این مبلغ، " . number_format($walletSum) . " تومان از اعتبار کیف‌پول کسر شده است\n";
        }
        $msg .= "💳 تسویه‌های ثبت‌شده: " . number_format($settledSum) . " تومان\n"
            . "━━━━━━━━━━━━━━\n"
            . "💸 بدهی فعلی: " . number_format($cur) . " تومان\n";
        if ($ceiling > 0) {
            $msg .= "🏦 سقف اعتبار: " . number_format($ceiling) . " تومان\n"
                . "🌟 اعتبار باقی‌مانده: " . number_format(max(0, $ceiling - $cur)) . " تومان\n";
        }
        $msg .= "💰 موجودی کیف‌پول: " . number_format($bal) . " تومان\n";
        if ($cur > 0) {
            $msg .= "\n⚠️ لطفاً برای جلوگیری از توقف فروش، بدهی خود را تسویه کنید.";
        }
        return $msg;
    }
}

if (!function_exists('fx_reseller_statement_send')) {
    /**
     * Send the weekly statement via the reseller's own bot token and stamp
     * botsaz.setting.last_stmt_ts. Returns true when sent.
     */
    function fx_reseller_statement_send(int $rid, int $sinceTs): bool {
        $token = fx_reseller_bot_token($rid);
        if (!$token) {
            $row = null;
            global $pdo;
            if ($pdo instanceof PDO) {
                try {
                    $stmt = $pdo->prepare("SELECT bot_token FROM botsaz WHERE id_user = :rid LIMIT 1");
                    $stmt->execute([':rid' => (string)$rid]);
                    $token = trim((string)$stmt->fetchColumn());
                } catch (\Throwable $e) {}
            }
            if (!$token) {
                return false;
            }
        }
        $msg = fx_reseller_statement_build($rid, $sinceTs);
        $kb = json_encode(['inline_keyboard' => [
            [
                ['text' => '💳 تسویه', 'callback_data' => 'fx_myacc_settle'],
                ['text' => '📒 گردش حساب', 'callback_data' => 'fx_myacc_ledger'],
            ],
            [['text' => '🏦 حساب من', 'callback_data' => 'fx_myaccount_menu']],
        ]], JSON_UNESCAPED_UNICODE);
        $ok = false;
        if (function_exists('sendmessage')) {
            sendmessage($rid, $msg, $kb, 'HTML', $token);
            $ok = true;
        } else {
            $res = @file_get_contents("https://api.telegram.org/bot{$token}/sendMessage?" . http_build_query([
                'chat_id' => $rid, 'text' => $msg, 'parse_mode' => 'HTML', 'reply_markup' => $kb,
            ]));
            $dec = json_decode((string)$res, true);
            $ok = is_array($dec) && !empty($dec['ok']);
        }
        if ($ok) {
            fx_res_setting_patch($rid, ['last_stmt_ts' => time()]);
        }
        return $ok;
    }
}

/* ============================================================================
 * FX-ACCOUNT (2026-10-27) — signed reseller account model (single source of truth)
 * ============================================================================
 * Three DISTINCT financial concepts (never conflate them):
 *   1) credit ceiling  L = botsaz.debt_ceiling          (independent setting)
 *   2) account balance B = wallet - current_debt        (signed: + claim / - debt)
 *   3) wallet          = user.Balance of the reseller   (cash held by the bot)
 *
 * Signs (B):
 *   B > 0  => the MAIN BOT owes the reseller  (claim = B)
 *   B = 0  => settled
 *   B < 0  => the RESELLER owes the main bot  (debt = -B)
 *
 * Derived:
 *   debt             = max(0, -B)
 *   claim            = max(0,  B)
 *   remaining_credit = max(0, L - debt)
 *
 * Every movement is a SIGNED delta applied to B:  B_new = B_old + delta
 *   purchase / charge / payment to reseller : delta < 0
 *   payment from reseller / settlement      : delta > 0
 * ------------------------------------------------------------------------- */

if (!function_exists('fx_debt_audit_columns')) {
    /** FX-ACCOUNT: true when the fx_debt_log audit columns exist (migration applied). */
    function fx_debt_audit_columns(): bool {
        static $has = null;
        if ($has !== null) {
            return $has;
        }
        global $pdo;
        $has = false;
        try {
            if ($pdo instanceof PDO) {
                $q = $pdo->query("SHOW COLUMNS FROM fx_debt_log LIKE 'balance_after'");
                $has = ($q && $q->fetch(PDO::FETCH_ASSOC)) ? true : false;
            }
        } catch (\Throwable $e) {
            $has = false;
        }
        return $has;
    }
}

if (!function_exists('fx_res_fmt_signed')) {
    /** FX-ACCOUNT: signed money display (explicit + / − prefix, 0 = no sign). */
    function fx_res_fmt_signed($n): string {
        $n = (int)$n;
        $s = number_format(abs($n));
        if ($n < 0) {
            return '−' . $s;
        }
        return $n > 0 ? '+' . $s : $s;
    }
}

if (!function_exists('fx_res_account_state')) {
    /**
     * FX-ACCOUNT: read-only signed account snapshot for a reseller.
     * Never writes. Safe to call in any context (main bot admin or reseller bot).
     */
    function fx_res_account_state(int $rid): array {
        $out = [
            'ok' => false, 'rid' => $rid, 'mode' => 'balance',
            'ceiling' => 0, 'wallet' => 0, 'current_debt' => 0,
            'balance' => 0, 'debt' => 0, 'claim' => 0,
            'remaining_credit' => 0, 'over_limit' => false,
            'pending_count' => 0, 'pending_amount' => 0, 'pending_debt' => 0,
            'bot' => null,
        ];
        if ($rid <= 0 || !function_exists('fx_debt_state')) {
            return $out;
        }
        $st = fx_debt_state($rid);
        if (!is_array($st['bot'])) {
            return $out;
        }
        $wallet = function_exists('fx_debt_wallet_balance') ? fx_debt_wallet_balance($rid) : 0;
        $ceiling = (int)($st['ceiling'] ?? 0);
        $cur = (int)($st['current'] ?? 0);
        $b = $wallet - $cur;
        $debt = max(0, -$b);
        $out['ok'] = true;
        $out['mode'] = (string)($st['mode'] ?? 'balance');
        $out['ceiling'] = $ceiling;
        $out['wallet'] = $wallet;
        $out['current_debt'] = $cur;
        $out['balance'] = $b;
        $out['debt'] = $debt;
        $out['claim'] = max(0, $b);
        $out['remaining_credit'] = max(0, $ceiling - $debt);
        $out['over_limit'] = ($ceiling > 0 && $debt > $ceiling);
        $out['bot'] = $st['bot'];

        global $pdo;
        if (($pdo instanceof PDO) && function_exists('fx_debt_res_table') && fx_debt_res_table()) {
            try {
                $cols = function_exists('fx_debt_wallet_column') && fx_debt_wallet_column()
                    ? "COUNT(*) c, COALESCE(SUM(amount),0) a, COALESCE(SUM(amount - wallet_part),0) d"
                    : "COUNT(*) c, COALESCE(SUM(amount),0) a, COALESCE(SUM(amount),0) d";
                $q = $pdo->prepare("SELECT " . $cols . " FROM fx_debt_reservations WHERE reseller_id = :rid AND state = 'reserved'");
                $q->execute([':rid' => (string)$rid]);
                $r = $q->fetch(PDO::FETCH_ASSOC);
                if (is_array($r)) {
                    $out['pending_count'] = (int)($r['c'] ?? 0);
                    $out['pending_amount'] = (int)($r['a'] ?? 0);
                    $out['pending_debt'] = (int)($r['d'] ?? 0);
                }
            } catch (\Throwable $e) { /* display-only */ }
        }
        return $out;
    }
}

if (!function_exists('fx_res_account_lines')) {
    /** FX-ACCOUNT: Persian display lines for any UI (bot / panel / report). */
    function fx_res_account_lines(array $a): array {
        $out = [];
        $out[] = '🏦 سقف اعتبار: ' . ((int)$a['ceiling'] > 0 ? number_format((int)$a['ceiling']) . ' تومان' : 'تنظیم نشده');
        $out[] = '⚖️ مانده حساب: ' . fx_res_fmt_signed((int)$a['balance']) . ' تومان';
        $out[] = '💸 بدهی نماینده به ربات اصلی: ' . number_format((int)$a['debt']) . ' تومان';
        $out[] = '💰 طلب نماینده از ربات اصلی: ' . number_format((int)$a['claim']) . ' تومان';
        if ((int)$a['ceiling'] > 0) {
            $out[] = '🌟 اعتبار بدهی باقی‌مانده: ' . number_format((int)$a['remaining_credit']) . ' تومان';
        }
        $out[] = '👛 کیف پول (نقد نزد ربات): ' . number_format((int)$a['wallet']) . ' تومان';
        if ((int)$a['pending_count'] > 0) {
            $out[] = '⏳ سفارش‌های در انتظار: ' . number_format((int)$a['pending_count']) . ' مورد | مبلغ '
                . number_format((int)$a['pending_amount']) . ' تومان (تعهد بدهی: ' . number_format((int)$a['pending_debt']) . ' تومان)';
        }
        $b = (int)$a['balance'];
        $out[] = '📊 وضعیت: ' . ($b > 0
            ? 'بستانکار — ربات اصلی به نماینده بدهکار است'
            : ($b < 0 ? 'بدهکار — نماینده به ربات اصلی بدهکار است' : 'تسویه'));
        if (!empty($a['over_limit'])) {
            $out[] = '🚨 هشدار: بدهی ' . number_format((int)$a['debt'] - (int)$a['ceiling']) . ' تومان از سقف اعتبار فراتر رفته است.';
        }
        return $out;
    }
}

if (!function_exists('fx_res_account_apply')) {
    /**
     * FX-ACCOUNT: the ONE writer for a signed reseller account movement.
     *
     *   B_new = B_old + delta        (delta > 0: reseller paid / granted credit)
     *
     * delta > 0 (incoming payment / credit):
     *   settle debt first (current_debt -= min(delta, current_debt)), the
     *   remainder is credited to the wallet (claim).
     * delta < 0 (charge / payment FROM the main bot TO the reseller):
     *   consume the wallet first, the remainder increases current_debt (gated
     *   by the credit ceiling unless allow_over_ceiling).
     *
     * Atomic (row locks botsaz -> user), idempotent when a $ref is supplied
     * (unique keys fx_debt_log.idempotency_key / wallet_ledger.fulfillment_key).
     * Audit: fx_debt_log (debt part, with actor/reason/before/after when the
     * audit columns exist), wallet_ledger (wallet part) and — for backward
     * compatibility with existing reports — a Payment_report row for the
     * wallet part.
     *
     * @return array{ok:bool,reason:string,before:int,after:int,wallet_before:int,wallet_after:int,debt_before:int,debt_after:int,ceiling:int,remaining_credit:int}
     */
    function fx_res_account_apply(int $rid, int $delta, string $method, string $ref = '', string $reason = '', ?int $actorId = null, array $opts = []): array {
        global $pdo;
        $snap = ['before' => 0, 'after' => 0, 'wallet_before' => 0, 'wallet_after' => 0, 'debt_before' => 0, 'debt_after' => 0, 'ceiling' => 0, 'remaining_credit' => 0];
        $fail = function (string $why) use (&$snap) {
            return array_merge(['ok' => false, 'reason' => $why], $snap);
        };
        if ($rid <= 0 || $delta === 0 || !($pdo instanceof PDO)) {
            return $fail('invalid_input');
        }
        $walletMethod = (string)($opts['wallet_method'] ?? $method);
        $walletOnly = !empty($opts['wallet_coverage_only']);
        $overCeiling = !empty($opts['allow_over_ceiling']);
        $withPayReport = !array_key_exists('with_payment_report', $opts) || !empty($opts['with_payment_report']);
        $hasAudit = fx_debt_audit_columns();
        $hasIdem = fx_debt_idem_column();
        $ownTx = false;
        try {
            if (!$pdo->inTransaction()) {
                $pdo->beginTransaction();
                $ownTx = true;
            }
            // consistent lock order with reserve/settle: botsaz -> user
            $lk = $pdo->prepare("SELECT * FROM botsaz WHERE id_user = :rid LIMIT 1 FOR UPDATE");
            $lk->execute([':rid' => (string)$rid]);
            $bot = $lk->fetch(PDO::FETCH_ASSOC);
            if (!is_array($bot)) {
                if ($ownTx) { $pdo->rollBack(); }
                return $fail('no_reseller');
            }
            $ceiling = (int)($bot['debt_ceiling'] ?? 0);
            $cur = (int)($bot['current_debt'] ?? 0);
            $lu = $pdo->prepare("SELECT Balance FROM user WHERE id = :rid LIMIT 1 FOR UPDATE");
            $lu->execute([':rid' => (string)$rid]);
            $balCol = $lu->fetchColumn();
            if ($balCol === false) {
                if ($ownTx) { $pdo->rollBack(); }
                return $fail('no_user');
            }
            $wallet = (int)round((float)$balCol);
            $before = $wallet - $cur;
            $snap = [
                'before' => $before, 'after' => $before,
                'wallet_before' => $wallet, 'wallet_after' => $wallet,
                'debt_before' => max(0, -$before), 'debt_after' => max(0, -$before),
                'ceiling' => $ceiling, 'remaining_credit' => max(0, $ceiling - max(0, -$before)),
            ];

            // idempotency fast-path (unique keys remain the real arbiter)
            if ($ref !== '') {
                if ($hasIdem) {
                    $chk = $pdo->prepare("SELECT 1 FROM fx_debt_log WHERE reseller_id = :rid AND invoice_ref = :ref AND method = :m LIMIT 1");
                    $chk->execute([':rid' => $rid, ':ref' => $ref, ':m' => $method]);
                    if ($chk->fetchColumn() !== false) {
                        if ($ownTx) { $pdo->commit(); }
                        return array_merge(['ok' => true, 'reason' => 'duplicate'], $snap);
                    }
                }
                try {
                    $chk2 = $pdo->prepare("SELECT 1 FROM wallet_ledger WHERE id_user = :uid AND fulfillment_key = :k LIMIT 1");
                    $chk2->execute([':uid' => (string)$rid, ':k' => 'acct|' . $walletMethod . '|' . $ref]);
                    if ($chk2->fetchColumn() !== false) {
                        if ($ownTx) { $pdo->commit(); }
                        return array_merge(['ok' => true, 'reason' => 'duplicate'], $snap);
                    }
                } catch (\Throwable $e) { /* wallet_ledger optional */ }
            }

            // split the signed delta into debt/wallet parts
            $debtPart = 0;
            $walletPart = 0;
            if ($delta > 0) {
                $debtPart = min($delta, $cur);
                $walletPart = $delta - $debtPart;
            } else {
                $amount = -$delta;
                $walletPart = min($amount, max(0, $wallet));
                if ($walletOnly && $walletPart < $amount) {
                    if ($ownTx) { $pdo->rollBack(); }
                    return $fail('insufficient_wallet');
                }
                $debtPart = $amount - $walletPart;
                if ($debtPart > 0 && !$overCeiling) {
                    if ($ceiling <= 0 || $cur + $debtPart > $ceiling) {
                        if ($ownTx) { $pdo->rollBack(); }
                        return $fail($ceiling <= 0 ? 'no_ceiling' : 'ceiling');
                    }
                }
            }

            // apply under the locks (guarded UPDATEs)
            if ($debtPart > 0) {
                if ($delta > 0) {
                    $u = $pdo->prepare("UPDATE botsaz SET current_debt = current_debt - :a WHERE id_user = :rid AND current_debt >= :a2");
                    $u->execute([':a' => $debtPart, ':a2' => $debtPart, ':rid' => (string)$rid]);
                } elseif ($overCeiling) {
                    $u = $pdo->prepare("UPDATE botsaz SET current_debt = current_debt + :a WHERE id_user = :rid");
                    $u->execute([':a' => $debtPart, ':rid' => (string)$rid]);
                } else {
                    $u = $pdo->prepare("UPDATE botsaz SET current_debt = current_debt + :a WHERE id_user = :rid AND debt_ceiling > 0 AND current_debt + :a2 <= :ceil");
                    $u->execute([':a' => $debtPart, ':a2' => $debtPart, ':ceil' => $ceiling, ':rid' => (string)$rid]);
                }
                if ($u->rowCount() !== 1) {
                    throw new \RuntimeException('debt update affected ' . $u->rowCount() . ' rows');
                }
            }
            if ($walletPart > 0) {
                if ($delta > 0) {
                    $u = $pdo->prepare("UPDATE user SET Balance = Balance + :a WHERE id = :rid");
                    $u->execute([':a' => $walletPart, ':rid' => (string)$rid]);
                } else {
                    $u = $pdo->prepare("UPDATE user SET Balance = Balance - :a WHERE id = :rid AND Balance >= :a2");
                    $u->execute([':a' => $walletPart, ':a2' => $walletPart, ':rid' => (string)$rid]);
                }
                if ($u->rowCount() !== 1) {
                    throw new \RuntimeException('wallet update affected ' . $u->rowCount() . ' rows');
                }
            }

            // read the committed values under the locks
            $ns = $pdo->prepare("SELECT current_debt FROM botsaz WHERE id_user = :rid LIMIT 1");
            $ns->execute([':rid' => (string)$rid]);
            $newCur = (int)$ns->fetchColumn();
            $nw = $pdo->prepare("SELECT Balance FROM user WHERE id = :rid LIMIT 1");
            $nw->execute([':rid' => (string)$rid]);
            $newWallet = (int)round((float)$nw->fetchColumn());
            $after = $newWallet - $newCur;
            $snap = [
                'before' => $before, 'after' => $after,
                'wallet_before' => $wallet, 'wallet_after' => $newWallet,
                'debt_before' => max(0, -$before), 'debt_after' => max(0, -$after),
                'ceiling' => $ceiling, 'remaining_credit' => max(0, $ceiling - max(0, -$after)),
            ];

            // ledger rows
            $idemKey = ($ref !== '' && $hasIdem) ? ('acct|' . $method . '|' . $ref) : null;
            if ($debtPart > 0) {
                if ($hasAudit) {
                    $pdo->prepare("INSERT INTO fx_debt_log (reseller_id, amount, direction, method, scope, invoice_ref, debt_after, time, idempotency_key, actor_id, reason, balance_before, balance_after, wallet_after) VALUES (:rid, :amt, :dir, :m, 'bot', :ref, :da, NOW(), :k, :actor, :reason, :bb, :ba, :wa)")
                        ->execute([
                            ':rid' => $rid, ':amt' => $debtPart,
                            ':dir' => ($delta > 0 ? 'out' : 'in'), ':m' => $method,
                            ':ref' => ($ref !== '' ? $ref : null), ':da' => $newCur,
                            ':k' => $idemKey, ':actor' => $actorId,
                            ':reason' => ($reason !== '' ? mb_substr($reason, 0, 191) : null),
                            ':bb' => $before, ':ba' => $after, ':wa' => $newWallet,
                        ]);
                } else {
                    if ($hasIdem && $idemKey !== null) {
                        $pdo->prepare("INSERT INTO fx_debt_log (reseller_id, amount, direction, method, scope, invoice_ref, debt_after, time, idempotency_key) VALUES (:rid, :amt, :dir, :m, 'bot', :ref, :da, NOW(), :k)")
                            ->execute([':rid' => $rid, ':amt' => $debtPart, ':dir' => ($delta > 0 ? 'out' : 'in'), ':m' => $method, ':ref' => $ref, ':da' => $newCur, ':k' => $idemKey]);
                    } else {
                        $pdo->prepare("INSERT INTO fx_debt_log (reseller_id, amount, direction, method, scope, invoice_ref, debt_after, time) VALUES (:rid, :amt, :dir, :m, 'bot', :ref, :da, NOW())")
                            ->execute([':rid' => $rid, ':amt' => $debtPart, ':dir' => ($delta > 0 ? 'out' : 'in'), ':m' => $method, ':ref' => ($ref !== '' ? $ref : null), ':da' => $newCur]);
                    }
                }
            }
            if ($walletPart > 0) {
                $fk = ($ref !== '' ? ('acct|' . $walletMethod . '|' . $ref) : null);
                try {
                    $pdo->prepare("INSERT INTO wallet_ledger (id_user, direction, amount, balance_after, category, description, id_order, fulfillment_key) VALUES (:uid, :dir, :amt, :ba, :cat, :desc, :ord, :fk)")
                        ->execute([
                            ':uid' => (string)$rid,
                            ':dir' => ($delta > 0 ? 'credit' : 'debit'),
                            ':amt' => $walletPart,
                            ':ba' => $newWallet,
                            ':cat' => $walletMethod,
                            ':desc' => ($reason !== '' ? mb_substr($reason, 0, 255) : $method),
                            ':ord' => ($ref !== '' ? $ref : null),
                            ':fk' => $fk,
                        ]);
                } catch (\Throwable $eWL) {
                    if (!fx_debt_is_duplicate($eWL)) {
                        throw $eWL;
                    }
                }
                if ($withPayReport) {
                    $orderId = bin2hex(random_bytes(5));
                    $ins = $pdo->prepare("INSERT INTO Payment_report (id_user, id_order, time, price, payment_Status, Payment_Method, id_invoice, bottype) VALUES (?,?,?,?,?,?,?,?)");
                    $ins->execute([(string)$rid, $orderId, date('Y/m/d H:i:s'), ($delta > 0 ? $walletPart : -$walletPart), 'paid', $walletMethod, null, null]);
                }
            }

            if ($ownTx) {
                $pdo->commit();
                $ownTx = false;
            }
            // re-arm the 80/95% alerts after a debt reduction (same policy as settle)
            if ($delta > 0 && $ceiling > 0 && $newCur * 100 < $ceiling * 80) {
                @update("botsaz", "debt_alert_80", 0, "id_user", $rid);
                @update("botsaz", "debt_alert_95", 0, "id_user", $rid);
            }
            if (function_exists('rx_log_event')) {
                rx_log_event('FX_ACCOUNT_APPLY', 'signed account movement', [
                    'rid' => $rid, 'delta' => $delta, 'method' => $method, 'ref' => $ref,
                    'reason' => $reason, 'actor' => $actorId,
                    'balance_before' => $before, 'balance_after' => $after,
                    'wallet_before' => $wallet, 'wallet_after' => $newWallet,
                    'debt_before' => max(0, -$before), 'debt_after' => max(0, -$after),
                ]);
            }
            return array_merge(['ok' => true, 'reason' => ''], $snap);
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if (fx_debt_is_duplicate($e)) {
                return array_merge(['ok' => true, 'reason' => 'duplicate'], $snap);
            }
            error_log('[FX_ACCOUNT] apply failed rid=' . $rid . ' delta=' . $delta . ' method=' . $method . ' ref=' . $ref . ': ' . $e->getMessage());
            return $fail('db');
        }
    }
}
