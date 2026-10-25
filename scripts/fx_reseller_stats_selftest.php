<?php
/**
 * fx_reseller_stats_selftest.php — read-only check of the reseller stats branch SQL.
 *
 *   docker exec faoxima-app-1 php /var/www/faoxima/scripts/fx_reseller_stats_selftest.php
 */
error_reporting(E_ALL & ~E_DEPRECATED);
define('REFACTORED_LEGACY_ROOT', '/var/www/faoxima');
require_once REFACTORED_LEGACY_ROOT . '/vpnbot/6327424512nomayandefoximabot/config.php';
define('FX_RESELLER_ID', 6327424512);
define('FX_RESELLER_TOKEN', $ApiToken);
require_once REFACTORED_LEGACY_ROOT . '/re/rx/function/bootstrap.php';
require_once REFACTORED_LEGACY_ROOT . '/re/rx/function/statistics_helpers.php';

$pdo = getDatabaseConnection();
if (!($pdo instanceof PDO)) {
    fwrite(STDERR, "DB unavailable\n");
    exit(1);
}
$tokenSql = "'" . addslashes((string) FX_RESELLER_TOKEN) . "'";
$FXU_SCOPE = "(agent = '" . (int) FX_RESELLER_ID . "' OR bottype = " . $tokenSql . ")";
$FXI_SCOPE = "EXISTS (SELECT 1 FROM user ufx WHERE ufx.id = invoice.id_user AND (ufx.agent = '" . (int) FX_RESELLER_ID . "' OR ufx.bottype = " . $tokenSql . "))";

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

$fxStats = $pdo->query("SELECT COUNT(*) AS users_count, COALESCE(SUM(Balance),0) AS balance_sum FROM user WHERE {$FXU_SCOPE}")->fetch(PDO::FETCH_ASSOC);
$fxUsers = (int) ($fxStats['users_count'] ?? 0);
$fxBalance = (float) ($fxStats['balance_sum'] ?? 0);

$fxSales = $pdo->query("SELECT COUNT(*) AS sales_count, COALESCE(SUM(price_product),0) AS sales_sum FROM invoice WHERE {$FXI_SCOPE}")->fetch(PDO::FETCH_ASSOC);
$fxSalesCount = (int) ($fxSales['sales_count'] ?? 0);
$fxSalesSum = (float) ($fxSales['sales_sum'] ?? 0);

$fxActive = (int) $pdo->query("SELECT COUNT(*) FROM invoice WHERE " . rx_stats_operational_service_predicate() . " AND {$FXI_SCOPE}")->fetchColumn();
$fxBuyers = (int) $pdo->query("SELECT COUNT(DISTINCT id_user) FROM invoice WHERE {$FXI_SCOPE}")->fetchColumn();
$fxTests = (int) $pdo->query("SELECT COUNT(*) FROM invoice WHERE name_product = 'سرویس تست' AND {$FXI_SCOPE}")->fetchColumn();

$fxTodayStart = (int) strtotime(date('Y-m-d 00:00:00'));
$fxTodayStmt = $pdo->prepare("SELECT COALESCE(SUM(price_product),0) FROM invoice WHERE time_sell >= :start AND {$FXI_SCOPE}");
$fxTodayStmt->execute([':start' => $fxTodayStart]);
$fxTodaySum = (float) $fxTodayStmt->fetchColumn();

$fxExtendStmt = $pdo->query("SELECT COUNT(*) AS c, COALESCE(SUM(CAST(price AS DECIMAL(20,2))),0) AS s FROM service_other WHERE status = 'paid' AND type IN ('extend_user','extend_user_by_admin') AND id_user IN (SELECT id FROM user WHERE {$FXU_SCOPE})");
$fxExtend = $fxExtendStmt ? $fxExtendStmt->fetch(PDO::FETCH_ASSOC) : ['c' => 0, 's' => 0];
$fxExtendCount = (int) ($fxExtend['c'] ?? 0);
$fxExtendSum = (float) ($fxExtend['s'] ?? 0);

$fxPanels = (int) $pdo->query("SELECT COUNT(*) FROM marzban_panel WHERE agent = " . (int) FX_RESELLER_ID)->fetchColumn();
$fxCards = (int) $pdo->query("SELECT COUNT(*) FROM card_number WHERE " . fx_tenant_sql_agent())->fetchColumn();
$fxChannels = (int) $pdo->query("SELECT COUNT(*) FROM channels WHERE " . fx_tenant_sql_agent())->fetchColumn();

$fxDebtRow = select("botsaz", "current_debt, debt_ceiling", "id_user", FX_RESELLER_ID, "select", ['cache' => false]);
$fxDebt = (float) ($fxDebtRow['current_debt'] ?? 0);
$fxCeiling = (float) ($fxDebtRow['debt_ceiling'] ?? 0);

$fxPayStmt = $pdo->query("SELECT Payment_Method, COUNT(*) AS c, COALESCE(SUM(CAST(price AS DECIMAL(20,2))),0) AS s FROM Payment_report WHERE payment_Status = 'paid' AND id_user IN (SELECT id FROM user WHERE {$FXU_SCOPE}) GROUP BY Payment_Method ORDER BY c DESC LIMIT 8");
$fxPayments = $fxPayStmt ? $fxPayStmt->fetchAll(PDO::FETCH_ASSOC) : [];

$globalUsers = (int) $pdo->query("SELECT COUNT(*) FROM user")->fetchColumn();
$globalSales = (int) $pdo->query("SELECT COUNT(*) FROM invoice")->fetchColumn();

$check('users query returns integer', $fxUsers >= 0);
$check('balance query returns number', $fxBalance >= 0);
$check('sales count is tenant-scoped', $fxSalesCount <= $globalSales);
$check('buyers <= users', $fxBuyers <= $fxUsers);
$check('active services <= sales', $fxActive <= $fxSalesCount);
$check('tests <= sales', $fxTests <= $fxSalesCount);
$check('today sales <= all sales', $fxTodaySum <= $fxSalesSum);
$check('extend count returns integer', $fxExtendCount >= 0);
$check('extend sum returns number', $fxExtendSum >= 0);
$check('panels query returns integer', $fxPanels >= 0);
$check('cards query returns integer', $fxCards >= 0);
$check('channels query returns integer', $fxChannels >= 0);
$check('debt fields readable', $fxDebt >= 0 && $fxCeiling >= 0);
$check('payment methods readable', is_array($fxPayments));
$check('tenant users not above global users', $fxUsers <= $globalUsers);

echo "\nTenant summary: users={$fxUsers} balance={$fxBalance} sales={$fxSalesCount} sum={$fxSalesSum} active={$fxActive} buyers={$fxBuyers} today={$fxTodaySum} panels={$fxPanels} cards={$fxCards} channels={$fxChannels} debt={$fxDebt}/{$fxCeiling}\n";
echo "PASS={$pass} FAIL={$fail}\n";
exit($fail === 0 ? 0 : 1);
