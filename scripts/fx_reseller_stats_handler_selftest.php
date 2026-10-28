<?php
/**
 * fx_reseller_stats_handler_selftest.php — end-to-end check of the reseller
 * time-filtered stats callback handler (fx_res_stats_handle) with mocked
 * Telegram calls and a real DB connection.
 *
 *   docker exec faoxima-app-1 php /var/www/faoxima/scripts/fx_reseller_stats_handler_selftest.php
 */
error_reporting(E_ALL & ~E_DEPRECATED);
date_default_timezone_set('Asia/Tehran');
define('REFACTORED_LEGACY_ROOT', '/var/www/faoxima');
require_once REFACTORED_LEGACY_ROOT . '/vpnbot/6327424512nomayandefoximabot/config.php';
define('FX_RESELLER_ID', 6327424512);
define('FX_RESELLER_TOKEN', $ApiToken);
require_once REFACTORED_LEGACY_ROOT . '/re/rx/function/bootstrap.php';
require_once REFACTORED_LEGACY_ROOT . '/re/rx/function/statistics_helpers.php';
require_once REFACTORED_LEGACY_ROOT . '/re/rx/function/bot_api_helpers.php';
require_once REFACTORED_LEGACY_ROOT . '/re/rx/function/database_helpers_2.php';
require_once REFACTORED_LEGACY_ROOT . '/re/rx/function/fx_reseller_stats.php';

$pdo = getDatabaseConnection();
if (!($pdo instanceof PDO)) {
    fwrite(STDERR, "DB unavailable\n");
    exit(1);
}
$tokenSql = "'" . addslashes((string) FX_RESELLER_TOKEN) . "'";
$FXU_SCOPE = "(agent = '" . (int) FX_RESELLER_ID . "' OR bottype = " . $tokenSql . ")";
$FXI_SCOPE = "EXISTS (SELECT 1 FROM user ufx WHERE ufx.id = invoice.id_user AND (ufx.agent = '" . (int) FX_RESELLER_ID . "' OR ufx.bottype = " . $tokenSql . "))";

$TEST_UID = '6327424512';
$STAT_MSG_ID = 424242;

$callLog = [];
function Editmessagetext($chat_id, $message_id, $text, $keyboard, $parse_mode = 'HTML')
{
    global $callLog;
    $callLog[] = ['op' => 'edit', 'chat' => $chat_id, 'msg' => (int) $message_id, 'text' => $text, 'kb' => $keyboard];
    return ['ok' => true];
}
function nm_adminInstantReply($chatId, $text, $keyboard = null, $parseMode = 'HTML')
{
    global $callLog;
    $callLog[] = ['op' => 'reply', 'chat' => $chatId, 'text' => $text, 'kb' => $keyboard];
    return true;
}
function telegram($method, $datas = [], $token = null)
{
    global $callLog;
    $callLog[] = ['op' => 'tg:' . $method];
    return ['ok' => true];
}

$GLOBALS['from_id'] = $TEST_UID;
$storedUserRow = select("user", "step, Processing_value", "id", $TEST_UID, "select", ['cache' => false]);
$GLOBALS['user'] = is_array($storedUserRow) ? $storedUserRow : ['id' => $TEST_UID, 'step' => 'home', 'Processing_value' => ''];

$GLOBALS['textbotlang'] = [
    'Admin' => [
        'getstats' => 'تاریخ شروع را ارسال کنید .\nمثال: \n<code>%s</code>',
    ],
    'users' => ['selectoption' => 'منتخاب کنید'],
];

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
$lastCalls = static function () use (&$callLog) {
    $out = $callLog;
    $callLog = [];
    return $out;
};

function currentStep($uid)
{
    $r = select("user", "step", "id", $uid, "select", ['cache' => false]);
    return is_array($r) ? (string) ($r['step'] ?? '') : '';
}
function currentProc($uid)
{
    $r = select("user", "Processing_value", "id", $uid, "select", ['cache' => false]);
    $raw = is_array($r) ? (string) ($r['Processing_value'] ?? '') : '';
    $d = json_decode($raw, true);
    return is_array($d) ? $d : [];
}

$baseArgs = [$TEST_UID, $STAT_MSG_ID, (array) $GLOBALS['user'], '', $pdo, $FXU_SCOPE, $FXI_SCOPE, null];

update("user", "step", "home", "id", $TEST_UID);
update("user", "Processing_value", "{}", "id", $TEST_UID);
$check('initial step is not a stats step', !in_array(currentStep($TEST_UID), ['get_time_start', 'get_time_end'], true));

$calls = fx_res_stats_handle('hoursago_stat', (string) ($GLOBALS['user']['step'] ?? ''), ...$baseArgs);
$log = $lastCalls();
$check('hoursago handled', $calls === true);
$check('hoursago edits the stats message', isset($log[0]) && $log[0]['op'] === 'edit' && $log[0]['msg'] === $STAT_MSG_ID);
$check('hoursago text has master layout title', isset($log[0]) && strpos($log[0]['text'], 'آمار ۱ ساعت گذشته') !== false);
$check('hoursago text has orders/extend/volume/time/location lines', isset($log[0])
    && strpos($log[0]['text'], 'تعداد سفارشات') !== false
    && strpos($log[0]['text'], 'تعداد تمدید') !== false
    && strpos($log[0]['text'], 'حجم‌های اضافه') !== false
    && strpos($log[0]['text'], 'زمان‌های اضافه') !== false
    && strpos($log[0]['text'], 'تغییر لوکیشن') !== false
    && strpos($log[0]['text'], 'اکانت‌های تست') !== false
    && strpos($log[0]['text'], 'تعداد کاربران') !== false);
$check('hoursago text has reseller extras (active services + payments section label)', isset($log[0])
    && strpos($log[0]['text'], 'سرویس‌های فعال') !== false
    && (strpos($log[0]['text'], 'پرداخت‌های موفق') !== false || strpos($log[0]['text'], '📌') === false));
$kb = isset($log[0]) ? json_decode((string) $log[0]['kb'], true) : null;
$check('hoursago keyboard keeps 8 filter buttons', is_array($kb) && count($kb['inline_keyboard'] ?? []) === 6 && count($kb['inline_keyboard'][2] ?? []) === 2);

$calls = fx_res_stats_handle('month_old_stat', '', ...$baseArgs);
$log = $lastCalls();
$check('month_old handled with edit', $calls === true && isset($log[0]) && $log[0]['op'] === 'edit' && strpos($log[0]['text'], 'آمار ماه گذشته') !== false && strpos($log[0]['text'], '⏳ بازه تایم') !== false);

$calls = fx_res_stats_handle('stat_all_bot', '', ...$baseArgs);
$log = $lastCalls();
$check('stat_all_bot handled with edit to all-time text', $calls === true && isset($log[0]) && $log[0]['op'] === 'edit' && strpos($log[0]['text'], 'آمار ربات نمایندگی') !== false);

$calls = fx_res_stats_handle('view_stat_time', '', ...$baseArgs);
$log = $lastCalls();
$check('view_stat_time handled', $calls === true);
$check('view_stat_time prompts for start date', isset($log[0]) && strpos($log[0]['text'], 'تاریخ شروع') !== false);
$check('view_stat_time sets step get_time_start', currentStep($TEST_UID) === 'get_time_start');
$check('view_stat_time stores stats message id', (int) currentProc($TEST_UID)['fxr_stat_msg_id'] === $STAT_MSG_ID);

$GLOBALS['user']['step'] = 'get_time_start';
$calls = fx_res_stats_handle('', 'get_time_start', $TEST_UID, 999, (array) $GLOBALS['user'], 'not-a-date', $pdo, $FXU_SCOPE, $FXI_SCOPE, null);
$log = $lastCalls();
$check('invalid start date rejected', $calls === true && isset($log[0]) && $log[0]['op'] === 'reply' && strpos($log[0]['text'], 'معتبر') !== false);
$check('step stays get_time_start after invalid date', currentStep($TEST_UID) === 'get_time_start');

$GLOBALS['user']['step'] = 'get_time_start';
$calls = fx_res_stats_handle('', 'get_time_start', $TEST_UID, 999, (array) $GLOBALS['user'], '2026/09/15', $pdo, $FXU_SCOPE, $FXI_SCOPE, null);
$log = $lastCalls();
$check('valid start date advances to get_time_end', $calls === true && currentStep($TEST_UID) === 'get_time_end');
$check('start date stored', currentProc($TEST_UID)['fxr_stat_start'] === '2026/09/15');
$check('end date prompt sent', isset($log[0]) && strpos($log[0]['text'], 'تاریخ پایان') !== false);

$GLOBALS['user']['step'] = 'get_time_end';
$calls = fx_res_stats_handle('', 'get_time_end', $TEST_UID, 1000, (array) $GLOBALS['user'], '2026/09/20', $pdo, $FXU_SCOPE, $FXI_SCOPE, null);
$log = $lastCalls();
$check('custom range handled', $calls === true);
$check('custom range edits original stats message', isset($log[0]) && $log[0]['op'] === 'edit' && $log[0]['msg'] === $STAT_MSG_ID);
$check('custom range text has selected-date title + range line', isset($log[0]) && strpos($log[0]['text'], 'آمار تاریخ انتخابی') !== false && strpos($log[0]['text'], '2026/09/15 00:00:00 تا 2026/09/20 23:59:59') !== false);
$check('step reset to home after custom range', currentStep($TEST_UID) === 'home');

$GLOBALS['user']['step'] = 'get_time_end';
$calls = fx_res_stats_handle('', 'get_time_end', $TEST_UID, 1000, (array) $GLOBALS['user'], '2026/09/01', $pdo, $FXU_SCOPE, $FXI_SCOPE, null);
$log = $lastCalls();
$check('inverted custom range rejected', $calls === true && isset($log[0]) && strpos($log[0]['text'], 'نامعتبر') !== false);
$check('step reset after inverted range', currentStep($TEST_UID) === 'home');

$check('close_stat is NOT handled by reseller handler (falls through to shared close)', fx_res_stats_handle('close_stat', '', ...$baseArgs) === false);
$check('unrelated callback not handled', fx_res_stats_handle('buy', '', ...$baseArgs) === false);

$restoreStep = (string) ($storedUserRow['step'] ?? 'home');
$restoreProc = (string) ($storedUserRow['Processing_value'] ?? '');
update("user", "step", $restoreStep, "id", $TEST_UID);
update("user", "Processing_value", $restoreProc, "id", $TEST_UID);
$check('user row state restored', currentStep($TEST_UID) === $restoreStep);

echo "\nPASS={$pass} FAIL={$fail}\n";
exit($fail === 0 ? 0 : 1);
