<?php
/**
 * fx_reseller_panel_selftest.php — verifies the reseller web panel's tenant
 * isolation at the pure-function level (no HTTP, no session):
 *   - only a reseller-owner account (admin.id_admin == botsaz.id_user) resolves to a rid
 *   - main-admin / unknown / empty accounts resolve to null (rejected)
 *   - the user/invoice/product scope predicates return ONLY the tenant's rows
 *
 * Read-only against the live DB.
 *
 *   docker exec faoxima-app-1 php /var/www/faoxima/scripts/fx_reseller_panel_selftest.php
 */
error_reporting(E_ALL & ~E_DEPRECATED);
date_default_timezone_set('Asia/Tehran');
define('REFACTORED_LEGACY_ROOT', '/var/www/faoxima');
require_once REFACTORED_LEGACY_ROOT . '/vpnbot/6327424512nomayandefoximabot/config.php';
require_once REFACTORED_LEGACY_ROOT . '/re/rx/function/bootstrap.php';
require_once REFACTORED_LEGACY_ROOT . '/re/rx/function/fx_reseller_service_mgmt.php';
require_once REFACTORED_LEGACY_ROOT . '/panel/reseller/lib/rid.php';

$pdo = getDatabaseConnection();
if (!($pdo instanceof PDO)) {
    fwrite(STDERR, "DB unavailable\n");
    exit(1);
}

$RID = 6327424512; // live reseller owner
$OWNER_USER = 'nomayande';   // admin row whose id_admin == RID
$MAIN_USER = 'admin';        // main admin whose id_admin != RID (not a reseller)

$pass = 0;
$fail = 0;
$check = static function ($label, $ok, $note = '') use (&$pass, &$fail) {
    if ($ok) { $pass++; echo "PASS {$label}\n"; }
    else { $fail++; echo "FAIL {$label}" . ($note !== '' ? " [{$note}]" : '') . "\n"; }
};

// ---- account -> rid resolution (reseller-only gate) ----
$check('owner account resolves to its rid', fx_res_panel_rid_for_username($pdo, $OWNER_USER) === $RID);
$check('main-admin account is rejected (null)', fx_res_panel_rid_for_username($pdo, $MAIN_USER) === null);
$check('unknown account is rejected (null)', fx_res_panel_rid_for_username($pdo, 'no_such_user_xyz') === null);
$check('empty account is rejected (null)', fx_res_panel_rid_for_username($pdo, '') === null);

// ---- scope predicates only see the tenant ----
$scopes = fx_res_panel_scopes($pdo, $RID);
$uScope = $scopes['u'];
$iScope = $scopes['i'];
$check('user scope is non-empty string', is_string($uScope) && $uScope !== '');
$check('invoice scope is non-empty string', is_string($iScope) && $iScope !== '');

$tenantUsers = (int) $pdo->query("SELECT COUNT(*) FROM user WHERE {$uScope}")->fetchColumn();
$allUsers = (int) $pdo->query("SELECT COUNT(*) FROM user")->fetchColumn();
$check('user scope returns >=1 rows', $tenantUsers >= 1, "tenantUsers={$tenantUsers}");
$check('user scope is a subset of all users', $tenantUsers <= $allUsers, "tenant={$tenantUsers} all={$allUsers}");

// Every scoped user must actually belong to the tenant — cross-checked by
// filtering the raw rows in PHP (independent of the SQL predicate syntax).
$rawUsers = $pdo->query("SELECT agent, bottype FROM user WHERE {$uScope}")->fetchAll(PDO::FETCH_ASSOC);
$tok = fx_res_panel_reseller_token($pdo, $RID);
$phpCount = 0;
foreach ($rawUsers as $ru) {
    if ((string) ($ru['agent'] ?? '') === (string) $RID || (string) ($ru['bottype'] ?? '') === $tok) {
        $phpCount++;
    }
}
$check('scoped users all verified in PHP', $phpCount === count($rawUsers) && $phpCount === $tenantUsers, "sql={$tenantUsers} php={$phpCount}");

// Pure main-platform users (agent='f', no bottype) must NOT be in the scope.
$nonTenant = (int) $pdo->query("SELECT COUNT(*) FROM user WHERE agent = 'f' AND (bottype IS NULL OR bottype = '') AND ({$uScope})")->fetchColumn();
$check('main-platform users not in tenant scope', $nonTenant === 0, "leaked={$nonTenant}");

// product scope returns only tenant products
[$pClause, $pParams] = fx_res_svc_scope_clause($RID);
$cnt = $pdo->prepare("SELECT COUNT(*) FROM product WHERE {$pClause}");
$cnt->execute($pParams);
$tenantProducts = (int) $cnt->fetchColumn();
$check('product scope returns >=1 rows', $tenantProducts >= 1, "tenantProducts={$tenantProducts}");

// A foreign tenant with no products must see nothing.
[$fClause, $fParams] = fx_res_svc_scope_clause(999999999);
$fcnt = $pdo->prepare("SELECT COUNT(*) FROM product WHERE {$fClause}");
$fcnt->execute($fParams);
$check('foreign tenant sees no products', (int) $fcnt->fetchColumn() === 0);

// ---- panel list is tenant-scoped ----
$panels = fx_res_svc_list_panels($pdo, $RID, 50);
$check('panel list returns >=1 for owner', is_array($panels) && count($panels) >= 1);
$check('panel list returns 0 for foreign tenant', count(fx_res_svc_list_panels($pdo, 999999999, 50)) === 0);
foreach ($panels as $pn) {
    $pAgent = (string) ($pn['agent'] ?? '');
    $okAgent = (stripos($pAgent, (string) $RID) !== false) || in_array(strtolower($pAgent), ['all', 'allusers'], true);
    $check('panel ' . ($pn['id'] ?? '?') . ' belongs to tenant', $okAgent, "agent={$pAgent}");
}

echo "RESULT: PASS=$pass FAIL=$fail\n";
exit($fail > 0 ? 1 : 0);
