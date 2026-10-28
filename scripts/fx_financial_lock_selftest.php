<?php

/**
 * fx_financial_lock_selftest.php — isolated checks for the per-user financial
 * callback lock (row-based lock with owner token + TTL).
 *
 * Runs only against faoxima_botcap_test.
 *
 *   docker compose exec -T app php /var/www/faoxima/scripts/fx_financial_lock_selftest.php
 */

declare(strict_types=1);

error_reporting(E_ALL & ~E_DEPRECATED);
date_default_timezone_set('Asia/Tehran');

define('REFACTORED_LEGACY_ROOT', dirname(__DIR__));
define('FAOXIMA_SKIP_BOTAPI_ROUTER', true);
require_once REFACTORED_LEGACY_ROOT . '/config.php';

$testDbName = getenv('FX_TEST_DB') ?: 'faoxima_botcap_test';
$dsn = 'mysql:host=' . (string) ($GLOBALS['dbhost'] ?? 'db') . ';dbname=' . $testDbName . ';charset=utf8mb4';
$pdo1 = new PDO($dsn, (string) ($GLOBALS['usernamedb'] ?? ''), (string) ($GLOBALS['passworddb'] ?? ''), [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$pdo2 = new PDO($dsn, (string) ($GLOBALS['usernamedb'] ?? ''), (string) ($GLOBALS['passworddb'] ?? ''), [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);

$GLOBALS['pdo'] = $pdo1;
$pdo = $pdo1;

require_once REFACTORED_LEGACY_ROOT . '/botapi.php';

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
$check('financial lock helpers loaded', function_exists('rx_callback_lock_acquire') && function_exists('rx_callback_lock_release') && function_exists('rx_callback_lock_release_for_owner'));

$pdo1->exec('DROP TABLE IF EXISTS rx_runtime_locks');
$owner = '7000009' . substr((string) time(), -2);

/* First acquisition succeeds. */
$lock = rx_callback_lock_acquire($owner, 'money', 0.3);
$check('first lock acquisition succeeds', is_string($lock) && $lock !== '', var_export($lock, true));
$__ttlRow = $pdo1->query("SELECT acquired_at, expires_at FROM rx_runtime_locks WHERE lock_name = " . $pdo1->quote((string) $lock))->fetch(PDO::FETCH_ASSOC);
$check('financial lock has 1-minute TTL', is_array($__ttlRow) && ((int) $__ttlRow['expires_at'] - (int) $__ttlRow['acquired_at']) >= 50 && ((int) $__ttlRow['expires_at'] - (int) $__ttlRow['acquired_at']) <= 70);

/* Second connection cannot take an active lock. */
$GLOBALS['pdo'] = $pdo2;
$pdo = $pdo2;
$blocked = rx_callback_lock_acquire($owner, 'money', 0.3);
$check('active lock blocks second connection', $blocked === false);

/* Immediate release makes it available again. */
$GLOBALS['pdo'] = $pdo1;
$pdo = $pdo1;
$released = rx_callback_lock_release($lock);
$check('release succeeds immediately', (string) $released === '1', var_export($released, true));

$GLOBALS['pdo'] = $pdo2;
$pdo = $pdo2;
$lock2 = rx_callback_lock_acquire($owner, 'money', 0.5);
$check('second connection acquires after release', is_string($lock2) && $lock2 !== '');
rx_callback_lock_release($lock2);

/* Stale lock must expire by TTL and be recoverable by another request. */
$GLOBALS['pdo'] = $pdo1;
$pdo = $pdo1;
$lock3 = rx_callback_lock_acquire($owner, 'money', 0.3);
$check('lock reacquired for TTL test', is_string($lock3) && $lock3 !== '');
$pdo1->prepare('UPDATE rx_runtime_locks SET expires_at = ? WHERE lock_name = ?')->execute([time() - 1, $lock3]);

$GLOBALS['pdo'] = $pdo2;
$pdo = $pdo2;
$lock4 = rx_callback_lock_acquire($owner, 'money', 0.5);
$check('expired lock is automatically taken over', is_string($lock4) && $lock4 !== '');

/* Old owner cannot release the new owner's lock. */
$GLOBALS['pdo'] = $pdo1;
$pdo = $pdo1;
$oldRelease = rx_callback_lock_release($lock3);
$check('old owner cannot release new lock', (string) $oldRelease === '0', var_export($oldRelease, true));

$GLOBALS['pdo'] = $pdo2;
$pdo = $pdo2;
$row = $pdo2->query("SELECT owner_token FROM rx_runtime_locks WHERE lock_name = " . $pdo2->quote($lock4))->fetchColumn();
$check('new owner still holds lock', is_string($row) && $row !== '');
$check('new owner release succeeds', (string) rx_callback_lock_release($lock4) === '1');

/* /start and the explicit unlock button can force-release without owner token. */
$owner2 = $owner . '1';
$GLOBALS['pdo'] = $pdo2;
$pdo = $pdo2;
$forceLock = rx_callback_lock_acquire($owner2, 'money', 0.3);
$GLOBALS['pdo'] = $pdo1;
$pdo = $pdo1;
$forceReleased = rx_callback_lock_release_for_owner($owner2, ['money']);
$forceRow = $pdo1->prepare('SELECT COUNT(*) FROM rx_runtime_locks WHERE lock_name = ?');
$forceRow->execute([(string) $forceLock]);
$check('force release removes lock without owner token', $forceReleased >= 1 && (int) $forceRow->fetchColumn() === 0);
$GLOBALS['pdo'] = $pdo2;
$pdo = $pdo2;
$check('force-released owner can acquire immediately', is_string(rx_callback_lock_acquire($owner2, 'money', 0.3)));
rx_callback_lock_release_for_owner($owner2, ['money']);

/* Global cleanup removes expired rows left by abandoned requests. */
$GLOBALS['pdo'] = $pdo1;
$pdo = $pdo1;
$pdo1->prepare('INSERT INTO rx_runtime_locks (lock_name, owner_token, acquired_at, expires_at) VALUES (?, ?, ?, ?)')
    ->execute(['stale:money:cleanup', 'stale-token', time() - 300, time() - 10]);
$cleaned = rx_callback_lock_cleanup_expired();
$staleExists = $pdo1->query("SELECT COUNT(*) FROM rx_runtime_locks WHERE lock_name = 'stale:money:cleanup'")->fetchColumn();
$check('expired lock rows are cleaned automatically', $cleaned >= 1 && (int) $staleExists === 0);

$pdo1->exec('DROP TABLE IF EXISTS rx_runtime_locks');

echo "\nPASS={$pass} FAIL={$fail}\n";
exit($fail === 0 ? 0 : 1);
