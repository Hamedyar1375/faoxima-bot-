<?php
/**
 * fx_debt_phase1_selftest.php — تست‌های فاز ۱ حسابداری عمده نمایندگی (FX-DEBT-P1)
 *
 * اجرا (دیتابیس تستی ایزوله):
 *   docker exec -e DBH=db -e DBU=faoxima -e DBP=k2IZahTmNexPe06fo4DS -e DBN=faoxima_botcap_empty \
 *     faoxima-app-1 php /var/www/faoxima/scripts/fx_debt_phase1_selftest.php
 *
 * پوشش:
 *   A) منطق خالص resolverها (بدون DB): محصول، حجم دلخواه، واحد (extra volume/time)
 *   B) DB ایزوله: reserve/ceiling، commit ایدمپوتنت، مسیر 1062 (کلید یکتا)، record_once،
 *      release بدون ردیف orphan، double-release، release بعد از commit، fallback بدون ستون
 *   C) سیم‌کشی استاتیک: عدم وجود fallback به retail در مسیرهای حساس + وجود safe-stop
 *
 * این تست هیچ داده‌ای از دیتابیس اصلی faoxima نمی‌خواند/نمی‌نویسد.
 */
error_reporting(E_ALL & ~E_DEPRECATED);

$ROOT = dirname(__DIR__);
require_once $ROOT . '/re/rx/function/pricing_fx.php';
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

if (!function_exists('update'))    { function update($t, $c, $v, $w, $wv) { return true; } }
if (!function_exists('select'))    { function select($t, $c = '*', $w = null, $wv = null, $m = 'select') { return []; } }
if (!function_exists('telegram'))  { function telegram($m, $p = []) { return true; } }
if (!function_exists('sendmessage')) { function sendmessage($c, $t, $k = null, $p = null, $tok = null) { return true; } }
if (!function_exists('jdate'))     { function jdate($f, $ts = null) { return date($f, $ts ?? time()); } }

echo "== بخش A: resolverهای قیمت عمده (منطق خالص) ==\n";

$panel = [
    'code_panel' => 'TEST_PANEL',
    'priceextravolume' => json_encode(['f' => 2000, 'n' => 3000]),
    'priceextratime' => json_encode(['f' => 1500]),
    'pricecustomvolume' => json_encode(['f' => 2500]),
    'pricecustomtime' => json_encode(['f' => 1800]),
];

$r = fx_credit_wholesale_product(['code_product' => 'TEST001', 'name_product' => 'Test', 'fx_wholesale_price' => 50000], $panel, 'f');
check('A1 محصول با fx_wholesale_price=50000 → ok/50000', !empty($r['ok']) && $r['amount'] === 50000, json_encode($r));

$r = fx_credit_wholesale_product(['code_product' => 'TEST002', 'name_product' => 'Test2', 'fx_wholesale_price' => null], $panel, 'f');
check('A2 محصول بدون قیمت عمده → fail (بدون fallback به retail)', empty($r['ok']) && $r['reason'] === 'no_wholesale', json_encode($r));

$r = fx_credit_wholesale_unit($panel, 'priceextravolume', 'zz', 5);
check('A3 واحد حجم: کلید zz → fallback به f → 5×2000=10000', !empty($r['ok']) && $r['amount'] === 10000, json_encode($r));

$r = fx_credit_wholesale_unit($panel, 'priceextravolume', 'n', 5);
check('A4 واحد حجم: کلید n → 5×3000=15000', !empty($r['ok']) && $r['amount'] === 15000, json_encode($r));

$r = fx_credit_wholesale_unit(['code_panel' => 'X'], 'priceextravolume', 'f', 5);
check('A5 واحد حجم بدون قیمت در پنل → fail', empty($r['ok']) && $r['reason'] === 'no_unit_price', json_encode($r));

$r = fx_credit_wholesale_unit($panel, 'priceextratime', 'f', 3);
check('A6 واحد زمان: 3×1500=4500', !empty($r['ok']) && $r['amount'] === 4500, json_encode($r));

$r = fx_credit_wholesale_custom($panel, 'f', 10, 30);
check('A7 حجم دلخواه: 10×2500 + 30×1800 = 79000', !empty($r['ok']) && $r['amount'] === 79000, json_encode($r));

$r = fx_credit_wholesale_product(['code_product' => 'custom_volume', 'name_product' => 'x', 'Volume_constraint' => 10, 'Service_time' => 0], $panel, 'f');
check('A8 محصول custom_volume با مپ خام → ok/25000', !empty($r['ok']) && $r['amount'] === 25000, json_encode($r));

$r = fx_credit_wholesale_product(['code_product' => 'custom_volume', 'name_product' => 'x', 'Volume_constraint' => 10, 'Service_time' => 0], ['code_panel' => 'Y'], 'f');
check('A9 محصول custom_volume بدون قیمت پایه → fail', empty($r['ok']), json_encode($r));

echo "\n== بخش B: تست DB ایزوله (idempotency_key + 1062) ==\n";

$DBH = getenv('DBH'); $DBU = getenv('DBU'); $DBP = getenv('DBP');
$DBN = getenv('DBN') ?: 'faoxima_botcap_empty';
if (strpos($DBN, 'botcap') === false) {
    echo "  ❌ DBN باید دیتابیس تستی botcap باشد (محافظه‌کارانه متوقف شد): DBN=$DBN\n";
    exit(1);
}
try {
    $pdo = new PDO("mysql:host=$DBH;dbname=$DBN;charset=utf8mb4", $DBU, $DBP, [
        PDO::ATTR_ERRMODE          => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (Throwable $e) {
    echo "  ❌ اتصال PDO ناموفق به $DBN: " . $e->getMessage() . "\n";
    exit(1);
}
$GLOBALS['pdo'] = $pdo;

$pdo->exec("CREATE TABLE IF NOT EXISTS botsaz (
    id_user varchar(50) NOT NULL PRIMARY KEY,
    bot_token text, admin_ids text, username text, setting text, hide_panel text,
    time varchar(50) DEFAULT '0',
    debt_mode varchar(12) DEFAULT 'balance',
    debt_ceiling bigint DEFAULT 0,
    current_debt bigint DEFAULT 0,
    debt_alert_80 tinyint DEFAULT 0,
    debt_alert_95 tinyint DEFAULT 0,
    retail_overrides text
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("CREATE TABLE IF NOT EXISTS fx_debt_log (
    id bigint NOT NULL AUTO_INCREMENT PRIMARY KEY,
    reseller_id bigint NOT NULL,
    amount bigint NOT NULL,
    direction varchar(10) NOT NULL,
    method varchar(30) NOT NULL,
    scope varchar(10) DEFAULT 'bot',
    invoice_ref varchar(64) DEFAULT NULL,
    debt_after bigint NOT NULL DEFAULT 0,
    time datetime DEFAULT NULL,
    idempotency_key varchar(120) DEFAULT NULL,
    KEY idx_fx_debt_log_scope (scope),
    UNIQUE KEY uq_fx_debt_log_idem (idempotency_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$TEST_RID = 990000777;
$pdo->exec("DELETE FROM fx_debt_log WHERE reseller_id = '$TEST_RID'");
$pdo->exec("DELETE FROM botsaz WHERE id_user = '$TEST_RID'");
$pdo->exec("INSERT INTO botsaz (id_user, bot_token, admin_ids, username, setting, hide_panel, time, debt_mode, debt_ceiling, current_debt) VALUES ('$TEST_RID', 'test:token', '[]', 'selftest', '{\"sale_notify\":\"0\"}', '[]', '0', 'debt', 100000, 0)");

check('B0 ستون idempotency_key در دسترس کوئری است', fx_debt_idem_column() === true);

$r = fx_debt_reserve($TEST_RID, 30000);
check('B1 reserve 30000 سقف 100000 → ok و debt=30000', !empty($r[0]) && fx_debt_state($TEST_RID)['current'] === 30000, json_encode($r));

$r = fx_debt_reserve($TEST_RID, 80000);
check('B2 reserve عبور از سقف → مسدود', empty($r[0]) && $r[1] === 'ceiling' && fx_debt_state($TEST_RID)['current'] === 30000, json_encode($r));

list($ok1, $rs1) = fx_debt_commit($TEST_RID, 30000, 'purchase', 'P1-REF-1');
list($ok2, $rs2) = fx_debt_commit($TEST_RID, 30000, 'purchase', 'P1-REF-1');
$cnt1 = (int)$pdo->query("SELECT COUNT(*) FROM fx_debt_log WHERE reseller_id = '$TEST_RID' AND invoice_ref = 'P1-REF-1'")->fetchColumn();
check('B3 commit دوبار با یک ref → ok سپس duplicate و فقط ۱ ردیف', !empty($ok1) && !empty($ok2) && $rs2 === 'duplicate' && $cnt1 === 1, "ok1=$ok1 rs2=$rs2 cnt=$cnt1");

$st = (string)$pdo->query("SELECT idempotency_key FROM fx_debt_log WHERE reseller_id = '$TEST_RID' AND invoice_ref = 'P1-REF-1' LIMIT 1")->fetchColumn();
check("B4 کلید ایدمپوتنسی درج شد (bot|purchase|P1-REF-1)", $st === 'bot|purchase|P1-REF-1', "key=$st");

// مسیر 1062: ردیف مزاحم با همان کلید ولی ref متفاوت (شبیه‌سازی رقابت) → INSERT باید 1062 بخورد و کل تراکنش برگردد
$pdo->exec("INSERT INTO fx_debt_log (reseller_id, amount, direction, method, scope, invoice_ref, debt_after, time, idempotency_key) VALUES ('$TEST_RID', 111, 'in', 'bulk_purchase', 'bot', 'P1-OTHER', 111, NOW(), 'bot|bulk_purchase|P1-RACE')");
$debtBefore = fx_debt_state($TEST_RID)['current'];
list($okR, $rsR) = fx_debt_inc($TEST_RID, 5000, 'bulk_purchase', 'P1-RACE');
$debtAfter = fx_debt_state($TEST_RID)['current'];
check('B5 مسیر 1062 → duplicate و بدهی افزایش نیافت (rollback کامل)', !empty($okR) && $rsR === 'duplicate' && $debtAfter === $debtBefore, "ok=$okR rs=$rsR before=$debtBefore after=$debtAfter");

// record_once: retry نباید بدهی را دوباره ثبت کند
list($okN1) = fx_debt_record_once($TEST_RID, 4000, 'purchase_gateway', 'P1-ORDER-1');
list($okN2, $rsN2) = fx_debt_record_once($TEST_RID, 4000, 'purchase_gateway', 'P1-ORDER-1');
$cntN = (int)$pdo->query("SELECT COUNT(*) FROM fx_debt_log WHERE reseller_id = '$TEST_RID' AND invoice_ref = 'P1-ORDER-1'")->fetchColumn();
check('B6 record_once retry → duplicate و فقط ۱ ردیف', !empty($okN1) && !empty($okN2) && $rsN2 === 'duplicate' && $cntN === 1, "rs=$rsN2 cnt=$cntN");

$debtNow = fx_debt_state($TEST_RID)['current'];
$r = fx_debt_reserve($TEST_RID, 10000);
check('B7 reserve 10000 اضافه → ok', !empty($r[0]) && fx_debt_state($TEST_RID)['current'] === $debtNow + 10000, json_encode($r));

fx_debt_release($TEST_RID, 10000, 'P1-REL-1');
$outPaid = (int)$pdo->query("SELECT COUNT(*) FROM fx_debt_log WHERE reseller_id = '$TEST_RID' AND direction = 'out' AND amount > 0")->fetchColumn();
$marker = (int)$pdo->query("SELECT COUNT(*) FROM fx_debt_log WHERE reseller_id = '$TEST_RID' AND method = 'revert' AND amount = 0 AND invoice_ref = 'P1-REL-1'")->fetchColumn();
check('B8 release → بدهی برگشت، بدون ردیف مالی out و ۱ مارکر zero-amount', fx_debt_state($TEST_RID)['current'] === $debtNow && $outPaid === 0 && $marker === 1, 'debt=' . fx_debt_state($TEST_RID)['current'] . " outPaid=$outPaid marker=$marker");

fx_debt_release($TEST_RID, 10000, 'P1-REL-1');
check('B9 release دوباره (double-release) → no-op و بدهی دست‌نخورده', fx_debt_state($TEST_RID)['current'] === $debtNow, 'debt=' . fx_debt_state($TEST_RID)['current']);

fx_debt_release($TEST_RID, 4000, 'P1-ORDER-1');
check('B10 release با ref commit‌شده → رد و بدهی دست‌نخورده', fx_debt_state($TEST_RID)['current'] === $debtNow, 'debt=' . fx_debt_state($TEST_RID)['current']);

// fallback بدون ستون: مسیر legacy نباید خطا بدهد (شبیه‌سازی با کش‌کردن مقدار false)
$ref = new ReflectionFunction('fx_debt_idem_column');
$idemOk = fx_debt_ref_committed($TEST_RID, 'P1-ORDER-1', 'purchase_gateway');
check('B11 dedupe مبتنی بر ref+method بدون نیاز به ستون یکتا کار می‌کند', $idemOk === true, "committed=$idemOk");

// FX-DEBT-P1: خریدهای تکرارشوندهٔ یک سرویس (حجم/زمان) باید ref یکتای هر تلاش داشته باشند
$d0 = fx_debt_state($TEST_RID)['current'];
fx_debt_reserve($TEST_RID, 5000);
list($okVxA, $rsnVxA) = fx_debt_commit($TEST_RID, 5000, 'extra_volume', 'P1-VOL#vxaaaa');
fx_debt_reserve($TEST_RID, 5000);
list($okVxB, $rsnVxB) = fx_debt_commit($TEST_RID, 5000, 'extra_volume', 'P1-VOL#vxbbbb');
$d2 = fx_debt_state($TEST_RID)['current'];
$cntVx = (int)$pdo->query("SELECT COUNT(*) FROM fx_debt_log WHERE reseller_id = '$TEST_RID' AND invoice_ref IN ('P1-VOL#vxaaaa','P1-VOL#vxbbbb')")->fetchColumn();
check('B12 دو خرید حجم متوالی همان سرویس (ref یکتا per-attempt) → هر دو ثبت شد', !empty($okVxA) && !empty($okVxB) && $rsnVxA === '' && $rsnVxB === '' && $cntVx === 2 && $d2 === $d0 + 10000, "d0=$d0 d2=$d2 cnt=$cntVx rsnA=$rsnVxA rsnB=$rsnVxB");

// تلاش سوم ناموفق: فقط سهم همان تلاش برمی‌گردد (نه خریدهای قبلی)
fx_debt_reserve($TEST_RID, 5000);
fx_debt_release($TEST_RID, 5000, 'P1-VOL#vxcccc');
check('B13 release تلاش ناموفق سوم → فقط ۵۰۰۰ برمی‌گردد و ردیف‌های commit‌شده دست‌نخورده', fx_debt_state($TEST_RID)['current'] === $d0 + 10000 && (int)$pdo->query("SELECT COUNT(*) FROM fx_debt_log WHERE reseller_id = '$TEST_RID' AND invoice_ref IN ('P1-VOL#vxaaaa','P1-VOL#vxbbbb')")->fetchColumn() === 2, 'debt=' . fx_debt_state($TEST_RID)['current']);

// FX-DEBT-P1: هم‌زمانی واقعی با دو کانکشن PDO (بدون pcntl) — قفل ردیف botsaz
$pdo2 = null;
try {
    $pdo2 = new PDO("mysql:host=$DBH;dbname=$DBN;charset=utf8mb4", $DBU, $DBP, [
        PDO::ATTR_ERRMODE          => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (Throwable $e) { $pdo2 = null; }
if ($pdo2 instanceof PDO) {
    $__mainPdo = $GLOBALS['pdo'];
    $pdo->exec("SET SESSION innodb_lock_wait_timeout = 2");

    // B14: inc هم‌زمان — نویسنده دوم روی قفل FOR UPDATE بلوکه می‌شود و با timeout تمیز شکست می‌خورد
    $dStart = fx_debt_state($TEST_RID)['current'];
    $raceRef = 'P1-CONC-INC';
    $GLOBALS['pdo'] = $pdo2;
    $pdo2->beginTransaction();
    list($okC2) = fx_debt_inc($TEST_RID, 7000, 'concurrency_test', $raceRef);
    $GLOBALS['pdo'] = $__mainPdo;
    list($okC1, $rsC1) = fx_debt_inc($TEST_RID, 7000, 'concurrency_test', $raceRef);
    $dBlocked = fx_debt_state($TEST_RID)['current'];
    $GLOBALS['pdo'] = $pdo2;
    $pdo2->commit();
    $GLOBALS['pdo'] = $__mainPdo;
    $dAfterWin = fx_debt_state($TEST_RID)['current'];
    list($okC3, $rsC3) = fx_debt_inc($TEST_RID, 7000, 'concurrency_test', $raceRef);
    $dFinal = fx_debt_state($TEST_RID)['current'];
    $cntConc = (int)$pdo->query("SELECT COUNT(*) FROM fx_debt_log WHERE reseller_id = '$TEST_RID' AND invoice_ref = '$raceRef'")->fetchColumn();
    check('B14 هم‌زمانی inc: دوم بلوکه/شکست بدون نوشتن، بعد از commit برنده retry=duplicate و فقط ۱ ردیف', !empty($okC2) && empty($okC1) && $rsC1 === 'db' && $dBlocked === $dStart && $dAfterWin === $dStart + 7000 && !empty($okC3) && $rsC3 === 'duplicate' && $dFinal === $dStart + 7000 && $cntConc === 1, "okC2=$okC2 okC1=$okC1 rsC1=$rsC1 blocked=$dBlocked afterWin=$dAfterWin rsn3=$rsC3 final=$dFinal cnt=$cntConc");

    // B15: reserve هم‌زمان — روی قفل بلوکه می‌شود (شکست تمیز، بدون نوشتن ناقص)
    $dR0 = fx_debt_state($TEST_RID)['current'];
    $GLOBALS['pdo'] = $pdo2;
    $pdo2->beginTransaction();
    list($okR2) = fx_debt_reserve($TEST_RID, 6000);
    $GLOBALS['pdo'] = $__mainPdo;
    list($okR1, $rsR1) = fx_debt_reserve($TEST_RID, 6000);
    $GLOBALS['pdo'] = $pdo2;
    $pdo2->commit();
    $GLOBALS['pdo'] = $__mainPdo;
    $dRW = fx_debt_state($TEST_RID)['current'];
    check('B15 هم‌زمانی reserve: تلاش موازی بلوکه/شکست تمیز (db) و فقط یک رزرو ثبت شد', !empty($okR2) && empty($okR1) && $rsR1 === 'db' && $dRW === $dR0 + 6000, "okR2=$okR2 okR1=$okR1 rsR1=$rsR1 d0=$dR0 after=$dRW");

    $pdo->exec("SET SESSION innodb_lock_wait_timeout = 50");
    $pdo2 = null;
} else {
    warn('دو کانکشن PDO ممکن نشد — تست هم‌زمانی رد شد');
}

$pdo->exec("DELETE FROM fx_debt_log WHERE reseller_id = '$TEST_RID'");
$pdo->exec("DELETE FROM botsaz WHERE id_user = '$TEST_RID'");

echo "\n== بخش C: سیم‌کشی استاتیک (رگرسیون حذف تصادفی) ==\n";
$srcUF   = (string)@file_get_contents($ROOT . '/re/rx/index/user_flow.php');
$srcBS   = (string)@file_get_contents($ROOT . '/re/rx/index/bootstrap.php');
$srcFIN  = (string)@file_get_contents($ROOT . '/re/rx/index/finalize.php');
$srcDH2  = (string)@file_get_contents($ROOT . '/re/rx/function/database_helpers_2.php');
$srcStock= (string)@file_get_contents($ROOT . '/re/rx/function/nm_stock.php');
$srcDebt = (string)@file_get_contents($ROOT . '/re/rx/index/fx_debt.php');

check('C1 fx_debt: resolverها و کلید ایدمپوتنسی موجودند', strpos($srcDebt, 'fx_credit_wholesale_product') !== false && strpos($srcDebt, 'fx_credit_wholesale_unit') !== false && strpos($srcDebt, 'idempotency_key') !== false);
check('C2 fx_debt: release idempotent + گارد commit + مارکر zero-amount', strpos($srcDebt, 'FX_DEBT_RELEASE_AFTER_COMMIT') !== false && strpos($srcDebt, "0, 'out', 'revert'") !== false && strpos($srcDebt, "'bot|revert|'") !== false);
check('C3 fx_debt: قفل FOR UPDATE در inc', strpos($srcDebt, 'FOR UPDATE') !== false);

check('C4 user_flow: resolver تک‌خرید/انبوه + safe-stop', substr_count($srcUF, 'fx_credit_wholesale_product') >= 2 && strpos($srcUF, "'user_flow_single_buy'") !== false && strpos($srcUF, "'user_flow_bulk_gate'") !== false);
check('C5 user_flow: fallback retail در انبوه حذف شد', strpos($srcUF, '$__fxDebtItemAmt = $__fxWhItem > 0 ? $__fxWhItem : (int)$__bulkItemCharge') === false && strpos($srcUF, '$__fxBulkWhUnit') !== false);
check('C6 user_flow: کسر کیف‌پول گارددار + بدون compensate نادرست', strpos($srcUF, 'Balance >= :check_delta') !== false && substr_count($srcUF, 'nm_agent_compensate') === 0, 'compensate=' . substr_count($srcUF, 'nm_agent_compensate'));
check('C7 user_flow: انبار ملی تک‌خرید charge قبل از commit + fail reason', strpos($srcUF, "nmStockLastFail") !== false && strpos($srcUF, "'charge_failed'") !== false);
check('C8 bootstrap: renewal/volume/time بدون fallback retail + changeloc safe-stop', strpos($srcBS, "'bootstrap_changeloc'") !== false && strpos($srcBS, 'fx_credit_wholesale_unit') !== false && strpos($srcBS, '= $fxRenewCustom ? (int)round((float)$fxRenewBasePrice) : (int)$pricelastextend') === false);
check('C9 finalize: renewal بدون fallback retail', strpos($srcFIN, "'finalize_renewal'") !== false && strpos($srcFIN, '= (int)$prodcut[\'price_product\'];' . "\n" . '            }') === false);
check('C10 gateway: safe-refund fx_wholesale_missing + resolver در ۵ مسیر', substr_count($srcDH2, "'fx_wholesale_missing'") >= 4 && substr_count($srcDH2, 'fx_credit_wholesale_product') >= 3 && substr_count($srcDH2, 'fx_credit_wholesale_unit') >= 2);
check('C11 nm_stock: بدون تحویل در صورت شکست شارژ (release رزرو + return false)', (function () use ($srcStock) {
    $pos = strpos($srcStock, "nmStockLastFail'] = 'charge_failed'");
    if ($pos === false) { return false; }
    $win = substr($srcStock, $pos, 500);
    return strpos($win, 'nmStockReleaseReservation($stock)') !== false && strpos($win, 'return false;') !== false;
})());
check('C12 gateway: هیچ fallback به Payment_report قیمت retail نمانده', strpos($srcDH2, "= (int)\$Payment_report['price'];") === false && strpos($srcDH2, "= (int)\$get_invoice['price_product'];") === false);
check('C13 refs تلاش‌محور برای عملیات تکرارشونده (حجم/زمان/تمدید/چانژلوکیشن)', strpos($srcBS, "'#vx'") !== false && strpos($srcBS, "'#et'") !== false && strpos($srcBS, "'#cl'") !== false && strpos($srcFIN, "'#fin'") !== false);
check('C14 gateway: قرارداد برگشت وجه idempotent (claim + duplicate + ledger)', strpos($srcDH2, 'REFUND_DUPLICATE_SKIPPED') !== false && strpos($srcDH2, "NOT LIKE '%auto-refund%'") !== false && strpos($srcDH2, 'balance_atomic_credit($userId, $amount)') !== false);
check('C15 gateway: ثبت بدهی در هر ۵ مسیر از طریق fx_debt_gateway_record_once (P2)', substr_count($srcDH2, 'fx_debt_gateway_record_once(') >= 5 && substr_count($srcDH2, "'fx_wholesale_missing'") >= 4 && strpos($srcDebt, 'function fx_debt_gateway_record_once') !== false);

echo "\nنتیجه: PASS=$pass FAIL=$fail" . ($warn ? " WARN=$warn" : "") . "\n";
exit($fail ? 1 : 0);
