<?php
/**
 * FX-RESELLER-SVCMGMT — shared tenant-scoped service (panel) management core.
 *
 * Single source of truth for the four reseller self-service panel operations,
 * shared by the reseller BOT, the reseller MINI-APP and the WEB PANEL:
 *
 *   change password        -> password_panel  (+ datelogin reset)
 *   change username        -> username_panel  (+ datelogin reset)
 *   panel address/domain   -> url_panel       (+ datelogin reset)
 *   subscription domain    -> linksubx
 *   service on/off         -> status          (active <-> disable)
 *   sale/renew on/off      -> status_extend   (on_extend <-> off_extend)
 *
 * TENANT ISOLATION (critical): a marzban_panel row belongs to tenant $rid iff
 *
 *   FIND_IN_SET($rid, REPLACE(agent, ' ', '')) > 0  OR  agent IN ('all','allusers')
 *
 * which is the exact ownership predicate already used for reseller products
 * (fx_res_product_scope_sql) and the reseller panel list (nm_render_panels).
 *
 * $rid is ALWAYS supplied by the caller from a trusted context:
 *   - reseller bot process : FX_RESELLER_ID (defined at process start)
 *   - reseller mini-app    : the reseller resolved from the authenticated session
 *   - web panel            : the admin-selected reseller uid (botsaz.id_user)
 * It is never taken from raw end-user input. Every read AND every write is
 * scoped by $rid, so a tenant can never read or mutate another tenant's panel.
 *
 * Panels are keyed on marzban_panel.id (stable primary key — code_panel is not
 * guaranteed unique). Credentials (password_panel) are never returned by the
 * list/lookup helpers.
 *
 * This file is part of the "function" bundle (see manifest.php) and is therefore
 * available in every context that loads function.php (bot, api, panel).
 */

if (!function_exists('fx_res_svc_pdo')) {
    function fx_res_svc_pdo(): ?PDO
    {
        global $pdo;
        if ($pdo instanceof PDO) {
            return $pdo;
        }
        if (function_exists('getDatabaseConnection')) {
            $conn = getDatabaseConnection();
            if ($conn instanceof PDO) {
                return $conn;
            }
        }
        return null;
    }
}

if (!function_exists('fx_res_svc_user_reseller_id')) {
    /**
     * Reverse lookup: given a Telegram user id, return the reseller (botsaz.id_user)
     * they own or administrate, or null if they are not a reseller admin.
     * Used by the mini-app to bind a session to exactly one tenant.
     */
    function fx_res_svc_user_reseller_id(?PDO $pdo, $userId): ?int
    {
        $pdo = $pdo ?: fx_res_svc_pdo();
        if (!($pdo instanceof PDO)) {
            return null;
        }
        $userId = (int) $userId;
        if ($userId <= 0) {
            return null;
        }
        try {
            $stmt = $pdo->prepare("SELECT id_user FROM botsaz WHERE id_user = :u LIMIT 1");
            $stmt->execute([':u' => (string) $userId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (is_array($row)) {
                return (int) $row['id_user'];
            }
            $rows = $pdo->query("SELECT id_user, admin_ids FROM botsaz")->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($rows as $b) {
                $ids = json_decode((string) ($b['admin_ids'] ?? '[]'), true);
                if (is_array($ids) && in_array((string) $userId, array_map('strval', $ids), true)) {
                    return (int) $b['id_user'];
                }
            }
        } catch (\Throwable $e) {
            return null;
        }
        return null;
    }
}

if (!function_exists('fx_res_svc_scope_clause')) {
    /**
     * Ownership WHERE-fragment for a tenant. Returns [clause, params].
     * $rid <= 0 (main-bot context) => no restriction (admin sees everything).
     */
    function fx_res_svc_scope_clause($rid): array
    {
        $rid = (int) $rid;
        if ($rid <= 0) {
            return ['1=1', []];
        }
        $clause = "(FIND_IN_SET(:fx_svc_rid, REPLACE(agent, ' ', '')) > 0 OR agent IN ('all','allusers'))";
        return [$clause, [':fx_svc_rid' => $rid]];
    }
}

if (!function_exists('fx_res_svc_get_panel')) {
    /**
     * Fetch a panel row only if it belongs to tenant $rid. Returns null when the
     * panel does not exist OR is owned by a different tenant (no info leak).
     */
    function fx_res_svc_get_panel(?PDO $pdo, $rid, $panelId): ?array
    {
        $pdo = $pdo ?: fx_res_svc_pdo();
        if (!($pdo instanceof PDO)) {
            return null;
        }
        $panelId = (int) $panelId;
        if ($panelId <= 0) {
            return null;
        }
        list($scope, $scopeParams) = fx_res_svc_scope_clause($rid);
        $stmt = $pdo->prepare("SELECT * FROM marzban_panel WHERE id = :fx_svc_id AND {$scope} LIMIT 1");
        $stmt->execute(array_merge([':fx_svc_id' => $panelId], $scopeParams));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }
}

if (!function_exists('fx_res_svc_list_panels')) {
    /**
     * List panels owned by tenant $rid (credentials excluded).
     */
    function fx_res_svc_list_panels(?PDO $pdo, $rid, $limit = 50): array
    {
        $pdo = $pdo ?: fx_res_svc_pdo();
        if (!($pdo instanceof PDO)) {
            return [];
        }
        $limit = max(1, min(200, (int) $limit));
        list($scope, $scopeParams) = fx_res_svc_scope_clause($rid);
        $sql = "SELECT id, name_panel, type, agent, status, status_extend, url_panel, username_panel, linksubx, sublink
                FROM marzban_panel WHERE {$scope} ORDER BY id ASC LIMIT {$limit}";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($scopeParams);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    }
}

if (!function_exists('fx_res_svc_apply')) {
    /**
     * Internal scoped UPDATE. $assignments maps column => value (null => SET NULL).
     * Only whitelisted columns are allowed; the update is constrained to the
     * tenant's own row and must affect exactly one row to count as success.
     */
    function fx_res_svc_apply(?PDO $pdo, $rid, $panelId, array $assignments): bool
    {
        $pdo = $pdo ?: fx_res_svc_pdo();
        if (!($pdo instanceof PDO)) {
            return false;
        }
        $panelId = (int) $panelId;
        if ($panelId <= 0 || empty($assignments)) {
            return false;
        }
        $allowed = ['password_panel', 'username_panel', 'url_panel', 'linksubx', 'datelogin', 'status_extend', 'status', 'name_panel'];
        $setParts = [];
        $params = [':fx_svc_id' => $panelId];
        $i = 0;
        foreach ($assignments as $col => $value) {
            if (!in_array($col, $allowed, true)) {
                return false;
            }
            $ph = ':fx_svc_v' . $i;
            if ($value === null) {
                $setParts[] = "`{$col}` = NULL";
            } else {
                $setParts[] = "`{$col}` = {$ph}";
                $params[$ph] = (string) $value;
            }
            $i++;
        }
        list($scope, $scopeParams) = fx_res_svc_scope_clause($rid);
        $sql = "UPDATE marzban_panel SET " . implode(', ', $setParts) . " WHERE id = :fx_svc_id AND {$scope}";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge($params, $scopeParams));
        return $stmt->rowCount() === 1;
    }
}

if (!function_exists('fx_res_svc_set_password')) {
    function fx_res_svc_set_password(?PDO $pdo, $rid, $panelId, $password): bool
    {
        $password = trim((string) $password);
        if ($password === '') {
            return false;
        }
        $len = function_exists('mb_strlen') ? mb_strlen($password) : strlen($password);
        if ($len > 200) {
            return false;
        }
        return fx_res_svc_apply($pdo, $rid, $panelId, ['password_panel' => $password, 'datelogin' => null]);
    }
}

if (!function_exists('fx_res_svc_set_username')) {
    function fx_res_svc_set_username(?PDO $pdo, $rid, $panelId, $username): bool
    {
        $username = trim((string) $username);
        if ($username === '') {
            return false;
        }
        $len = function_exists('mb_strlen') ? mb_strlen($username) : strlen($username);
        if ($len > 200) {
            return false;
        }
        return fx_res_svc_apply($pdo, $rid, $panelId, ['username_panel' => $username, 'datelogin' => null]);
    }
}

if (!function_exists('fx_res_svc_set_url')) {
    function fx_res_svc_set_url(?PDO $pdo, $rid, $panelId, $url): bool
    {
        $url = trim((string) $url);
        if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }
        return fx_res_svc_apply($pdo, $rid, $panelId, ['url_panel' => $url, 'datelogin' => null]);
    }
}

if (!function_exists('fx_res_svc_set_sublink')) {
    function fx_res_svc_set_sublink(?PDO $pdo, $rid, $panelId, $link): bool
    {
        $link = trim((string) $link);
        if ($link === '' || (function_exists('mb_strlen') ? mb_strlen($link) : strlen($link)) > 1000) {
            return false;
        }
        return fx_res_svc_apply($pdo, $rid, $panelId, ['linksubx' => $link]);
    }
}

if (!function_exists('fx_res_svc_toggle')) {
    /**
     * Flip a toggle for a tenant-owned panel. $field is 'status' (service
     * on/off: active<->disable) or 'status_extend' (sale/renew on/off:
     * on_extend<->off_extend). Returns the new value, or null on failure.
     */
    function fx_res_svc_toggle(?PDO $pdo, $rid, $panelId, string $field): ?string
    {
        if (!in_array($field, ['status_extend', 'status'], true)) {
            return null;
        }
        $row = fx_res_svc_get_panel($pdo, $rid, $panelId);
        if ($row === null) {
            return null;
        }
        $current = (string) ($row[$field] ?? '');
        if ($field === 'status_extend') {
            $next = ($current === 'on_extend') ? 'off_extend' : 'on_extend';
        } else {
            $next = ($current === 'active') ? 'disable' : 'active';
        }
        if (!fx_res_svc_apply($pdo, $rid, $panelId, [$field => $next])) {
            return null;
        }
        return $next;
    }
}
