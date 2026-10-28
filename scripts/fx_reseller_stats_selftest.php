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
$check = static function ($label, $ok, $note = '') use (&$pass, &$fail) {
    if ($ok) {
        $pass++;
        echo "PASS {$label}\n";
    } else {
        $fail++;
        echo "FAIL {$label}" . ($note !== '' ? " [{$note}]" : '') . "\n";
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

// === Time-filtered reseller stats (fx_reseller_stats.php) ===
require_once REFACTORED_LEGACY_ROOT . '/re/rx/function/fx_reseller_stats.php';

$check('stats keyboard is valid JSON', is_array(json_decode(fx_res_stats_keyboard(), true)));
$fxKb = json_decode(fx_res_stats_keyboard(), true);
$fxBtns = [];
foreach (($fxKb['inline_keyboard'] ?? []) as $fxRow) {
    foreach ($fxRow as $fxBtn) {
        $fxBtns[] = $fxBtn['callback_data'] ?? null;
    }
}
$check('keyboard has 8 filter buttons', count($fxBtns) === 8);
$check('keyboard buttons are the expected set', $fxBtns === [
    'stat_all_bot', 'hoursago_stat', 'today_stat', 'yesterday_stat',
    'month_current_stat', 'month_old_stat', 'view_stat_time', 'close_stat',
]);

$check('is_active recognizes stat callbacks', fx_res_stats_is_active('today_stat', '') === true);
$check('is_active recognizes date steps', fx_res_stats_is_active('', 'get_time_start') === true);
$check('is_active rejects unrelated callbacks', fx_res_stats_is_active('buy', '') === false);

$fxScopes = fx_res_stats_scopes();
$check('scopes fallback matches manual scope', $fxScopes === [$FXU_SCOPE, $FXI_SCOPE]);

$fxGlobalActive = (int) $pdo->query("SELECT COUNT(*) FROM invoice WHERE " . rx_stats_operational_service_predicate())->fetchColumn();
$fxGlobalExtend = (int) $pdo->query("SELECT COUNT(*) FROM service_other WHERE status = 'paid' AND type IN ('extend_user','extend_user_by_admin')")->fetchColumn();

$fxWindows = [
    'hour' => fx_res_stats_window('hour'),
    'today' => fx_res_stats_window('today'),
    'yesterday' => fx_res_stats_window('yesterday'),
    'month_current' => fx_res_stats_window('month_current'),
    'month_old' => fx_res_stats_window('month_old'),
];
$fxWindowOk = true;
foreach ($fxWindows as $fxWinName => $fxWin) {
    if ($fxWin === null || $fxWin['ts_from'] > $fxWin['ts_to'] || $fxWin['title'] === '' || $fxWin['dt_from'] > $fxWin['dt_to']) {
        $fxWindowOk = false;
        break;
    }
}
$check('all preset windows are valid', $fxWindowOk);

$fxCustom = fx_res_stats_window('custom', '2020/01/01', '2030/01/01');
$check('custom window full history is valid', $fxCustom !== null && $fxCustom['ts_from'] < $fxCustom['ts_to']);
$check('custom window rejects inverted range', fx_res_stats_window('custom', '2030/01/01', '2020/01/01') === null);
$fxWindows['custom_all'] = $fxCustom;

$fxCollectOk = true;
$fxCollectNotes = [];
foreach ($fxWindows as $fxWinName => $fxWin) {
    $fxM = fx_res_stats_collect($pdo, $fxWin, $FXU_SCOPE, $FXI_SCOPE);
    if (
        $fxM['orders_count'] < 0 || $fxM['orders_sum'] < 0
        || $fxM['active_count'] < 0 || $fxM['test_count'] < 0 || $fxM['users_new'] < 0
        || $fxM['extend_count'] < 0 || $fxM['extra_volume_count'] < 0
        || $fxM['extra_time_count'] < 0 || $fxM['change_location_count'] < 0
        || !is_array($fxM['payments'])
    ) {
        $fxCollectOk = false;
        $fxCollectNotes[] = $fxWinName . ':bad-metrics';
        continue;
    }
    if ($fxM['orders_count'] > $fxSalesCount || $fxM['orders_sum'] > $fxSalesSum) {
        $fxCollectOk = false;
        $fxCollectNotes[] = $fxWinName . ':orders>tenant';
    }
    if ($fxM['active_count'] > $fxGlobalActive) {
        $fxCollectOk = false;
        $fxCollectNotes[] = $fxWinName . ':active>global';
    }
    if ($fxM['extend_count'] > $fxGlobalExtend) {
        $fxCollectOk = false;
        $fxCollectNotes[] = $fxWinName . ':extend>global';
    }
    $fxPayStmtG = $pdo->prepare("SELECT COUNT(*) AS c FROM Payment_report WHERE payment_Status = 'paid' AND `time` BETWEEN :f AND :t");
    $fxPayStmtG->bindValue(':f', $fxWin['dt_from']);
    $fxPayStmtG->bindValue(':t', $fxWin['dt_to']);
    $fxPayStmtG->execute();
    $fxGlobalPaid = (int) ($fxPayStmtG->fetch(PDO::FETCH_ASSOC)['c'] ?? 0);
    $fxPayTotal = 0;
    foreach ($fxM['payments'] as $fxPr) {
        $fxPayTotal += (int) $fxPr['c'];
    }
    if ($fxPayTotal > $fxGlobalPaid) {
        $fxCollectOk = false;
        $fxCollectNotes[] = $fxWinName . ':payments>global';
    }
    $fxText = fx_res_stats_filtered_text($fxWin, $fxM);
    if (strpos($fxText, $fxWin['title']) === false) {
        $fxCollectOk = false;
        $fxCollectNotes[] = $fxWinName . ':title-missing';
    }
}
$check('collect() returns valid scoped metrics for all windows', $fxCollectOk, $fxCollectNotes ? implode(',', $fxCollectNotes) : '');

$fxAllTimeText = fx_res_stats_all_time_text($pdo, $FXU_SCOPE, $FXI_SCOPE);
$check('all-time reseller text renders', is_string($fxAllTimeText) && strpos($fxAllTimeText, 'آمار ربات نمایندگی') !== false);
$check('all-time reseller text is tenant-scoped footer', strpos($fxAllTimeText, 'فقط داده‌های همین ربات نمایندگی') !== false);

echo "\nTenant summary: users={$fxUsers} balance={$fxBalance} sales={$fxSalesCount} sum={$fxSalesSum} active={$fxActive} buyers={$fxBuyers} today={$fxTodaySum} panels={$fxPanels} cards={$fxCards} channels={$fxChannels} debt={$fxDebt}/{$fxCeiling}\n";
echo "PASS={$pass} FAIL={$fail}\n";
exit($fail === 0 ? 0 : 1);
