<?php
/**
 * fx_migrate_fx_debt_reservations_wallet_part.php — مهاجرت WALLET-FIRST (2026-10-17)
 *
 * افزودن ستون سهم کیف‌پول به جدول رزروها:
 *   ALTER TABLE fx_debt_reservations
 *     ADD COLUMN wallet_part bigint NOT NULL DEFAULT 0 AFTER amount;
 *
 * معنی: هنگام رزرو خرید عمده، ابتدا تا سقف اعتبار موجود (Balance>0) از کیف‌پول
 * کسر می‌شود (wallet_part) و فقط باقی‌مانده به بدهی اضافه می‌گردد. ردیف‌های
 * قدیمی مقدار 0 می‌گیرند و رفتارشان دقیقاً legacy می‌ماند.
 *
 * پیش‌فرض: DRY-RUN (هیچ تغییری اعمال نمی‌شود). برای اعمال واقعی: --apply
 * کد بدون این ستون هم کار می‌کند: fx_debt_wallet_column() مقدار false برمی‌گرداند
 * و همه مسیرها byte-for-byte همان رفتار قبلی را دارند.
 *
 * اجرا (dry-run) روی دیتابیس اصلی (فقط خواندنی، اتصال اعمال نمی‌شود):
 *   docker exec -e DBH=db -e DBU=faoxima -e DBP=... -e DBN=faoxima \
 *     faoxima-app-1 php /var/www/faoxima/scripts/fx_migrate_fx_debt_reservations_wallet_part.php
 * اعمال (فقط بعد از تأیید مالک + بکاپ):
 *   ... php /var/www/faoxima/scripts/fx_migrate_fx_debt_reservations_wallet_part.php --apply
 * برگشت:
 *   ALTER TABLE fx_debt_reservations DROP COLUMN wallet_part;
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
$hasTable = (bool)$pdo->query("SHOW TABLES LIKE 'fx_debt_reservations'")->fetchColumn();
if (!$hasTable) {
    fwrite(STDERR, "❌ جدول fx_debt_reservations وجود ندارد — ابتدا مهاجرت fx_migrate_fx_debt_reservations.php را اعمال کنید.\n");
    exit(1);
}
$hasCol = (bool)$pdo->query("SHOW COLUMNS FROM fx_debt_reservations LIKE 'wallet_part'")->fetch(PDO::FETCH_ASSOC);
if ($hasCol) {
    echo "✅ ستون wallet_part از قبل وجود دارد — کاری لازم نیست.\n";
    exit(0);
}

$sql = "ALTER TABLE fx_debt_reservations ADD COLUMN wallet_part bigint NOT NULL DEFAULT 0 AFTER amount";

if (!$apply) {
    echo "— DRY-RUN — دستور پیشنهادی (اعمال نشد):\n$sql\n";
    echo "برای اعمال: همین اسکریپت را با --apply اجرا کنید (پس از تأیید مالک؛ ابتدا بکاپ دیتابیس).\n";
    exit(0);
}

echo "در حال اعمال مهاجرت...\n";
$pdo->exec($sql);
$ok = (bool)$pdo->query("SHOW COLUMNS FROM fx_debt_reservations LIKE 'wallet_part'")->fetch(PDO::FETCH_ASSOC);
echo $ok ? "✅ مهاجرت با موفقیت اعمال شد.\n" : "❌ مهاجرت ناقص است؛ بررسی دستی لازم است.\n";
exit($ok ? 0 : 1);
