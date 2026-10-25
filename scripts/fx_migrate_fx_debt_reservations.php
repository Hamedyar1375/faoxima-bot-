<?php
/**
 * fx_migrate_fx_debt_reservations.php — مهاجرت فاز ۲ (FX-DEBT-P2)
 *
 * ساخت جدول رزروهای بدهی (یک ردیف به‌ازای هر تلاش خرید/تمدید):
 *   CREATE TABLE fx_debt_reservations (
 *     ref varchar(191) NOT NULL,          -- کلید یکتای تلاش (id_invoice#vx…)
 *     reseller_id bigint NOT NULL,
 *     amount bigint NOT NULL DEFAULT 0,
 *     state enum('reserved','committed','released') NOT NULL DEFAULT 'reserved',
 *     created_at datetime NOT NULL,
 *     updated_at datetime NOT NULL,
 *     PRIMARY KEY (ref),
 *     KEY idx_fx_debt_res (reseller_id, state, created_at)
 *   ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
 *
 * پیش‌فرض: DRY-RUN (هیچ تغییری اعمال نمی‌شود). برای اعمال واقعی: --apply
 * کد بدون این جدول هم کار می‌کند: fx_debt_reserve بدون ref (یا وقتی جدول موجود
 * نباشد) به مسیر legacy برمی‌گردد و commit/release فقط از ledger استفاده می‌کنند.
 *
 * اجرا (dry-run):
 *   docker exec -e DBH=db -e DBU=faoxima -e DBP=... -e DBN=faoxima \
 *     faoxima-app-1 php /var/www/faoxima/scripts/fx_migrate_fx_debt_reservations.php
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
$has = (bool)$pdo->query("SHOW TABLES LIKE 'fx_debt_reservations'")->fetchColumn();
if ($has) {
    $rows = (int)$pdo->query("SELECT COUNT(*) FROM fx_debt_reservations")->fetchColumn();
    $pend = (int)$pdo->query("SELECT COUNT(*) FROM fx_debt_reservations WHERE state = 'reserved'")->fetchColumn();
    echo "✅ جدول fx_debt_reservations از قبل وجود دارد (ردیف‌ها: {$rows} — در انتظار: {$pend}) — کاری لازم نیست.\n";
    exit(0);
}

$sql = "CREATE TABLE IF NOT EXISTS fx_debt_reservations (
  ref varchar(191) NOT NULL,
  reseller_id bigint NOT NULL,
  amount bigint NOT NULL DEFAULT 0,
  state enum('reserved','committed','released') NOT NULL DEFAULT 'reserved',
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY (ref),
  KEY idx_fx_debt_res (reseller_id, state, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

if (!$apply) {
    echo "— DRY-RUN — دستور پیشنهادی (اعمال نشد):\n$sql\n";
    echo "برای اعمال: همین اسکریپت را با --apply اجرا کنید (پس از تأیید مالک؛ ابتدا بکاپ دیتابیس).\n";
    exit(0);
}

echo "در حال اعمال مهاجرت...\n";
$pdo->exec($sql);
$has2 = (bool)$pdo->query("SHOW TABLES LIKE 'fx_debt_reservations'")->fetchColumn();
$idx2 = (bool)$pdo->query("SHOW INDEX FROM fx_debt_reservations WHERE Key_name = 'idx_fx_debt_res'")->fetch(PDO::FETCH_ASSOC);
echo $has2 && $idx2 ? "✅ مهاجرت با موفقیت اعمال شد.\n" : "❌ مهاجرت ناقص است؛ بررسی دستی لازم است.\n";
exit($has2 && $idx2 ? 0 : 1);
