<?php

/**
 * fx_miniapp_auth_selftest.php — isolated VerifyHandler routing checks.
 *
 * Verifies the auth branch selection without writing to the production DB:
 *  - empty / 0 / null / undefined tenant => main-bot token path;
 *  - valid numeric tenant => reseller bot token path;
 *  - invalid tenant hint / invalid signature => rejected.
 *
 *   docker compose exec -T app php /var/www/faoxima/scripts/fx_miniapp_auth_selftest.php
 */

declare(strict_types=1);

error_reporting(E_ALL & ~E_DEPRECATED);
date_default_timezone_set('Asia/Tehran');

define('REFACTORED_LEGACY_ROOT', dirname(__DIR__));
define('FAOXIMA_SKIP_BOTAPI_ROUTER', true);
require_once REFACTORED_LEGACY_ROOT . '/config.php';
require_once REFACTORED_LEGACY_ROOT . '/function.php';
require_once REFACTORED_LEGACY_ROOT . '/api/lib/Auth.php';
require_once REFACTORED_LEGACY_ROOT . '/api/handlers/VerifyHandler.php';
$GLOBALS['pdo'] = $pdoTest;
$pdo = $pdoTest;

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

function fx_make_init_data(string $botToken, int $userId): string
{
    $fields = [
        'user'      => json_encode(['id' => $userId, 'username' => 'u' . $userId], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'auth_date' => (string) time(),
    ];
    $checkArr = [];
    foreach ($fields as $k => $v) {
        $checkArr[] = $k . '=' . $v;
    }
    sort($checkArr, SORT_STRING);
    $secret = hash_hmac('sha256', $botToken, 'WebAppData', true);
    $fields['hash'] = hash_hmac('sha256', implode("\n", $checkArr), $secret);
    return http_build_query($fields);
}

$mainToken = (string) ($GLOBALS['APIKEY'] ?? '');
$check('main bot token available', $mainToken !== '');
$resellerToken = '654321:RESELLER_TEST_TOKEN';
$resellerRid = '999001';

$pdoTest->exec('DROP TABLE IF EXISTS miniapp_auth_sessions');
FaoximaAuth::storeSessionTenant('main-session-token', 6327424512, 0);
FaoximaAuth::storeSessionTenant('reseller-session-token', 6327424512, 999001);
$check('main session tenant is 0', FaoximaAuth::sessionTenantForToken('main-session-token', 6327424512) === 0);
$check('reseller session tenant is isolated', FaoximaAuth::sessionTenantForToken('reseller-session-token', 6327424512) === 999001);
$check('unknown session token has no tenant', FaoximaAuth::sessionTenantForToken('missing-token', 6327424512) === null);
$pdoTest->exec('DROP TABLE IF EXISTS miniapp_auth_sessions');

$pdoTest->exec('DROP TABLE IF EXISTS botsaz');
$pdoTest->exec("CREATE TABLE botsaz (
    id int unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
    id_user varchar(64) NOT NULL,
    bot_token text NULL,
    admin_ids text NULL,
    username varchar(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdoTest->prepare('INSERT INTO botsaz (id_user, bot_token, admin_ids, username) VALUES (?, ?, ?, ?)')
    ->execute([$resellerRid, $resellerToken, '[]', 'testresellerbot']);

$requestTenantHint = new ReflectionMethod('VerifyHandler', 'requestTenantHint');
$requestTenantHint->setAccessible(true);
$tryResolveReseller = new ReflectionMethod('VerifyHandler', 'tryResolveReseller');
$tryResolveReseller->setAccessible(true);

$mainInit = fx_make_init_data($mainToken, 880000001);
$mainValid = false;
try {
    $mainValid = is_array(FaoximaAuth::validateInitData($mainInit, $mainToken));
} catch (Throwable $e) {
    $mainValid = false;
}
$check('main initData validates with main token', $mainValid);

foreach (['', '0', 'null', 'undefined'] as $tenantValue) {
    $_GET = ['tenant' => $tenantValue];
    $_POST = [];
    unset($_SERVER['HTTP_X_FX_TENANT']);
    $hint = $requestTenantHint->invoke(null);
    $check('tenant "' . $tenantValue . '" is treated as absent', $hint === null, var_export($hint, true));
}

$_GET = [];
$_POST = [];
unset($_SERVER['HTTP_X_FX_TENANT']);
$check('missing tenant is treated as absent', $requestTenantHint->invoke(null) === null);

$resInit = fx_make_init_data($resellerToken, 880000002);
$_GET = [];
$_POST = [];
$_SERVER['HTTP_X_FX_TENANT'] = $resellerRid;
$resolved = $tryResolveReseller->invoke(null, $resInit, $pdoTest, $resellerRid);
$check('valid tenant resolves reseller token', is_array($resolved) && (int) ($resolved['reseller_id'] ?? 0) === (int) $resellerRid, json_encode($resolved, JSON_UNESCAPED_UNICODE));

$wrong = $tryResolveReseller->invoke(null, $resInit, $pdoTest, '999002');
$check('wrong tenant is rejected', $wrong === null);

$badInit = fx_make_init_data('999999:BAD_TOKEN', 880000002);
$bad = $tryResolveReseller->invoke(null, $badInit, $pdoTest, $resellerRid);
$check('invalid signature is rejected', $bad === null);

$pdoTest->exec('DROP TABLE IF EXISTS botsaz');

echo "\nPASS={$pass} FAIL={$fail}\n";
exit($fail === 0 ? 0 : 1);
