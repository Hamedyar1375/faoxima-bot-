<?php
/**
 * fx_reseller_service_mgmt_selftest.php — tenant-isolation + behaviour check of
 * the shared reseller service-management core (re/rx/function/fx_reseller_service_mgmt.php).
 *
 * Runs against the live DB with a backup/restore of the mutated panel so the
 * data is left exactly as found.
 *
 *   docker exec faoxima-app-1 php /var/www/faoxima/scripts/fx_reseller_service_mgmt_selftest.php
 */
error_reporting(E_ALL & ~E_DEPRECATED);
date_default_timezone_set('Asia/Tehran');
define('REFACTORED_LEGACY_ROOT', '/var/www/faoxima');
require_once REFACTORED_LEGACY_ROOT . '/vpnbot/6327424512nomayandefoximabot/config.php';
require_once REFACTORED_LEGACY_ROOT . '/re/rx/function/bootstrap.php';
require_once REFACTORED_LEGACY_ROOT . '/re/rx/function/fx_reseller_service_mgmt.php';

$pdo = getDatabaseConnection();
if (!($pdo instanceof PDO)) {
    fwrite(STDERR, "DB unavailable\n");
    exit(1);
}

$RID_OWNED   = 6327424512;   // live reseller that owns the test panels
$RID_FOREIGN = 999999999;    // reseller that owns nothing
$RID_ADMIN   = 0;            // unrestricted (admin/web context)
$TARGET_ID   = 1;            // "test-panel", agent = RID_OWNED

$pass = 0;
$fail = 0;
$check = static function ($label, $ok, $note = '') use (&$pass, &$fail) {
    if ($ok) {
        $pass++;
        echo "PASS {$label}\n";
    } else {
        $fail++;
        echo "FAIL {$label}" . ($note !== '' ? " [{$note}]" : '') . "\n";
    }
};

function svc_raw($pdo, $id, $col) {
    $stmt = $pdo->prepare("SELECT `{$col}` FROM marzban_panel WHERE id = :id");
    $stmt->execute([':id' => $id]);
    return $stmt->fetchColumn();
}
function svc_backup_panel($pdo, $id) {
    $stmt = $pdo->prepare("SELECT * FROM marzban_panel WHERE id = :id");
    $stmt->execute([':id' => $id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}
function svc_restore_panel($pdo, array $row) {
    $id = (int) $row['id'];
    unset($row['id']);
    $set = implode(', ', array_map(static fn ($c) => "`{$c}` = :c_{$c}", array_keys($row)));
    $params = [':id' => $id];
    foreach ($row as $c => $v) {
        $params[':c_' . $c] = $v;
    }
    $stmt = $pdo->prepare("UPDATE marzban_panel SET {$set} WHERE id = :id");
    $stmt->execute($params);
    return $stmt->rowCount() === 1;
}

$backup = svc_backup_panel($pdo, $TARGET_ID);
$check('backup captured', is_array($backup) && ($backup['id'] ?? null) == $TARGET_ID);

try {
    // ---- scope clause shape ----
    list($c0, $p0) = fx_res_svc_scope_clause(0);
    $check('scope(0) unrestricted', $c0 === '1=1' && $p0 === []);
    list($cO, $pO) = fx_res_svc_scope_clause($RID_OWNED);
    $check('scope(rid) has FIND_IN_SET', strpos($cO, 'FIND_IN_SET') !== false && $pO === [':fx_svc_rid' => $RID_OWNED]);

    // ---- read scoping ----
    $list = fx_res_svc_list_panels($pdo, $RID_OWNED);
    $ids = array_map(static fn ($r) => (int) $r['id'], $list);
    $check('list(owned) includes target', in_array($TARGET_ID, $ids, true), 'ids=' . implode(',', $ids));
    $check('list(owned) excludes password field', !isset($list[0]['password_panel']));
    $check('list(foreign) empty', fx_res_svc_list_panels($pdo, $RID_FOREIGN) === []);

    $row = fx_res_svc_get_panel($pdo, $RID_OWNED, $TARGET_ID);
    $check('get(owned) returns row', is_array($row) && $row['name_panel'] === 'test-panel');
    $check('get(foreign) null (cross-tenant)', fx_res_svc_get_panel($pdo, $RID_FOREIGN, $TARGET_ID) === null);
    $check('get(nonexistent) null', fx_res_svc_get_panel($pdo, $RID_OWNED, 999999999) === null);
    $check('get(admin=0) unrestricted', is_array(fx_res_svc_get_panel($pdo, $RID_ADMIN, $TARGET_ID)));

    // ---- password ----
    $newPass = 'S3cure-Pass-' . substr(md5((string) getmypid()), 0, 6);
    $check('set_password(owned) ok', fx_res_svc_set_password($pdo, $RID_OWNED, $TARGET_ID, $newPass) === true);
    $check('password persisted', (string) svc_raw($pdo, $TARGET_ID, 'password_panel') === $newPass);
    $check('datelogin reset on password', svc_raw($pdo, $TARGET_ID, 'datelogin') === null);
    $check('set_password(foreign) denied', fx_res_svc_set_password($pdo, $RID_FOREIGN, $TARGET_ID, 'HaxPass') === false);
    $check('password unchanged after cross-tenant', (string) svc_raw($pdo, $TARGET_ID, 'password_panel') === $newPass);
    $check('set_password(empty) invalid', fx_res_svc_set_password($pdo, $RID_OWNED, $TARGET_ID, '   ') === false);

    // ---- username ----
    $check('set_username(owned) ok', fx_res_svc_set_username($pdo, $RID_OWNED, $TARGET_ID, 'svc_user_test') === true);
    $check('username persisted', (string) svc_raw($pdo, $TARGET_ID, 'username_panel') === 'svc_user_test');
    $check('datelogin reset on username', svc_raw($pdo, $TARGET_ID, 'datelogin') === null);
    $check('set_username(foreign) denied', fx_res_svc_set_username($pdo, $RID_FOREIGN, $TARGET_ID, 'hax_user') === false);
    $check('username unchanged after cross-tenant', (string) svc_raw($pdo, $TARGET_ID, 'username_panel') === 'svc_user_test');

    // ---- url (domain) ----
    $check('set_url(invalid) rejected', fx_res_svc_set_url($pdo, $RID_OWNED, $TARGET_ID, 'not a url') === false);
    $check('set_url(owned) ok', fx_res_svc_set_url($pdo, $RID_OWNED, $TARGET_ID, 'https://svc.example.com/panel') === true);
    $check('url persisted', (string) svc_raw($pdo, $TARGET_ID, 'url_panel') === 'https://svc.example.com/panel');
    $check('datelogin reset on url', svc_raw($pdo, $TARGET_ID, 'datelogin') === null);
    $check('set_url(foreign) denied', fx_res_svc_set_url($pdo, $RID_FOREIGN, $TARGET_ID, 'https://evil.example.com') === false);
    $check('url unchanged after cross-tenant', (string) svc_raw($pdo, $TARGET_ID, 'url_panel') === 'https://svc.example.com/panel');

    // ---- sublink ----
    $check('set_sublink(owned) ok', fx_res_svc_set_sublink($pdo, $RID_OWNED, $TARGET_ID, 'https://svc.example.com/sub') === true);
    $check('sublink persisted', (string) svc_raw($pdo, $TARGET_ID, 'linksubx') === 'https://svc.example.com/sub');
    $check('set_sublink(foreign) denied', fx_res_svc_set_sublink($pdo, $RID_FOREIGN, $TARGET_ID, 'https://evil/sub') === false);
    $check('sublink unchanged after cross-tenant', (string) svc_raw($pdo, $TARGET_ID, 'linksubx') === 'https://svc.example.com/sub');

    // ---- toggles ----
    $t1 = fx_res_svc_toggle($pdo, $RID_OWNED, $TARGET_ID, 'status');
    $check('toggle status #1 -> active', $t1 === 'active' && (string) svc_raw($pdo, $TARGET_ID, 'status') === 'active');
    $t2 = fx_res_svc_toggle($pdo, $RID_OWNED, $TARGET_ID, 'status');
    $check('toggle status #2 -> disable', $t2 === 'disable' && (string) svc_raw($pdo, $TARGET_ID, 'status') === 'disable');
    $t3 = fx_res_svc_toggle($pdo, $RID_OWNED, $TARGET_ID, 'status_extend');
    $check('toggle status_extend -> on_extend', $t3 === 'on_extend' && (string) svc_raw($pdo, $TARGET_ID, 'status_extend') === 'on_extend');
    $check('toggle(foreign) null', fx_res_svc_toggle($pdo, $RID_FOREIGN, $TARGET_ID, 'status') === null);
    $check('status unchanged after cross-tenant toggle', (string) svc_raw($pdo, $TARGET_ID, 'status') === 'disable');
    $check('toggle(invalid field) null', fx_res_svc_toggle($pdo, $RID_OWNED, $TARGET_ID, 'bogus') === null);

    // ---- whitelist guard ----
    $agentBefore = (string) svc_raw($pdo, $TARGET_ID, 'agent');
    $check('apply(non-whitelisted col) denied', fx_res_svc_apply($pdo, $RID_OWNED, $TARGET_ID, ['agent' => '123456']) === false);
    $check('agent unchanged after whitelist guard', (string) svc_raw($pdo, $TARGET_ID, 'agent') === $agentBefore);
} finally {
    $restored = svc_restore_panel($pdo, $backup);
    $check('panel restored from backup', $restored === true);
}

echo "\nRESULT: PASS={$pass} FAIL={$fail}\n";
exit($fail === 0 ? 0 : 1);
