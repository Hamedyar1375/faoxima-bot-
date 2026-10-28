<?php
/**
 * fx_reseller_svcmgmt_bot_selftest.php — end-to-end check of the reseller
 * service-management bot gate (fx_res_svc_gate) with mocked Telegram calls and
 * a real DB connection (user row + mutated panel backed up & restored).
 *
 *   docker exec faoxima-app-1 php /var/www/faoxima/scripts/fx_reseller_svcmgmt_bot_selftest.php
 */
error_reporting(E_ALL & ~E_DEPRECATED);
date_default_timezone_set('Asia/Tehran');
define('REFACTORED_LEGACY_ROOT', '/var/www/faoxima');
require_once REFACTORED_LEGACY_ROOT . '/vpnbot/6327424512nomayandefoximabot/config.php';
define('FX_RESELLER_ID', 6327424512);
define('FX_RESELLER_TOKEN', $ApiToken);
require_once REFACTORED_LEGACY_ROOT . '/re/rx/function/bootstrap.php';
require_once REFACTORED_LEGACY_ROOT . '/re/rx/function/fx_reseller_service_mgmt.php'; // core
require_once REFACTORED_LEGACY_ROOT . '/re/rx/index/fx_reseller_service_mgmt.php';    // gate

$TEST_UID = '6327424512';   // reseller owner (admin)
$pdo = getDatabaseConnection();
$GLOBALS['pdo'] = $pdo;
$GLOBALS['user'] = ['id' => $TEST_UID, 'step' => 'home', 'Processing_value' => '{}'];
if (!($pdo instanceof PDO)) {
    fwrite(STDERR, "DB unavailable\n");
    exit(1);
}

// ---- mocked Telegram / reseller helpers ----
$callLog = [];
function sendmessage($chat, $text, $kb = null, $parse = 'HTML')
{
    global $callLog;
    $callLog[] = ['op' => 'send', 'chat' => $chat, 'text' => $text, 'kb' => $kb];
    return true;
}
function Editmessagetext($chat, $msg, $text, $kb = null, $parse = 'HTML')
{
    global $callLog;
    $callLog[] = ['op' => 'edit', 'chat' => $chat, 'msg' => (int) $msg, 'text' => $text, 'kb' => $kb];
    return true;
}
function telegram($m, $d = [], $t = null) { global $callLog; $callLog[] = ['op' => 'tg:' . $m]; return ['ok' => true]; }
function nm_adminInstantReply($c, $t, $kb = null, $p = 'HTML') { global $callLog; $callLog[] = ['op' => 'reply', 'chat' => $c, 'text' => $t, 'kb' => $kb]; return true; }
function fx_res_answer_cb() { global $callLog; $callLog[] = ['op' => 'answer_cb']; }
function fx_res_exit_to_hub($from_id, $message_id = 0) { global $callLog; $callLog[] = ['op' => 'exit_hub', 'chat' => $from_id]; }

$FOREIGN_UID = '111';       // not a reseller admin
$PANEL_ID = 1;              // test-panel, agent = 6327424512

$pass = 0;
$fail = 0;
$check = static function ($label, $ok, $note = '') use (&$pass, &$fail) {
    if ($ok) { $pass++; echo "PASS {$label}\n"; }
    else { $fail++; echo "FAIL {$label}" . ($note !== '' ? " [{$note}]" : '') . "\n"; }
};
$lastCalls = static function () use (&$callLog) { $o = $callLog; $callLog = []; return $o; };

function raw($pdo, $col, $id)
{
    $s = $pdo->prepare("SELECT `{$col}` FROM marzban_panel WHERE id = :id");
    $s->execute([':id' => $id]);
    return $s->fetchColumn();
}
function bakPanel($pdo, $id)
{
    $s = $pdo->prepare("SELECT * FROM marzban_panel WHERE id = :id");
    $s->execute([':id' => $id]);
    return $s->fetch(PDO::FETCH_ASSOC);
}
function restorePanel($pdo, array $row)
{
    $id = (int) $row['id'];
    unset($row['id']);
    $set = implode(', ', array_map(static fn ($c) => "`{$c}` = :c_{$c}", array_keys($row)));
    $p = [':id' => $id];
    foreach ($row as $c => $v) { $p[':c_' . $c] = $v; }
    $s = $pdo->prepare("UPDATE marzban_panel SET {$set} WHERE id = :id");
    $s->execute($p);
    return $s->rowCount() === 1;
}
function curStep($uid)
{
    $r = select("user", "step", "id", $uid, "select", ['cache' => false]);
    return is_array($r) ? (string) ($r['step'] ?? '') : '';
}
function curProc($uid)
{
    $r = select("user", "Processing_value", "id", $uid, "select", ['cache' => false]);
    $d = json_decode((string) (is_array($r) ? ($r['Processing_value'] ?? '') : ''), true);
    return is_array($d) ? $d : [];
}

// ---- backups ----
$storedUser = select("user", "step, Processing_value", "id", $TEST_UID, "select", ['cache' => false]);
$backupUserStep = is_array($storedUser) ? (string) ($storedUser['step'] ?? 'home') : 'home';
$backupUserProc = is_array($storedUser) ? (string) ($storedUser['Processing_value'] ?? '') : '';
$backupPanel = bakPanel($pdo, $PANEL_ID);
$check('backups captured', is_array($backupPanel) && ($backupPanel['id'] ?? 0) == $PANEL_ID);

// reset to a clean baseline
update("user", "step", "home", "id", $TEST_UID);
update("user", "Processing_value", "{}", "id", $TEST_UID);
$baseUser = ['id' => $TEST_UID, 'step' => 'home', 'Processing_value' => '{}'];

$gate = static function ($datain, $text, $from, $msgid, $user) {
    return fx_res_svc_gate($datain, $text, $from, $msgid, $user, null);
};

try {
    // ---- non-reseller callbacks are ignored ----
    $check('unrelated callback ignored', $gate('buy', '', $TEST_UID, 10, $baseUser) === false);
    $check('empty callback+text ignored', $gate('', '', $TEST_UID, 10, $baseUser) === false);

    // ---- non-actor is turned away ----
    $ok = $gate('fx_res_svc_menu', '', $FOREIGN_UID, 11, $baseUser);
    $log = $lastCalls();
    $check('non-actor callback consumed', $ok === true);
    $check('non-actor denied message', isset($log[0]) && strpos((string) $log[0]['text'], '🔒') !== false);

    // ---- menu ----
    $ok = $gate('fx_res_svc_menu', '', $TEST_UID, 100, $baseUser);
    $log = $lastCalls();
    $check('menu handled', $ok === true);
    $kb = isset($log[0]) ? json_decode((string) $log[0]['kb'], true) : null;
    $flat = is_array($kb) ? json_encode($kb) : '';
    $check('menu lists owned panel button', strpos($flat, 'fx_res_svc_panel_' . $PANEL_ID) !== false);
    $check('menu text titled', isset($log[0]) && strpos((string) $log[0]['text'], 'مدیریت سرویس‌های من') !== false);

    // ---- reply-mode entry (typed hub label) ----
    $ok = $gate('', '🛠 مدیریت سرویس‌های من', $TEST_UID, 101, $baseUser);
    $log = $lastCalls();
    $check('reply-mode entry handled', $ok === true && (isset($log[0]) && strpos((string) $log[0]['text'], 'مدیریت سرویس‌های من') !== false));

    // ---- reseller-admin link shortcuts (web panel + mini app) ----
    $ok = $gate('fx_res_svc_link_panel', '', $TEST_UID, 102, $baseUser);
    $log = $lastCalls();
    $linkKb = isset($log[0]) ? json_decode((string) $log[0]['kb'], true) : null;
    $panelUrl = (string) ($linkKb['inline_keyboard'][0][0]['url'] ?? '');
    $check('panel link handled', $ok === true);
    $check('panel link is URL button (reseller panel)', $panelUrl !== '' && strpos($panelUrl, '/panel/reseller/') !== false && strpos($panelUrl, 'https://') === 0, $panelUrl);
    $check('panel link message titled', isset($log[0]) && strpos((string) $log[0]['text'], 'پنل وب مدیریت') !== false);

    $ok = $gate('', '🚀 مینی‌اپ', $TEST_UID, 103, $baseUser);
    $log = $lastCalls();
    $linkKb = isset($log[0]) ? json_decode((string) $log[0]['kb'], true) : null;
    $appUrl = (string) ($linkKb['inline_keyboard'][0][0]['web_app']['url'] ?? '');
    $check('mini app reply entry handled', $ok === true);
    $check('mini app is web_app button', $appUrl !== '' && strpos($appUrl, '/app/') !== false && strpos($appUrl, 'https://') === 0, $appUrl);
    $check('mini app link message titled', isset($log[0]) && strpos((string) $log[0]['text'], 'مینی‌اپ') !== false);

    $ok = $gate('fx_res_svc_link_app', '', $FOREIGN_UID, 104, $baseUser);
    $log = $lastCalls();
    $check('non-actor link denied', $ok === true && isset($log[0]) && strpos((string) $log[0]['text'], '🔒') !== false);

    // ---- exit ----
    $ok = $gate('fx_res_svc_exit', '', $TEST_UID, 102, $baseUser);
    $log = $lastCalls();
    $check('exit handled', $ok === true);
    $exitCalled = false;
    foreach ($log as $c) {
        if (($c['op'] ?? '') === 'exit_hub' && (string) ($c['chat'] ?? '') === (string) $TEST_UID) { $exitCalled = true; break; }
    }
    $check('exit calls fx_res_exit_to_hub', $exitCalled);

    // ---- panel action screen ----
    $ok = $gate('fx_res_svc_panel_' . $PANEL_ID, '', $TEST_UID, 103, $baseUser);
    $log = $lastCalls();
    $flat = isset($log[0]) ? json_encode((string) $log[0]['kb']) : '';
    $check('panel screen handled', $ok === true);
    foreach (['setpass_', 'setuser_', 'seturl_', 'setsub_', 'togstatus_', 'togextend_'] as $pfx) {
        $check("panel screen has {$pfx}button", strpos($flat, 'fx_res_svc_' . $pfx . $PANEL_ID) !== false);
    }
    $check('panel screen hides password value', !isset($log[0]) || strpos((string) $log[0]['text'], (string) raw($pdo, 'password_panel', $PANEL_ID)) === false);

    // ---- unknown panel id ----
    $ok = $gate('fx_res_svc_panel_999999', '', $TEST_UID, 104, $baseUser);
    $log = $lastCalls();
    $check('unknown panel handled w/ notice', $ok === true && isset($log[0]) && strpos((string) $log[0]['text'], 'پیدا نشد') !== false);

    // ---- start password step ----
    $ok = $gate('fx_res_svc_setpass_' . $PANEL_ID, '', $TEST_UID, 105, $baseUser);
    $log = $lastCalls();
    $check('setpass prompt handled', $ok === true && isset($log[0]) && strpos((string) $log[0]['text'], 'رمز') !== false);
    $check('setpass sets step', curStep($TEST_UID) === 'fx_res_svc_setpass');
    $check('setpass stores panel id', (int) curProc($TEST_UID)['panel'] === $PANEL_ID);

    // ---- apply password (text) ----
    $u = ['id' => $TEST_UID, 'step' => 'fx_res_svc_setpass', 'Processing_value' => json_encode(['panel' => $PANEL_ID])];
    $ok = $gate('', 'NewPass-123', $TEST_UID, 106, $u);
    $log = $lastCalls();
    $check('password applied handled', $ok === true);
    $check('password persisted', (string) raw($pdo, 'password_panel', $PANEL_ID) === 'NewPass-123');
    $check('datelogin reset', raw($pdo, 'datelogin', $PANEL_ID) === null);
    $check('step reset after apply', curStep($TEST_UID) === 'home');
    $check('success message sent', in_array(true, array_map(static fn ($c) => strpos((string) ($c['text'] ?? ''), '✅') !== false, $log), true));

    // ---- set username ----
    $gate('fx_res_svc_setuser_' . $PANEL_ID, '', $TEST_UID, 107, $baseUser);
    $lastCalls();
    $u = ['id' => $TEST_UID, 'step' => 'fx_res_svc_setuser', 'Processing_value' => json_encode(['panel' => $PANEL_ID])];
    $gate('', 'svc_newuser', $TEST_UID, 108, $u);
    $lastCalls();
    $check('username persisted', (string) raw($pdo, 'username_panel', $PANEL_ID) === 'svc_newuser');

    // ---- set url (bare domain coerced) ----
    $gate('fx_res_svc_seturl_' . $PANEL_ID, '', $TEST_UID, 109, $baseUser);
    $lastCalls();
    $u = ['id' => $TEST_UID, 'step' => 'fx_res_svc_seturl', 'Processing_value' => json_encode(['panel' => $PANEL_ID])];
    $gate('', 'svc.example.com', $TEST_UID, 110, $u);
    $lastCalls();
    $check('url coerced + persisted', (string) raw($pdo, 'url_panel', $PANEL_ID) === 'https://svc.example.com');

    // ---- set url invalid ----
    $gate('fx_res_svc_seturl_' . $PANEL_ID, '', $TEST_UID, 111, $baseUser);
    $lastCalls();
    $u = ['id' => $TEST_UID, 'step' => 'fx_res_svc_seturl', 'Processing_value' => json_encode(['panel' => $PANEL_ID])];
    $ok = $gate('', 'http://bad url with spaces', $TEST_UID, 112, $u);
    $log = $lastCalls();
    $check('invalid url rejected (consumed)', $ok === true);
    $check('invalid url shows error', in_array(true, array_map(static fn ($c) => strpos((string) ($c['text'] ?? ''), 'معتبر') !== false, $log), true));
    $check('url unchanged after invalid', (string) raw($pdo, 'url_panel', $PANEL_ID) === 'https://svc.example.com');

    // ---- set sublink ----
    $gate('fx_res_svc_setsub_' . $PANEL_ID, '', $TEST_UID, 113, $baseUser);
    $lastCalls();
    $u = ['id' => $TEST_UID, 'step' => 'fx_res_svc_setsub', 'Processing_value' => json_encode(['panel' => $PANEL_ID])];
    $gate('', 'https://svc.example.com/sub', $TEST_UID, 114, $u);
    $lastCalls();
    $check('sublink persisted', (string) raw($pdo, 'linksubx', $PANEL_ID) === 'https://svc.example.com/sub');

    // ---- toggle service (status) ----
    $beforeStatus = (string) raw($pdo, 'status', $PANEL_ID);
    $ok = $gate('fx_res_svc_togstatus_' . $PANEL_ID, '', $TEST_UID, 115, $baseUser);
    $log = $lastCalls();
    $afterStatus = (string) raw($pdo, 'status', $PANEL_ID);
    $check('toggle status handled', $ok === true);
    $check('status flipped', $afterStatus !== $beforeStatus && in_array($afterStatus, ['active', 'disable'], true));

    // ---- toggle extend (status_extend) ----
    $beforeExt = (string) raw($pdo, 'status_extend', $PANEL_ID);
    $ok = $gate('fx_res_svc_togextend_' . $PANEL_ID, '', $TEST_UID, 116, $baseUser);
    $log = $lastCalls();
    $afterExt = (string) raw($pdo, 'status_extend', $PANEL_ID);
    $check('toggle extend handled', $ok === true);
    $check('status_extend flipped', $afterExt !== $beforeExt && in_array($afterExt, ['on_extend', 'off_extend'], true));

    // ---- pressing a button while in a text step cancels the step ----
    $gate('fx_res_svc_setpass_' . $PANEL_ID, '', $TEST_UID, 117, $baseUser); // step = setpass
    $check('step active before cancel', curStep($TEST_UID) === 'fx_res_svc_setpass');
    $gate('fx_res_svc_panel_' . $PANEL_ID, '', $TEST_UID, 118, ['id' => $TEST_UID, 'step' => 'fx_res_svc_setpass', 'Processing_value' => json_encode(['panel' => $PANEL_ID])]);
    $lastCalls();
    $check('step cleared after button cancel', curStep($TEST_UID) === 'home');
} finally {
    update("user", "step", $backupUserStep, "id", $TEST_UID);
    update("user", "Processing_value", $backupUserProc, "id", $TEST_UID);
    $check('user row restored', curStep($TEST_UID) === $backupUserStep);
    $check('panel restored', restorePanel($pdo, $backupPanel));
}

echo "\nRESULT: PASS={$pass} FAIL={$fail}\n";
exit($fail === 0 ? 0 : 1);
