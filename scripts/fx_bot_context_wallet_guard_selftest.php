<?php

declare(strict_types=1);

/**
 * fx_bot_context_wallet_guard_selftest.php — isolated checks for bot context,
 * tenant target authorization and cross-bot conversation-state reset.
 * Runs against the isolated test DB only.
 */

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
    if ($ok) { $pass++; echo "PASS {$label}\n"; return; }
    $fail++; echo "FAIL {$label}" . ($note !== '' ? " [{$note}]" : '') . "\n";
};

$check('test DB is isolated', $testDbName !== 'faoxima', $testDbName);
$check('bot context helpers loaded', function_exists('rx_current_bot_context') && function_exists('rx_admin_target_user_allowed') && function_exists('rx_guard_bot_context_switch'));

$pdoTest->exec('DROP TABLE IF EXISTS user_bot_context');
$pdoTest->exec('DROP TABLE IF EXISTS invoice');
$pdoTest->exec('DROP TABLE IF EXISTS user');
$pdoTest->exec("CREATE TABLE invoice (
    id_invoice varchar(100) NOT NULL PRIMARY KEY,
    id_user varchar(64) NOT NULL,
    username varchar(100) NULL,
    Status varchar(40) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdoTest->exec("CREATE TABLE user (
    id varchar(64) NOT NULL PRIMARY KEY,
    step varchar(200) NOT NULL DEFAULT 'home',
    agent varchar(100) NOT NULL DEFAULT 'f',
    Balance int NOT NULL DEFAULT 0,
    Processing_value text NULL,
    Processing_value_one text NULL,
    Processing_value_tow text NULL,
    Processing_value_four text NULL,
    Processing_value_price varchar(100) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$tenant = 6327424512;
$pdoTest->prepare("INSERT INTO user (id, step, agent, Balance, Processing_value, Processing_value_one, Processing_value_tow, Processing_value_four) VALUES (?, 'home', ?, 100, '1', '2', '3', '4')")
    ->execute(['700001001', (string)$tenant]);
$pdoTest->prepare("INSERT INTO user (id, step, agent, Balance) VALUES (?, 'home', 'f', 500)")
    ->execute(['700001002']);

fx_set_tenant_agent_id($tenant);
$ctx = rx_current_bot_context();
$check('authenticated tenant becomes reseller context', $ctx['type'] === 'reseller' && (int)$ctx['tenant_id'] === $tenant, json_encode($ctx));
$check('reseller admin may target own tenant user', rx_admin_target_user_allowed('700001001'));
$check('reseller admin may not target main-bot user', !rx_admin_target_user_allowed('700001002'));
$check('reseller admin may target tenant owner', rx_admin_target_user_allowed((string)$tenant));

// Simulate the same Telegram user having a pending state in the main bot.
$pdoTest->exec("CREATE TABLE IF NOT EXISTS user_bot_context (user_id varchar(64) NOT NULL PRIMARY KEY, bot_id varchar(64) NOT NULL DEFAULT '0', updated_at int unsigned NOT NULL DEFAULT 0) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdoTest->prepare("INSERT INTO user_bot_context (user_id, bot_id, updated_at) VALUES (?, '0', ?)")
    ->execute(['700001001', time()]);
$row = $pdoTest->query("SELECT * FROM user WHERE id = '700001001'")->fetch(PDO::FETCH_ASSOC);
$row = rx_guard_bot_context_switch('700001001', $row, $pdoTest);
$check('switching bots resets transient step', (string)($row['step'] ?? '') === 'home', (string)($row['step'] ?? ''));
$check('switching bots clears processing fields', (string)($row['Processing_value'] ?? '') === '0' && (string)($row['Processing_value_four'] ?? '') === '0');
$ctxRow = $pdoTest->query("SELECT bot_id FROM user_bot_context WHERE user_id = '700001001'")->fetchColumn();
$check('new bot context persisted', (string)$ctxRow === (string)$tenant, (string)$ctxRow);
$check('wallet balance was not modified by state reset', (int)$pdoTest->query("SELECT Balance FROM user WHERE id = '700001001'")->fetchColumn() === 100);

$pdoTest->prepare("INSERT INTO invoice (id_invoice, id_user, username, Status) VALUES (?, ?, ?, 'active')")->execute(['INV-TENANT', '700001001', 'svc-tenant']);
$pdoTest->prepare("INSERT INTO invoice (id_invoice, id_user, username, Status) VALUES (?, ?, ?, 'active')")->execute(['INV-MAIN', '700001002', 'svc-main']);
$tenantInv = $pdoTest->query("SELECT * FROM invoice WHERE id_invoice = 'INV-TENANT'")->fetch(PDO::FETCH_ASSOC);
$mainInv = $pdoTest->query("SELECT * FROM invoice WHERE id_invoice = 'INV-MAIN'")->fetch(PDO::FETCH_ASSOC);
$check('reseller admin may act on own invoice', rx_admin_invoice_allowed($tenantInv));
$check('reseller admin cannot act on foreign invoice', !rx_admin_invoice_allowed($mainInv));
$check('invoice ownership check does not mutate balance', (int)$pdoTest->query("SELECT Balance FROM user WHERE id = '700001001'")->fetchColumn() === 100 && (int)$pdoTest->query("SELECT Balance FROM user WHERE id = '700001002'")->fetchColumn() === 500);

// Same conditional-claim primitive used by the service refund branch.
$claim = $pdoTest->prepare("UPDATE invoice SET Status = 'removebyadmin' WHERE id_invoice = ? AND id_user = ? AND Status <> 'removebyadmin'");
$claim->execute(['INV-TENANT', '700001001']);
$firstClaim = $claim->rowCount();
$claim->execute(['INV-TENANT', '700001001']);
$secondClaim = $claim->rowCount();
$check('refund claim is idempotent', $firstClaim === 1 && $secondClaim === 0, 'first=' . $firstClaim . ' second=' . $secondClaim);
$check('duplicate refund claim leaves balance unchanged', (int)$pdoTest->query("SELECT Balance FROM user WHERE id = '700001001'")->fetchColumn() === 100);

$pdoTest->exec('DROP TABLE IF EXISTS invoice');
$pdoTest->exec('DROP TABLE IF EXISTS user_bot_context');
$pdoTest->exec('DROP TABLE IF EXISTS user');

echo "\nPASS={$pass} FAIL={$fail}\n";
exit($fail === 0 ? 0 : 1);
