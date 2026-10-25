<?php
/**
 * fx_debt_phase2_selftest.php — تست‌های فاز ۲ حسابداری عمده نمایندگی (FX-DEBT-P2)
 *
 * اجرا (دیتابیس تستی ایزوله):
 *   docker exec -e DBH=db -e DBU=faoxima -e DBP=k2IZahTmNexPe06fo4DS -e DBN=faoxima_botcap_empty \
 *     faoxima-app-1 php /var/www/faoxima/scripts/fx_debt_phase2_selftest.php
 *
 * پوشش:
 *   R) رزروهای یونیک (fx_debt_reservations): retry همان ref، commit/release یک‌بار،
 *      release بعد از commit، commit بعد از release، rollback روی سقف، orphan report،
 *      هم‌زمانی واقعی دو کانکشن (نزدیک سقف)
 *   G) هلپر درگاه (fx_debt_gateway_record_once): recorded/duplicate/missing_wholesale/
 *      db_failed/skipped_balance + مسیر unit resolver + خریدهای متوالی
 *   P) ماشین حالت پرداخت (payment_fulfillment): کالبک تکراری، ownership،
 *      crash قبل/بعد از ثبت بدهی + بازیابی reconcile، قرارداد db_failed → reconcile_later،
 *      missing_wholesale → manual_review
 *   S) سیم‌کشی استاتیک: ثبت بدهی قبل از rx_pf_complete در هر ۵ مسیر + refهای رزرو
 *
 * این تست هیچ داده‌ای از دیتابیس اصلی faoxima نمی‌خواند/نمی‌نویسد.
 */
error_reporting(E_ALL & ~E_DEPRECATED);

$ROOT = dirname(__DIR__);
require_once $ROOT . '/re/rx/function/pricing_fx.php';
require_once $ROOT . '/re/rx/index/fx_debt.php';
require_once $ROOT . '/re/rx/function/payment_fulfillment.php';

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

$RUN = 'P2S-' . bin2hex(random_bytes(4));
echo "RUN ID: $RUN\n";

echo "\n== بخش R: رزروهای یونیک (fx_debt_reservations) ==\n";

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
$pdo->exec("CREATE TABLE IF NOT EXISTS Payment_report (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    id_order VARCHAR(191) NOT NULL,
    id_invoice VARCHAR(191) NULL,
    price bigint DEFAULT 0,
    payment_Status VARCHAR(40) DEFAULT 'waiting',
    at_updated VARCHAR(40) NULL,
    direct_payment_done TINYINT DEFAULT 0,
    dec_not_confirmed TEXT NULL,
    crypto_check_count INT DEFAULT 0,
    KEY idx_pr_id_order (id_order(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

register_shutdown_function(function () {
    global $pdo, $RUN;
    try {
        if ($pdo instanceof PDO) {
            $bak = $pdo->query("SHOW TABLES LIKE 'fx_debt_log_p2bak'")->fetchColumn();
            if ($bak !== false) {
                $pdo->exec("RENAME TABLE fx_debt_log_p2bak TO fx_debt_log");
            }
            $pdo->exec("DELETE FROM Payment_report WHERE id_order LIKE '$RUN%'");
            $pdo->exec("DELETE FROM payment_fulfillment WHERE id_order LIKE '$RUN%'");
            $pdo->exec("DELETE FROM fx_debt_reservations WHERE reseller_id = 7099002");
            $pdo->exec("DELETE FROM fx_debt_log WHERE reseller_id = 7099002");
            $pdo->exec("DELETE FROM botsaz WHERE id_user = '7099002'");
        }
    } catch (Throwable $e) {
    }
});

$TEST_RID = '7099002';
$pdo->exec("DELETE FROM botsaz WHERE id_user = '$TEST_RID'");
$pdo->exec("INSERT INTO botsaz (id_user, debt_mode, debt_ceiling, current_debt) VALUES ('$TEST_RID','debt',1000000,0)");

check('R1 جدول رزروها شناسایی می‌شود (fx_debt_res_table=true)', fx_debt_res_table() === true);

$refR2 = "$RUN-R2";
list($okR2, $rsnR2, $debtR2) = fx_debt_reserve($TEST_RID, 50000, $refR2);
check('R2 رزرو با ref → ok و debt+50000 و state=reserved', !empty($okR2) && $rsnR2 === '' && $debtR2 === 50000 && fx_debt_reservation_state($refR2, $TEST_RID) === 'reserved', "ok=$okR2 rsn=$rsnR2 debt=$debtR2 state=" . fx_debt_reservation_state($refR2, $TEST_RID));

list($okR3, $rsnR3) = fx_debt_reserve($TEST_RID, 50000, $refR2);
$cntResR3 = (int)$pdo->query("SELECT COUNT(*) FROM fx_debt_reservations WHERE ref = '$refR2'")->fetchColumn();
check('R3 retry همان ref در حالت reserved → duplicate بدون رزرو دوباره', !empty($okR3) && $rsnR3 === 'duplicate' && fx_debt_state($TEST_RID)['current'] === 50000 && $cntResR3 === 1, "rsn=$rsnR3 debt=" . fx_debt_state($TEST_RID)['current'] . " cnt=$cntResR3");

list($okR4, $rsnR4) = fx_debt_commit($TEST_RID, 50000, 'purchase', $refR2);
$cntLogR4 = (int)$pdo->query("SELECT COUNT(*) FROM fx_debt_log WHERE reseller_id = '$TEST_RID' AND invoice_ref = '$refR2' AND direction = 'in'")->fetchColumn();
check('R4 commit بعد از رزرو → ثبت یک ردیف + state=committed', !empty($okR4) && $rsnR4 === '' && $cntLogR4 === 1 && fx_debt_reservation_state($refR2, $TEST_RID) === 'committed', "ok=$okR4 rsn=$rsnR4 cnt=$cntLogR4 state=" . fx_debt_reservation_state($refR2, $TEST_RID));

list($okR5, $rsnR5) = fx_debt_commit($TEST_RID, 50000, 'purchase', $refR2);
$cntLogR5 = (int)$pdo->query("SELECT COUNT(*) FROM fx_debt_log WHERE reseller_id = '$TEST_RID' AND invoice_ref = '$refR2' AND direction = 'in'")->fetchColumn();
check('R5 commit تکراری → duplicate و فقط ۱ ردیف', !empty($okR5) && $rsnR5 === 'duplicate' && $cntLogR5 === 1, "rsn=$rsnR5 cnt=$cntLogR5");

fx_debt_release($TEST_RID, 50000, $refR2);
$markerR6 = (int)$pdo->query("SELECT COUNT(*) FROM fx_debt_log WHERE reseller_id = '$TEST_RID' AND method = 'revert' AND invoice_ref = '$refR2'")->fetchColumn();
check('R6 release بعد از commit → رد و بدهی/state دست‌نخورده', fx_debt_state($TEST_RID)['current'] === 50000 && fx_debt_reservation_state($refR2, $TEST_RID) === 'committed' && $markerR6 === 0, 'debt=' . fx_debt_state($TEST_RID)['current'] . ' marker=' . $markerR6);

$refR7 = "$RUN-R7";
list($okR7) = fx_debt_reserve($TEST_RID, 30000, $refR7);
$debtR7a = fx_debt_state($TEST_RID)['current'];
fx_debt_release($TEST_RID, 30000, $refR7);
check('R7 رزرو سپس release → بدهی برگشت و state=released', !empty($okR7) && $debtR7a === 80000 && fx_debt_state($TEST_RID)['current'] === 50000 && fx_debt_reservation_state($refR7, $TEST_RID) === 'released', 'debt7a=' . $debtR7a . ' now=' . fx_debt_state($TEST_RID)['current']);

fx_debt_release($TEST_RID, 30000, $refR7);
check('R8 release دوباره همان ref → no-op و بدهی دست‌نخورده', fx_debt_state($TEST_RID)['current'] === 50000);

list($okR9, $rsnR9) = fx_debt_commit($TEST_RID, 30000, 'purchase', $refR7);
$cntInR9 = (int)$pdo->query("SELECT COUNT(*) FROM fx_debt_log WHERE reseller_id = '$TEST_RID' AND invoice_ref = '$refR7' AND direction = 'in'")->fetchColumn();
check('R9 commit بعد از release → رد (reserved_released) بدون ردیف', empty($okR9) && $rsnR9 === 'reserved_released' && $cntInR9 === 0 && fx_debt_state($TEST_RID)['current'] === 50000, "ok=$okR9 rsn=$rsnR9 cnt=$cntInR9");

list($okR10, $rsnR10) = fx_debt_reserve($TEST_RID, 30000, $refR7);
check('R10 رزرو دوباره ref released → رد (already_released) بدون تغییر بدهی', empty($okR10) && $rsnR10 === 'already_released' && fx_debt_state($TEST_RID)['current'] === 50000, "ok=$okR10 rsn=$rsnR10");

list($okR11, $rsnR11) = fx_debt_reserve($TEST_RID, 50000, $refR2);
check('R11 رزرو دوباره ref committed → رد (already_committed) بدون رزرو دوباره', empty($okR11) && $rsnR11 === 'already_committed' && fx_debt_state($TEST_RID)['current'] === 50000, "ok=$okR11 rsn=$rsnR11");

$pdo->exec("UPDATE botsaz SET debt_ceiling = 60000 WHERE id_user = '$TEST_RID'");
$refR12 = "$RUN-R12";
list($okR12, $rsnR12) = fx_debt_reserve($TEST_RID, 20000, $refR12);
$stateR12 = fx_debt_reservation_state($refR12, $TEST_RID);
check('R12 سقف در مسیر ref → ceiling و رزرو rollback شد (بدون ردیف orphan)', empty($okR12) && $rsnR12 === 'ceiling' && $stateR12 === '' && fx_debt_state($TEST_RID)['current'] === 50000, "ok=$okR12 rsn=$rsnR12 state=$stateR12");
$pdo->exec("UPDATE botsaz SET debt_ceiling = 1000000 WHERE id_user = '$TEST_RID'");

$refA = "$RUN-ORPH-A"; $refB = "$RUN-ORPH-B";
fx_debt_reserve($TEST_RID, 5000, $refA);
fx_debt_reserve($TEST_RID, 6000, $refB);
$pdo->exec("UPDATE fx_debt_reservations SET created_at = NOW() - INTERVAL 10 MINUTE WHERE ref IN ('$refA','$refB')");
$pend1 = fx_debt_reservations_pending(5);
$pendRefs1 = array_column($pend1, 'ref');
$foundA = in_array($refA, $pendRefs1, true);
$foundB = in_array($refB, $pendRefs1, true);
fx_debt_release($TEST_RID, 5000, $refA);
fx_debt_commit($TEST_RID, 6000, 'extra_volume', $refB);
$pend2 = fx_debt_reservations_pending(5);
$pendRefs2 = array_column($pend2, 'ref');
check('R13 orphan report: رزروهای باز شناسایی و پس از commit/release حذف می‌شوند', $foundA && $foundB && !in_array($refA, $pendRefs2, true) && !in_array($refB, $pendRefs2, true), 'foundA=' . (int)$foundA . ' foundB=' . (int)$foundB . ' pend2=' . count($pendRefs2));

$pdo->exec("UPDATE botsaz SET current_debt = 0 WHERE id_user = '$TEST_RID'");
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

    // R14: رزرو هم‌زمان — تلاش دوم روی قفل بلوکه و تمیز شکست می‌خورد؛ رزروش rollback می‌شود
    $refR14 = "$RUN-R14";
    $GLOBALS['pdo'] = $pdo2;
    $pdo2->beginTransaction();
    $pdo2->exec("UPDATE botsaz SET current_debt = current_debt + 1000 WHERE id_user = '$TEST_RID'");
    $GLOBALS['pdo'] = $__mainPdo;
    list($okR14, $rsnR14) = fx_debt_reserve($TEST_RID, 20000, $refR14);
    $stateR14 = fx_debt_reservation_state($refR14, $TEST_RID);
    $GLOBALS['pdo'] = $pdo2;
    $pdo2->commit();
    $GLOBALS['pdo'] = $__mainPdo;
    list($okR14b, $rsnR14b, $debtR14b) = fx_debt_reserve($TEST_RID, 20000, $refR14);
    $GLOBALS['pdo'] = $pdo2;
    list($okR14c, $rsnR14c) = fx_debt_reserve($TEST_RID, 20000, $refR14);
    $GLOBALS['pdo'] = $__mainPdo;
    check('R14 هم‌زمانی رزرو: تلاش موازی → db + rollback رزرو؛ retry بعد از commit برنده → ok و سپس duplicate', empty($okR14) && $rsnR14 === 'db' && $stateR14 === '' && !empty($okR14b) && $rsnR14b === '' && $debtR14b === 21000 && !empty($okR14c) && $rsnR14c === 'duplicate', "okA=$okR14 rsnA=$rsnR14 stA=$stateR14 okB=$okR14b rsnB=$rsnR14b dB=$debtR14b rsnC=$rsnR14c");

    // R15: دو خرید هم‌زمان نزدیک سقف — یکی رزرو می‌شود، دومی ceiling می‌گیرد (بدون overshoot)
    $pdo->exec("UPDATE botsaz SET current_debt = 0, debt_ceiling = 100000 WHERE id_user = '$TEST_RID'");
    $refE = "$RUN-R15E"; $refF = "$RUN-R15F";
    $GLOBALS['pdo'] = $pdo2;
    $pdo2->beginTransaction();
    list($okE) = fx_debt_reserve($TEST_RID, 60000, $refE);
    $GLOBALS['pdo'] = $__mainPdo;
    list($okF, $rsnF) = fx_debt_reserve($TEST_RID, 60000, $refF);
    $GLOBALS['pdo'] = $pdo2;
    $pdo2->commit();
    $GLOBALS['pdo'] = $__mainPdo;
    list($okF2, $rsnF2) = fx_debt_reserve($TEST_RID, 60000, $refF);
    $stateF2 = fx_debt_reservation_state($refF, $TEST_RID);
    $debt15 = fx_debt_state($TEST_RID)['current'];
    check('R15 هم‌زمانی نزدیک سقف: برنده 60000 رزرو شد، بازنده ceiling گرفت (بدون overshoot و بدون رزرو orphan)', !empty($okE) && empty($okF) && $rsnF === 'db' && empty($okF2) && $rsnF2 === 'ceiling' && $stateF2 === '' && $debt15 === 60000, "okE=$okE okF=$okF rsnF=$rsnF okF2=$okF2 rsnF2=$rsnF2 debt=$debt15");

    $pdo->exec("SET SESSION innodb_lock_wait_timeout = 50");
    $GLOBALS['pdo'] = $__mainPdo;
    $pdo2 = null;
} else {
    warn('دو کانکشن PDO ممکن نشد — تست هم‌زمانی رزرو رد شد', false);
}

$pdo->exec("UPDATE botsaz SET current_debt = 0, debt_ceiling = 1000000 WHERE id_user = '$TEST_RID'");
$pdo->exec("DELETE FROM fx_debt_reservations WHERE reseller_id = '$TEST_RID'");
$pdo->exec("DELETE FROM fx_debt_log WHERE reseller_id = '$TEST_RID'");

echo "\n== بخش G: هلپر درگاه (fx_debt_gateway_record_once) ==\n";

$panelG = [
    'code_panel' => 'P2_PANEL',
    'priceextravolume' => json_encode(['f' => 2000]),
    'priceextratime' => json_encode(['f' => 1500]),
];
$prodG = ['code_product' => 'P2T1', 'name_product' => 'T1', 'fx_wholesale_price' => 30000];

$g1 = fx_debt_gateway_record_once("$RUN-G1", 'purchase_gateway', $prodG, $panelG, 'f', 0);
check('G1 بدون FX_RESELLER_ID → skipped (بدون اثر)', $g1['action'] === 'skipped', json_encode($g1));

define('FX_RESELLER_ID', 7099002);

$pdo->exec("UPDATE botsaz SET debt_mode = 'balance', current_debt = 0 WHERE id_user = '$TEST_RID'");
$g2 = fx_debt_gateway_record_once("$RUN-G2", 'purchase_gateway', $prodG, $panelG, 'f', 30000);
check('G2 حالت balance → skipped_balance بدون نوشتن', $g2['action'] === 'skipped_balance' && fx_debt_state($TEST_RID)['current'] === 0, json_encode($g2));

$pdo->exec("UPDATE botsaz SET debt_mode = 'debt' WHERE id_user = '$TEST_RID'");
$g3 = fx_debt_gateway_record_once("$RUN-G3", 'purchase_gateway', $prodG, $panelG, 'f', 0);
$cntG3 = (int)$pdo->query("SELECT COUNT(*) FROM fx_debt_log WHERE reseller_id = '$TEST_RID' AND invoice_ref = '$RUN-G3' AND method = 'purchase_gateway'")->fetchColumn();
check('G3 ثبت از resolver محصول → recorded/30000 و ۱ ردیف', $g3['action'] === 'recorded' && $g3['amount'] === 30000 && $cntG3 === 1 && fx_debt_state($TEST_RID)['current'] === 30000, json_encode($g3) . " cnt=$cntG3");

$g4 = fx_debt_gateway_record_once("$RUN-G3", 'purchase_gateway', $prodG, $panelG, 'f', 0);
$cntG4 = (int)$pdo->query("SELECT COUNT(*) FROM fx_debt_log WHERE reseller_id = '$TEST_RID' AND invoice_ref = '$RUN-G3'")->fetchColumn();
check('G4 retry همان سفارش → duplicate و بدون ردیف دوم', $g4['action'] === 'duplicate' && $cntG4 === 1 && fx_debt_state($TEST_RID)['current'] === 30000, json_encode($g4) . " cnt=$cntG4");

$g5 = fx_debt_gateway_record_once("$RUN-G5", 'purchase_gateway', ['code_product' => 'NOX', 'name_product' => 'x'], ['code_panel' => 'P2_PANEL'], 'f', 0);
$cntG5 = (int)$pdo->query("SELECT COUNT(*) FROM fx_debt_log WHERE reseller_id = '$TEST_RID' AND invoice_ref = '$RUN-G5'")->fetchColumn();
check('G5 بدون قیمت عمده (stash=0) → missing_wholesale بدون ردیف', $g5['action'] === 'missing_wholesale' && $cntG5 === 0 && fx_debt_state($TEST_RID)['current'] === 30000, json_encode($g5));

$g6 = fx_debt_gateway_record_once("$RUN-G6", 'extra_volume_gateway', null, $panelG, 'f', 0, ['column' => 'priceextravolume', 'qty' => 5]);
check('G6 مسیر unit resolver (حجم 5×2000) → recorded/10000', $g6['action'] === 'recorded' && $g6['amount'] === 10000 && fx_debt_state($TEST_RID)['current'] === 40000, json_encode($g6));

$pdo->exec("RENAME TABLE fx_debt_log TO fx_debt_log_p2bak");
$g7 = fx_debt_gateway_record_once("$RUN-G7", 'purchase_gateway', $prodG, $panelG, 'f', 30000);
$pdo->exec("RENAME TABLE fx_debt_log_p2bak TO fx_debt_log");
check('G7 خطای DB → db_failed با amount (قرارداد reconcile/manual)', $g7['action'] === 'db_failed' && $g7['amount'] === 30000, json_encode($g7));

$refG8a = "$RUN-G8A"; $refG8b = "$RUN-G8B";
fx_debt_reserve($TEST_RID, 20000, $refG8a);
fx_debt_commit($TEST_RID, 20000, 'extra_volume', $refG8a);
fx_debt_reserve($TEST_RID, 20000, $refG8b);
fx_debt_commit($TEST_RID, 20000, 'extra_volume', $refG8b);
$cntG8 = (int)$pdo->query("SELECT COUNT(*) FROM fx_debt_log WHERE reseller_id = '$TEST_RID' AND invoice_ref IN ('$refG8a','$refG8b') AND direction = 'in'")->fetchColumn();
check('G8 دو خرید حجم متوالی (ref یکتا per-attempt) → هر دو ثبت، بدون dedupe اشتباه', $cntG8 === 2 && fx_debt_state($TEST_RID)['current'] === 80000, "cnt=$cntG8 debt=" . fx_debt_state($TEST_RID)['current']);

echo "\n== بخش P: ماشین حالت پرداخت (payment_fulfillment) ==\n";

function p2_seed_order(PDO $pdo, string $oid, string $inv = 'wallet'): void {
    $pdo->prepare("INSERT INTO Payment_report (id_order, id_invoice, price, payment_Status, direct_payment_done, at_updated) VALUES (?, ?, 1000, 'waiting', 0, ?)")
        ->execute([$oid, $inv, date('Y/m/d H:i:s')]);
}
function p2_kill_lease(PDO $pdo, string $oid): void {
    $pdo->prepare("UPDATE payment_fulfillment SET lease_expires_at = UNIX_TIMESTAMP() - 10 WHERE id_order = ?")->execute([$oid]);
}

$panelP = ['code_panel' => 'P2_PANEL', 'priceextravolume' => json_encode(['f' => 2000])];
$prodP = ['code_product' => 'P2P1', 'name_product' => 'P1', 'fx_wholesale_price' => 50000];

$oid1 = "$RUN-ORDER-1";
p2_seed_order($pdo, $oid1);
$c1 = rx_pf_claim($oid1);
$token1 = $c1['token'] ?? '';
$c2 = rx_pf_claim($oid1);
check('P1 کالبک تکراری: claim اول claimed، claim دوم busy (lease فعال)', ($c1['status'] ?? '') === 'claimed' && $token1 !== '' && ($c2['status'] ?? '') === 'busy', json_encode(['c1' => $c1['status'] ?? '', 'c2' => $c2['status'] ?? '']));

$prep1 = rx_pf_prepare($oid1, $token1, null, ['op' => 'test']);
$prep1b = rx_pf_prepare($oid1, $token1, null, ['op' => 'test']);
$done1 = rx_pf_complete($oid1, $token1, ['op' => 'test']);
$done1b = rx_pf_complete($oid1, $token1, ['op' => 'test']);
$pay1 = $pdo->query("SELECT direct_payment_done, payment_Status FROM Payment_report WHERE id_order = '$oid1'")->fetch(PDO::FETCH_ASSOC);
$c3 = rx_pf_claim($oid1);
check('P2 ownership: prepare فقط با state=claimed، complete یک‌بار؛ بار دوم already_completed؛ پرداخت paid', $prep1 === true && $prep1b === false && $done1 === 'completed' && $done1b === 'already_completed' && (int)$pay1['direct_payment_done'] === 1 && $pay1['payment_Status'] === 'paid' && ($c3['status'] ?? '') === 'already_completed', json_encode(['p1' => $prep1, 'p1b' => $prep1b, 'd1' => $done1, 'd1b' => $done1b, 'pay' => $pay1, 'c3' => $c3['status'] ?? '']));

$oid2 = "$RUN-ORDER-2";
p2_seed_order($pdo, $oid2);
$c20 = rx_pf_claim($oid2);
$t20 = $c20['token'] ?? '';
rx_pf_prepare($oid2, $t20, null, ['op' => 'buy']);
p2_kill_lease($pdo, $oid2);
$c21 = rx_pf_claim($oid2);
$b21 = rx_pf_begin($oid2, null);
$t21 = $b21['token'] ?? '';
$rec21 = fx_debt_gateway_record_once($oid2, 'purchase_gateway', $prodP, $panelP, 'f', 0);
p2_kill_lease($pdo, $oid2);
$c22 = rx_pf_claim($oid2);
$b22 = rx_pf_begin($oid2, null);
$t22 = $b22['token'] ?? '';
$rec22 = fx_debt_gateway_record_once($oid2, 'purchase_gateway', $prodP, $panelP, 'f', 0);
$done2 = rx_pf_complete($oid2, $t22, ['op' => 'buy']);
$cnt2 = (int)$pdo->query("SELECT COUNT(*) FROM fx_debt_log WHERE reseller_id = '$TEST_RID' AND invoice_ref = '$oid2'")->fetchColumn();
check('P3 crash قبل/بعد از ثبت بدهی → بازیابی reconcile: ثبت یک‌بار، retry duplicate، سپس complete (بدون double charge)', ($c21['status'] ?? '') === 'requires_reconciliation' && !empty($b21['proceed']) && ($b21['phase'] ?? '') === 'reconcile' && $rec21['action'] === 'recorded' && ($c22['status'] ?? '') === 'requires_reconciliation' && !empty($b22['proceed']) && $rec22['action'] === 'duplicate' && $done2 === 'completed' && $cnt2 === 1, json_encode(['c21' => $c21['status'] ?? '', 'b21' => $b21['phase'] ?? '', 'r21' => $rec21['action'], 'c22' => $c22['status'] ?? '', 'r22' => $rec22['action'], 'done' => $done2, 'cnt' => $cnt2]));

$oid3 = "$RUN-ORDER-3";
p2_seed_order($pdo, $oid3);
$c30 = rx_pf_claim($oid3);
$t30 = $c30['token'] ?? '';
rx_pf_prepare($oid3, $t30, null, ['op' => 'buy']);
$pdo->exec("RENAME TABLE fx_debt_log TO fx_debt_log_p2bak");
$rec31 = fx_debt_gateway_record_once($oid3, 'purchase_gateway', $prodP, $panelP, 'f', 0);
$out31 = rx_pf_reconcile_later($oid3, $t30, 'fx_debt_record_failed: ' . $rec31['reason']);
$pdo->exec("RENAME TABLE fx_debt_log_p2bak TO fx_debt_log");
$state31 = (string)$pdo->query("SELECT state FROM payment_fulfillment WHERE id_order = '$oid3'")->fetchColumn();
p2_kill_lease($pdo, $oid3);
$b32 = rx_pf_begin($oid3, null);
$t32 = $b32['token'] ?? '';
$rec32 = fx_debt_gateway_record_once($oid3, 'purchase_gateway', $prodP, $panelP, 'f', 0);
$done3 = rx_pf_complete($oid3, $t32, ['op' => 'buy']);
$cnt3 = (int)$pdo->query("SELECT COUNT(*) FROM fx_debt_log WHERE reseller_id = '$TEST_RID' AND invoice_ref = '$oid3'")->fetchColumn();
check('P4 قرارداد db_failed → reconcile_later (سفارش کامل نمی‌شود)، سپس retry → ثبت+complete با ۱ ردیف', $rec31['action'] === 'db_failed' && !empty($out31['requires_reconciliation']) && $state31 === 'reconciling' && !empty($b32['proceed']) && $rec32['action'] === 'recorded' && $done3 === 'completed' && $cnt3 === 1, json_encode(['r31' => $rec31['action'], 'out' => $out31['status'] ?? '', 'st' => $state31, 'r32' => $rec32['action'], 'done' => $done3, 'cnt' => $cnt3]));

$oid4 = "$RUN-ORDER-4";
p2_seed_order($pdo, $oid4);
$c40 = rx_pf_claim($oid4);
$t40 = $c40['token'] ?? '';
rx_pf_prepare($oid4, $t40, null, ['op' => 'buy']);
$rec41 = fx_debt_gateway_record_once($oid4, 'purchase_gateway', ['code_product' => 'NOX', 'name_product' => 'x'], ['code_panel' => 'P2_PANEL'], 'f', 0);
$out41 = rx_pf_manual_review($oid4, $t40, 'fx_wholesale_missing_after_delivery', ['username' => 'u']);
$state41 = (string)$pdo->query("SELECT state FROM payment_fulfillment WHERE id_order = '$oid4'")->fetchColumn();
$cnt4 = (int)$pdo->query("SELECT COUNT(*) FROM fx_debt_log WHERE reseller_id = '$TEST_RID' AND invoice_ref = '$oid4'")->fetchColumn();
$done4 = rx_pf_complete($oid4, $t40, ['op' => 'buy']);
check('P5 missing_wholesale → manual_review (بدون complete، بدون بدهی)', $rec41['action'] === 'missing_wholesale' && !empty($out41['manual_review']) && $state41 === 'manual_review' && $cnt4 === 0 && $done4 !== 'completed', json_encode(['r' => $rec41['action'], 'out' => $out41['status'] ?? '', 'st' => $state41, 'cnt' => $cnt4, 'done' => $done4]));

echo "\n== بخش S: سیم‌کشی استاتیک (ترتیب ثبت بدهی قبل از complete + refهای رزرو) ==\n";
$srcUF   = (string)@file_get_contents($ROOT . '/re/rx/index/user_flow.php');
$srcBS   = (string)@file_get_contents($ROOT . '/re/rx/index/bootstrap.php');
$srcFIN  = (string)@file_get_contents($ROOT . '/re/rx/index/finalize.php');
$srcDH2  = (string)@file_get_contents($ROOT . '/re/rx/function/database_helpers_2.php');
$srcDebt = (string)@file_get_contents($ROOT . '/re/rx/index/fx_debt.php');

$orderOk = true;
$orderDetail = [];
$pos = 0;
for ($i = 0; $i < 5; $i++) {
    $pRec = strpos($srcDH2, 'fx_debt_gateway_record_once(', $pos);
    if ($pRec === false) { $orderOk = false; $orderDetail[] = "rec$i=missing"; break; }
    $pCmp = strpos($srcDH2, 'rx_pf_complete(', $pRec);
    if ($pCmp === false) { $orderOk = false; $orderDetail[] = "cmp$i=missing"; break; }
    $pNext = strpos($srcDH2, 'fx_debt_gateway_record_once(', $pRec + 1);
    $orderOk = $orderOk && ($pNext === false || $pCmp < $pNext);
    $pos = $pRec + 1;
}
check('S1 ترتیب: در هر ۵ مسیر ثبت بدهی قبل از rx_pf_complete است', $orderOk && substr_count($srcDH2, 'fx_debt_gateway_record_once(') === 5, implode(',', $orderDetail) . ' calls=' . substr_count($srcDH2, 'fx_debt_gateway_record_once('));

check('S2 هلپر در fx_debt.php تعریف و در dh2 استفاده شده', strpos($srcDebt, 'function fx_debt_gateway_record_once') !== false && substr_count($srcDH2, 'fx_debt_gateway_record_once(') === 5);

$cntRec2 = substr_count($srcDH2, "rx_pf_reconcile_later(\$order_id, \$rxToken, 'fx_debt_record_failed");
$cntMan2 = substr_count($srcDH2, "rx_pf_manual_review(\$order_id, \$rxToken, 'fx_debt_record_failed'");
$cntManW = substr_count($srcDH2, "'fx_wholesale_missing_after_delivery'");
check('S3 هندلرهای شکست: ≥3 reconcile_later (panel/volume/time)، ≥2 manual_review (national/stock renewal)، ۵ مورد missing_wholesale', $cntRec2 >= 3 && $cntMan2 >= 2 && $cntManW === 5, "rec=$cntRec2 man=$cntMan2 mw=$cntManW");

$cntResUF = preg_match_all('/fx_debt_reserve\([^;]*\$randomString/', $srcUF);
$cntResBS = strpos($srcBS, 'fx_debt_reserve($__fxRid, $__fxDebtWholesale, (string)$randomString)') !== false
    && strpos($srcBS, 'fx_debt_reserve($__fxRid, $__fxDebtWholesale, (string)$__fxVxRef)') !== false
    && strpos($srcBS, 'fx_debt_reserve($__fxRid, $__fxDebtWholesale, (string)$__fxEtRef)') !== false;
$cntResFin = strpos($srcFIN, 'fx_debt_reserve($__fxRid, $__fxDebtWholesale, (string)$__fxFinRef)') !== false;
check('S4 هر ۸ فراخوان رزرو ref یکتای تلاش دارند (user_flow=4، bootstrap=3، finalize=1)', $cntResUF === 4 && $cntResBS && $cntResFin, "uf=$cntResUF bs=" . (int)$cntResBS . " fin=" . (int)$cntResFin);

check('S5 توابع رزرو در fx_debt.php موجودند (state/mark/pending/legacy + امضای ۳آرگومان reserve)', strpos($srcDebt, 'function fx_debt_reservation_state') !== false && strpos($srcDebt, 'function fx_debt_reservation_mark') !== false && strpos($srcDebt, 'function fx_debt_reservations_pending') !== false && strpos($srcDebt, 'function fx_debt_reserve_legacy') !== false && strpos($srcDebt, 'string $ref = ') !== false && strpos($srcDebt, "'fx_debt_reservations'") !== false);

check('S6 سیم‌کشی قدیمی (record_once مستقیم در dh2) حذف شده', substr_count($srcDH2, 'fx_debt_record_once($__fxRid') === 0);

echo "\nنتیجه: PASS=$pass FAIL=$fail" . ($warn ? " WARN=$warn" : "") . "\n";
exit($fail ? 1 : 0);
