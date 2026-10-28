<?php

/**
 * fx_ticket_notify_tenant_selftest.php — isolated ticket recipient isolation.
 *
 *   docker compose exec -T app php /var/www/faoxima/scripts/fx_ticket_notify_tenant_selftest.php
 */

declare(strict_types=1);

error_reporting(E_ALL & ~E_DEPRECATED);
date_default_timezone_set('Asia/Tehran');

define('REFACTORED_LEGACY_ROOT', dirname(__DIR__));
define('FAOXIMA_SKIP_BOTAPI_ROUTER', true);
require_once REFACTORED_LEGACY_ROOT . '/config.php';
require_once REFACTORED_LEGACY_ROOT . '/function.php';
require_once REFACTORED_LEGACY_ROOT . '/api/lib/Auth.php';
require_once REFACTORED_LEGACY_ROOT . '/api/handlers/TicketsHandler.php';

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

$pdoTest->exec('DROP TABLE IF EXISTS admin');
$pdoTest->exec('DROP TABLE IF EXISTS botsaz');
$pdoTest->exec('CREATE TABLE admin (id_admin varchar(64) NOT NULL PRIMARY KEY) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
$pdoTest->exec('CREATE TABLE botsaz (id_user varchar(64) NOT NULL PRIMARY KEY, bot_token text NULL, admin_ids text NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
$pdoTest->exec("INSERT INTO admin (id_admin) VALUES ('111'),('222'),('999')");
$pdoTest->prepare('INSERT INTO botsaz (id_user, bot_token, admin_ids) VALUES (?,?,?)')
    ->execute(['999', '999:RESELLER_TOKEN', '["333"]']);

$GLOBALS['adminnumber'] = '111';

$rc = new ReflectionClass('TicketsHandler');
$handler = $rc->newInstanceWithoutConstructor();
$method = $rc->getMethod('tenantNotificationContext');
$method->setAccessible(true);

fx_set_tenant_agent_id(0);
$main = $method->invoke($handler, '');
$mainRecipients = array_map('strval', $main['recipients']);
$check('main tenant has no reseller recipients', !in_array('999', $mainRecipients, true) && !in_array('333', $mainRecipients, true), json_encode($mainRecipients));
$check('main tenant includes main admins', in_array('111', $mainRecipients, true) && in_array('222', $mainRecipients, true), json_encode($mainRecipients));
$check('main tenant uses main bot token', $main['bot_token'] === null);

fx_set_tenant_agent_id(999);
$res = $method->invoke($handler, '111');
$resRecipients = array_map('strval', $res['recipients']);
$check('reseller tenant includes owner and its admins', in_array('999', $resRecipients, true) && in_array('333', $resRecipients, true), json_encode($resRecipients));
$check('reseller tenant excludes main admins', !in_array('111', $resRecipients, true) && !in_array('222', $resRecipients, true), json_encode($resRecipients));
$check('reseller tenant uses reseller bot token', (string) $res['bot_token'] === '999:RESELLER_TOKEN');

$pdoTest->exec('DROP TABLE IF EXISTS admin');
$pdoTest->exec('DROP TABLE IF EXISTS botsaz');

echo "\nPASS={$pass} FAIL={$fail}\n";
exit($fail === 0 ? 0 : 1);
