<?php
/**
 * fx_wallet_first_selftest.php — تست‌های WALLET-FIRST (2026-10-17)
 *
 * اجرا (فقط دیتابیس تستی botcap؛ اسکریپت خودش محافظت می‌کند):
 *   docker exec -e DBH=db -e DBU=faoxima -e DBP=<test-db-pass> -e DBN=faoxima_botcap_empty \
 *     faoxima-app-1 php /var/www/faoxima/scripts/fx_wallet_first_selftest.php
 *
 * پوشش (سناریوهای اجباری):
 *   W1  قبل از مهاجرت: همه مسیرها دقیقاً legacy (کیف‌پول دست‌نخورده)
 *   W2  اعتبار > مبلغ: رزرو/کامیت تمام‌کیف‌پول (ردیف bot صفر + ردیف کیف‌پول)
 *   W3  اعتبار < مبلغ: اسپلیت (کیف‌پول + بدهی)، کامیت دو ردیف
 *   W4  release بعد از commit → رد
 *   W5  release اسپلیت: بازگشت کیف‌پول + بدهی؛ release دوباره no-op
 *   W6  اعتبار = مبلغ (مرز)
 *   W7  سقف دقیقاً مرزی (dp = remaining) + قفل سقف فقط روی سهم بدهی
 *   W8  عبور از سقف با کیف‌پول جزئی → ceiling، بدون کسر کیف‌پول
 *   W9  عبور از سقف با کیف‌پول صفر
 *   W10 کیف‌پول خالص وقتی بدهی روی سقف است → مجاز (dp=0)، gate هم ok
 *   W11 کالبک تکراری درگاه → یک‌بار کسر کیف‌پول
 *   W12 درگاه: ceiling_exceeded سپس retry بعد از افزایش سقف
 *   W13 هم‌زمانی دو کانکشن نزدیک سقف → بدون overshoot و بدون کسر مضاعف
 *   W14 release سپس رزرو مجدد با ref جدید
 *   W15 جداسازی چند نمایندگی
 *   W16 حالت balance دست‌نخورده
 *   W17 مبلغ صفر/منفی → no-op
 *   W18 کلید خاموشی wallet_first='0' → legacy
 *   W19 صورتحساب: سهم کیف‌پول در جمع فروش + خط توضیح
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

$RUN = 'WFS-' . bin2hex(random_bytes(4));
echo "RUN ID: $RUN\n";

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

$RID  = '7099101';
$RID2 = '7099102';

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
$pdo->exec("DROP TABLE IF EXISTS fx_debt_reservations");
$pdo->exec("CREATE TABLE fx_debt_reservations (
    ref varchar(191) NOT NULL,
    reseller_id bigint NOT NULL,
    amount bigint NOT NULL DEFAULT 0,
    state enum('reserved','committed','released') NOT NULL DEFAULT 'reserved',
    created_at datetime NOT NULL,
    updated_at datetime NOT NULL,
    PRIMARY KEY (ref),
    KEY idx_fx_debt_res (reseller_id, state, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("CREATE TABLE IF NOT EXISTS user (
    id bigint NOT NULL PRIMARY KEY,
    Balance bigint NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

register_shutdown_function(function () {
    global $pdo, $RID, $RID2;
    try {
        if ($pdo instanceof PDO) {
            foreach ([$RID, $RID2] as $r) {
                $pdo->exec("DELETE FROM user WHERE id = '$r'");
                $pdo->exec("DELETE FROM fx_debt_reservations WHERE reseller_id = '$r'");
                $pdo->exec("DELETE FROM fx_debt_log WHERE reseller_id = '$r'");
                $pdo->exec("DELETE FROM botsaz WHERE id_user = '$r'");
            }
        }
    } catch (Throwable $e) {
    }
});

function wfs_bal(PDO $pdo, string $rid): int { return (int)$pdo->query("SELECT Balance FROM user WHERE id = '$rid'")->fetchColumn(); }
function wfs_set_bal(PDO $pdo, string $rid, int $v): void {
    $pdo->exec("DELETE FROM user WHERE id = '$rid'");
    $pdo->prepare("INSERT INTO user (id, Balance) VALUES (?, ?)")->execute([$rid, $v]);
}
function wfs_set_debt(PDO $pdo, string $rid, int $cur, int $ceil): void {
    $pdo->exec("INSERT INTO botsaz (id_user, debt_mode, debt_ceiling, current_debt) VALUES ('$rid','debt',$ceil,$cur) ON DUPLICATE KEY UPDATE debt_mode='debt', debt_ceiling=$ceil, current_debt=$cur");
}
function wfs_setting(PDO $pdo, string $rid, array $patch): void {
    $cur = [];
    $raw = (string)$pdo->query("SELECT setting FROM botsaz WHERE id_user = '$rid'")->fetchColumn();
    $j = json_decode($raw, true);
    if (is_array($j)) { $cur = $j; }
    foreach ($patch as $k => $v) {
        if ($v === null) { unset($cur[$k]); } else { $cur[$k] = $v; }
    }
    $pdo->prepare("UPDATE botsaz SET setting = ? WHERE id_user = ?")->execute([json_encode($cur), $rid]);
}
function wfs_ref(string $run, string $tag): string { return $run . '-' . $tag; }
function wfs_res_wp(PDO $pdo, string $ref): int { return (int)$pdo->query("SELECT wallet_part FROM fx_debt_reservations WHERE ref = '$ref'")->fetchColumn(); }
function wfs_log_rows(PDO $pdo, string $rid, string $ref): array {
    return $pdo->query("SELECT direction, method, scope, amount FROM fx_debt_log WHERE reseller_id = '$rid' AND invoice_ref = '$ref' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
}

// ===================== W1: قبل از مهاجرت (legacy خالص) =====================
echo "\n== W1: قبل از مهاجرت — همه مسیرها legacy و کیف‌پول دست‌نخورده ==\n";
$hasCol = (bool)$pdo->query("SHOW COLUMNS FROM fx_debt_reservations LIKE 'wallet_part'")->fetch(PDO::FETCH_ASSOC);
if ($hasCol) { $pdo->exec("ALTER TABLE fx_debt_reservations DROP COLUMN wallet_part"); }
check('W1.1 fx_debt_wallet_column قبل از مهاجرت = false', fx_debt_wallet_column() === false);
wfs_set_bal($pdo, $RID, 100000);
wfs_set_debt($pdo, $RID, 0, 1000000);
check('W1.2 fx_debt_wallet_first قبل از مهاجرت = false (کلید خاموشی بی‌اثر)', fx_debt_wallet_first((int)$RID) === false);

$refW1a = wfs_ref($RUN, 'W1A');
list($okW1a, $rsnW1a, $debtW1a) = fx_debt_reserve((int)$RID, 30000, $refW1a);
check('W1.3 رزرو legacy: بدهی +30000 و کیف‌پول دست‌نخورده', !empty($okW1a) && $rsnW1a === '' && $debtW1a === 30000 && wfs_bal($pdo, $RID) === 100000, "ok=$okW1a rsn=$rsnW1a debt=$debtW1a bal=" . wfs_bal($pdo, $RID));
list($okW1b, $rsnW1b) = fx_debt_commit((int)$RID, 30000, 'purchase', $refW1a);
$rowsW1 = wfs_log_rows($pdo, $RID, $refW1a);
check('W1.4 کامیت legacy: فقط ۱ ردیف bot با مبلغ کامل، بدون ردیف کیف‌پول', !empty($okW1b) && $rsnW1b === '' && count($rowsW1) === 1 && (int)$rowsW1[0]['amount'] === 30000 && $rowsW1[0]['scope'] === 'bot', json_encode($rowsW1, JSON_UNESCAPED_UNICODE));

$refW1c = wfs_ref($RUN, 'W1C');
fx_debt_reserve((int)$RID, 20000, $refW1c);
fx_debt_release((int)$RID, 20000, $refW1c);
$revW1 = (int)$pdo->query("SELECT COUNT(*) FROM fx_debt_log WHERE reseller_id = '$RID' AND invoice_ref = '$refW1c' AND method = 'revert'")->fetchColumn();
check('W1.5 release legacy: بدهی برگشت، کیف‌پول دست‌نخورده، ۱ مارکر revert', fx_debt_state((int)$RID)['current'] === 30000 && wfs_bal($pdo, $RID) === 100000 && $revW1 === 1, 'debt=' . fx_debt_state((int)$RID)['current'] . ' bal=' . wfs_bal($pdo, $RID) . ' rev=' . $revW1);

$refW1d = wfs_ref($RUN, 'W1D');
fx_debt_reserve((int)$RID, 7000, $refW1d);
$pdo->exec("UPDATE fx_debt_reservations SET created_at = NOW() - INTERVAL 10 MINUTE WHERE ref = '$refW1d'");
$pendW1 = fx_debt_reservations_pending(5);
$foundW1 = false;
foreach ($pendW1 as $p) { if (($p['ref'] ?? '') === $refW1d) { $foundW1 = true; } }
fx_debt_release((int)$RID, 7000, $refW1d);
check('W1.6 گزارش orphan قبل از مهاجرت کار می‌کند (بدون ستون wallet_part)', $foundW1 === true && fx_debt_reservation_state($refW1d, (int)$RID) === 'released');

$pdo->exec("DELETE FROM fx_debt_log WHERE reseller_id = '$RID'");
$pdo->exec("DELETE FROM fx_debt_reservations WHERE reseller_id = '$RID'");
wfs_set_debt($pdo, $RID, 0, 1000000);

// مهاجرت تستی روی دیتابیس تستی (فقط botcap)
$pdo->exec("ALTER TABLE fx_debt_reservations ADD COLUMN wallet_part bigint NOT NULL DEFAULT 0 AFTER amount");
check('W1.7 بعد از افزودن ستون: wallet_column=true و wallet_first=true (کش فقط true)', fx_debt_wallet_column() === true && fx_debt_wallet_first((int)$RID) === true);

// ===================== W2: اعتبار > مبلغ (تمام‌کیف‌پول) =====================
echo "\n== W2: اعتبار > مبلغ — تمام‌کیف‌پول (dp=0) ==\n";
wfs_set_bal($pdo, $RID, 100000);
wfs_set_debt($pdo, $RID, 0, 1000000);
$refW2 = wfs_ref($RUN, 'W2');
list($okW2, $rsnW2, $debtW2) = fx_debt_reserve((int)$RID, 30000, $refW2);
check('W2.1 رزرو: wp=30000، بدهی 0، مانده 70000، ردیف رزرو wallet_part=30000', !empty($okW2) && $rsnW2 === '' && $debtW2 === 0 && wfs_bal($pdo, $RID) === 70000 && wfs_res_wp($pdo, $refW2) === 30000, "ok=$okW2 debt=$debtW2 bal=" . wfs_bal($pdo, $RID) . ' wp=' . wfs_res_wp($pdo, $refW2));
check('W2.2 retry همان ref در حالت reserved → duplicate بدون کسر دوباره', (function () use ($RID, $refW2, $pdo) { $r = fx_debt_reserve((int)$RID, 30000, $refW2); return !empty($r[0]) && $r[1] === 'duplicate' && wfs_bal($pdo, $RID) === 70000; })());

list($okW2c, $rsnW2c) = fx_debt_commit((int)$RID, 30000, 'purchase', $refW2);
$rowsW2 = wfs_log_rows($pdo, $RID, $refW2);
$botW2 = null; $walW2 = null;
foreach ($rowsW2 as $r) { if ($r['scope'] === 'bot') { $botW2 = $r; } if ($r['scope'] === 'main' && $r['method'] === 'wallet_purchase') { $walW2 = $r; } }
check('W2.3 کامیت: ردیف bot با مبلغ 0 + ردیف کیف‌پول 30000 (main/in)، state=committed', !empty($okW2c) && $rsnW2c === '' && $botW2 !== null && (int)$botW2['amount'] === 0 && $walW2 !== null && (int)$walW2['amount'] === 30000 && $walW2['direction'] === 'in' && fx_debt_reservation_state($refW2, (int)$RID) === 'committed', json_encode($rowsW2, JSON_UNESCAPED_UNICODE) . ' state=' . fx_debt_reservation_state($refW2, (int)$RID));
$balW2 = wfs_bal($pdo, $RID);
list($okW2d, $rsnW2d) = fx_debt_commit((int)$RID, 30000, 'purchase', $refW2);
$cntW2 = count(wfs_log_rows($pdo, $RID, $refW2));
check('W2.4 کامیت تکراری → duplicate؛ بدون ردیف دوم و بدون کسر دوباره کیف‌پول', !empty($okW2d) && $rsnW2d === 'duplicate' && $cntW2 === 2 && wfs_bal($pdo, $RID) === $balW2, "rsn=$rsnW2d cnt=$cntW2 bal=" . wfs_bal($pdo, $RID));

// ===================== W3: اعتبار < مبلغ (اسپلیت) =====================
echo "\n== W3: اعتبار < مبلغ — اسپلیت کیف‌پول + بدهی ==\n";
wfs_set_bal($pdo, $RID, 20000);
wfs_set_debt($pdo, $RID, 0, 1000000);
$refW3 = wfs_ref($RUN, 'W3');
list($okW3, $rsnW3, $debtW3) = fx_debt_reserve((int)$RID, 50000, $refW3);
check('W3.1 رزرو اسپلیت: wp=20000، بدهی +30000، مانده 0', !empty($okW3) && $rsnW3 === '' && $debtW3 === 30000 && wfs_bal($pdo, $RID) === 0 && wfs_res_wp($pdo, $refW3) === 20000, "ok=$okW3 debt=$debtW3 bal=" . wfs_bal($pdo, $RID) . ' wp=' . wfs_res_wp($pdo, $refW3));
list($okW3c) = fx_debt_commit((int)$RID, 50000, 'extra_volume', $refW3);
$rowsW3 = wfs_log_rows($pdo, $RID, $refW3);
$botW3 = 0; $walW3 = 0;
foreach ($rowsW3 as $r) { if ($r['scope'] === 'bot') { $botW3 = (int)$r['amount']; } if ($r['method'] === 'wallet_purchase') { $walW3 = (int)$r['amount']; } }
check('W3.2 کامیت اسپلیت: ردیف bot=30000 + ردیف کیف‌پول=20000', !empty($okW3c) && $botW3 === 30000 && $walW3 === 20000 && count($rowsW3) === 2, json_encode($rowsW3, JSON_UNESCAPED_UNICODE));

// W4: release بعد از commit → رد
$revBeforeW4 = (int)$pdo->query("SELECT COUNT(*) FROM fx_debt_log WHERE reseller_id = '$RID' AND invoice_ref = '$refW3' AND method = 'revert'")->fetchColumn();
fx_debt_release((int)$RID, 50000, $refW3);
$revAfterW4 = (int)$pdo->query("SELECT COUNT(*) FROM fx_debt_log WHERE reseller_id = '$RID' AND invoice_ref = '$refW3' AND method = 'revert'")->fetchColumn();
check('W4 release بعد از commit → رد؛ بدهی و state دست‌نخورده، بدون مارکر revert', fx_debt_state((int)$RID)['current'] === 30000 && fx_debt_reservation_state($refW3, (int)$RID) === 'committed' && $revAfterW4 === 0 && $revBeforeW4 === 0, 'debt=' . fx_debt_state((int)$RID)['current'] . ' rev=' . $revAfterW4);

// ===================== W5: release اسپلیت + تکرار =====================
echo "\n== W5: release اسپلیت (بازگشت کیف‌پول + کاهش بدهی) و release دوباره no-op ==\n";
wfs_set_bal($pdo, $RID, 5000);
wfs_set_debt($pdo, $RID, 0, 1000000);
$refW5 = wfs_ref($RUN, 'W5');
list($okW5) = fx_debt_reserve((int)$RID, 20000, $refW5);
$preW5 = [wfs_bal($pdo, $RID), fx_debt_state((int)$RID)['current'], wfs_res_wp($pdo, $refW5)];
fx_debt_release((int)$RID, 20000, $refW5);
$revW5 = (int)$pdo->query("SELECT COUNT(*) FROM fx_debt_log WHERE reseller_id = '$RID' AND invoice_ref = '$refW5' AND method = 'revert'")->fetchColumn();
check('W5.1 release: کیف‌پول 5000 برگشت، بدهی -15000، state=released، ۱ مارکر', !empty($okW5) && $preW5 === [0, 15000, 5000] && wfs_bal($pdo, $RID) === 5000 && fx_debt_state((int)$RID)['current'] === 0 && fx_debt_reservation_state($refW5, (int)$RID) === 'released' && $revW5 === 1, json_encode($preW5) . ' bal=' . wfs_bal($pdo, $RID) . ' debt=' . fx_debt_state((int)$RID)['current'] . ' rev=' . $revW5);
fx_debt_release((int)$RID, 20000, $refW5);
$revW5b = (int)$pdo->query("SELECT COUNT(*) FROM fx_debt_log WHERE reseller_id = '$RID' AND invoice_ref = '$refW5' AND method = 'revert'")->fetchColumn();
check('W5.2 release دوباره → no-op (مارکر ۱، بدهی/کیف‌پول بدون تغییر)', $revW5b === 1 && wfs_bal($pdo, $RID) === 5000 && fx_debt_state((int)$RID)['current'] === 0);

// ===================== W6: اعتبار = مبلغ =====================
echo "\n== W6: اعتبار = مبلغ (مرز) ==\n";
wfs_set_bal($pdo, $RID, 40000);
wfs_set_debt($pdo, $RID, 0, 1000000);
$refW6 = wfs_ref($RUN, 'W6');
list($okW6, $rsnW6, $debtW6) = fx_debt_reserve((int)$RID, 40000, $refW6);
list($okW6c) = fx_debt_commit((int)$RID, 40000, 'renewal', $refW6);
$rowsW6 = wfs_log_rows($pdo, $RID, $refW6);
$walW6 = 0;
foreach ($rowsW6 as $r) { if ($r['method'] === 'wallet_purchase') { $walW6 = (int)$r['amount']; } }
check('W6 اعتبار=مبلغ: wp=40000 dp=0، مانده 0، ردیف کیف‌پول 40000، بدهی 0', !empty($okW6) && $rsnW6 === '' && $debtW6 === 0 && wfs_bal($pdo, $RID) === 0 && !empty($okW6c) && $walW6 === 40000 && fx_debt_state((int)$RID)['current'] === 0, "ok=$okW6 debt=$debtW6 bal=" . wfs_bal($pdo, $RID) . " wal=$walW6");

// ===================== W7: مرز دقیق سقف =====================
echo "\n== W7: مرز دقیق سقف (فقط سهم بدهی) ==\n";
wfs_set_bal($pdo, $RID, 30000);
wfs_set_debt($pdo, $RID, 50000, 100000);
$refW7 = wfs_ref($RUN, 'W7');
list($okW7, $rsnW7, $debtW7) = fx_debt_reserve((int)$RID, 80000, $refW7);
check('W7.1 current=50000 + dp=50000 = سقف 100000 → مجاز (برابر مرز)', !empty($okW7) && $rsnW7 === '' && $debtW7 === 100000 && wfs_bal($pdo, $RID) === 0, "ok=$okW7 rsn=$rsnW7 debt=$debtW7");
fx_debt_release((int)$RID, 80000, $refW7);
check('W7.2 release: بدهی برگشت به 50000 و کیف‌پول به 30000', fx_debt_state((int)$RID)['current'] === 50000 && wfs_bal($pdo, $RID) === 30000, 'debt=' . fx_debt_state((int)$RID)['current'] . ' bal=' . wfs_bal($pdo, $RID));

// ===================== W8: عبور از سقف با کیف‌پول جزئی =====================
echo "\n== W8: عبور از سقف (کیف‌پول جزئی) — بدون کسر کیف‌پول ==\n";
wfs_set_bal($pdo, $RID, 30000);
wfs_set_debt($pdo, $RID, 80000, 100000);
$refW8 = wfs_ref($RUN, 'W8');
list($okW8, $rsnW8, $debtW8) = fx_debt_reserve((int)$RID, 60000, $refW8);
$stateW8 = fx_debt_reservation_state($refW8, (int)$RID);
check('W8 ceiling (wp=30000، dp=30000 > باقی‌مانده 20000): بدون کسر کیف‌پول، بدون ردیف رزرو', empty($okW8) && $rsnW8 === 'ceiling' && $debtW8 === 80000 && wfs_bal($pdo, $RID) === 30000 && $stateW8 === '', "ok=$okW8 rsn=$rsnW8 bal=" . wfs_bal($pdo, $RID) . " state=$stateW8");

// ===================== W9: عبور از سقف با کیف‌پول صفر =====================
wfs_set_bal($pdo, $RID, 0);
wfs_set_debt($pdo, $RID, 80000, 100000);
$refW9 = wfs_ref($RUN, 'W9');
list($okW9, $rsnW9) = fx_debt_reserve((int)$RID, 30000, $refW9);
check('W9 ceiling با کیف‌پول صفر: رد، بدون هیچ تغییر', empty($okW9) && $rsnW9 === 'ceiling' && fx_debt_state((int)$RID)['current'] === 80000 && wfs_bal($pdo, $RID) === 0 && fx_debt_reservation_state($refW9, (int)$RID) === '');

// ===================== W10: کیف‌پول خالص روی سقف پُر =====================
echo "\n== W10: کیف‌پول خالص وقتی بدهی = سقف → مجاز (dp=0) ==\n";
wfs_set_bal($pdo, $RID, 50000);
wfs_set_debt($pdo, $RID, 100000, 100000);
$refW10 = wfs_ref($RUN, 'W10');
list($gateOkW10, $gateMsgW10) = fx_debt_gate((int)$RID, 50000);
list($okW10, $rsnW10, $debtW10) = fx_debt_reserve((int)$RID, 50000, $refW10);
check('W10.1 gate با dp=0 → ok بدون پیام', !empty($gateOkW10) && $gateMsgW10 === '');
check('W10.2 رزرو کیف‌پول خالص روی سقف پُر → مجاز، بدهی 100000 دست‌نخورده، مانده 0', !empty($okW10) && $rsnW10 === '' && $debtW10 === 100000 && wfs_bal($pdo, $RID) === 0 && wfs_res_wp($pdo, $refW10) === 50000, "ok=$okW10 rsn=$rsnW10 debt=$debtW10 bal=" . wfs_bal($pdo, $RID));
fx_debt_release((int)$RID, 50000, $refW10);
check('W10.3 release: مانده 50000، بدهی همان 100000 (dp=0 چیزی برنگرداند)', wfs_bal($pdo, $RID) === 50000 && fx_debt_state((int)$RID)['current'] === 100000, 'bal=' . wfs_bal($pdo, $RID) . ' debt=' . fx_debt_state((int)$RID)['current']);

// اطلاع‌رسانی/گزارش: ستون wallet_part در orphan report هم دیده می‌شود
wfs_set_debt($pdo, $RID, 0, 1000000);
$refW10o = wfs_ref($RUN, 'W10O');
fx_debt_reserve((int)$RID, 10000, $refW10o);
$pdo->exec("UPDATE fx_debt_reservations SET created_at = NOW() - INTERVAL 10 MINUTE WHERE ref = '$refW10o'");
$pendW10 = fx_debt_reservations_pending(5);
$wpPend = -1;
foreach ($pendW10 as $p) { if (($p['ref'] ?? '') === $refW10o) { $wpPend = (int)($p['wallet_part'] ?? -1); } }
fx_debt_release((int)$RID, 10000, $refW10o);
check('W10.4 orphan report ستون wallet_part را نشان می‌دهد', wfs_res_wp($pdo, $refW10o) === 10000 && $wpPend !== -1 ? $wpPend === 0 || $wpPend === 10000 : false, 'wpPend=' . $wpPend);

// ===================== W11: کالبک تکراری درگاه =====================
echo "\n== W11: درگاه — کالبک تکراری (یک‌بار کسر کیف‌پول) ==\n";
if (!defined('FX_RESELLER_ID')) { define('FX_RESELLER_ID', (int)$RID); }
wfs_set_bal($pdo, $RID, 60000);
wfs_set_debt($pdo, $RID, 0, 1000000);
$prodW = ['code_product' => 'WFS1', 'name_product' => 'T', 'fx_wholesale_price' => 30000];
$panelW = ['code_panel' => 'WFS_PANEL', 'priceextravolume' => json_encode(['f' => 2000])];
$oidW11 = wfs_ref($RUN, 'GW11');
$g1 = fx_debt_gateway_record_once($oidW11, 'purchase_gateway', $prodW, $panelW, 'f', 0);
$rowsW11a = wfs_log_rows($pdo, $RID, $oidW11);
$g2 = fx_debt_gateway_record_once($oidW11, 'purchase_gateway', $prodW, $panelW, 'f', 0);
$rowsW11b = wfs_log_rows($pdo, $RID, $oidW11);
check('W11.1 کالبک اول: recorded، کسر 30000 کیف‌پول (۲ ردیف: bot صفر + wallet 30000)', $g1['action'] === 'recorded' && $g1['amount'] === 30000 && wfs_bal($pdo, $RID) === 30000 && count($rowsW11a) === 2, json_encode($g1) . ' bal=' . wfs_bal($pdo, $RID));
check('W11.2 کالبک تکراری: duplicate، بدون کسر دوباره، همان ۲ ردیف', $g2['action'] === 'duplicate' && wfs_bal($pdo, $RID) === 30000 && count($rowsW11b) === 2, json_encode($g2) . ' bal=' . wfs_bal($pdo, $RID) . ' cnt=' . count($rowsW11b));

// ===================== W12: درگاه — ceiling سپس retry =====================
echo "\n== W12: درگاه — ceiling_exceeded سپس retry بعد از افزایش سقف ==\n";
wfs_set_bal($pdo, $RID, 10000);
wfs_set_debt($pdo, $RID, 90000, 100000);
$oidW12 = wfs_ref($RUN, 'GW12');
$g3 = fx_debt_gateway_record_once($oidW12, 'purchase_gateway', $prodW, $panelW, 'f', 0);
$balW12 = wfs_bal($pdo, $RID);
check('W12.1 ceiling_exceeded: هیچ کسری/ثبتی رخ نداد', $g3['action'] === 'ceiling_exceeded' && $g3['reason'] === 'ceiling' && $g3['amount'] === 30000 && fx_debt_state((int)$RID)['current'] === 90000 && $balW12 === 10000 && count(wfs_log_rows($pdo, $RID, $oidW12)) === 0, json_encode($g3) . ' bal=' . $balW12 . ' debt=' . fx_debt_state((int)$RID)['current']);
wfs_set_debt($pdo, $RID, 90000, 200000);
$g4 = fx_debt_gateway_record_once($oidW12, 'purchase_gateway', $prodW, $panelW, 'f', 0);
check('W12.2 retry بعد از افزایش سقف → recorded؛ wp=10000 dp=20000؛ بدهی 110000', $g4['action'] === 'recorded' && wfs_bal($pdo, $RID) === 0 && fx_debt_state((int)$RID)['current'] === 110000, json_encode($g4) . ' bal=' . wfs_bal($pdo, $RID) . ' debt=' . fx_debt_state((int)$RID)['current']);

// ===================== W13: هم‌زمانی دو کانکشن =====================
echo "\n== W13: هم‌زمانی دو کانکشن نزدیک سقف (بدون overshoot) ==\n";
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
    wfs_set_bal($pdo, $RID, 30000);
    wfs_set_debt($pdo, $RID, 0, 100000);
    $refW13a = wfs_ref($RUN, 'W13A');
    $refW13b = wfs_ref($RUN, 'W13B');
    // کانکشن ۲ قفل botsaz را در تراکنش خود نگه می‌دارد (خرید موازی در حال انجام)
    $GLOBALS['pdo'] = $pdo2;
    $pdo2->beginTransaction();
    $pdo2->exec("UPDATE botsaz SET current_debt = current_debt + 60000 WHERE id_user = '$RID'");
    $GLOBALS['pdo'] = $__mainPdo;
    // تلاش موازی روی کانکشن اصلی → قفل تا timeout و شکست تمیز db + بدون کسر کیف‌پول
    list($okW13a, $rsnW13a) = fx_debt_reserve((int)$RID, 95000, $refW13a);
    $balDuring = wfs_bal($pdo, $RID);
    $stW13a = fx_debt_reservation_state($refW13a, (int)$RID);
    $GLOBALS['pdo'] = $pdo2;
    $pdo2->commit();
    $GLOBALS['pdo'] = $__mainPdo;
    // retry بعد از باز شدن قفل: wp=30000 dp=65000 → 60000+65000 > 100000 → ceiling
    list($okW13b, $rsnW13b) = fx_debt_reserve((int)$RID, 95000, $refW13b);
    $balAfter = wfs_bal($pdo, $RID);
    $debtW13 = fx_debt_state((int)$RID)['current'];
    check('W13 هم‌زمانی: تلاش موازی → db و بدون کسر؛ retry → ceiling؛ bدهی=60000 مانده=30000 (بدون overshoot)', empty($okW13a) && $rsnW13a === 'db' && $balDuring === 30000 && $stW13a === '' && empty($okW13b) && $rsnW13b === 'ceiling' && $debtW13 === 60000 && $balAfter === 30000, "rsnA=$rsnW13a balA=$balDuring rsnB=$rsnW13b debt=$debtW13 bal=$balAfter");
    $pdo->exec("SET SESSION innodb_lock_wait_timeout = 50");
    $GLOBALS['pdo'] = $__mainPdo;
    $pdo2 = null;
} else {
    warn('دو کانکشن PDO ممکن نشد — تست هم‌زمانی رد شد', false);
}
wfs_set_debt($pdo, $RID, 0, 1000000);
$pdo->exec("DELETE FROM fx_debt_log WHERE reseller_id = '$RID'");
$pdo->exec("DELETE FROM fx_debt_reservations WHERE reseller_id = '$RID'");

// ===================== W14: release سپس رزرو مجدد با ref جدید =====================
echo "\n== W14: release سپس رزرو مجدد (ref جدید) ==\n";
wfs_set_bal($pdo, $RID, 30000);
wfs_set_debt($pdo, $RID, 0, 1000000);
$refW14a = wfs_ref($RUN, 'W14A');
$refW14b = wfs_ref($RUN, 'W14B');
list($okW14a) = fx_debt_reserve((int)$RID, 20000, $refW14a);
fx_debt_commit((int)$RID, 20000, 'purchase', $refW14a);
$balAfter14a = wfs_bal($pdo, $RID);
fx_debt_release((int)$RID, 20000, $refW14a); // باید رد شود (کامیت شده)
$rev14a = (int)$pdo->query("SELECT COUNT(*) FROM fx_debt_log WHERE reseller_id = '$RID' AND invoice_ref = '$refW14a' AND method = 'revert'")->fetchColumn();
list($okW14b, $rsnW14b) = fx_debt_reserve((int)$RID, 15000, $refW14b);
$wp14b = wfs_res_wp($pdo, $refW14b);
fx_debt_release((int)$RID, 15000, $refW14b);
check('W14: بعد از commit+release رد‌شده (بدون مارکر)؛ ref جدید wp=10000 dp=5000 و release کامل (مانده 10000، بدهی 0)', !empty($okW14a) && $balAfter14a === 10000 && $rev14a === 0 && !empty($okW14b) && $rsnW14b === '' && $wp14b === 10000 && wfs_bal($pdo, $RID) === 10000 && fx_debt_state((int)$RID)['current'] === 0, "okA=$okW14a balA=$balAfter14a okB=$okW14b wpB=$wp14b bal=" . wfs_bal($pdo, $RID) . ' debt=' . fx_debt_state((int)$RID)['current']);

// ===================== W15: جداسازی چند نمایندگی =====================
echo "\n== W15: جداسازی چند نمایندگی ==\n";
wfs_set_bal($pdo, $RID, 25000);
wfs_set_debt($pdo, $RID, 0, 1000000);
wfs_set_bal($pdo, $RID2, 80000);
wfs_set_debt($pdo, $RID2, 0, 500000);
$refW15 = wfs_ref($RUN, 'W15A');
$refW15b = wfs_ref($RUN, 'W15B');
fx_debt_reserve((int)$RID, 40000, $refW15);
fx_debt_reserve((int)$RID2, 50000, $refW15b);
$isoOk = wfs_bal($pdo, $RID) === 0 && wfs_bal($pdo, $RID2) === 30000
    && fx_debt_state((int)$RID)['current'] === 15000 && fx_debt_state((int)$RID2)['current'] === 0
    && wfs_res_wp($pdo, $refW15) === 25000 && wfs_res_wp($pdo, $refW15b) === 50000;
fx_debt_release((int)$RID, 40000, $refW15);
fx_debt_release((int)$RID2, 50000, $refW15b);
check('W15: اسپلیت هر نمایندگی مستقل است و release هرکدام فقط حساب خودش را برمی‌گرداند', $isoOk && wfs_bal($pdo, $RID) === 25000 && wfs_bal($pdo, $RID2) === 80000 && fx_debt_state((int)$RID)['current'] === 0 && fx_debt_state((int)$RID2)['current'] === 0, 'iso=' . (int)$isoOk);

// ===================== W16: حالت balance دست‌نخورده =====================
echo "\n== W16: حالت balance (بدون بدهی) دست‌نخورده ==\n";
$pdo->exec("UPDATE botsaz SET debt_mode = 'balance' WHERE id_user = '$RID'");
wfs_set_bal($pdo, $RID, 70000);
$refW16 = wfs_ref($RUN, 'W16');
list($okW16, $rsnW16) = fx_debt_reserve((int)$RID, 50000, $refW16);
check('W16 حالت balance: reserve no-op (بدون رزرو/کسر)، کیف‌پول و بدهی دست‌نخورده', !empty($okW16) && $rsnW16 === '' && wfs_bal($pdo, $RID) === 70000 && wfs_res_wp($pdo, $refW16) === 0 && fx_debt_reservation_state($refW16, (int)$RID) === '', 'bal=' . wfs_bal($pdo, $RID) . ' state=' . fx_debt_reservation_state($refW16, (int)$RID));
$pdo->exec("UPDATE botsaz SET debt_mode = 'debt' WHERE id_user = '$RID'");

// ===================== W17: مبلغ صفر/منفی =====================
echo "\n== W17: مبلغ صفر/منفی → no-op ==\n";
wfs_set_bal($pdo, $RID, 20000);
wfs_set_debt($pdo, $RID, 0, 1000000);
$r17a = fx_debt_reserve((int)$RID, 0, wfs_ref($RUN, 'W17A'));
$r17b = fx_debt_reserve((int)$RID, -5000, wfs_ref($RUN, 'W17B'));
$r17c = fx_debt_record_once((int)$RID, 0, 'purchase', wfs_ref($RUN, 'W17C'));
check('W17: amount<=0 → no-op موفق بدون هیچ تغییر', !empty($r17a[0]) && $r17a[1] === '' && !empty($r17b[0]) && !empty($r17c[0]) && wfs_bal($pdo, $RID) === 20000 && fx_debt_state((int)$RID)['current'] === 0, json_encode($r17a) . json_encode($r17b) . json_encode($r17c));

// ===================== W18: کلید خاموشی =====================
echo "\n== W18: کلید خاموشی wallet_first='0' → legacy ==\n";
wfs_set_bal($pdo, $RID, 90000);
wfs_set_debt($pdo, $RID, 0, 1000000);
wfs_setting($pdo, $RID, ['wallet_first' => '0']);
$offOk = fx_debt_wallet_first((int)$RID) === false;
$refW18 = wfs_ref($RUN, 'W18');
list($okW18, $rsnW18) = fx_debt_reserve((int)$RID, 40000, $refW18);
list($okW18c) = fx_debt_commit((int)$RID, 40000, 'purchase', $refW18);
$rowsW18 = wfs_log_rows($pdo, $RID, $refW18);
check('W18: خاموشی → رزرو/کامیت legacy کامل، کیف‌پول دست‌نخورده، wallet_part=0', $offOk && !empty($okW18) && !empty($okW18c) && wfs_bal($pdo, $RID) === 90000 && fx_debt_state((int)$RID)['current'] === 40000 && wfs_res_wp($pdo, $refW18) === 0 && count($rowsW18) === 1, 'bal=' . wfs_bal($pdo, $RID) . ' debt=' . fx_debt_state((int)$RID)['current'] . ' rows=' . count($rowsW18));
$refW18b = wfs_ref($RUN, 'W18B');
fx_debt_reserve((int)$RID, 40000, $refW18b);
fx_debt_release((int)$RID, 40000, $refW18b); // legacy release (بدهی برمی‌گردد؛ کیف‌پولی کسر نشده بود)
$pdo->exec("UPDATE botsaz SET setting = NULL WHERE id_user = '$RID'");
check('W18.2 خاموشی: release legacy بدهی را برگرداند و کیف‌پول ثابت ماند', fx_debt_state((int)$RID)['current'] === 40000 && wfs_bal($pdo, $RID) === 90000 && fx_debt_reservation_state($refW18b, (int)$RID) === 'released', 'debt=' . fx_debt_state((int)$RID)['current'] . ' bal=' . wfs_bal($pdo, $RID));

// ===================== W19: صورتحساب =====================
echo "\n== W19: صورتحساب — سهم کیف‌پول در جمع فروش ==\n";
$pdo->exec("DELETE FROM fx_debt_log WHERE reseller_id = '$RID'");
wfs_set_bal($pdo, $RID, 20000);
wfs_set_debt($pdo, $RID, 0, 1000000);
$refW19 = wfs_ref($RUN, 'W19');
fx_debt_reserve((int)$RID, 25000, $refW19);
fx_debt_commit((int)$RID, 25000, 'purchase', $refW19);
$stmtTxt = fx_reseller_statement_build((int)$RID, time() - 3600);
$hasWalletLine = strpos($stmtTxt, 'از اعتبار کیف‌پول') !== false;
$sumOk = strpos($stmtTxt, number_format(25000)) !== false; // 25000 = 20000 کیف‌پول + 5000 بدهی
check('W19: صورتحساب مبلغ کل (کیف‌پول+بدهی) و خط سهم کیف‌پول را نشان می‌دهد', $hasWalletLine && $sumOk, substr($stmtTxt, 0, 300));

echo "\nنتیجه: PASS=$pass FAIL=$fail" . ($warn ? " WARN=$warn" : "") . "\n";
exit($fail ? 1 : 0);
