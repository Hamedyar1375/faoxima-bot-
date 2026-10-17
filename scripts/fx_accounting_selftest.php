<?php
/**
 * fx_accounting_selftest.php — تست دائمی حسابداری جلسه ۵ (تراز ربات اصلی/نمایندگی)
 *
 * اجرا:
 *   docker exec -e DBH=faoxima-db -e DBU=faoxima -e DBP=k2IZahTmNexPe06fo4DS -e DBN=faoxima \
 *     faoxima-app-1 php /var/www/faoxima/scripts/fx_accounting_selftest.php
 *
 * بخش A: منطق خالص (بدون DB)
 * بخش B: تست DB داخل BEGIN..ROLLBACK (بدون تغییر داده واقعی) — با reseller تستی 990000001
 * بخش C: تست قرارداد قفل MySQL (GET_LOCK روی دو کانکشن جدا)
 * بخش D: تست سیم‌کشی استاتیک (وجود نقاط اصلاح‌شده در سورس — رگرسیون حذف تصادفی)
 *
 * هیچ داده مالی واقعی تغییر نمی‌کند.
 */
error_reporting(E_ALL & ~E_DEPRECATED);

$ROOT = dirname(__DIR__);
require_once $ROOT . '/re/rx/index/fx_debt.php';

$pass = 0; $fail = 0; $warn = 0;
function check($name, $cond, $detail = '') {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✅ $name\n"; }
    else { $fail++; echo "  ❌ $name" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}
function warn($name, $cond, $detail = '') {
    global $warn;
    if (!$cond) { $warn++; echo "  ⚠️  $name" . ($detail !== '' ? " — $detail" : '') . "\n"; }
    else { echo "  ✅ $name\n"; }
}

// استاب‌های محیط CLI (fx_debt برای alert/notify از این‌ها استفاده می‌کند)
if (!function_exists('update'))    { function update($t, $c, $v, $w, $wv) { return true; } }
if (!function_exists('select'))    { function select($t, $c = '*', $w = null, $wv = null, $m = 'select') { return []; } }
if (!function_exists('telegram'))  { function telegram($m, $p = []) { return true; } }
if (!function_exists('sendmessage')) { function sendmessage($c, $t, $k = null, $p = null, $tok = null) { return true; } }
if (!function_exists('jdate'))     { function jdate($f, $ts = null) { return date($f, $ts ?? time()); } }

echo "== بخش A: منطق خالص (بدون DB) ==\n";

$lim = fx_n2_credit_limit(['maxbuyagent' => 5000000, 'agent' => 'n2']);
check('A1 سقف n2 = maxbuyagent وقتی > 0', $lim === 5000000, "got=$lim");

$envLim = getenv('NM_DEBT_LIMIT');
$expect0 = ($envLim !== false && $envLim !== '' && ctype_digit((string)$envLim)) ? (int)$envLim : 12000000;
$lim0 = fx_n2_credit_limit(['maxbuyagent' => 0, 'agent' => 'n2']);
check("A2 سقف n2 پیش‌فرض وقتی maxbuyagent=0 (انتظار=$expect0)", $lim0 === $expect0, "got=$lim0");

echo "\n== بخش B: تست DB (transaction + ROLLBACK) ==\n";

$DBH = getenv('DBH'); $DBU = getenv('DBU'); $DBP = getenv('DBP'); $DBN = getenv('DBN');
if (!$DBH || !$DBU || !$DBN) {
    echo "  ⚠️  env DBX تنظیم نیست — بخش B/C رد شد\n";
    goto static_tests;
}
try {
    $pdo = new PDO("mysql:host=$DBH;dbname=$DBN;charset=utf8mb4", $DBU, $DBP, [
        PDO::ATTR_ERRMODE          => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (Throwable $e) {
    echo "  ❌ اتصال PDO ناموفق: " . $e->getMessage() . "\n";
    exit(1);
}
$GLOBALS['pdo'] = $pdo;

$TEST_RID = 990000001;
$logBefore = (int)$pdo->query("SELECT COUNT(*) FROM fx_debt_log")->fetchColumn();
$sazBefore = (int)$pdo->query("SELECT COUNT(*) FROM botsaz WHERE id_user = '$TEST_RID'")->fetchColumn();

$pdo->exec("SET autocommit = 0");
$pdo->exec("INSERT INTO botsaz (id_user, bot_token, admin_ids, username, setting, hide_panel, time, debt_mode, debt_ceiling, current_debt) VALUES ('$TEST_RID', 'test:token', '[]', 'selftest', '{\"sale_notify\":\"0\"}', '[]', '0', 'debt', 100000, 0)");

$r = fx_debt_reserve($TEST_RID, 30000);
check('B1 reserve 30000 با سقف 100000 → ok و debt=30000', !empty($r[0]) && fx_debt_state($TEST_RID)['current'] === 30000, json_encode($r, JSON_UNESCAPED_UNICODE));

$r = fx_debt_reserve($TEST_RID, 80000);
check('B2 reserve 80000 (30000+80000>سقف) → مسدود و debt دست‌نخورده', empty($r[0]) && $r[1] === 'ceiling' && fx_debt_state($TEST_RID)['current'] === 30000, json_encode($r, JSON_UNESCAPED_UNICODE));

$r = fx_debt_reserve($TEST_RID, 70000);
check('B3 reserve 70000 تا مرز دقیق سقف → ok و debt=100000', !empty($r[0]) && fx_debt_state($TEST_RID)['current'] === 100000, json_encode($r, JSON_UNESCAPED_UNICODE));

list($okC, $rsC) = fx_debt_commit($TEST_RID, 70000, 'change_location', 'INV-SELFTEST-1');
$sc = (string)$pdo->query("SELECT scope FROM fx_debt_log WHERE reseller_id = '$TEST_RID' AND invoice_ref = 'INV-SELFTEST-1' AND method = 'change_location' ORDER BY id DESC LIMIT 1")->fetchColumn();
$da = (int)$pdo->query("SELECT debt_after FROM fx_debt_log WHERE reseller_id = '$TEST_RID' AND invoice_ref = 'INV-SELFTEST-1' ORDER BY id DESC LIMIT 1")->fetchColumn();
check("B4 commit change_location → ردیف in با scope='bot' debt_after=100000", !empty($okC) && $sc === 'bot' && $da === 100000, "ok=$okC scope=$sc debt_after=$da");

fx_debt_release($TEST_RID, 30000, 'INV-SELFTEST-1');
$cur = fx_debt_state($TEST_RID)['current'];
$revDir = (string)$pdo->query("SELECT direction FROM fx_debt_log WHERE reseller_id = '$TEST_RID' AND method = 'revert' ORDER BY id DESC LIMIT 1")->fetchColumn();
check('B5 release 30000 → debt=70000 و ردیف out نوع revert', $cur === 70000 && $revDir === 'out', "cur=$cur rev=$revDir");

list($ok1) = fx_debt_record_once($TEST_RID, 5000, 'purchase_gateway', 'ORDER-SELFTEST-1');
list($ok2, $reason2) = fx_debt_record_once($TEST_RID, 5000, 'purchase_gateway', 'ORDER-SELFTEST-1');
$cnt = (int)$pdo->query("SELECT COUNT(*) FROM fx_debt_log WHERE reseller_id = '$TEST_RID' AND invoice_ref = 'ORDER-SELFTEST-1' AND method = 'purchase_gateway'")->fetchColumn();
check('B6 record_once خرید درگاه → اولین بار ok، دوم duplicate، فقط ۱ ردیف', !empty($ok1) && !empty($ok2) && $reason2 === 'duplicate' && $cnt === 1, "ok1=$ok1 ok2=$ok2 reason=$reason2 cnt=$cnt");
check('B7 debt نهایی = 75000 (70000+5000)', fx_debt_state($TEST_RID)['current'] === 75000, 'cur=' . fx_debt_state($TEST_RID)['current']);

$pdo->exec("ROLLBACK");
$pdo->exec("SET autocommit = 1");
$logAfter = (int)$pdo->query("SELECT COUNT(*) FROM fx_debt_log")->fetchColumn();
$sazAfter = (int)$pdo->query("SELECT COUNT(*) FROM botsaz WHERE id_user = '$TEST_RID'")->fetchColumn();
check('B8 ROLLBACK → fx_debt_log بدون ردپا', $logAfter === $logBefore, "before=$logBefore after=$logAfter");
check('B9 ROLLBACK → botsaz تستی حذف شد', $sazAfter === $sazBefore, "before=$sazBefore after=$sazAfter");

echo "\n== بخش C: قرارداد قفل (دو کانکشن) ==\n";
try {
    $pdo2 = new PDO("mysql:host=$DBH;dbname=$DBN;charset=utf8mb4", $DBU, $DBP, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (Throwable $e) { $pdo2 = null; }
if ($pdo2 instanceof PDO) {
    $name = 'fx:moneytest:' . $TEST_RID;
    $g1 = (string)$pdo->query("SELECT GET_LOCK('$name', 1)")->fetchColumn();
    $g2 = (string)$pdo2->query("SELECT GET_LOCK('$name', 0.3)")->fetchColumn();
    check('C1 قفل روی کانکشن ۱ گرفته شد', $g1 === '1', "g1=$g1");
    check('C2 کانکشن ۲ طی timeout نمی‌تواند بگیرد', $g2 !== '1', "g2=$g2");
    $pdo->query("SELECT RELEASE_LOCK('$name')")->fetchColumn();
    $g3 = (string)$pdo2->query("SELECT GET_LOCK('$name', 1)")->fetchColumn();
    check('C3 بعد از release کانکشن ۲ می‌گیرد', $g3 === '1', "g3=$g3");
    $pdo2->query("SELECT RELEASE_LOCK('$name')")->fetchColumn();

    $nullScope = (int)$pdo->query("SELECT COUNT(*) FROM fx_debt_log WHERE scope IS NULL")->fetchColumn();
    check('C4 هیچ ردیف بدون scope در fx_debt_log نیست', $nullScope === 0, "null=$nullScope");
} else {
    echo "  ⚠️  کانکشن دوم ممکن نشد — بخش C رد شد\n";
}

static_tests:
echo "\n== بخش D: سیم‌کشی استاتیک (رگرسیون) ==\n";
$srcBootstrap = (string)@file_get_contents($ROOT . '/re/rx/index/bootstrap.php');
$srcDh2       = (string)@file_get_contents($ROOT . '/re/rx/function/database_helpers_2.php');
$srcFxDebt    = (string)@file_get_contents($ROOT . '/re/rx/index/fx_debt.php');

check('D1 قفل کالبک مالی در bootstrap.php هست', strpos($srcBootstrap, "\$_rxMoneyCallbackPrefixes") !== false && strpos($srcBootstrap, "rx_callback_lock_acquire(\$from_id, 'money'") !== false);
check('D2 فیکس fx چانژلوکیشن: reserve/commit/release در bootstrap.php', strpos($srcBootstrap, 'fx_debt_reserve($__fxRidCl') !== false && strpos($srcBootstrap, "'change_location', \$__fxClRef") !== false && strpos($srcBootstrap, 'fx_debt_release($__fxRidCl') !== false);
check('D3 فیکس fx خرید درگاه: purchase_gateway در database_helpers_2.php', substr_count($srcDh2, "'purchase_gateway', (string)\$order_id") >= 2, 'count=' . substr_count($srcDh2, "'purchase_gateway', (string)\$order_id"));
check('D4 متدهای جدید در لیست صورتحساب fx_debt.php', strpos($srcFxDebt, "'purchase_gateway','bulk_purchase','change_location'") !== false);
check('D5 برچسب‌های اعلان فروش برای متدهای جدید', strpos($srcFxDebt, "'purchase_gateway' =>") !== false && strpos($srcFxDebt, "'change_location' =>") !== false);

echo "\nنتیجه: PASS=$pass FAIL=$fail" . ($warn ? " WARN=$warn" : "") . "\n";
exit($fail ? 1 : 0);
