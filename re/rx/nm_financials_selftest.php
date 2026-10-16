<?php
/**
 * nm_financials_selftest.php — تست خودکار گام ۱ (فقط‌خوانDB + transaction رول‌بک)
 * اجرای آفلاین: pure logic بدون دیتابیس.
 * اجرای کامل:   docker exec -e DBH.. faoxima-app-1 php /var/www/faoxima/re/rx/nm_financials_selftest.php
 * هیچ داده‌ای تغییر نمی‌کند: تست DB داخل BEGIN..ROLLBACK است.
 */
error_reporting(E_ALL & ~E_DEPRECATED);
require __DIR__ . '/nm_financials.php';

$pass = 0; $fail = 0;
function check($name, $cond, $detail = '') {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✅ $name\n"; }
    else { $fail++; echo "  ❌ $name" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}

echo "== بخش A: منطق خالص (بدون DB) ==\n";

$r = nm_can_purchase(1000000, 2000000);
check('A1 خرید ۲M با موجودی ۱M → مجاز (بدهی ۱M < سقف ۱۲M)', $r['ok'] === true && $r['balance_after'] === -1000000 && $r['debt_after'] === 1000000, json_encode($r, JSON_UNESCAPED_UNICODE));

$r = nm_can_purchase(0, 12000000);
check('A2 مرز: بدهی دقیقاً = سقف ۱۲M → مجاز (abs <= limit)', $r['ok'] === true && $r['balance_after'] === -12000000, json_encode($r, JSON_UNESCAPED_UNICODE));

$r = nm_can_purchase(0, 12000001);
check('A3 یک تومان بیش از سقف → مسدود (debt_limit_exceeded)', $r['ok'] === false && $r['reason'] === 'debt_limit_exceeded', json_encode($r, JSON_UNESCAPED_UNICODE));

$r = nm_can_purchase(-11000000, 1000000);
check('A4 بدهی فعلی ۱۱M + خرید ۱M → مرز، مجاز', $r['ok'] === true && $r['balance_after'] === -12000000, json_encode($r, JSON_UNESCAPED_UNICODE));

$r = nm_can_purchase(-11000000, 1500000);
check('A5 بدهی فعلی ۱۱M + خرید ۱.۵M → عبور از سقف، مسدود', $r['ok'] === false && $r['reason'] === 'debt_limit_exceeded', json_encode($r, JSON_UNESCAPED_UNICODE));

$r = nm_can_purchase(5000000, 3000000);
check('A6 خرید عادی بدون بدهی → مجاز، بدهی صفر', $r['ok'] === true && $r['balance_after'] === 2000000 && $r['debt_after'] === 0, json_encode($r, JSON_UNESCAPED_UNICODE));

$r = nm_can_purchase(0, 0);
check('A7 قیمت صفر → empty_price', $r['ok'] === false && $r['reason'] === 'empty_price', json_encode($r, JSON_UNESCAPED_UNICODE));

check('A8a nm_debt_of(-1040000) = 1040000', nm_debt_of(-1040000) === 1040000);
check('A8b nm_debt_of(500) = 0', nm_debt_of(500) === 0);
check('A8c nm_debt_of(0) = 0', nm_debt_of(0) === 0);

$r = nm_can_purchase(0, 5000000, 4000000);
check('A9a سقف سفارشی ۴M: بدهی ۵M → مسدود', $r['ok'] === false, json_encode($r, JSON_UNESCAPED_UNICODE));
$r = nm_can_purchase(0, 3000000, 4000000);
check('A9b سقف سفارشی ۴M: بدهی ۳M → مجاز', $r['ok'] === true, json_encode($r, JSON_UNESCAPED_UNICODE));

check('A10 سقف پیش‌فرض = 12,000,000', nm_default_debt_limit() === 12000000, (string)nm_default_debt_limit());

echo "== بخش B: تست دیتابیس (transaction + ROLLBACK — بدون ردپا) ==\n";

$DBH = getenv('DBH'); $DBU = getenv('DBU'); $DBP = getenv('DBP'); $DBN = getenv('DBN');
if (!$DBH || !$DBU || !$DBN) {
    echo "  ⚠️  env DBX تنظیم نیست — بخش B رد شد (بخش A کافی برای منطق خالص)\n";
    echo "\nنتیجه: PASS=$pass FAIL=$fail (بدون بخش B)\n";
    exit($fail ? 1 : 0);
}

try {
    $pdo = new PDO("mysql:host=$DBH;dbname=$DBN;charset=utf8mb4", $DBU, $DBP, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
} catch (Throwable $e) {
    echo "  ❌ اتصال PDO ناموفق: " . $e->getMessage() . "\n";
    exit(1);
}

$row = $pdo->query("SELECT id, Balance FROM user WHERE id > 0 AND Balance >= 0 ORDER BY id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$row) { echo "  ❌ جدول user خالی است\n"; exit(1); }
$uid = (int)$row['id'];
$B0  = (int)$row['Balance'];
$logCntBefore = (int)$pdo->query("SELECT COUNT(*) FROM fx_debt_log")->fetchColumn();

echo "  (کاربر تست: id=$uid موجودی اولیه=$B0)\n";
$pdo->exec("SET autocommit = 0");

// B1: کسر ۵۰۰۰ → موجودی منفی‌تر / کم‌تر
$r = nm_debit_balance($pdo, $uid, 5000, 12000000, 'NMF_SELFTEST');
$b = (int)$pdo->query("SELECT Balance FROM user WHERE id = $uid")->fetchColumn();
check('B1 کسر ۵۰۰۰ اتمیک → موجودی جدید = ' . ($B0 - 5000), $r['ok'] === true && $b === $B0 - 5000, json_encode($r));

// B2: کسری که بدهی را از سقف عبور می‌دهد → مسدود، موجودی دست‌نخورده
$r = nm_debit_balance($pdo, $uid, $B0 + 12000000 + 1000, 12000000, 'NMF_SELFTEST2');
$b = (int)$pdo->query("SELECT Balance FROM user WHERE id = $uid")->fetchColumn();
check('B2 عبور از سقف → مسدود + موجودی دست‌نخورده', $r['ok'] === false && $r['reason'] === 'debt_limit_exceeded' && $b === $B0 - 5000, json_encode($r));

// B3: مرز دقیق — کسری که بدهی را دقیقاً به سقف می‌رساند → مجاز
$r = nm_debit_balance($pdo, $uid, $B0 - 5000 + 12000000, 12000000, 'NMF_SELFTEST3');
$b = (int)$pdo->query("SELECT Balance FROM user WHERE id = $uid")->fetchColumn();
check('B3 کسری تا مرز دقیق سقف → مجاز', $r['ok'] === true && $b === -12000000, json_encode($r) . " b=$b");

// B4: واریز → بدهی کاهش می‌یابد (برگشت به وضعیت بعد از B1)
$r = nm_credit_balance($pdo, $uid, 12005000, 'NMF_SELFTEST', 'credit');
$b = (int)$pdo->query("SELECT Balance FROM user WHERE id = $uid")->fetchColumn();
check('B4 واریز ۱۲,۰۰۵,۰۰۰ → موجودی می‌شود ۵,۰۰۰', $r['ok'] === true && $b === 5000, json_encode($r) . " b=$b");

// B4-bis: جداسازی دفتر — ردیف‌های NM باید scope='main' ثبت شوند (ستون از scripts/fx_migrate_debt_scope.php)
$sc = (string)$pdo->query("SELECT scope FROM fx_debt_log WHERE reseller_id = $uid AND invoice_ref = 'NMF_SELFTEST' AND method = 'credit' ORDER BY id DESC LIMIT 1")->fetchColumn();
check("B4bis ردیف NM با scope='main' ثبت شده", $sc === 'main', "scope=$sc");

// ROLLBACK — پاک‌سازی کامل
$pdo->exec("ROLLBACK");
$pdo->exec("SET autocommit = 1");

$b = (int)$pdo->query("SELECT Balance FROM user WHERE id = $uid")->fetchColumn();
$logCntAfter = (int)$pdo->query("SELECT COUNT(*) FROM fx_debt_log")->fetchColumn();
check('B5 ROLLBACK → موجودی برگشت به ' . $B0, $b === $B0, "b=$b");
check('B6 ROLLBACK → fx_debt_log بدون ردپا', $logCntAfter === $logCntBefore, "before=$logCntBefore after=$logCntAfter");

echo "\nنتیجه: PASS=$pass FAIL=$fail\n";
exit($fail ? 1 : 0);
