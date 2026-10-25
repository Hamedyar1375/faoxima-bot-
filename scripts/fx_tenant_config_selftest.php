<?php
/**
 * fx_tenant_config_selftest.php — verify PaySetting/card_number/channels isolation.
 *
 *   docker exec faoxima-app-1 php /var/www/faoxima/scripts/fx_tenant_config_selftest.php
 */
error_reporting(E_ALL & ~E_DEPRECATED);

$mainMode = in_array('--main', $argv ?? [], true);
define('REFACTORED_LEGACY_ROOT', '/var/www/faoxima');
if (!$mainMode) {
    define('FX_RESELLER_ID', 6327424512);
    define('FX_RESELLER_TOKEN', 'selftest-token');
}

require_once REFACTORED_LEGACY_ROOT . '/re/rx/function/bootstrap.php';

$pdo = getDatabaseConnection();
if (!($pdo instanceof PDO)) {
    fwrite(STDERR, "DB unavailable\n");
    exit(1);
}

$pass = 0;
$fail = 0;
$check = static function ($label, $ok) use (&$pass, &$fail) {
    if ($ok) {
        $pass++;
        echo "PASS {$label}\n";
    } else {
        $fail++;
        echo "FAIL {$label}\n";
    }
};

$tenant = $mainMode ? 0 : (int) FX_RESELLER_ID;
$key = 'fx_tenant_selftest_key';
$tenantValue = 'tenant-' . bin2hex(random_bytes(3));
$mainValue = 'main-' . bin2hex(random_bytes(3));

$pdo->prepare("DELETE FROM PaySetting WHERE NamePay = ? AND agent IN (0, ?)")->execute([$key, $tenant]);

$check('agent id', fx_tenant_agent_id() === $tenant);
$check('scope sql', fx_tenant_sql_agent() === 'agent = ' . $tenant);
$check('scoped table', fx_tenant_scope_for_table('PaySetting') === $tenant);
$check('unscoped table', fx_tenant_scope_for_table('user') === null);

update('PaySetting', 'ValuePay', $mainMode ? $mainValue : $tenantValue, 'NamePay', $key);
$row = select('PaySetting', 'ValuePay', 'NamePay', $key, 'select', ['cache' => false]);
$check('write/read own row', is_array($row) && ($row['ValuePay'] ?? null) === ($mainMode ? $mainValue : $tenantValue));

$mainRow = $pdo->query("SELECT ValuePay FROM PaySetting WHERE NamePay = " . $pdo->quote($key) . " AND agent = 0")->fetchColumn();
$tenantRow = $pdo->query("SELECT ValuePay FROM PaySetting WHERE NamePay = " . $pdo->quote($key) . " AND agent = 6327424512")->fetchColumn();
if ($mainMode) {
    $check('main write did not touch tenant', $tenantRow === false);
} else {
    $check('tenant write did not touch main', $mainRow === false);
}

$tenantCards = $pdo->query("SELECT COUNT(*) FROM card_number WHERE agent = 6327424512")->fetchColumn();
$mainCards = $pdo->query("SELECT COUNT(*) FROM card_number WHERE agent = 0")->fetchColumn();
$check('tenant cards seeded', (int) $tenantCards > 0);
$check('main cards retained', (int) $mainCards > 0);

$mainCardId = (int) $pdo->query("SELECT id FROM card_number WHERE agent = 0 ORDER BY id ASC LIMIT 1")->fetchColumn();
if ($mainCardId > 0) {
    $check('foreign card rejected for tenant', $mainMode ? fx_tenant_row_owned('card_number', 'id', $mainCardId) : !fx_tenant_row_owned('card_number', 'id', $mainCardId));
}

$check('channels scoped table', fx_tenant_scope_for_table('channels') === $tenant);
$tempRemark = 'fx-selftest-' . bin2hex(random_bytes(3));
$pdo->prepare("INSERT INTO channels (agent, remark, linkjoin, link) VALUES (0, ?, 'https://t.me/+main', 'https://t.me/main')")->execute([$tempRemark]);
$pdo->prepare("INSERT INTO channels (agent, remark, linkjoin, link) VALUES (?, ?, 'https://t.me/+tenant', 'https://t.me/tenant')")->execute([6327424512, $tempRemark]);
$ownChannel = select('channels', 'link', 'remark', $tempRemark, 'select', ['cache' => false]);
$check('channel read is tenant scoped', is_array($ownChannel) && ($ownChannel['link'] ?? null) === ($mainMode ? 'https://t.me/main' : 'https://t.me/tenant'));
$pdo->prepare("DELETE FROM channels WHERE remark = ?")->execute([$tempRemark]);

$pdo->prepare("DELETE FROM PaySetting WHERE NamePay = ? AND agent IN (0, ?)")->execute([$key, $tenant]);

echo "\nPASS={$pass} FAIL={$fail}\n";
exit($fail === 0 ? 0 : 1);
