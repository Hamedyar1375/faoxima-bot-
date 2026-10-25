<?php
/**
 * Faoxima Reseller Retail Price Override
 * Allows resellers to set their own retail price per product.
 * Table: fx_retail_prices (product_id, reseller_id, retail_price)
 */

if (!defined('FX_RETAIL_OVERRIDE_LOADED')) {
    define('FX_RETAIL_OVERRIDE_LOADED', true);

    /**
     * True only inside a reseller webhook process (/vpnbot/.../index.php).
     * The main bot never defines FX_RESELLER_ID.
     */
    function fx_is_reseller_context(): bool {
        return defined('FX_RESELLER_ID') && (int) FX_RESELLER_ID > 0;
    }

    /**
     * Agent value used to scope product queries.
     *
     * In a reseller bot the tenant is ALWAYS FX_RESELLER_ID, regardless of the
     * customer row's legacy agent value (f/n/n2). In the main bot the customer's
     * own agent group is used, so main and reseller catalogs stay isolated.
     */
    function fx_tenant_scope_agent($fallbackAgent = null): string {
        if (fx_is_reseller_context()) {
            return (string) (int) FX_RESELLER_ID;
        }
        if ($fallbackAgent !== null) {
            return trim((string) $fallbackAgent);
        }
        $user = (isset($GLOBALS['user']) && is_array($GLOBALS['user'])) ? $GLOBALS['user'] : [];
        return trim((string) ($user['agent'] ?? ''));
    }

    /**
     * Hard tenant ownership check for a product row.
     *
     * In reseller context, only rows assigned to that reseller id or explicitly
     * shared (all/allusers) are valid. This is the final guard used by the
     * resolver, catalog renderer and retail-override applier so a product NAME
     * and its PRICE always come from the same tenant-owned row.
     */
    function fx_product_matches_tenant(array $product, ?int $resellerId = null): bool {
        $rid = $resellerId !== null
            ? (int) $resellerId
            : (fx_is_reseller_context() ? (int) FX_RESELLER_ID : 0);
        if ($rid <= 0) {
            return true; // main context is scoped by the customer agent query
        }

        $raw = $product['agent'] ?? null;
        if (is_array($raw)) {
            $values = $raw;
        } else {
            $raw = trim((string) $raw);
            if ($raw === '') {
                return false;
            }
            $decoded = json_decode($raw, true);
            $values = is_array($decoded) ? $decoded : (preg_split('/[,|]/', $raw) ?: []);
        }

        $ridValue = (string) $rid;
        foreach ($values as $value) {
            if (is_array($value) || is_object($value)) {
                continue;
            }
            $value = strtolower(trim((string) $value));
            if ($value === $ridValue || in_array($value, ['all', 'allusers'], true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Tenant-isolated product scope SQL.
     *
     * Reseller context: owned products (agent CSV contains the reseller id) or
     * explicitly shared products (all/allusers). Main products f/n/n2 are NOT
     * included, even if the reseller has a retail override row for them.
     *
     * Main context: the bind value comes from fx_tenant_scope_agent() and is the
     * main customer's agent group, so reseller-only numeric products are not
     * included.
     *
     * $agentParam must be bound exactly once by the caller.
     */
    function fx_customer_product_scope_sql(string $agentParam = ':agent', string $alias = 'product'): string {
        $a = preg_replace('/[^A-Za-z0-9_]/', '', (string) $alias);
        if ($a === '') { $a = 'product'; }
        $param = preg_match('/^:[A-Za-z0-9_]+$/', (string) $agentParam) ? (string) $agentParam : ':agent';
        return "(FIND_IN_SET({$param}, REPLACE({$a}.agent, ' ', '')) > 0 OR {$a}.agent IN ('all', 'allusers'))";
    }

    /**
     * FX-RESELLER (2026-10-16, tightened 2026-10-25): SQL fragment for products
     * a reseller actually owns or that are explicitly shared. Main groups
     * (f/n/n2) are intentionally excluded to prevent tenant catalog leakage.
     * $ridParam must be bound exactly once by the caller.
     */
    function fx_res_product_scope_sql(string $alias = 'p', string $ridParam = ':rid'): string {
        $a = preg_replace('/[^A-Za-z0-9_]/', '', (string)$alias);
        if ($a === '') { $a = 'p'; }
        $param = preg_match('/^:[A-Za-z0-9_]+$/', (string)$ridParam) ? (string)$ridParam : ':rid';
        return "(FIND_IN_SET({$param}, REPLACE({$a}.agent, ' ', '')) > 0 OR {$a}.agent IN ('all', 'allusers'))";
    }

    /**
     * Return all trusted active bot/token hints from the current server-side
     * context. This is used for brand-new users whose DB row does not yet
     * carry an agent/bottype. Only trusted process state is considered
     * (constants, globals and session); never arbitrary request parameters.
     */
    function fx_retail_active_bot_tokens(): array {
        $candidates = [];

        foreach (['FX_RESELLER_TOKEN', 'FX_ACTIVE_BOT_TOKEN', 'BOT_TOKEN'] as $constant) {
            if (defined($constant)) {
                $candidates[] = constant($constant);
            }
        }

        foreach (['FX_RESELLER_TOKEN', 'fx_active_bot_token', 'active_bot_token', 'tenant_bot_token', 'reseller_bot_token', 'reseller_token', 'botType', 'bottype', 'bot_type', 'bot_token', 'botToken', 'ApiToken', 'APIKEY'] as $key) {
            if (isset($GLOBALS[$key])) {
                $candidates[] = $GLOBALS[$key];
            }
        }

        if (isset($_SESSION) && is_array($_SESSION)) {
            foreach (['FX_RESELLER_TOKEN', 'fx_active_bot_token', 'active_bot_token', 'tenant_bot_token', 'reseller_bot_token', 'reseller_token', 'botType', 'bottype', 'bot_type', 'bot_token', 'botToken', 'ApiToken', 'APIKEY', 'api_key', 'apiKey'] as $key) {
                if (isset($_SESSION[$key])) {
                    $candidates[] = $_SESSION[$key];
                }
            }
        }

        $tokens = [];
        foreach ($candidates as $candidate) {
            if (!is_scalar($candidate)) {
                continue;
            }
            $token = trim((string) $candidate);
            if ($token !== '') {
                $tokens[$token] = true;
            }
        }

        return array_keys($tokens);
    }

    /**
     * Back-compat helper: first trusted active token hint.
     */
    function fx_retail_active_bot_token(): string {
        $tokens = fx_retail_active_bot_tokens();
        return isset($tokens[0]) ? (string) $tokens[0] : '';
    }

    /**
     * Map an active bot token (or a numeric reseller id carried by the active
     * bot context) to its reseller/tenant id via botsaz.
     */
    function fx_retail_reseller_id_for_token(string $token): int {
        $token = trim($token);
        if ($token === '') {
            return 0;
        }

        static $tokenTenantMemo = [];
        if (array_key_exists($token, $tokenTenantMemo)) {
            return (int) $tokenTenantMemo[$token];
        }
        $tokenTenantMemo[$token] = 0;

        $pdo = function_exists('fx_pdo') ? fx_pdo() : ($GLOBALS['pdo'] ?? null);
        if (!($pdo instanceof PDO)) {
            return 0;
        }

        try {
            if (ctype_digit($token)) {
                $stmt = $pdo->prepare("SELECT id_user FROM botsaz WHERE id_user = :rid LIMIT 1");
                $stmt->execute([':rid' => $token]);
                $resellerId = $stmt->fetchColumn();
            } else {
                $stmt = $pdo->prepare("SELECT id_user FROM botsaz WHERE bot_token = :token LIMIT 1");
                $stmt->execute([':token' => $token]);
                $resellerId = $stmt->fetchColumn();
            }
            if ($resellerId !== false && ctype_digit((string) $resellerId) && (int) $resellerId > 0) {
                $tokenTenantMemo[$token] = (int) $resellerId;
                return (int) $resellerId;
            }
        } catch (\Throwable $e) {
            error_log('[FX-RETAIL] tenant lookup by bot context failed: ' . $e->getMessage());
        }

        return 0;
    }

    /**
     * Resolve the reseller/tenant whose retail price must be used for the
     * current purchase context.
     *
     * The reseller webhook process has FX_RESELLER_ID defined, but the same
     * customer can also purchase through the main bot or the mini-app. In
     * those processes there is no FX_RESELLER_ID constant, so the tenant must
     * be derived from the authenticated user (agent / bottype token), the
     * currently active bot token/session mapped via botsaz, or, as a last
     * resort, from a product assigned exclusively to one reseller.
     *
     * Returning 0 means "main bot / no reseller context" and leaves the
     * product price untouched.
     */
    function fx_retail_reseller_id(?int $explicitResellerId = null, ?array $product = null): int {
        if ($explicitResellerId !== null && $explicitResellerId > 0) {
            return $explicitResellerId;
        }
        if (defined('FX_RESELLER_ID') && (int) FX_RESELLER_ID > 0) {
            return (int) FX_RESELLER_ID;
        }

        $user = (isset($GLOBALS['user']) && is_array($GLOBALS['user'])) ? $GLOBALS['user'] : [];

        // New users created by a reseller bot carry agent = reseller id, even
        // when the current request itself is served by the main bot/mini-app.
        $agent = trim((string) ($user['agent'] ?? ''));
        if ($agent !== '' && ctype_digit($agent) && (int) $agent > 0) {
            return (int) $agent;
        }

        // Legacy/migrated users keep agent = f/n/n2 and are linked to the
        // tenant by the bot token stored in user.bottype.
        $botType = trim((string) ($user['bottype'] ?? ''));
        if ($botType !== '') {
            $tenantId = fx_retail_reseller_id_for_token($botType);
            if ($tenantId > 0) {
                return $tenantId;
            }
        }

        // Brand-new users may not have an agent/bottype on their DB row yet.
        // The request is still running inside the active reseller bot process
        // (or its authenticated session), so resolve the tenant from that bot
        // token on the fly. This is what makes the pre-invoice/checkout use the
        // reseller's retail price instead of the base price.
        foreach (fx_retail_active_bot_tokens() as $activeToken) {
            $tenantId = fx_retail_reseller_id_for_token((string) $activeToken);
            if ($tenantId > 0) {
                return $tenantId;
            }
        }

        // Last resort: a product assigned exclusively to one reseller belongs
        // to that tenant. Only do this when there is no explicit user context;
        // a main-bot customer (agent=f/n/n2, no reseller token) must not be
        // repriced merely because an admin views/assigns a tenant product.
        // Never guess when an agent CSV contains several ids.
        $hasUserContext = $agent !== '' || $botType !== '' || !empty($user['id']);
        if (!$hasUserContext && is_array($product)) {
            $rawAgent = trim((string) ($product['agent'] ?? ''));
            $agentIds = [];
            foreach (preg_split('/[,|]/', $rawAgent) ?: [] as $value) {
                $value = trim((string) $value);
                if ($value !== '' && ctype_digit($value) && (int) $value > 0) {
                    $agentIds[(int) $value] = true;
                }
            }
            if (count($agentIds) === 1) {
                return (int) array_key_first($agentIds);
            }
        }

        return 0;
    }

    function fx_apply_retail_override(array &$product, ?int $resellerId = null): void {
        if (empty($product['id'])) return;

        $rid = fx_retail_reseller_id($resellerId, $product);
        if ($rid <= 0) return;
        // Never apply a reseller retail price to a product row that does not
        // belong to that tenant. Otherwise a foreign product title could be
        // rendered with this reseller's markup/override.
        if (function_exists('fx_product_matches_tenant') && !fx_product_matches_tenant($product, $rid)) {
            error_log(sprintf('[FX-RETAIL-REJECT] product=%s name=%s agent=%s tenant=%s',
                (string)($product['id'] ?? ''), (string)($product['name_product'] ?? ''),
                (string)($product['agent'] ?? ''), (string)$rid));
            return;
        }

        $pdo = function_exists('fx_pdo') ? fx_pdo() : ($GLOBALS['pdo'] ?? null);
        if (!($pdo instanceof PDO)) return;
        try {
            $stmt = $pdo->prepare("SELECT retail_price FROM fx_retail_prices WHERE product_id = :pid AND reseller_id = :rid LIMIT 1");
            $stmt->execute([':pid' => (int)$product['id'], ':rid' => $rid]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row && isset($row['retail_price']) && (int)$row['retail_price'] > 0) {
                // The reseller-specific retail price is authoritative. Apply it
                // AFTER any FX/panel pricing and never fall back to the base
                // price when a positive override exists for this tenant.
                $product['price_product'] = (int)$row['retail_price'];
                $product['fx_retail_applied'] = true;
                $product['fx_retail_reseller_id'] = $rid;
            }
        } catch (\Throwable $e) {
            error_log('[FX-RETAIL] price lookup failed for product ' . (int)$product['id'] . ' tenant ' . $rid . ': ' . $e->getMessage());
        }
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
                : "(FIND_IN_SET(:rid2, REPLACE(p.agent, ' ', '')) OR p.agent IN ('all', 'allusers'))";
            $sql = "SELECT p.id, p.name_product, p.price_product AS base_price, p.fx_wholesale_price AS wholesale, r.retail_price FROM product p LEFT JOIN fx_retail_prices r ON p.id = r.product_id AND r.reseller_id = :rid WHERE {$scope}";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':rid' => $resellerId, ':rid2' => (string)$resellerId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) { return []; }
    }
}
