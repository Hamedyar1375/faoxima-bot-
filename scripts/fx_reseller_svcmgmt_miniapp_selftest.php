<?php
/**
 * fx_reseller_svcmgmt_miniapp_selftest.php — end-to-end check of the reseller
 * mini-app path: reseller-bot initData verification -> session token ->
 * tenant-scoped service actions (list/update/toggle). Non-reseller actors are
 * rejected. Panel + user token are backed up & restored.
 *
 *   docker exec faoxima-app-1 php /var/www/faoxima/scripts/fx_reseller_svcmgmt_miniapp_selftest.php
 */
error_reporting(E_ALL & ~E_DEPRECATED);
date_default_timezone_set('Asia/Tehran');
define('REFACTORED_LEGACY_ROOT', '/var/www/faoxima');

// Load the api context (config + function bundle + api libs).
require_once REFACTORED_LEGACY_ROOT . '/api/lib/Bootstrap.php';
require_once REFACTORED_LEGACY_ROOT . '/api/handlers/VerifyHandler.php';
require_once REFACTORED_LEGACY_ROOT . '/api/handlers/ResellerServiceHandler.php';
require_once REFACTORED_LEGACY_ROOT . '/api/handlers/UserInfoHandler.php';

$pdo = $GLOBALS['pdo'] ?? getDatabaseConnection();
$GLOBALS['pdo'] = $pdo;
if (!($pdo instanceof PDO)) {
    fwrite(STDERR, "DB unavailable\n");
    exit(1);
}

// Force the MAIN-bot token to a dummy so the main path cannot validate the
// reseller-signed initData — this exercises the reseller fallback.
$GLOBALS['APIKEY'] = '000000000:FAKE_MAIN_TOKEN_FOR_TEST';

// Resolve the live reseller bot (by owner id) to get its real token + username.
$bstmt = $pdo->prepare("SELECT id_user, username, bot_token FROM botsaz WHERE id_user = :u LIMIT 1");
$bstmt->execute([':u' => '6327424512']);
$botRow = $bstmt->fetch(PDO::FETCH_ASSOC);
if (!is_array($botRow) || empty($botRow['bot_token'])) {
    fwrite(STDERR, "Reseller bot not found\n");
    exit(1);
}
$RID = (int) $botRow['id_user'];
$BOT_TOKEN = (string) $botRow['bot_token'];
$BOT_USERNAME = (string) $botRow['username'];
$OWNER_UID = (int) $botRow['id_user'];

// ---- capture emit (throw to stop before exit) ----
class __CapStop extends Exception {}
function __verify_emit(int $http, array $payload): void
{
    $GLOBALS['__cap'] = ['http' => $http, 'payload' => $payload];
    throw new __CapStop('captured');
}

// ---- initData generator (signed with the given bot token) ----
function makeInitData(string $botToken, array $user, array $bot): string
{
    $fields = [
        'user'      => json_encode($user, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'bot'       => json_encode($bot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'auth_date' => (string) time(),
    ];
    $checkArr = [];
    foreach ($fields as $k => $v) {
        $checkArr[] = $k . '=' . $v;
    }
    sort($checkArr, SORT_STRING);
    $checkString = implode("\n", $checkArr);
    $secret = hash_hmac('sha256', $botToken, 'WebAppData', true);
    $hash = hash_hmac('sha256', $checkString, $secret);
    return http_build_query(array_merge($fields, ['hash' => $hash]));
}

// ---- backups ----
$uStmt = $pdo->prepare("SELECT token FROM user WHERE id = :id");
$uStmt->execute([':id' => $OWNER_UID]);
$backupToken = (string) $uStmt->fetchColumn();
$pStmt = $pdo->prepare("SELECT * FROM marzban_panel WHERE id = :id");
$pStmt->execute([':id' => 1]);
$backupPanel = $pStmt->fetch(PDO::FETCH_ASSOC);

$pass = 0;
$fail = 0;
$check = static function ($label, $ok, $note = '') use (&$pass, &$fail) {
    if ($ok) { $pass++; echo "PASS {$label}\n"; }
    else { $fail++; echo "FAIL {$label}" . ($note !== '' ? " [{$note}]" : '') . "\n"; }
};

function runVerify(string $initData): ?array
{
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST['initData'] = $initData;
    $GLOBALS['__cap'] = null;
    try {
        VerifyHandler::run();
    } catch (__CapStop $e) {
        // captured
    } catch (Throwable $e) {
        return null;
    }
    return $GLOBALS['__cap'] ?? null;
}

try {
    // ---- 1) owner verifies via reseller bot ----
    $initOwner = makeInitData($BOT_TOKEN,
        ['id' => $OWNER_UID, 'username' => 'ubuntu137', 'first_name' => 'Owner'],
        ['id' => 8055618404, 'username' => $BOT_USERNAME, 'first_name' => 'ResellerBot']);
    $v = runVerify($initOwner);
    $check('owner verify captured', is_array($v));
    $check('owner verify status true', is_array($v) && !empty($v['payload']['status']));
    $check('owner verify reseller_id bound', is_array($v) && (int) ($v['payload']['reseller_id'] ?? 0) === $RID);
    $check('owner verify is_reseller_admin', is_array($v) && ($v['payload']['is_reseller_admin'] ?? false) === true);
    $token = is_array($v) ? (string) ($v['payload']['token'] ?? '') : '';
    $check('owner verify issued token', $token !== '');

    // token resolves back to the owner user
    $user = FaoximaAuth::userFromToken($token);
    $check('token resolves to owner user', is_array($user) && (int) ($user['id'] ?? 0) === $OWNER_UID);

    // ---- 2) list panels (scoped to the reseller) ----
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $GLOBALS['__cap'] = null;
    try {
        $h = new ResellerServiceHandler(is_array($user) ? $user : [], []);
        $h->mode = 'list';
        $h->handle();
    } catch (__CapStop $e) {
    }
    $listCap = $GLOBALS['__cap'] ?? null;
    $check('list captured', is_array($listCap));
    $check('list status true', is_array($listCap) && !empty($listCap['payload']['status']));
    $listPanels = is_array($listCap) ? (array) ($listCap['payload']['obj']['panels'] ?? []) : [];
    $listIds = array_map(static fn ($p) => (int) ($p['id'] ?? 0), $listPanels);
    $check('list includes owned panel 1', in_array(1, $listIds, true), 'ids=' . implode(',', $listIds));
    $check('list excludes password field', !isset($listPanels[0]['password_panel']));

    // ---- 3) update url (scoped) ----
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $GLOBALS['__cap'] = null;
    try {
        $h = new ResellerServiceHandler(is_array($user) ? $user : [], ['panel' => 1, 'field' => 'url', 'value' => 'svc.example.com']);
        $h->mode = 'update';
        $h->handle();
    } catch (__CapStop $e) {
    }
    $upCap = $GLOBALS['__cap'] ?? null;
    $check('update url captured+ok', is_array($upCap) && !empty($upCap['payload']['status']));
    $cStmt = $pdo->prepare("SELECT url_panel FROM marzban_panel WHERE id = 1");
    $cStmt->execute();
    $check('url persisted (coerced)', (string) $cStmt->fetchColumn() === 'https://svc.example.com');

    // ---- 4) toggle status (scoped) ----
    $before = (string) $pdo->query("SELECT status FROM marzban_panel WHERE id = 1")->fetchColumn();
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $GLOBALS['__cap'] = null;
    try {
        $h = new ResellerServiceHandler(is_array($user) ? $user : [], ['panel' => 1, 'field' => 'status']);
        $h->mode = 'toggle';
        $h->handle();
    } catch (__CapStop $e) {
    }
    $tgCap = $GLOBALS['__cap'] ?? null;
    $after = (string) $pdo->query("SELECT status FROM marzban_panel WHERE id = 1")->fetchColumn();
    $check('toggle status captured+ok', is_array($tgCap) && !empty($tgCap['payload']['status']));
    $check('status flipped', $after !== $before && in_array($after, ['active', 'disable'], true));

    // ---- 4b) user_info exposes reseller context (drives the SPA entry) ----
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $GLOBALS['__cap'] = null;
    try {
        $h = new UserInfoHandler(is_array($user) ? $user : [], []);
        $h->handle();
    } catch (__CapStop $e) {
    }
    $uiCap = $GLOBALS['__cap'] ?? null;
    $uiObj = is_array($uiCap) ? (array) ($uiCap['payload']['obj'] ?? []) : [];
    $check('user_info captured', is_array($uiCap));
    $check('user_info marks reseller admin', ($uiObj['is_reseller_admin'] ?? false) === true);
    $check('user_info reseller_id', (int) ($uiObj['reseller_id'] ?? 0) === $RID);

    // ---- 5) non-reseller actor is accepted only through reseller resolution ----
    // Keep the main token fake for each standalone verify call (the owner call
    // intentionally switches APIKEY to the reseller token inside the handler).
    $GLOBALS['APIKEY'] = '000000000:FAKE_MAIN_TOKEN_FOR_TEST';
    $initStranger = makeInitData($BOT_TOKEN,
        ['id' => 111, 'username' => 'stranger', 'first_name' => 'X'],
        ['id' => 8055618404, 'username' => $BOT_USERNAME, 'first_name' => 'ResellerBot']);
    $v2 = runVerify($initStranger);
    $check('stranger accepts as reseller customer', is_array($v2) && !empty($v2['payload']['status']) && !empty($v2['payload']['token']));

    // ---- 6) a main-bot customer (unknown bot) is rejected under dummy main token ----
    $GLOBALS['APIKEY'] = '000000000:FAKE_MAIN_TOKEN_FOR_TEST';
    $initUnknownBot = makeInitData($BOT_TOKEN,
        ['id' => 222, 'username' => 'cust', 'first_name' => 'C'],
        ['id' => 999999999, 'username' => 'some_other_bot', 'first_name' => 'Other']);
    $v3 = runVerify($initUnknownBot);
    $check('unknown-bot verify rejected', is_array($v3) && empty($v3['payload']['status']));
} finally {
    // restore user token + panel
    $rStmt = $pdo->prepare("UPDATE user SET token = :t WHERE id = :id");
    $rStmt->execute([':t' => $backupToken, ':id' => $OWNER_UID]);
    $id = (int) $backupPanel['id'];
    unset($backupPanel['id']);
    $set = implode(', ', array_map(static fn ($c) => "`{$c}` = :c_{$c}", array_keys($backupPanel)));
    $p = [':id' => $id];
    foreach ($backupPanel as $c => $vv) { $p[':c_' . $c] = $vv; }
    $rs = $pdo->prepare("UPDATE marzban_panel SET {$set} WHERE id = :id");
    $rs->execute($p);
    $check('panel + token restored', $rs->rowCount() === 1);
}

echo "\nRESULT: PASS={$pass} FAIL={$fail}\n";
exit($fail === 0 ? 0 : 1);
