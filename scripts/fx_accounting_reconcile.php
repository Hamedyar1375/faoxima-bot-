<?php
/**
 * fx_accounting_reconcile.php — گزارش تراز حسابداری ربات نمایندگی (فقط‌خواندنی)
 *
 * اجرا:
 *   docker exec -e DBH=faoxima-db -e DBU=faoxima -e DBP=k2IZahTmNexPe06fo4DS -e DBN=faoxima \
 *     faoxima-app-1 php /var/www/faoxima/scripts/fx_accounting_reconcile.php [reseller_id]
 *
 * خروجی: برای هر نماینده —
 *   ۱) تراز دفتر scope='bot': افتتاحیهٔ ضمنی + in − out = بدهی فعلی (با فرق و علت‌یابی)
 *   ۲) پیوستگی ردیف‌ها (debt_after هر ردیف باید = running sum باشد؛ شکست‌ها = ریست بدون‌لاگ)
 *   ۳) تفکیک روش‌های درآمدی در دوره
 *   ۴) تطبیق فاکتورهای تنت ↔ ردیف‌های دفتر (خرید/تمدید/حجم/زمان/چانژلوکیشن)
 *   ۵) scope='main' (دفتر NM ربات اصلی)
 *   ۶) کیف‌پول مشتریان تنت: wallet_ledger ↔ Balance (افتتاحیهٔ ضمنی)
 * هیچ کوئری نوشتنی در این اسکریپت وجود ندارد.
 */
error_reporting(E_ALL & ~E_DEPRECATED);

$DBH = getenv('DBH'); $DBU = getenv('DBU'); $DBP = getenv('DBP'); $DBN = getenv('DBN');
if (!$DBH || !$DBU || !$DBN) {
    fwrite(STDERR, "env DBH/DBU/DBP/DBN تنظیم نیست\n");
    exit(2);
}
try {
    $pdo = new PDO("mysql:host=$DBH;dbname=$DBN;charset=utf8mb4", $DBU, $DBP, [
        PDO::ATTR_ERRMODE          => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (Throwable $e) {
    fwrite(STDERR, "PDO: " . $e->getMessage() . "\n");
    exit(2);
}

$onlyRid = isset($argv[1]) ? preg_replace('/\D/', '', (string)$argv[1]) : '';

function fmt($n) { return number_format((int)$n); }

$GATEWAY_METHODS = ['purchase_gateway', 'renewal_gateway', 'extra_volume_gateway', 'extra_time_gateway'];
$INVOICE_METHODS = ['purchase', 'bulk_purchase', 'renewal', 'extra_volume', 'extra_time', 'change_location'];

$sql = "SELECT * FROM botsaz";
if ($onlyRid !== '') {
    $sql .= " WHERE id_user = " . $pdo->quote($onlyRid);
}
$resellers = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
if (!$resellers) {
    echo "⚠️  نماینده‌ای در botsaz پیدا نشد.\n";
    exit(0);
}

$globalDiff = 0;
foreach ($resellers as $rs) {
    $rid = (string)$rs['id_user'];
    echo "\n╔══════════════════════════════════════════════════════════════\n";
    echo "║ نماینده: {$rid} | mode=" . ($rs['debt_mode'] ?? 'balance') . " | ceiling=" . fmt($rs['debt_ceiling'] ?? 0) . " | current_debt=" . fmt($rs['current_debt'] ?? 0) . "\n";
    echo "╚══════════════════════════════════════════════════════════════\n";

    $stmt = $pdo->prepare("SELECT id, amount, direction, method, invoice_ref, debt_after, scope, time FROM fx_debt_log WHERE reseller_id = :rid AND scope = 'bot' ORDER BY id ASC");
    $stmt->execute([':rid' => $rid]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $sumIn = 0; $sumOut = 0; $running = 0; $breaks = [];
    $byMethod = [];
    foreach ($rows as $r) {
        $amt = (int)$r['amount'];
        if ($r['direction'] === 'in') {
            $sumIn += $amt; $running += $amt;
            $m = (string)$r['method'];
            if (!isset($byMethod[$m])) $byMethod[$m] = [0, 0];
            $byMethod[$m][0]++;
            $byMethod[$m][1] += $amt;
        } else {
            $sumOut += $amt; $running -= $amt;
        }
        if ((int)$r['debt_after'] !== $running) {
            $breaks[] = "id={$r['id']} time={$r['time']} method={$r['method']} debt_after=" . fmt($r['debt_after']) . " expected=" . fmt($running);
            $running = (int)$r['debt_after'];
        }
    }
    $expectedOpening = (int)($rs['current_debt'] ?? 0) - ($sumIn - $sumOut);
    echo "\n— ۱) تراز دفتر (scope=bot) — ردیف‌ها: " . count($rows) . "\n";
    echo "   in=" . fmt($sumIn) . " | out=" . fmt($sumOut) . " | (in-out)=" . fmt($sumIn - $sumOut) . "\n";
    echo "   current_debt (botsaz)=" . fmt($rs['current_debt'] ?? 0) . "\n";
    echo "   افتتاحیهٔ ضمنی لازم = " . fmt($expectedOpening) . ($expectedOpening !== 0 ? "  ⚠️ (اگر صفر نباشد یعنی افتتاحیه/ریستِ ثبت‌نشده وجود دارد)" : "  ✅ (دفتر خودکفا)") . "\n";
    if ($expectedOpening !== 0) $globalDiff++;

    echo "\n— ۲) پیوستگی ردیف‌ها —\n";
    if (!$breaks) {
        echo "   ✅ پیوسته — هیچ شکستی بین debt_after و جمع تجمعی نیست.\n";
    } else {
        echo "   ⚠️ " . count($breaks) . " شکست (ریست/اصلاح بدون ردیف دفتر):\n";
        foreach (array_slice($breaks, 0, 20) as $b) echo "      • $b\n";
        if (count($breaks) > 20) echo "      … و " . (count($breaks) - 20) . " مورد دیگر\n";
    }

    echo "\n— ۳) تفکیک روش‌های in —\n";
    ksort($byMethod);
    if (!$byMethod) echo "   (خالی)\n";
    foreach ($byMethod as $m => $v) {
        echo "   " . str_pad($m, 26) . " x" . str_pad((string)$v[0], 4) . " = " . fmt($v[1]) . " تومان\n";
    }

    echo "\n— ۴) تطبیق فاکتورهای تنت ↔ دفتر —\n";
    $rtok = '';
    try {
        $s2 = $pdo->prepare("SELECT bot_token FROM botsaz WHERE id_user = :rid LIMIT 1");
        $s2->execute([':rid' => $rid]);
        $rtok = (string)$s2->fetchColumn();
    } catch (Throwable $e) {}
    if ($rtok === '') {
        echo "   (بدون توکن — تطبیق فاکتور رد شد)\n";
    } else {
        $s3 = $pdo->prepare("SELECT COUNT(*) FROM invoice i JOIN user u ON u.id = i.id_user WHERE (u.agent = :rid OR u.bottype = :rtok) AND i.Status = 'active'");
        $s3->execute([':rid' => $rid, ':rtok' => $rtok]);
        echo "   فاکتورهای active تنت: " . fmt($s3->fetchColumn()) . "\n";

        $orphans = 0; $checked = 0;
        foreach ($rows as $r) {
            $m = (string)$r['method'];
            if ($r['direction'] !== 'in' || $r['invoice_ref'] === '') continue;
            if ($m === 'adjustment') continue;
            $checked++;
            if (in_array($m, $GATEWAY_METHODS, true)) {
                $s4 = $pdo->prepare("SELECT 1 FROM Payment_report WHERE id_order = :ref LIMIT 1");
                $s4->execute([':ref' => $r['invoice_ref']]);
            } else {
                $s4 = $pdo->prepare("SELECT 1 FROM invoice WHERE id_invoice = :ref LIMIT 1");
                $s4->execute([':ref' => $r['invoice_ref']]);
            }
            if ($s4->fetchColumn() === false) {
                $orphans++;
                echo "   ⚠️ ردیف دفتر بدون مرجع: id={$r['id']} method={$m} ref={$r['invoice_ref']}\n";
            }
        }
        echo "   " . ($orphans === 0 ? "✅" : "⚠️") . " مراجع بررسی‌شده: {$checked} | بدون مرجع: {$orphans}\n";
    }

    echo "\n— ۵) دفتر scope=main (NM ربات اصلی) —\n";
    $s5 = $pdo->prepare("SELECT COUNT(*) c, COALESCE(SUM(CASE WHEN direction='in' THEN amount ELSE 0 END),0) i, COALESCE(SUM(CASE WHEN direction='out' THEN amount ELSE 0 END),0) o FROM fx_debt_log WHERE reseller_id = :rid AND scope = 'main'");
    $s5->execute([':rid' => $rid]);
    $m5 = $s5->fetch(PDO::FETCH_ASSOC);
    echo "   ردیف‌ها: " . fmt($m5['c']) . " | in=" . fmt($m5['i']) . " | out=" . fmt($m5['o']) . " | خالص=" . fmt((int)$m5['i'] - (int)$m5['o']) . "\n";

    echo "\n— ۶) کیف‌پول مشتریان تنت (wallet_ledger ↔ Balance) —\n";
    if ($rtok === '') {
        echo "   (بدون توکن — رد شد)\n";
    } else {
        $s6 = $pdo->prepare("SELECT u.id, u.Balance, COALESCE(wl.cr,0) cr, COALESCE(wl.db,0) db FROM user u LEFT JOIN (SELECT id_user, SUM(CASE WHEN direction='credit' THEN amount ELSE 0 END) cr, SUM(CASE WHEN direction='debit' THEN amount ELSE 0 END) db FROM wallet_ledger GROUP BY id_user) wl ON wl.id_user = u.id WHERE (u.agent = :rid OR u.bottype = :rtok)");
        $s6->execute([':rid' => $rid, ':rtok' => $rtok]);
        $users6 = $s6->fetchAll(PDO::FETCH_ASSOC);
        echo "   کاربران تنت: " . count($users6) . "\n";
        foreach ($users6 as $u6) {
            $impliedOpening = (int)$u6['Balance'] - (int)$u6['cr'] + (int)$u6['db'];
            echo "      user {$u6['id']}: Balance=" . fmt($u6['Balance']) . " | ledger cr=" . fmt($u6['cr']) . " db=" . fmt($u6['db']) . " | افتتاحیهٔ ضمنی=" . fmt($impliedOpening) . "\n";
        }
    }
}

echo "\n══════════════════════════════════════════════════════════════\n";
echo ($globalDiff === 0 ? "✅ همهٔ دفترها خودکفا (افتتاحیهٔ ضمنی صفر).\n" : "⚠️ {$globalDiff} نماینده با افتتاحیهٔ ضمنی غیرصفر — ردیف‌های تاریخی ثبت‌نشده (فقط گزارش، بدون تغییر داده).\n");
exit(0);
