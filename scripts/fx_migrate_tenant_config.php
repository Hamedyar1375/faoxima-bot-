<?php
/**
 * fx_migrate_tenant_config.php — per-reseller payment/card/channel isolation.
 *
 * Adds a tenant key (`agent`) to PaySetting, card_number and channels.
 * Main bot rows stay at agent=0. Existing live reseller bots are seeded with a
 * private copy of the current main settings once, so enabling isolation does
 * not silently disable their payment methods on deploy.
 *
 * Default: dry-run. Apply with --apply.
 *
 *   docker exec faoxima-app-1 sh -c 'DBH="$DB_HOST" DBU="$DB_USER" DBP="$DB_PASS" DBN="$DB_NAME" \
 *     php /var/www/faoxima/scripts/fx_migrate_tenant_config.php --apply'
 */
error_reporting(E_ALL & ~E_DEPRECATED);
$args = $argv ?? [];
$apply = in_array('--apply', $args, true);

$DBH = getenv('DBH');
$DBU = getenv('DBU');
$DBP = getenv('DBP');
$DBN = getenv('DBN') ?: 'faoxima';
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

function fx_col_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1");
    $stmt->execute([$table, $column]);
    return (bool) $stmt->fetchColumn();
}

function fx_index_exists(PDO $pdo, string $table, string $index): bool
{
    $stmt = $pdo->prepare("SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1");
    $stmt->execute([$table, $index]);
    return (bool) $stmt->fetchColumn();
}

function fx_primary_columns(PDO $pdo, string $table): array
{
    $stmt = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = 'PRIMARY' ORDER BY ORDINAL_POSITION");
    $stmt->execute([$table]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

$statements = [];
if (!fx_col_exists($pdo, 'PaySetting', 'agent')) {
    $statements[] = "ALTER TABLE PaySetting ADD COLUMN agent BIGINT NOT NULL DEFAULT 0 AFTER NamePay";
}
$pk = fx_primary_columns($pdo, 'PaySetting');
if ($pk !== ['agent', 'NamePay']) {
    $statements[] = "ALTER TABLE PaySetting DROP PRIMARY KEY, ADD PRIMARY KEY (agent, NamePay)";
}
if (!fx_col_exists($pdo, 'card_number', 'agent')) {
    $statements[] = "ALTER TABLE card_number ADD COLUMN agent BIGINT NOT NULL DEFAULT 0 AFTER id";
}
if (fx_index_exists($pdo, 'card_number', 'cardnumber')) {
    $statements[] = "ALTER TABLE card_number DROP INDEX cardnumber";
}
if (!fx_index_exists($pdo, 'card_number', 'uniq_card_agent')) {
    $statements[] = "ALTER TABLE card_number ADD UNIQUE KEY uniq_card_agent (agent, cardnumber)";
}
if (!fx_col_exists($pdo, 'channels', 'agent')) {
    $statements[] = "ALTER TABLE channels ADD COLUMN agent BIGINT NOT NULL DEFAULT 0 AFTER id";
}
if (!fx_index_exists($pdo, 'channels', 'idx_channels_agent')) {
    $statements[] = "ALTER TABLE channels ADD KEY idx_channels_agent (agent)";
}

$resellerIds = [];
$stmt = $pdo->query("SELECT DISTINCT CAST(id_user AS UNSIGNED) FROM botsaz WHERE id_user REGEXP '^[0-9]+$' AND CAST(id_user AS UNSIGNED) > 0");
foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $rid) {
    $resellerIds[] = (int) $rid;
}
$resellerIds = array_values(array_unique($resellerIds));

echo "Database: {$DBN}\n";
echo "Reseller ids: " . (empty($resellerIds) ? '(none)' : implode(',', $resellerIds)) . "\n";
foreach ($statements as $sql) {
    echo "[schema] {$sql}\n";
}

if (!$apply) {
    echo "DRY-RUN — add --apply to execute.\n";
    exit(0);
}

foreach ($statements as $sql) {
    $pdo->exec($sql);
}

if (!empty($resellerIds)) {
    $payCopy = $pdo->prepare("INSERT IGNORE INTO PaySetting (agent, NamePay, ValuePay)
        SELECT ?, NamePay, ValuePay FROM PaySetting
        WHERE agent = 0 AND NamePay NOT LIKE 'card_cycle_%'");
    $cardCopy = $pdo->prepare("INSERT IGNORE INTO card_number (agent, cardnumber, namecard, is_active, is_visible, created_at)
        SELECT ?, cardnumber, namecard, is_active, is_visible, created_at FROM card_number
        WHERE agent = 0 AND NOT EXISTS (
            SELECT 1 FROM (SELECT agent FROM card_number WHERE agent = ?) x
        )");
    $channelCopy = $pdo->prepare("INSERT INTO channels (agent, remark, linkjoin, link)
        SELECT ?, remark, linkjoin, link FROM channels
        WHERE agent = 0 AND NOT EXISTS (
            SELECT 1 FROM (SELECT agent FROM channels WHERE agent = ?) y
        )");

    foreach ($resellerIds as $rid) {
        $payCopy->execute([$rid]);
        $cardCopy->execute([$rid, $rid]);
        $channelCopy->execute([$rid, $rid]);
        echo "[seed] agent={$rid}: pay+cards+channels copied if empty\n";
    }
}

$verify = $pdo->query("SELECT agent, COUNT(*) FROM PaySetting GROUP BY agent ORDER BY agent")->fetchAll(PDO::FETCH_KEY_PAIR);
echo "PaySetting rows by agent: " . json_encode($verify, JSON_UNESCAPED_UNICODE) . "\n";
echo "APPLIED.\n";
