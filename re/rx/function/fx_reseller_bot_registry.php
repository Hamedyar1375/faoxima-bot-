<?php
/**
 * FX-RESELLER BOT REGISTRY (2026-10-15)
 *
 * Single source of truth for the reseller-bot cap/counters and for race-safe
 * registration in the `botsaz` table. Used by every creation path:
 *   - re/rx/index/fx_reseller_mgmt.php  (central admin panel)
 *   - re/rx/index/agent_bot_creator.php (agent self-service)
 *   - api/users.php                     (active_bot_agent API)
 *
 * Cap precedence (unchanged): FX_MAX_RESELLER_BOTS -> setting.max_reseller_bots
 * -> env FX_MAX_RESELLER_BOTS -> 15.
 *
 * Atomicity: MySQL named lock (GET_LOCK) held across the re-check + INSERT.
 * Engines without GET_LOCK fall back to a row lock on the single `setting` row
 * inside a transaction. No UNIQUE index is added on botsaz.id_user: multiple
 * bots per reseller may be allowed by the business; the lock protects the
 * count/insert window instead.
 */

if (!function_exists('fx_reseller_bot_cap')) {
    function fx_reseller_bot_cap(): int
    {
        $max = 0;
        if (defined('FX_MAX_RESELLER_BOTS')) {
            $max = (int) FX_MAX_RESELLER_BOTS;
        } elseif (isset($GLOBALS['setting']['max_reseller_bots']) && (int) $GLOBALS['setting']['max_reseller_bots'] > 0) {
            $max = (int) $GLOBALS['setting']['max_reseller_bots'];
        } else {
            $envMax = getenv('FX_MAX_RESELLER_BOTS');
            if ($envMax !== false && (int) $envMax > 0) {
                $max = (int) $envMax;
            }
        }
        if ($max <= 0) {
            $max = 15;
        }
        return $max;
    }
}

if (!function_exists('fx_reseller_bot_count_total')) {
    function fx_reseller_bot_count_total(PDO $pdo): int
    {
        try {
            return (int) $pdo->query("SELECT COUNT(*) FROM botsaz")->fetchColumn();
        } catch (\Throwable $e) {
            return -1;
        }
    }
}

if (!function_exists('fx_reseller_bot_count_owner')) {
    function fx_reseller_bot_count_owner(PDO $pdo, string $rid): int
    {
        try {
            $st = $pdo->prepare("SELECT COUNT(*) FROM botsaz WHERE id_user = :u");
            $st->execute([':u' => $rid]);
            return (int) $st->fetchColumn();
        } catch (\Throwable $e) {
            return -1;
        }
    }
}

if (!function_exists('fx_reseller_bot_count_token')) {
    function fx_reseller_bot_count_token(PDO $pdo, string $token): int
    {
        try {
            $st = $pdo->prepare("SELECT COUNT(*) FROM botsaz WHERE bot_token = :t");
            $st->execute([':t' => $token]);
            return (int) $st->fetchColumn();
        } catch (\Throwable $e) {
            return -1;
        }
    }
}

if (!function_exists('fx_reseller_bot_lock_acquire')) {
    function fx_reseller_bot_lock_acquire(PDO $pdo, int $timeout = 15): array
    {
        try {
            $timeout = min(60, max(0, (int) $timeout));
            $got = $pdo->query("SELECT GET_LOCK('fx_reseller_bots_register', {$timeout})")->fetchColumn();
            if ((string) $got === '1') {
                return ['ok' => true, 'mech' => 'lock', 'busy' => false];
            }
            if ($got !== null && $got !== false) {
                return ['ok' => false, 'mech' => '', 'busy' => true];
            }
        } catch (\Throwable $e) {
            // fall through to the transaction fallback
        }
        try {
            if (!$pdo->inTransaction()) {
                $pdo->beginTransaction();
            }
            $pdo->query("SELECT 1 FROM setting LIMIT 1 FOR UPDATE")->fetchColumn();
            return ['ok' => true, 'mech' => 'txn', 'busy' => false];
        } catch (\Throwable $e2) {
            try {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
            } catch (\Throwable $e3) {
            }
            return ['ok' => false, 'mech' => '', 'busy' => false];
        }
    }
}

if (!function_exists('fx_reseller_bot_lock_release')) {
    function fx_reseller_bot_lock_release(PDO $pdo, array $lock): void
    {
        if (empty($lock['ok'])) {
            return;
        }
        if (($lock['mech'] ?? '') === 'txn') {
            try {
                if ($pdo->inTransaction()) {
                    $pdo->commit();
                }
            } catch (\Throwable $e) {
                try {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                } catch (\Throwable $e2) {
                }
            }
            return;
        }
        try {
            $st = $pdo->prepare("SELECT RELEASE_LOCK('fx_reseller_bots_register')");
            $st->execute();
        } catch (\Throwable $e) {
        }
    }
}

if (!function_exists('fx_reseller_bot_check')) {
    /**
     * Caller must hold the registry lock (see fx_reseller_bot_lock_acquire).
     * @return array{ok:bool,code:string,cap:int,total:int,owner:int}
     */
    function fx_reseller_bot_check(PDO $pdo, string $rid, string $token): array
    {
        $cap = fx_reseller_bot_cap();
        $total = fx_reseller_bot_count_total($pdo);
        if ($total < 0) {
            return ['ok' => false, 'code' => 'db', 'cap' => $cap, 'total' => 0, 'owner' => 0];
        }
        $owner = fx_reseller_bot_count_owner($pdo, $rid);
        if ($owner < 0) {
            return ['ok' => false, 'code' => 'db', 'cap' => $cap, 'total' => $total, 'owner' => 0];
        }
        if ($owner > 0) {
            return ['ok' => false, 'code' => 'user_exists', 'cap' => $cap, 'total' => $total, 'owner' => $owner];
        }
        if ($total >= $cap) {
            return ['ok' => false, 'code' => 'cap', 'cap' => $cap, 'total' => $total, 'owner' => 0];
        }
        $tok = fx_reseller_bot_count_token($pdo, $token);
        if ($tok < 0) {
            return ['ok' => false, 'code' => 'db', 'cap' => $cap, 'total' => $total, 'owner' => 0];
        }
        if ($tok > 0) {
            return ['ok' => false, 'code' => 'token_exists', 'cap' => $cap, 'total' => $total, 'owner' => 0];
        }
        return ['ok' => true, 'code' => '', 'cap' => $cap, 'total' => $total, 'owner' => 0];
    }
}

if (!function_exists('fx_reseller_bot_insert')) {
    function fx_reseller_bot_insert(PDO $pdo, string $rid, string $token, string $username): array
    {
        $datasetting = json_encode([
            "minpricetime" => 4000, "pricetime" => 4000, "minpricevolume" => 4000, "pricevolume" => 4000,
            "support_username" => "@support", "Channel_Report" => 0,
            "cart_info" => "جهت پرداخت مبلغ را به شماره کارت زیر واریز نمایید", 'show_product' => true,
        ], JSON_UNESCAPED_UNICODE);
        try {
            $stmt = $pdo->prepare("INSERT INTO botsaz (id_user, bot_token, admin_ids, username, time, setting, hide_panel) VALUES (:u, :t, :a, :x, :tm, :s, :h)");
            $stmt->bindValue(':u', $rid, PDO::PARAM_STR);
            $stmt->bindValue(':t', $token, PDO::PARAM_STR);
            $stmt->bindValue(':a', json_encode([(string) $rid]), PDO::PARAM_STR);
            $stmt->bindValue(':x', $username, PDO::PARAM_STR);
            $stmt->bindValue(':tm', date('Y/m/d H:i:s'), PDO::PARAM_STR);
            $stmt->bindValue(':s', $datasetting, PDO::PARAM_STR);
            $stmt->bindValue(':h', "{}", PDO::PARAM_STR);
            $stmt->execute();
            return ['ok' => true, 'id' => (int) $pdo->lastInsertId()];
        } catch (\Throwable $e) {
            return ['ok' => false, 'code' => 'db', 'error' => $e->getMessage()];
        }
    }
}

if (!function_exists('fx_reseller_bot_register')) {
    /**
     * Atomic create: lock -> re-check (owner/cap/token) -> insert -> unlock.
     * @return array{ok:bool,code:string,cap:int,total:int,owner:int,id?:int,error?:string}
     */
    function fx_reseller_bot_register(PDO $pdo, string $rid, string $token, string $username, int $timeout = 15): array
    {
        $lock = fx_reseller_bot_lock_acquire($pdo, $timeout);
        if (empty($lock['ok'])) {
            return [
                'ok' => false,
                'code' => !empty($lock['busy']) ? 'busy' : 'db',
                'cap' => fx_reseller_bot_cap(),
                'total' => 0,
                'owner' => 0,
            ];
        }
        try {
            $chk = fx_reseller_bot_check($pdo, $rid, $token);
            if (empty($chk['ok'])) {
                return $chk;
            }
            $ins = fx_reseller_bot_insert($pdo, $rid, $token, $username);
            if (empty($ins['ok'])) {
                return [
                    'ok' => false,
                    'code' => 'db',
                    'cap' => $chk['cap'],
                    'total' => $chk['total'],
                    'owner' => 0,
                    'error' => (string) ($ins['error'] ?? ''),
                ];
            }
            return [
                'ok' => true,
                'code' => 'ok',
                'cap' => $chk['cap'],
                'total' => $chk['total'] + 1,
                'owner' => 1,
                'id' => (int) $ins['id'],
            ];
        } finally {
            fx_reseller_bot_lock_release($pdo, $lock);
        }
    }
}
