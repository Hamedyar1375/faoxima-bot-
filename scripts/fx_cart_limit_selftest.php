<?php

/**
 * fx_cart_limit_selftest.php — isolated checks for cart-to-cart deposit limits
 * and authenticated tenant resolution.
 *
 * Runs only against the dedicated test database (default: faoxima_botcap_test).
 * The production faoxima database is never modified by this script.
 *
 *   docker compose exec -T app php /var/www/faoxima/scripts/fx_cart_limit_selftest.php
 */

declare(strict_types=1);

error_reporting(E_ALL & ~E_DEPRECATED);
date_default_timezone_set('Asia/Tehran');

define('REFACTORED_LEGACY_ROOT', dirname(__DIR__));

require_once REFACTORED_LEGACY_ROOT . '/config.php';
require_once REFACTORED_LEGACY_ROOT . '/function.php';

$testDbName = getenv('FX_TEST_DB') ?: 'faoxima_botcap_test';
$dsn = 'mysql:host=' . (string) ($GLOBALS['dbhost'] ?? 'db') . ';dbname=' . $testDbName . ';charset=utf8mb4';
$pdoTest = new PDO($dsn, (string) ($GLOBALS['usernamedb'] ?? ''), (string) ($GLOBALS['passworddb'] ?? ''), [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);

$GLOBALS['pdo'] = $pdoTest;
$pdo = $pdoTest;

$pass = 0;
$fail = 0;
$check = static function (string $label, bool $ok, string $note = '') use (&$pass, &$fail): void {
    if ($ok) {
        $pass++;
        echo "PASS {$label}\n";
        return;
    }
    $fail++;
    echo "FAIL {$label}" . ($note !== '' ? " [{$note}]" : '') . "\n";
};

$check('test DB is isolated', $testDbName !== 'faoxima', $testDbName);
$check('global PDO points to isolated test DB', getDatabaseConnection() === $pdoTest);

$pdoTest->exec('DROP TABLE IF EXISTS PaySetting');
$pdoTest->exec("CREATE TABLE PaySetting (
    NamePay varchar(500) NOT NULL,
    agent bigint NOT NULL DEFAULT 0,
    ValuePay text NOT NULL,
    PRIMARY KEY (NamePay, agent)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdoTest->exec('DROP TABLE IF EXISTS botsaz');
$pdoTest->exec("CREATE TABLE botsaz (
    id bigint NOT NULL AUTO_INCREMENT,
    id_user varchar(64) NOT NULL,
    username varchar(255) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_id_user (id_user)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdoTest->exec('DROP TABLE IF EXISTS setting');
$pdoTest->exec("CREATE TABLE setting (
    id bigint NOT NULL AUTO_INCREMENT,
    name varchar(255) NULL,
    redis_enabled varchar(20) NULL DEFAULT '0',
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$seed = static function (string $name, int $agent, string $value) use ($pdoTest): void {
    $stmt = $pdoTest->prepare('INSERT INTO PaySetting (NamePay, agent, ValuePay) VALUES (?, ?, ?)');
    $stmt->execute([$name, $agent, $value]);
};

$tenantId = 6327424512;
$mainBalance = json_encode(['f' => '1000000', 'n' => '1000000', 'n2' => '1000000'], JSON_UNESCAPED_UNICODE);
$tenantBalance = json_encode(['f' => '10000000', 'n' => '1000000', 'n2' => '1000000'], JSON_UNESCAPED_UNICODE);
$minJson = json_encode(['f' => '20000', 'n' => '20000', 'n2' => '20000'], JSON_UNESCAPED_UNICODE);
$seed('maxbalance', 0, $mainBalance);
$seed('maxbalance', $tenantId, $tenantBalance);
$seed('minbalance', 0, $minJson);
$seed('minbalance', $tenantId, $minJson);
$seed('maxbalancecart', 0, '1000000');
$seed('maxbalancecart', $tenantId, '5000000');
$seed('minbalancecart', 0, '20000');
$seed('minbalancecart', $tenantId, '20000');
$seed('maxbalancezarinpal', 0, '1000000');
$seed('maxbalancezarinpal', $tenantId, '1000000');
$seed('minbalancezarinpal', 0, '20000');
$seed('minbalancezarinpal', $tenantId, '20000');
$pdoTest->prepare('INSERT INTO botsaz (id_user, username) VALUES (?, ?)')->execute([(string) $tenantId, 'nomayandefoximabot']);

fx_set_tenant_agent_id(0);

$check('authenticated tenant resolves from botsaz', rx_resolve_authenticated_tenant_agent_id(['agent' => (string) $tenantId]) === $tenantId);
$check('non-numeric agent stays main', rx_resolve_authenticated_tenant_agent_id(['agent' => 'n']) === 0);
$check('unknown numeric agent stays main', rx_resolve_authenticated_tenant_agent_id(['agent' => '999999999']) === 0);
$check('malformed agent stays main', rx_resolve_authenticated_tenant_agent_id(['agent' => '6327424512 OR 1=1']) === 0);

/* Main tenant must still read the main row. */
$check('main maxbalancecart unchanged', rx_gatewayDepositLimit('maxbalancecart') === 1000000);
$check('main wallet max unchanged', rx_getDepositLimits('n') === [20000, 1000000]);

/* Authenticated reseller tenant. */
fx_set_tenant_agent_id($tenantId);
$check('tenant maxbalancecart = 5,000,000', rx_gatewayDepositLimit('maxbalancecart') === 5000000);
$check('other method cap remains 1,000,000', rx_gatewayDepositLimit('maxbalancezarinpal') === 1000000);
$check('wallet effective limits expose card cap', rx_getDepositLimits((string) $tenantId) === [20000, 5000000]);
$check('1,000,000 accepted by effective wallet limit', rx_depositLimitAllowsAmount(1000000, (string) $tenantId) === true);
$check('1,000,001 accepted by effective wallet limit', rx_depositLimitAllowsAmount(1000001, (string) $tenantId) === true);
$check('5,000,000 accepted by effective wallet limit', rx_depositLimitAllowsAmount(5000000, (string) $tenantId) === true);
$check('5,000,001 rejected by effective wallet limit', rx_depositLimitAllowsAmount(5000001, (string) $tenantId) === false);
$check('below minimum rejected', rx_depositLimitAllowsAmount(19999, (string) $tenantId) === false);

/* Admin-style scoped write must not touch the main row. */
update('PaySetting', 'ValuePay', '5100000', 'NamePay', 'maxbalancecart');
$tenantCart = $pdoTest->query("SELECT ValuePay FROM PaySetting WHERE NamePay = 'maxbalancecart' AND agent = {$tenantId}")->fetchColumn();
$mainCart = $pdoTest->query("SELECT ValuePay FROM PaySetting WHERE NamePay = 'maxbalancecart' AND agent = 0")->fetchColumn();
$check('tenant update writes tenant row only', (string) $tenantCart === '5100000' && (string) $mainCart === '1000000', 'tenant=' . $tenantCart . ' main=' . $mainCart);
update('PaySetting', 'ValuePay', '5000000', 'NamePay', 'maxbalancecart');

/* The actual API method limiter must return the authenticated tenant's cap. */
require_once REFACTORED_LEGACY_ROOT . '/api/handlers/BaseHandler.php';
require_once REFACTORED_LEGACY_ROOT . '/api/handlers/PaymentInitHandler.php';
if (!class_exists('FxCartLimitBaseProbe')) {
    final class FxCartLimitBaseProbe extends BaseHandler
    {
        public function handle(): void
        {
        }

        public function readPaySetting(string $name): string
        {
            return $this->paySetting($name);
        }
    }
}
$apiProbe = new FxCartLimitBaseProbe(['id' => '1', 'agent' => (string) $tenantId], []);
$apiGet = static function (string $name) use ($apiProbe): string {
    return $apiProbe->readPaySetting($name);
};
$apiInit = new PaymentInitHandler(['id' => '1', 'agent' => (string) $tenantId], []);
$methodLimitsRef = new ReflectionMethod(PaymentInitHandler::class, 'methodLimits');
$methodLimitsRef->setAccessible(true);
$apiCartLimits = $methodLimitsRef->invoke($apiInit, 'carttocart', (string) $tenantId, $apiGet);
$apiZarinpalLimits = $methodLimitsRef->invoke($apiInit, 'zarinpal', (string) $tenantId, $apiGet);
$check('API cart method uses tenant cap', $apiCartLimits === [20000, 5000000], json_encode($apiCartLimits));
$check('API other method keeps its own cap', $apiZarinpalLimits === [20000, 1000000], json_encode($apiZarinpalLimits));
$check('API rejects 5,000,001 directly', 5000001 > $apiCartLimits[1]);
$check('API accepts 5,000,000 directly', 5000000 >= $apiCartLimits[0] && 5000000 <= $apiCartLimits[1]);

/* Missing tenant row falls back to main row, not to another tenant. */
$pdoTest->prepare("DELETE FROM PaySetting WHERE NamePay = 'maxbalancecart' AND agent = ?")->execute([$tenantId]);
$check('missing tenant row falls back to main cap', rx_gatewayDepositLimit('maxbalancecart') === 1000000);

/* Invalid/missing tenant source must fail closed to main tenant. */
$pdoTest->exec('DROP TABLE botsaz');
$check('missing botsaz fails closed to main tenant', rx_resolve_authenticated_tenant_agent_id(['agent' => (string) $tenantId]) === 0);

/* DB error path must not throw and must use documented fallback. */
$pdoTest->exec('DROP TABLE PaySetting');
$fallbackOk = true;
$fallbackValue = null;
try {
    $fallbackValue = rx_gatewayDepositLimit('maxbalancecart');
} catch (Throwable $e) {
    $fallbackOk = false;
}
$check('missing PaySetting table does not throw', $fallbackOk);
$check('missing PaySetting uses safe default fallback', $fallbackOk && $fallbackValue === RX_DEFAULT_MAX_DEPOSIT, 'value=' . var_export($fallbackValue, true));

echo "\nPASS={$pass} FAIL={$fail}\n";
exit($fail === 0 ? 0 : 1);
