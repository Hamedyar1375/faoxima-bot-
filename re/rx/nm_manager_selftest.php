<?php
/**
 * nm_manager_selftest.php — STEP_3/4: Self-Test جامع مدیریت نمایندگی
 * ==================================================================
 * اجرا در container:  php /var/www/faoxima/re/rx/nm_manager_selftest.php
 *
 * بخش A — تست‌های خالص (بدون DB):
 *   - nm_agent_purchase_guard: مرز دقیق سقف، عبور یک تومانی، غیر-n2
 *   - nm_admin_set_debt_limit / nm_admin_apply_balance: validation
 *   - nm_invoice_status_fa / nm_fmt_money (نمایش بدهکار)
 *
 * بخش B — تست‌های دیتابیس زنده (transaction + ROLLBACK → صفر ردپا):
 *   - سقف از user.maxbuyagent خوانده می‌شود
 *   - افزایش موجودی اتمیک
 *   - کاهش موجودی با عبور از سقف → block (rowCount=0)
 *   - کسر خرید در مرز دقیق → allowed؛ یک تومانی بیشتر → blocked
 *   - ledger در fx_debt_log ثبت می‌شود (داخل transaction → rollback)
 *
 * همه عمل‌های نوشتاری داخل BEGIN/ROLLBACK — پس از خروج، دیتابیس دست‌نخورده است.
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);
if (!defined('REFACTORED_LEGACY_ROOT')) {
    define('REFACTORED_LEGACY_ROOT', '/var/www/faoxima');
}
chdir(REFACTORED_LEGACY_ROOT);
require REFACTORED_LEGACY_ROOT . '/config.php';
require REFACTORED_LEGACY_ROOT . '/re/rx/function/bootstrap.php';
require REFACTORED_LEGACY_ROOT . '/re/rx/nm_financials.php';
require REFACTORED_LEGACY_ROOT . '/re/rx/nm_formatter.php';
require REFACTORED_LEGACY_ROOT . '/re/rx/nm_manager.php';

$PASS = 0; $FAIL = 0; $LOG = [];
function T($name, $cond, $detail = '') {
    global $PASS, $FAIL, $LOG;
    if ($cond) { $PASS++; $LOG[] = "  ✅ $name" . ($detail !== '' ? "  ($detail)" : ''); }
    else { $FAIL++; $LOG[] = "  ❌ $name" . ($detail !== '' ? "  ($detail)" : ''); }
}

/* ================= بخش A: خالص ================= */
echo "=== بخش A: تست‌های خالص (بدون DB) ===\n";

$u1 = ['agent' => 'n2', 'Balance' => 0, 'maxbuyagent' => 1000000, 'id' => 999999];

// A1: خرید ۱,۰۰۰,۰۰۰ با موجودی ۰ → مرز دقیق → مجاز
$r = nm_agent_purchase_guard($u1, 1000000);
T('A1 مرز دقیق سقف = مجاز', $r['ok'] === true, 'ok=' . var_export($r['ok'], true) . ' reason=' . ($r['msg'] ?: 'ok'));
T('A1a balance_after = -1,000,000', $r['balance_after'] === -1000000, 'bal=' . $r['balance_after']);
T('A1b debt_after = 1,000,000', $r['debt_after'] === 1000000, 'debt=' . $r['debt_after']);

// A2: خرید ۱,۰۰۰,۰۰۱ → یک تومانی بیشتر → مسدود
$r = nm_agent_purchase_guard($u1, 1000001);
T('A2 عبور یک تومانی = مسدود', $r['ok'] === false, 'msg contains limit=' . (strpos($r['msg'], 'سقف بدهکاری') !== false ? 'yes' : 'NO'));
T('A2a reason = debt_limit_exceeded text', strpos($r['msg'], 'مسدود') !== false);

// A3: maxbuyagent=0 → سقف پیش‌فرض (env/12M)
$u2 = ['agent' => 'n2', 'Balance' => 0, 'maxbuyagent' => 0, 'id' => 999998];
T('A3 maxbuyagent=0 → پیش‌فرض', nm_agent_purchase_limit($u2) === nm_default_debt_limit(), 'limit=' . nm_agent_purchase_limit($u2));

// A4: agent!=n2 → guard همیشه مجاز (تداخل با منطق عادی ندارد)
$u3 = ['agent' => 'f', 'Balance' => -99999999, 'maxbuyagent' => 0, 'id' => 999997];
$r = nm_agent_purchase_guard($u3, 100);
T('A4 agent=f → guard pass-through', $r['ok'] === true && $r['limit'] === 0);

// A5: قیمت نامعتبر → pass-through
$r = nm_agent_purchase_guard($u1, 0);
T('A5 price<=0 → pass-through', $r['ok'] === true);

// A6: پیام مسدودی — فرمت پول فارسی (بدهی = قدرمطلق مثبت؛ علامت منفی فقط برای «موجودی» است)
$r = nm_agent_purchase_guard(['agent' => 'n2', 'Balance' => -900000, 'maxbuyagent' => 1000000], 200000);
T('A6 balance=-900k price=200k limit=1M → مسدود', $r['ok'] === false);
T('A6a نمایش بدهی «۱,۱۰۰,۰۰۰» در پیام', strpos($r['msg'], '۱,۱۰۰,۰۰۰') !== false, 'debt_after=' . $r['debt_after']);
T('A6b نمایش سقف «۱,۰۰۰,۰۰۰» در پیام', strpos($r['msg'], '۱,۰۰۰,۰۰۰') !== false);
T('A6c نمایش موجودی بدهکار «۹۰۰,۰۰۰-» در پیام', strpos($r['msg'], '۹۰۰,۰۰۰-') !== false);

// A7: نمایش money (STEP_2 regression)
T('A7 nm_fmt_money(-1040000)=۱,۰۴۰,۰۰۰-', nm_fmt_money(-1040000) === '۱,۰۴۰,۰۰۰-');
T('A7a nm_fmt_money(0)=۰', nm_fmt_money(0) === '۰');

// A8: status map
T('A8 invoice status fa', nm_invoice_status_fa('active') === '✅ فعال' && nm_invoice_status_fa('end_of_volume') === '📉 منقضی (حجم)' && nm_invoice_status_fa('xyz') === 'xyz');

// A9: dispatch non-nm prefix → false (بدون تداخل با callbackهای دیگر)
T('A9 dispatch nm_ prefix gate', nm_manager_dispatch('admin_status', 1, 1, ['step' => 'home']) === false);
T('A9a dispatch empty → false', nm_manager_dispatch('', 1, 1, ['step' => 'home']) === false);

// A10: regression — موجودی مثبت نباید به‌عنوان بدهی محاسبه شود (باگ abs() در STEP_1)
$r = nm_agent_purchase_guard(['agent' => 'n2', 'Balance' => 2500000, 'maxbuyagent' => 1000000], 1000000);
T('A10 موجودی +۲.۵M - خرید ۱M = +۱.۵M (بدهی صفر) → مجاز با سقف ۱M', $r['ok'] === true, 'balance_after=' . $r['balance_after'] . ' debt=' . $r['debt_after']);
$r = nm_agent_purchase_guard(['agent' => 'n2', 'Balance' => 1500000, 'maxbuyagent' => 1000000], 500000);
T('A10a موجودی +۱.۵M - خرید ۵۰۰k = +۱M → مجاز', $r['ok'] === true, 'balance_after=' . $r['balance_after']);

echo implode("\n", $LOG) . "\n\n";

/* ================= بخش B: دیتابیس زنده (transaction) ================= */
echo "=== بخش B: دیتابیس زنده (transaction + ROLLBACK — صفر ردپا) ===\n";
$log = [];
function TB($name, $cond, $detail = '') { global $PASS, $FAIL, $log; T($name, $cond, $detail); }

$pdo = $GLOBALS['pdo'];
$adminId = null;
$st = $pdo->query("SELECT id, Balance, maxbuyagent FROM user WHERE agent='n2' AND id != '0' ORDER BY id ASC LIMIT 1");
$row = $st->fetch(PDO::FETCH_ASSOC);
if (!$row) {
    TB('B0 نماینده n2 وجود دارد', false, 'NO n2 USER');
} else {
    $adminId = (int)$row['id'];
    $origBalance = (int)$row['Balance'];
    $origLimit = (int)$row['maxbuyagent'];
    TB('B0 نماینده یافت شد', true, "id=$adminId balance=$origBalance");

    // helper: خواندن تاز (prepared statement تاز در هر فراخوانی)
    $freshBal = function ($id) use ($pdo) {
        $q = $pdo->prepare("SELECT Balance, maxbuyagent FROM user WHERE id = :id");
        $q->execute([':id' => $id]);
        $r = $q->fetch(PDO::FETCH_ASSOC);
        return $r ?: ['Balance' => null, 'maxbuyagent' => null];
    };

    // snapshot ledger count
    $ledBefore = (int)$pdo->query("SELECT COUNT(*) FROM fx_debt_log")->fetchColumn();

    $pdo->beginTransaction();
    try {
        // B1: سقف از user.maxbuyagent خوانده می‌شود
        $pdo->prepare("UPDATE user SET maxbuyagent = 1000000 WHERE id = :id")->execute([':id' => $adminId]);
        $u = $freshBal($adminId);
        TB('B1 سقف فعال = maxbuyagent (1M)', nm_agent_purchase_limit(['maxbuyagent' => $u['maxbuyagent'], 'agent' => 'n2']) === 1000000, 'mba=' . $u['maxbuyagent']);

        // B2: افزایش موجودی اتمیک (base = 4,001,000)
        $admRow = nm_admin();
        TB('B2a nm_admin() = نماینده n2', (int)$admRow['id'] === $adminId);
        $out = nm_admin_apply_balance('increase', 5000000);
        $u = $freshBal($adminId);
        TB('B2 افزایش +۵M → balance=' . ($origBalance + 5000000), (int)$u['Balance'] === $origBalance + 5000000, 'bal=' . $u['Balance']);
        TB('B2b پیام نتیجه دارای موجودی بعد', strpos($out, 'موجودی بعد') !== false);
        $bal = (int)$u['Balance']; // 9,001,000

        // B3: کاهش 8M از 9,001,000 → 1,001,000 (هنوز مثبت، بدهی ۰) → allowed (اصلاح abs())
        $out = nm_admin_apply_balance('decrease', 8000000);
        $u = $freshBal($adminId);
        TB('B3 کاهش ۸M → balance=1,001,000 (مجاز، بدهی صفر)', (int)$u['Balance'] === 1001000, 'bal=' . $u['Balance']);

        // B4: کاهش 2,001,000 → balance = -1,000,000 (مرز دقیق) → allowed
        $out = nm_admin_apply_balance('decrease', 2001000);
        $u = $freshBal($adminId);
        TB('B4 کاهش ۲,۰۰۱,۰۰۰ → balance=-1,000,000 (مرز دقیق)', (int)$u['Balance'] === -1000000, 'bal=' . $u['Balance']);
        TB('B4a نمایش بدهکار در پیام «۱,۰۰۰,۰۰۰-»', strpos($out, '۱,۰۰۰,۰۰۰-') !== false);

        // B4b: کاهش ۱۰۰۰ تومانی دیگر → عبور یک تومانی از سقف → block
        $out = nm_admin_apply_balance('decrease', 1000);
        $u = $freshBal($adminId);
        TB('B4b کاهش ۱,۰۰۰ از -۱,۰۰۰,۰۰۰ → block (عبور یک تومانی)', (int)$u['Balance'] === -1000000 && strpos($out, 'امکان‌پذیر نیست') !== false, 'bal=' . $u['Balance']);

        // B5: خرید ۵۰۰k در بدهی ۱M با سقف ۱M → guard block
        $uArr = ['agent' => 'n2', 'Balance' => (int)$u['Balance'], 'maxbuyagent' => (int)$u['maxbuyagent']];
        $g = nm_agent_purchase_guard($uArr, 500000);
        TB('B5 خرید ۵۰۰k در بدهی ۱M با سقف ۱M → guard block', $g['ok'] === false, 'debt_after=' . $g['debt_after']);
        TB('B5a پیام مسدودی با ارقام فارسی', strpos($g['msg'], '۱,۵۰۰,۰۰۰') !== false, 'debt_after=' . $g['debt_after']);

        // B5b: قیمت price=0 → guard ok (pass-through)
        $g = nm_agent_purchase_guard($uArr, 0);
        TB('B5b price=0 → guard pass', $g['ok'] === true);

        // B6: کسر اتمیک — در مرز دقیق (balance=-1M, limit=1M): هر کسری > سقف → rowCount=0
        $r = nm_agent_debit($adminId, 1000, 1000000, 'TESTEDGE');
        TB('B6 debit 1k at -limit → blocked (rowCount=0)', $r['ok'] === false && $r['reason'] === 'debt_limit_exceeded', 'reason=' . $r['reason']);
        $u = $freshBal($adminId);
        TB('B6a balance untouched (-1M)', (int)$u['Balance'] === -1000000, 'bal=' . $u['Balance']);

        // B7: credit via nm_agent_compensate → balance -1M + 1.5M = +500k
        $ok = nm_agent_compensate($adminId, 1500000, 'TESTCOMP', 'refund');
        $u = $freshBal($adminId);
        TB('B7 compensate 1.5M → balance=+500k', $ok && (int)$u['Balance'] === 500000, 'bal=' . $u['Balance']);

        // B8: ledger rows created inside tx
        $ledNow = (int)$pdo->query("SELECT COUNT(*) FROM fx_debt_log WHERE invoice_ref IN ('TESTEDGE','TESTCOMP')")->fetchColumn();
        TB('B8 ledger: TESTCOMP recorded, TESTEDGE not (blocked)', $ledNow === 1, 'count=' . $ledNow);

        // B9: سقف بدهکاری apply (maxbuyagent)
        $out = nm_admin_set_debt_limit(15000000);
        $u = $freshBal($adminId);
        TB('B9 set limit 15M → maxbuyagent=15000000', (int)$u['maxbuyagent'] === 15000000, 'mba=' . $u['maxbuyagent']);
        TB('B9a پیام تنظیم سقف', strpos($out, '۱۵,۰۰۰,۰۰۰') !== false);

        // B10: set limit invalid (0) → no change
        $out = nm_admin_set_debt_limit(0);
        $u = $freshBal($adminId);
        TB('B10 set limit 0 → rejected, unchanged', strpos($out, 'نامعتبر') !== false && (int)$u['maxbuyagent'] === 15000000);

        // B11: purchase guard with new 15M limit (balance=+500k)
        $uArr2 = ['agent' => 'n2', 'Balance' => (int)$u['Balance'], 'maxbuyagent' => (int)$u['maxbuyagent']];
        $g = nm_agent_purchase_guard($uArr2, 14000000);
        TB('B11 خرید ۱۴M با سقف ۱۵M و موجودی ۵۰۰k → مجاز (debt=13.5M<=15M)', $g['ok'] === true, 'debt_after=' . $g['debt_after']);
        $g = nm_agent_purchase_guard($uArr2, 15500001);
        TB('B11a خرید ۱۵,۵۰۰,۰۰۱ → مسدود (debt=15,000,001 > 15M)', $g['ok'] === false, 'debt_after=' . $g['debt_after']);

        // B12: رندر listها (products/panels) — live read
        $prodTxt = nm_render_products();
        TB('B12 render products (live)', strpos($prodTxt, 'محصولات نماینده') !== false);
        $panelTxt = nm_render_panels();
        TB('B12a render panels (live)', strpos($panelTxt, 'پنل‌های نماینده') !== false);
        $discTxt = nm_render_discounts();
        TB('B12b render discounts (empty ok)', strpos($discTxt, 'تخفیف‌های نماینده') !== false);
        $invTxt = nm_render_invoices();
        TB('B12c render invoices (live)', strpos($invTxt, 'صورت‌حساب‌های نماینده') !== false);

        // B13: status render (آیدی با ارقام فارسی — مطابق STEP_2)
        $data = nm_build_manager_status($pdo, $adminId);
        $data['credit_limit'] = nm_agent_purchase_limit($uArr2);
        $statusTxt = nm_format_manager_status($data, ['mask_token' => true]);
        TB('B13 status render has owner id (ارقام فارسی)', strpos($statusTxt, nm_persian_digits((string)$adminId)) !== false);
        TB('B13a status render has bot status line', strpos($statusTxt, 'وضعیت ربات') !== false);

    } catch (Throwable $e) {
        TB('B-EXC', false, get_class($e) . ': ' . $e->getMessage());
    } finally {
        $pdo->rollBack();
    }

    // B14: verify ROLLBACK — zero footprint
    $u = $freshBal($adminId);
    $ledAfter = (int)$pdo->query("SELECT COUNT(*) FROM fx_debt_log WHERE invoice_ref IN ('TESTEDGE','TESTCOMP')")->fetchColumn();
    TB('B14 ROLLBACK: balance restored', (int)$u['Balance'] === $origBalance, 'bal=' . $u['Balance'] . ' orig=' . $origBalance);
    TB('B14a ROLLBACK: maxbuyagent restored', (int)$u['maxbuyagent'] === $origLimit, 'mba=' . $u['maxbuyagent'] . ' orig=' . $origLimit);
    TB('B14b ROLLBACK: ledger clean', $ledAfter === 0, 'count=' . $ledAfter);
    $ledTotal = (int)$pdo->query("SELECT COUNT(*) FROM fx_debt_log")->fetchColumn();
    TB('B14c ROLLBACK: ledger total unchanged', $ledTotal === $ledBefore, "now=$ledTotal before=$ledBefore");
}

echo implode("\n", $LOG) . "\n\n";
echo "نتیجه: PASS=$PASS FAIL=$FAIL\n";
exit($FAIL === 0 ? 0 : 1);
