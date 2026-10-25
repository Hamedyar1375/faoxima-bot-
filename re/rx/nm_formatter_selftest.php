<?php
/**
 * nm_formatter_selftest.php — تست خودکار قالب پیام وضعیت (قالب مرحله ۳)
 * ===================================================================
 * بخش A: منطق خالص قالب‌بندی — بدون دیتابیس (اجرای آفلاین)
 * بخش B: خواندنی‌های زنده از دیتابیس — فقط SELECT، بدون هیچ تغییری
 *
 * اجرا:
 *   php nm_formatter_selftest.php                    (فقط بخش A)
 *   docker exec faoxima-app-1 php /var/www/faoxima/re/rx/nm_formatter_selftest.php
 */
error_reporting(E_ALL & ~E_DEPRECATED);
require __DIR__ . '/nm_formatter.php';

$pass = 0; $fail = 0;
function check($name, $cond, $detail = '') {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✅ $name\n"; }
    else { $fail++; echo "  ❌ $name" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}

echo "== بخش A: قالب‌بندی خالص (بدون DB) ==\n";

// --- F1..F5: اعداد نمونه با علامت منفی در انتها (nm_fmt_money) ---
check('F1 nm_fmt_money(-1040000) = «۱,۰۴۰,۰۰۰-»', nm_fmt_money(-1040000) === '۱,۰۴۰,۰۰۰-', nm_fmt_money(-1040000));
check('F2 nm_fmt_money(1040000)  = «۱,۰۴۰,۰۰۰» (بدون منفی)', nm_fmt_money(1040000) === '۱,۰۴۰,۰۰۰', nm_fmt_money(1040000));
check('F3 nm_fmt_money(0)        = «۰»', nm_fmt_money(0) === '۰', nm_fmt_money(0));
check('F4 nm_fmt_money(-12000000)= «۱۲,۰۰۰,۰۰۰-»', nm_fmt_money(-12000000) === '۱۲,۰۰۰,۰۰۰-', nm_fmt_money(-12000000));
check('F5 nm_fmt_money(12345678) = «۱۲,۳۴۵,۶۷۸»', nm_fmt_money(12345678) === '۱۲,۳۴۵,۶۷۸', nm_fmt_money(12345678));

// --- F6..F9: حاشیه‌ها ---
check('F6 عدد کمتر از ۱۰۰۰ بدون جداکننده: nm_fmt_money(999) = «۹۹۹»', nm_fmt_money(999) === '۹۹۹', nm_fmt_money(999));
check('F7 ورودی رشته‌ای: nm_fmt_money("1040000") = «۱,۰۴۰,۰۰۰»', nm_fmt_money('1040000') === '۱,۰۴۰,۰۰۰', nm_fmt_money('1040000'));
$s = nm_fmt_money(-1040000);
check('F8a علامت منفی در عدد منفی موجود است', strpos($s, '-') !== false, $s);
check('F8b علامت منفی دقیقاً در پایان است (۱,۰۴۰,۰۰۰-)', substr($s, -1) === '-', $s);
check('F8c در عدد مثبت هیچ «-» وجود ندارد', strpos(nm_fmt_money(1040000), '-') === false, nm_fmt_money(1040000));

// --- F10: ارقام فقط فارسی ---
$out = nm_fmt_money(-12345678);
check('F10 خروجی هیچ رقم لاتین ندارد', preg_match('/[0-9]/', $out) === 0, $out);

// --- F11: تبدیل ارقام ---
check('F11a nm_persian_digits("0123456789") = «۰۱۲۳۴۵۶۷۸۹»', nm_persian_digits('0123456789') === '۰۱۲۳۴۵۶۷۸۹');
check('F11b nm_persian_digits("6327424512") = «۶۳۲۷۴۲۴۵۱۲»', nm_persian_digits('6327424512') === '۶۳۲۷۴۲۴۵۱۲');

// --- F12: قالب تعداد/آیدی بدون جداکننده ---
check('F12 nm_fmt_count(1234567) = «۱۲۳۴۵۶۷»', nm_fmt_count(1234567) === '۱۲۳۴۵۶۷', nm_fmt_count(1234567));
check('F12b nm_fmt_count(0) = «۰»', nm_fmt_count(0) === '۰');

// --- F13: تفسیر وضعیت ربات ---
check('F13a Bot_Status=botstatuson → true', nm_bot_status_is_active('botstatuson') === true);
check('F13b Bot_Status=botstatusoff → false', nm_bot_status_is_active('botstatusoff') === false);
check('F13c Bot_Status=خالی → null', nm_bot_status_is_active('') === null);
check('F13d Bot_Status=مقدار ناشناخته → null', nm_bot_status_is_active('weird') === null);

// --- F14: رندر کامل پیام (مرجعی — قالب مرحله ۳) ---
$data = [
    'owner_id'      => 6327424512,
    'bot_id'        => '8055618404',
    'bot_token'     => 'AAFm_test_token_ABC123',
    'user_count'    => 4,
    'account_count' => 17,
    'credit_limit'  => 12000000,
    'balance'       => -1040000,
    'bot_active'    => true,
];
$expected = "اطلاعات ربات نماینده\n\n"
    . "👤 آیدی عددی مالک: ۶۳۲۷۴۲۴۵۱۲\n"
    . "🏷 آیدی ربات: ۸۰۵۵۶۱۸۴۰۴\n"
    . "🤖 توکن ربات: AAFm_test_token_ABC123\n\n"
    . "👥 تعداد کاربران: ۴\n"
    . "⚜️ تعداد اکانت‌های ساخته‌شده: ۱۷\n\n"
    . "💳 سقف بدهکاری: ۱۲,۰۰۰,۰۰۰ تومان\n"
    . "🏦 مانده حساب با مالک: −۱,۰۴۰,۰۰۰ تومان\n"
    . "📊 وضعیت حساب: بدهکار\n"
    . "🟢 وضعیت ربات: فعال";
$got = nm_format_manager_status($data);
check('F14 رندر کامل پیام مطابق قالب مرجع', $got === $expected, "got=" . str_replace("\n", "\\n", $got));

// --- F15: توکن ماسک‌شده ---
$gotM = nm_format_manager_status($data, ['mask_token' => true]);
check('F15a توکن ماسک: «AAFm_tes…C123» (۸+۴ کاراکتر)', strpos($gotM, '🤖 توکن ربات: AAFm_tes…C123') !== false, $gotM);
check('F15b توکن خام در خروجی ماسک وجود ندارد', strpos($gotM, 'AAFm_test_token_ABC123') === false);

// --- F16/F17/F18: حالت‌های وضعیت و مقادیر گمشده ---
$d2 = $data; $d2['bot_active'] = false;
check('F16 غیرفعال → «🔴 وضعیت ربات: غیرفعال»', strpos(nm_format_manager_status($d2), '🔴 وضعیت ربات: غیرفعال') !== false);
$d3 = $data; $d3['bot_active'] = null;
check('F17 نامشخص → «⚪ وضعیت ربات: نامشخص»', strpos(nm_format_manager_status($d3), '⚪ وضعیت ربات: نامشخص') !== false);
$d4 = $data; $d4['owner_id'] = null;
check('F18 مالک گمشده → «—»', strpos(nm_format_manager_status($d4), '👤 آیدی عددی مالک: —') !== false);

// --- F19: قالب‌بندی مبلغ علامت‌دار (علامت در ابتدای عدد) ---
check('F19a nm_fmt_money_signed(-2180000) = «−۲,۱۸۰,۰۰۰»', nm_fmt_money_signed(-2180000) === '−۲,۱۸۰,۰۰۰', nm_fmt_money_signed(-2180000));
check('F19b nm_fmt_money_signed(2180000) = «+۲,۱۸۰,۰۰۰»', nm_fmt_money_signed(2180000) === '+۲,۱۸۰,۰۰۰', nm_fmt_money_signed(2180000));
check('F19c nm_fmt_money_signed(0) = «۰»', nm_fmt_money_signed(0) === '۰', nm_fmt_money_signed(0));

// --- F20..F22: حالت‌های حساب در رندر ---
$dPos = $data; $dPos['balance'] = 2180000; $dPos['needs_reconcile'] = false;
$rPos = nm_format_manager_status($dPos);
check('F20 مانده مثبت → «+۲,۱۸۰,۰۰۰ تومان» و «بستانکار»',
    strpos($rPos, '🏦 مانده حساب با مالک: +۲,۱۸۰,۰۰۰ تومان') !== false
    && strpos($rPos, '📊 وضعیت حساب: بستانکار') !== false);

$dZero = $data; $dZero['balance'] = 0; $dZero['needs_reconcile'] = false;
$rZero = nm_format_manager_status($dZero);
check('F21 مانده صفر → «۰ تومان» و «تسویه‌شده»',
    strpos($rZero, '🏦 مانده حساب با مالک: ۰ تومان') !== false
    && strpos($rZero, '📊 وضعیت حساب: تسویه‌شده') !== false);

$dRec = $data; $dRec['needs_reconcile'] = true;
$rRec = nm_format_manager_status($dRec);
check('F22 نیازمند تطبیق → «—» و «نیازمند تطبیق حساب»',
    strpos($rRec, '🏦 مانده حساب با مالک: —') !== false
    && strpos($rRec, '📊 وضعیت حساب: نیازمند تطبیق حساب') !== false);

// --- F23: منطق کنترل تناقض مانده با دفتر main ---
check('F23a بدهی منطبق (-1000 و آخرین 1000) → بدون تناقض', nm_balance_needs_reconcile(-1000, 1000) === false);
check('F23b بدهی نامنطبق (-1000 و آخرین 900) → تناقض', nm_balance_needs_reconcile(-1000, 900) === true);
check('F23c مانده مثبت + آخرین بدهی صفر → بدون تناقض', nm_balance_needs_reconcile(5000, 0) === false);
check('F23d مانده مثبت + آخرین بدهی >۰ → تناقض', nm_balance_needs_reconcile(5000, 300) === true);
check('F23e بدون ردیف دفتر (null) → بدون تناقض', nm_balance_needs_reconcile(-500, null) === false);
check('F23f صفر + آخرین بدهی صفر → بدون تناقض', nm_balance_needs_reconcile(0, 0) === false);

echo "== بخش B: خواندن زنده از دیتابیس (فقط SELECT — بدون تغییر) ==\n";

$DBH = getenv('DB_HOST'); $DBU = getenv('DB_USER'); $DBP = getenv('DB_PASS'); $DBN = getenv('DB_NAME');
if (!$DBH || !$DBU || !$DBN) {
    echo "  ⚠️  env DBX تنظیم نیست — بخش B رد شد (بخش A کافی برای منطق خالص)\n";
    echo "\nنتیجه: PASS=$pass FAIL=$fail (بدون بخش B)\n";
    exit($fail ? 1 : 0);
}

try {
    $pdo = new PDO("mysql:host=$DBH;dbname=$DBN;charset=utf8mb4", $DBU, $DBP, [
        PDO::ATTR_ERRMODE       => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (Throwable $e) {
    echo "  ❌ اتصال PDO ناموفق: " . $e->getMessage() . "\n";
    exit(1);
}

// ردپای تغییرات: قبل/بعد — هر دو باید یکسان باشند
$debtBefore = (int)$pdo->query("SELECT COUNT(*) FROM fx_debt_log")->fetchColumn();
$usersBefore = (int)$pdo->query("SELECT COUNT(*) FROM user")->fetchColumn();

$data = nm_build_manager_status($pdo);
echo "  (داده استخراج‌شده: " . json_encode([
    'owner_id' => $data['owner_id'], 'bot_id' => $data['bot_id'],
    'token_len' => strlen($data['bot_token']), 'user_count' => $data['user_count'],
    'account_count' => $data['account_count'], 'credit_limit' => $data['credit_limit'],
    'balance' => $data['balance'], 'needs_reconcile' => $data['needs_reconcile'],
    'bot_active' => $data['bot_active'],
], JSON_UNESCAPED_UNICODE) . ")\n";

// B1: آیدی مالک از Requestagent (نخستین درخواست)
$own = (int)$pdo->query("SELECT id FROM Requestagent ORDER BY time ASC LIMIT 1")->fetchColumn();
check('B1 آیدی مالک = نخستین Requestagent', $data['owner_id'] === $own, "got=" . var_export($data['owner_id'], true) . " want=$own");

// B2: آیدی ربات و توکن از user.bottype
check('B2a آیدی ربات = «8055618404»', $data['bot_id'] === '8055618404', $data['bot_id']);
check('B2b توکن ربات فرمت معتبر دارد (>= ۳۰ کاراکتر)', preg_match('/^[A-Za-z0-9_-]{30,}$/', $data['bot_token']) === 1, 'len=' . strlen($data['bot_token']));

// B3: تعداد کاربران (بدون سطر سیستمی id=0)
$uc = (int)$pdo->query("SELECT COUNT(*) FROM user WHERE id != '0'")->fetchColumn();
check('B3 تعداد کاربران = userهای غیرسیستمی', $data['user_count'] === $uc, "got={$data['user_count']} want=$uc");

// B4: اکانت‌های ساخته‌شده
$ac = (int)$pdo->query("SELECT COUNT(*) FROM remnawave_users")->fetchColumn();
check('B4 تعداد اکانت‌ها = remnawave_users', $data['account_count'] === $ac, "got={$data['account_count']} want=$ac");

// B5: موجودی مالک دقیقاً برابر ستون Balance است (حتی اگر منفی باشد)
$st = $pdo->prepare("SELECT Balance FROM user WHERE id = :id LIMIT 1");
$st->execute([':id' => (string)$own]);
$balDb = (int)$st->fetchColumn();
check('B5 موجودی = Balance مالک در DB', $data['balance'] === $balDb, "got={$data['balance']} want=$balDb");

// B5b: پرچم تطبیق مطابق آخرین ردیف دفتر scope='main' (محاسبهٔ مستقل در تست)
$lastM = $pdo->prepare("SELECT debt_after FROM fx_debt_log WHERE reseller_id = :rid AND scope = 'main' ORDER BY id DESC LIMIT 1");
$lastM->execute([':rid' => (string)$own]);
$lastV = $lastM->fetchColumn();
$expectRec = ($lastV === false) ? false : ((int)$lastV !== ((int)$balDb < 0 ? -((int)$balDb) : 0));
check('B5b پرچم needs_reconcile مطابق آخرین ردیف دفتر main', $data['needs_reconcile'] === $expectRec,
      'needs=' . var_export($data['needs_reconcile'], true) . ' expect=' . var_export($expectRec, true));

// B6: سقف بدهکاری از STEP_1
check('B6 سقف بدهکاری = nm_default_debt_limit()', $data['credit_limit'] === nm_default_debt_limit(), (string)$data['credit_limit']);

// B7: وضعیت ربات از setting.Bot_Status
check('B7 وضعیت ربات خوانده شد (bool|null)', in_array($data['bot_active'], [true, false, null], true), var_export($data['bot_active'], true));

// B8: رندر زنده — خط سقف، خط مانده علامت‌دار و خط وضعیت حساب
$render = nm_format_manager_status($data);
check('B8a رندر شامل «💳 سقف بدهکاری: ' . nm_fmt_money($data['credit_limit']) . ' تومان»',
      strpos($render, '💳 سقف بدهکاری: ' . nm_fmt_money($data['credit_limit']) . ' تومان') !== false);
$wantBalLine = !empty($data['needs_reconcile'])
    ? '🏦 مانده حساب با مالک: —'
    : '🏦 مانده حساب با مالک: ' . nm_fmt_money_signed($data['balance']) . ' تومان';
check('B8b رندر شامل خط مانده علامت‌دار', strpos($render, $wantBalLine) !== false, $wantBalLine);
$wantState = !empty($data['needs_reconcile']) ? 'نیازمند تطبیق حساب'
    : ((int)$data['balance'] < 0 ? 'بدهکار' : ((int)$data['balance'] > 0 ? 'بستانکار' : 'تسویه‌شده'));
check('B8b2 رندر شامل «📊 وضعیت حساب: ' . $wantState . '»', strpos($render, '📊 وضعیت حساب: ' . $wantState) !== false);
check('B8c رندر شامل «👤 آیدی عددی مالک: ' . nm_fmt_count($own) . '»',
      strpos($render, '👤 آیدی عددی مالک: ' . nm_fmt_count($own)) !== false);

// B9: نمایش بدهکار در رندر — موجودی را منفی فرض می‌کنیم (تست نمایش، بدون تغییر DB)
$neg = $data; $neg['balance'] = -1040000; $neg['needs_reconcile'] = false;
$renderNeg = nm_format_manager_status($neg);
check('B9 مانده منفی در رندر = «−۱,۰۴۰,۰۰۰ تومان» و «بدهکار»',
      strpos($renderNeg, '🏦 مانده حساب با مالک: −۱,۰۴۰,۰۰۰ تومان') !== false
      && strpos($renderNeg, '📊 وضعیت حساب: بدهکار') !== false, $renderNeg);

// B10: رندر ماسک‌شده — توکن خام نریزد
$renderMasked = nm_format_manager_status($data, ['mask_token' => true]);
check('B10 توکن خام در رندر ماسک نکرده نمی‌افتد',
      strlen($data['bot_token']) > 12 && strpos($renderMasked, $data['bot_token']) === false);

// B11: صفر ردپا — داده‌ها دست‌نخورده
$debtAfter = (int)$pdo->query("SELECT COUNT(*) FROM fx_debt_log")->fetchColumn();
$usersAfter = (int)$pdo->query("SELECT COUNT(*) FROM user")->fetchColumn();
check('B11 صفر تغییر: fx_debt_log و user دست‌نخورده', $debtBefore === $debtAfter && $usersBefore === $usersAfter);

echo "\n== نمونه رندر زنده (توکن ماسک) ==\n" . $renderMasked . "\n";
echo "\nنتیجه: PASS=$pass FAIL=$fail\n";
exit($fail ? 1 : 0);
