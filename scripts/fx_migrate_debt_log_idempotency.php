<?php
/**
 * fx_migrate_debt_log_idempotency.php — مهاجرت فاز ۱ (FX-DEBT-P1)
 *
 * افزودن ستون idempotency_key به fx_debt_log + ایندکس یکتا:
 *   ALTER TABLE fx_debt_log
 *     ADD COLUMN idempotency_key varchar(120) NULL,
 *     ADD UNIQUE KEY uq_fx_debt_log_idem (idempotency_key);
 *
 * پیش‌فرض: DRY-RUN (هیچ تغییری اعمال نمی‌شود). برای اعمال واقعی: --apply
 * نکته: ردیف‌های قدیمی NULL می‌مانند و در MySQL چند مقدار NULL در ایندکس یکتا مجاز است.
 * کد بدون این ستون هم کار می‌کند (مسیر legacy + dedupe مبتنی بر SELECT).
 *
 * اجرا (dry-run):
 *   docker exec -e DBH=db -e DBU=faoxima -e DBP=... -e DBN=faoxima \
 *     faoxima-app-1 php /var/www/faoxima/scripts/fx_migrate_debt_log_idempotency.php
 */
error_reporting(E_ALL & ~E_DEPRECATED);
$args = $argv ?? [];
$apply = in_array('--apply', $args, true);

$DBH = getenv('DBH'); $DBU = getenv('DBU'); $DBP = getenv('DBP'); $DBN = getenv('DBN') ?: 'faoxima';
if (!$DBH || !$DBU) {
    fwrite(STDERR, "env DBH/DBU/DBP/DBN لازم است\n");
    exit(1);
}
try {
    $pdo = new PDO("mysql:host=$DBH;dbname=$DBN;charset=utf8mb4", $DBU, $DBP, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (Throwable $e) {
    fwrite(STDERR, "اتصال ناموفق: " . $e->getMessage() . "\n");
    exit(1);
}

echo "دیتابیس: $DBN\n";
$has = (bool)$pdo->query("SHOW COLUMNS FROM fx_debt_log LIKE 'idempotency_key'")->fetch(PDO::FETCH_ASSOC);
$rows = (int)$pdo->query("SELECT COUNT(*) FROM fx_debt_log")->fetchColumn();
echo "ردیف‌های فعلی fx_debt_log: $rows\n";

if ($has) {
    echo "✅ ستون idempotency_key از قبل وجود دارد — کاری لازم نیست.\n";
    $idx = $pdo->query("SHOW INDEX FROM fx_debt_log WHERE Key_name = 'uq_fx_debt_log_idem'")->fetch(PDO::FETCH_ASSOC);
    echo ($idx ? "✅ ایندکس یکتا uq_fx_debt_log_idem موجود است.\n" : "⚠️  ایندکس یکتا وجود ندارد؛ دستور زیر را جداگانه بررسی کنید:\n   ALTER TABLE fx_debt_log ADD UNIQUE KEY uq_fx_debt_log_idem (idempotency_key);\n");
    exit(0);
}

$sql = "ALTER TABLE fx_debt_log
  ADD COLUMN idempotency_key varchar(120) NULL,
  ADD UNIQUE KEY uq_fx_debt_log_idem (idempotency_key)";

if (!$apply) {
    echo "— DRY-RUN — دستور پیشنهادی (اعمال نشد):\n  $sql\n";
    echo "برای اعمال: همین اسکریپت را با --apply اجرا کنید (پس از تأیید مالک؛ ابتدا بکاپ دیتابیس).\n";
    exit(0);
}

echo "در حال اعمال مهاجرت...\n";
$pdo->exec($sql);
$has2 = (bool)$pdo->query("SHOW COLUMNS FROM fx_debt_log LIKE 'idempotency_key'")->fetch(PDO::FETCH_ASSOC);
$idx2 = $pdo->query("SHOW INDEX FROM fx_debt_log WHERE Key_name = 'uq_fx_debt_log_idem'")->fetch(PDO::FETCH_ASSOC);
echo $has2 && $idx2 ? "✅ مهاجرت با موفقیت اعمال شد.\n" : "❌ مهاجرت ناقص است؛ بررسی دستی لازم است.\n";
exit($has2 && $idx2 ? 0 : 1);
