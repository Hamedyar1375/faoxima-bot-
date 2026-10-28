<?php
/**
 * fx_migrate_fx_debt_log_audit.php — FX-ACCOUNT (2026-10-27)
 *
 * Adds auditable before/after columns to fx_debt_log:
 *   actor_id       BIGINT NULL      — who performed the manual operation (Telegram id / 0=system)
 *   reason         VARCHAR(191) NULL— explicit reason / reference text
 *   balance_before BIGINT NULL      — signed account balance B before the movement
 *   balance_after  BIGINT NULL      — signed account balance B after the movement
 *   wallet_after   BIGINT NULL      — wallet (user.Balance) after the movement
 *
 * Existing rows keep NULL (no historical data is rewritten — audit-safe).
 * The code works without this migration (fx_debt_audit_columns() guards it).
 *
 * DRY-RUN by default; apply with --apply:
 *   docker exec -e DBH=faoxima-db-1 -e DBU=faoxima -e DBP=... -e DBN=faoxima \
 *     faoxima-app-1 php /var/www/faoxima/scripts/fx_migrate_fx_debt_log_audit.php --apply
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
$rows = (int)$pdo->query("SELECT COUNT(*) FROM fx_debt_log")->fetchColumn();
echo "ردیف‌های فعلی fx_debt_log: $rows\n";

$defs = [
    'actor_id'       => "ADD COLUMN actor_id BIGINT NULL",
    'reason'         => "ADD COLUMN reason VARCHAR(191) NULL",
    'balance_before' => "ADD COLUMN balance_before BIGINT NULL",
    'balance_after'  => "ADD COLUMN balance_after BIGINT NULL",
    'wallet_after'   => "ADD COLUMN wallet_after BIGINT NULL",
];

$missing = [];
foreach ($defs as $col => $ddl) {
    $has = (bool)$pdo->query("SHOW COLUMNS FROM fx_debt_log LIKE '" . $col . "'")->fetch(PDO::FETCH_ASSOC);
    echo ($has ? "✅ ستون {$col} موجود است\n" : "➕ ستون {$col} اضافه می‌شود\n");
    if (!$has) {
        $missing[] = $ddl;
    }
}

if (!$missing) {
    echo "✅ همهٔ ستون‌های ممیزی از قبل وجود دارند — کاری لازم نیست.\n";
    exit(0);
}

$sql = "ALTER TABLE fx_debt_log " . implode(", ", $missing);
if (!$apply) {
    echo "\n[DRY-RUN] دستور زیر اجرا می‌شود:\n   " . $sql . "\nبرای اعمال واقعی: --apply\n";
    exit(0);
}

$pdo->exec($sql);
echo "\n✅ اجرا شد.\n";
foreach (array_keys($defs) as $col) {
    $has = (bool)$pdo->query("SHOW COLUMNS FROM fx_debt_log LIKE '" . $col . "'")->fetch(PDO::FETCH_ASSOC);
    echo ($has ? "✅" : "❌") . " {$col}\n";
}
echo "ردیف‌های قدیمی دست‌نخورده (NULL) — بدون بازنویسی تاریخچه.\n";
