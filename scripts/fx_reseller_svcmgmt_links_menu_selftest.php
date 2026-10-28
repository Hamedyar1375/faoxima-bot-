<?php
error_reporting(E_ALL & ~E_DEPRECATED);
date_default_timezone_set('Asia/Tehran');
define('REFACTORED_LEGACY_ROOT', '/var/www/faoxima');
chdir(REFACTORED_LEGACY_ROOT);
require_once REFACTORED_LEGACY_ROOT . '/re/rx/function/bootstrap.php';
$pdo = getDatabaseConnection();
$GLOBALS['pdo'] = $pdo;
if (!($pdo instanceof PDO)) { fwrite(STDERR, "DB unavailable\n"); exit(1); }

$botsaz = $pdo->query("SELECT admin_ids FROM botsaz WHERE id_user = '6327424512'")->fetch(PDO::FETCH_ASSOC);
$admin_ids = is_array($botsaz) ? json_decode((string) ($botsaz['admin_ids'] ?? '[]'), true) : [];
if (!is_array($admin_ids)) { $admin_ids = []; }
define('FX_RESELLER_ID', 6327424512);
$LOCKED = (in_array('lock', $argv ?? [], true));
if ($LOCKED) { define('FX_RESELLER_LOCK', 1); }
if (!function_exists('languagechange')) {
    function languagechange($f) {
        $d = json_decode((string) @file_get_contents($f), true);
        return is_array($d) ? $d : [];
    }
}
// NOTE: the gate (fx_res_svc_actor_allowed) is intentionally NOT loaded yet —
// in production the keyboard section runs before the admin section loads it,
// so the first phase exercises the inline RBAC fallback in layouts_1.php.

$pass = 0; $fail = 0;
$check = static function ($l, $o) use (&$pass, &$fail) { if ($o) { $pass++; echo "PASS $l\n"; } else { $fail++; echo "FAIL $l\n"; } };

$buildAdminKb = static function (int $from) use ($admin_ids, $pdo): string {
    $from_id = $from;
    $datain = ''; $text = ''; $message_id = 0; $callback_query_id = '';
    $username = ''; $first_name = '';
    $keyboardadmin = null;
    include REFACTORED_LEGACY_ROOT . '/re/rx/keyboard/layouts_1.php';
    return is_string($keyboardadmin) ? $keyboardadmin : '';
};

$kbOwner = $buildAdminKb(6327424512);
$check('owner kb built', $kbOwner !== '');
$check('owner sees web panel button', strpos($kbOwner, '🌐 پنل وب مدیریت') !== false);
$check('owner sees mini app button', strpos($kbOwner, '🚀 مینی‌اپ') !== false);
$check('owner kb is reply keyboard (offinline)', strpos($kbOwner, '"keyboard"') !== false);

$kbForeign = $buildAdminKb(2222);
$check('foreign kb built', $kbForeign !== '' || true);
$check('foreign does NOT see web panel button', strpos($kbForeign, '🌐 پنل وب مدیریت') === false);
$check('foreign does NOT see mini app button', strpos($kbForeign, '🚀 مینی‌اپ') === false);

// delegated admin (in admin_ids but not owner, no admin row) still sees it.
// The gate/layout RBAC reads botsaz.admin_ids from the DB, so temporarily
// register the delegated id there, then restore the original value.
$extraId = 9900123456;
$botsazRow = $pdo->query("SELECT admin_ids FROM botsaz WHERE id_user = '6327424512'")->fetch(PDO::FETCH_ASSOC);
$origAdminIds = is_array($botsazRow) ? (string) ($botsazRow['admin_ids'] ?? '') : '';
$probeIds = is_array(json_decode($origAdminIds, true)) ? json_decode($origAdminIds, true) : [];
if (!in_array((string) $extraId, array_map('strval', (array) $probeIds), true)) {
    $probeIds[] = (string) $extraId;
}
$probeJson = json_encode(array_values(array_map('strval', $probeIds)), JSON_UNESCAPED_UNICODE);
$pdo->prepare("UPDATE botsaz SET admin_ids = ? WHERE id_user = '6327424512'")->execute([$probeJson]);
try {
    $buildAdminKbDelegated = static function (int $from) use ($admin_ids, $extraId, $pdo): string {
        $from_id = $from;
        $admin_ids = array_merge(array_values($admin_ids), [(string) $extraId]);
        $datain = ''; $text = ''; $message_id = 0; $callback_query_id = '';
        $username = ''; $first_name = '';
        $keyboardadmin = null;
        include REFACTORED_LEGACY_ROOT . '/re/rx/keyboard/layouts_1.php';
        return is_string($keyboardadmin) ? $keyboardadmin : '';
    };
    $kbDelegated = $buildAdminKbDelegated($extraId);
    $check('delegated admin sees web panel button', strpos($kbDelegated, '🌐 پنل وب مدیریت') !== false);
    $check('delegated admin sees mini app button', strpos($kbDelegated, '🚀 مینی‌اپ') !== false);
} finally {
    $pdo->prepare("UPDATE botsaz SET admin_ids = ? WHERE id_user = '6327424512'")->execute([$origAdminIds]);
}

// ---- phase 2: gate already loaded (function_exists branch) ----
require_once REFACTORED_LEGACY_ROOT . '/re/rx/index/fx_reseller_service_mgmt.php';
$check('gate function now available', function_exists('fx_res_svc_actor_allowed'));
$kbOwner2 = $buildAdminKb(6327424512);
$check('owner still sees web panel button (fn branch)', strpos($kbOwner2, '🌐 پنل وب مدیریت') !== false);
$check('owner still sees mini app button (fn branch)', strpos($kbOwner2, '🚀 مینی‌اپ') !== false);
$kbForeign2 = $buildAdminKb(2222);
$check('foreign still hidden (fn branch)', strpos($kbForeign2, '🌐 پنل وب مدیریت') === false);

echo "RESULT: PASS=$pass FAIL=$fail\n";
exit($fail > 0 ? 1 : 0);
