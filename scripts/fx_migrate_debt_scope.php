<?php
/**
 * fx_migrate_debt_scope.php — جداسازی دفتر حساب دو سیستم روی fx_debt_log
 * =====================================================================
 * FX-ACCOUNT (2026-10-17): fx_debt_log توسط دو سیستم نوشته می‌شود:
 *   - scope='bot'  : سیستم FX-DEBT ربات نمایندگی (botsaz.current_debt)
 *   - scope='main' : ماژول NM نماینده اصلی در ربات اصلی (user.Balance منفی)
 * این اسکریپت ستون scope را (idempotent) اضافه و ردیف‌های قدیمی NM را بک‌فیل می‌کند.
 *
 * اجرا (روی هاست):
 *   docker exec -i faoxima-app-1 php /var/www/faoxima/scripts/fx_migrate_debt_scope.php
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/../config.php';

$hasColumn = false;
$indexExists = false;
try {
    $q = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fx_debt_log' AND COLUMN_NAME = 'scope'");
    $hasColumn = (bool)$q->fetchColumn();
    $q = $pdo->query("SELECT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fx_debt_log' AND INDEX_NAME = 'idx_fx_debt_log_scope' LIMIT 1");
    $indexExists = (bool)$q->fetchColumn();
} catch (Throwable $e) {
    fwrite(STDERR, "schema check failed: " . $e->getMessage() . "\n");
    exit(1);
}

if (!$hasColumn) {
    try {
        $pdo->exec("ALTER TABLE fx_debt_log ADD COLUMN scope VARCHAR(10) NOT NULL DEFAULT 'bot' AFTER method");
        echo "✅ column scope added (default 'bot')\n";
    } catch (Throwable $e) {
        fwrite(STDERR, "ALTER failed: " . $e->getMessage() . "\n");
        exit(1);
    }
} else {
    echo "ℹ️ column scope already exists\n";
}

if (!$indexExists) {
    try {
        $pdo->exec("ALTER TABLE fx_debt_log ADD INDEX idx_fx_debt_log_scope (scope)");
        echo "✅ index idx_fx_debt_log_scope added\n";
    } catch (Throwable $e) {
        fwrite(STDERR, "index add failed: " . $e->getMessage() . "\n");
        exit(1);
    }
} else {
    echo "ℹ️ index already exists\n";
}

$marked = 0;
try {
    // ردیف‌های شناخته‌شدهٔ ماژول NM (nm_admin_apply_balance) — قدیمی‌ها پیش از افزودن ستون
    // با scope پیش‌فرض 'bot' ثبت شده بودند. الگوی قطعی: method='credit' + invoice_ref='admin_charge'
    $upd = $pdo->prepare("UPDATE fx_debt_log SET scope = 'main' WHERE scope = 'bot' AND method = 'credit' AND invoice_ref = 'admin_charge'");
    $upd->execute();
    $marked = $upd->rowCount();
} catch (Throwable $e) {
    fwrite(STDERR, "backfill failed: " . $e->getMessage() . "\n");
    exit(1);
}
echo "✅ backfilled nm rows to scope='main': {$marked}\n";

try {
    $rows = $pdo->query("SELECT scope, method, direction, COUNT(*) c FROM fx_debt_log GROUP BY scope, method, direction ORDER BY scope, method")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        echo "   {$r['scope']} | {$r['method']} | {$r['direction']} => {$r['c']}\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, "report failed: " . $e->getMessage() . "\n");
    exit(1);
}

echo "done.\n";
