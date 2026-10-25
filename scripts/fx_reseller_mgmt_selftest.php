<?php
/**
 * fx_reseller_mgmt_selftest.php — تست دائمی کارت جزئیات نماینده + فهرست نمایندگان (قرارداد مانده حساب)
 *
 * اجرا (مقادیر DB مطابق env استاندارد کانتینر؛ هم DB_HOST/DB_USER/DB_PASS/DB_NAME
 * و هم DBH/DBU/DBP/DBN پذیرفته می‌شود):
 *   docker exec -e DB_HOST=<host> -e DB_USER=<user> -e DB_PASS=<pass> -e DB_NAME=<db> \
 *     faoxima-app-1 php /var/www/faoxima/scripts/fx_reseller_mgmt_selftest.php
 *
 * بخش A: منطق خالص (قالب پول علامت‌دار + کنترل تناقض — مشترک با nm_formatter)
 * بخش B: رندر زنده کارت fx_res_bot_detail_view روی داده واقعی (فقط SELECT — بدون تغییر)
 * بخش C: رندر زنده «گزارش مالی و اعتباری» + «دفتر حساب» (فقط SELECT — بدون تغییر)
 * بخش D: «فهرست نمایندگان» — عنوان مشترک دارای راهنما + سطرها با مانده حساب علامت‌دار
 *
 * هیچ داده‌ای تغییر نمی‌کند.
 */
error_reporting(E_ALL & ~E_DEPRECATED);

$ROOT = dirname(__DIR__);
if (!defined('REFACTORED_LEGACY_ROOT')) define('REFACTORED_LEGACY_ROOT', $ROOT);

$pass = 0; $fail = 0;
function check($name, $cond, $detail = '') {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✅ $name\n"; }
    else { $fail++; echo "  ❌ $name" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}

if (!function_exists('update'))      { function update($t, $c, $v, $w, $wv) { return true; } }
if (!function_exists('telegram'))    { function telegram($m, $p = []) { return true; } }
if (!function_exists('sendmessage')) { function sendmessage($c, $t, $k = null, $p = null, $tok = null) { return true; } }
if (!function_exists('jdate'))       { function jdate($f, $ts = null) { return date($f, $ts ?? time()); } }
if (!function_exists('step'))        { function step($s, $f) { return true; } }
if (!function_exists('Editmessagetext')) {
    function Editmessagetext($chat_id, $message_id, $text, $keyboard = null, $parse_mode = 'HTML') {
        $GLOBALS['__fx_cap'] = ['text' => $text, 'kb' => $keyboard];
        return true;
    }
}

echo "== بخش A: منطق خالص (بدون DB) ==\n";
require_once $ROOT . '/re/rx/nm_formatter.php';
check('A1 nm_fmt_money_signed(-1040000) = «−۱,۰۴۰,۰۰۰»', nm_fmt_money_signed(-1040000) === '−۱,۰۴۰,۰۰۰', nm_fmt_money_signed(-1040000));
check('A2 nm_fmt_money_signed(4001000) = «+۴,۰۰۱,۰۰۰»', nm_fmt_money_signed(4001000) === '+۴,۰۰۱,۰۰۰', nm_fmt_money_signed(4001000));
check('A3 nm_fmt_money_signed(0) = «۰»', nm_fmt_money_signed(0) === '۰', nm_fmt_money_signed(0));
check('A4 کنترل تناقض: مانده -1000 با آخرین بدهی 1000 → بدون تناقض', nm_balance_needs_reconcile(-1000, 1000) === false);
check('A5 کنترل تناقض: مانده 5000 با آخرین بدهی 300 → تناقض', nm_balance_needs_reconcile(5000, 300) === true);

echo "\n== بخش B: رندر زنده کارت (فقط SELECT) ==\n";

$DBH = getenv('DB_HOST') ?: getenv('DBH');
$DBU = getenv('DB_USER') ?: getenv('DBU');
$DBP = getenv('DB_PASS') ?: getenv('DBP');
$DBN = getenv('DB_NAME') ?: getenv('DBN');
if (!$DBH || !$DBU || !$DBN) {
    echo "  ⚠️  env DB تنظیم نیست — بخش B رد شد (بخش A کافی برای منطق خالص)\n";
    echo "\nنتیجه: PASS=$pass FAIL=$fail (بدون بخش B)\n";
    exit($fail ? 1 : 0);
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

if (!function_exists('select')) {
    function select($t, $c = '*', $w = null, $wv = null, $m = 'select', $opts = []) {
        global $pdo;
        $st = $pdo->prepare("SELECT {$c} FROM {$t} WHERE {$w} = :v LIMIT 1");
        $st->execute([':v' => $wv]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return is_array($r) ? $r : [];
    }
}

require_once $ROOT . '/re/rx/index/fx_reseller_mgmt.php';

$logBefore   = (int)$pdo->query("SELECT COUNT(*) FROM fx_debt_log")->fetchColumn();
$usersBefore = (int)$pdo->query("SELECT COUNT(*) FROM user")->fetchColumn();

$rid = (int)$pdo->query("SELECT b.id_user FROM botsaz b JOIN user u ON u.id = b.id_user ORDER BY b.id_user ASC LIMIT 1")->fetchColumn();
if ($rid <= 0) {
    echo "  ⚠️  نماینده‌ای با سطر user+botsaz یافت نشد — بخش B رد شد\n";
    exit($fail ? 1 : 0);
}

$v   = fx_res_bot_detail_view($rid);
$txt = (string)($v['txt'] ?? '');
check("B1 کارت برای نماینده {$rid} با ok=true برگشت", !empty($v['ok']));

$st = $pdo->prepare("SELECT Balance FROM user WHERE id = :id LIMIT 1");
$st->execute([':id' => (string)$rid]);
$balDb = (int)$st->fetchColumn();

$lm = $pdo->prepare("SELECT debt_after FROM fx_debt_log WHERE reseller_id = :rid AND scope = 'main' ORDER BY id DESC LIMIT 1");
$lm->execute([':rid' => (string)$rid]);
$lastV = $lm->fetchColumn();
$lastDebt = ($lastV === false) ? null : (int)$lastV;
$needsRec = nm_balance_needs_reconcile($balDb, $lastDebt);

$wantBal = $needsRec
    ? '🏦 مانده حساب با مالک: —'
    : '🏦 مانده حساب با مالک: ' . nm_fmt_money_signed($balDb) . ' تومان';
$wantState = $needsRec ? 'نیازمند تطبیق حساب'
    : ($balDb < 0 ? 'بدهکار' : ($balDb > 0 ? 'بستانکار' : 'تسویه‌شده'));

check('B2 خط مانده علامت‌دار مطابق Balance واقعی', strpos($txt, $wantBal) !== false, "want=$wantBal");
check('B3 خط وضعیت حساب مطابق دفتر main', strpos($txt, '📊 وضعیت حساب: ' . $wantState) !== false, "want=$wantState");
check('B4 برچسب قدیمی «💰 موجودی:» حذف شده', strpos($txt, '💰 موجودی:') === false);
check('B5 خط نام/آیدی در ابتدای کارت حفظ شده', strpos($txt, "👤 ") === 0);

$bst = $pdo->prepare("SELECT username FROM botsaz WHERE id_user = :id LIMIT 1");
$bst->execute([':id' => (string)$rid]);
$botName = (string)$bst->fetchColumn();
if ($botName !== '') {
    check('B6 خط ربات (username) حفظ شده', strpos($txt, '🤖 ربات: @' . $botName) !== false);
} else {
    check('B6 خط «ربات ثبت نشده» مطابق داده', strpos($txt, '⚠️ رباتی برای این نماینده ثبت نشده') !== false);
}

$debSt = fx_debt_state($rid);
if ($debSt['mode'] === 'debt') {
    check('B7 خط حالت اعتباری (scope=bot) حفظ شده', strpos($txt, '🏦 حالت: اعتباری (بدهی)') !== false);
} else {
    check('B7 خط حالت پیش‌پرداخت (scope=bot) حفظ شده', strpos($txt, '🏦 حالت: پیش‌پرداخت (موجودی)') !== false);
}

echo "\n== بخش C: رندر صفحه گزارش مالی و دفتر حساب (فقط SELECT) ==\n";
$GLOBALS['__fx_cap'] = ['text' => '', 'kb' => null];
fx_res_render_report((int)$rid, 0, (string)$rid);
$rep = (string)($GLOBALS['__fx_cap']['text'] ?? '');
check('C1 گزارش: خط «مانده حساب با مالک» مطابق DB', strpos($rep, $wantBal) !== false, '');
check('C2 گزارش: خط وضعیت حساب مطابق دفتر main', strpos($rep, '📊 وضعیت حساب: ' . $wantState) !== false, '');
check('C3 گزارش: عبارت «کیف‌پول» برای مانده استفاده نشده', strpos($rep, 'کیف‌پول') === false, $rep);
check('C4 گزارش: بلوک اعتباری ربات (scope=bot) حفظ شده',
    strpos($rep, '📏 سقف بدهی') !== false && strpos($rep, '💸 بدهی فعلی') !== false && strpos($rep, '✅ اعتبار باقی‌مانده') !== false);
check('C5 گزارش: دکمه بازگشت حفظ شده', is_string($GLOBALS['__fx_cap']['kb'] ?? null) && strpos((string)$GLOBALS['__fx_cap']['kb'], 'fx_res_sel_') !== false);

$GLOBALS['__fx_cap'] = ['text' => '', 'kb' => null];
fx_res_render_ledger((int)$rid, 0, (string)$rid);
$led = (string)($GLOBALS['__fx_cap']['text'] ?? '');
check('C6 دفتر: خط «مانده حساب با مالک» مطابق DB', strpos($led, $wantBal) !== false, '');
check('C7 دفتر: «💸 بدهی ربات:» جدا از مانده (scope=bot)', strpos($led, '💸 بدهی ربات:') !== false, '');
check('C8 دفتر: برچسب قدیمی «💰 موجودی:» حذف شده', strpos($led, '💰 موجودی:') === false, '');
check('C9 دفتر: دکمه بازگشت حفظ شده', is_string($GLOBALS['__fx_cap']['kb'] ?? null) && strpos((string)$GLOBALS['__fx_cap']['kb'], 'fx_res_sel_') !== false);

echo "\n== بخش D: فهرست نمایندگان (عنوان مشترک + سطرها) ==\n";
$listTitle = fx_res_list_title();
check('D1 عنوان فهرست: راهنمای «مانده حساب با مالک» + برچسب بدهی ربات موجود است',
    strpos($listTitle, 'مانده حساب با مالک (مثبت: طلبکار از مالک | منفی: بدهکار به مالک)') !== false
    && strpos($listTitle, 'بدهی اعتباری ربات') !== false
    && strpos($listTitle, '<b>مدیریت ربات‌های نماینده</b>') !== false);

$listRows = fx_res_list_rows(fx_reseller_get_all());
$rowSel = null;
foreach ($listRows as $lr) {
    if (($lr[0]['callback_data'] ?? '') === "fx_res_sel_{$rid}") { $rowSel = $lr[0]; break; }
}
check("D2 سطر نماینده با callback صحیح fx_res_sel_{$rid} ساخته شد", is_array($rowSel));
$rowText = is_array($rowSel) ? (string)$rowSel['text'] : '';
$wantRowBal = $needsRec ? '🏦 —' : '🏦 ' . nm_fmt_money_signed($balDb);
check('D3 سطر: مانده علامت‌دار مطابق قرارداد (فرم فشرده)', strpos($rowText, $wantRowBal) !== false, "want=$wantRowBal row=$rowText");
check('D4 سطر: برچسب قدیمی «💰» حذف شده', strpos($rowText, '💰') === false, $rowText);

$hasCreate = false; $hasBack = false;
foreach ($listRows as $lr) {
    $cb = (string)($lr[0]['callback_data'] ?? '');
    if ($cb === 'fx_res_create_start') $hasCreate = true;
    if ($cb === 'fx_res_backadmin')    $hasBack = true;
}
check('D5 دکمه‌های «ساخت ربات نماینده» و «بازگشت» حفظ شده‌اند', $hasCreate && $hasBack);

$logAfter   = (int)$pdo->query("SELECT COUNT(*) FROM fx_debt_log")->fetchColumn();
$usersAfter = (int)$pdo->query("SELECT COUNT(*) FROM user")->fetchColumn();
check('D6 صفر تغییر داده بعد از همهٔ رندرها: fx_debt_log و user دست‌نخورده', $logBefore === $logAfter && $usersBefore === $usersAfter);

echo "\n== نمونه رندر زنده کارت ==\n" . $txt . "\n";
echo "\n== نمونه رندر گزارش مالی و اعتباری ==\n" . $rep . "\n";
echo "\n== نمونه رندر دفتر حساب ==\n" . $led . "\n";
echo "\n== نمونه عنوان فهرست نمایندگان ==\n" . $listTitle . "\n";
echo "\n== نمونه سطر نماینده {$rid} ==\n" . $rowText . "\n";
echo "\nنتیجه: PASS=$pass FAIL=$fail\n";
exit($fail ? 1 : 0);
