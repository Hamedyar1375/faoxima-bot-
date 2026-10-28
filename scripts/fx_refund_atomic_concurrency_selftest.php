<?php

declare(strict_types=1);

/**
 * Isolated concurrency test for the refund claim + atomic balance + ledger
 * transaction used by removeserviceandback.
 *
 * Runs only against faoxima_botcap_test. Never touches production data.
 */

error_reporting(E_ALL & ~E_DEPRECATED);
date_default_timezone_set('Asia/Tehran');

define('REFACTORED_LEGACY_ROOT', dirname(__DIR__));
$testDbName = getenv('FX_TEST_DB') ?: 'faoxima_botcap_test';
$dsn = 'mysql:host=' . (string)($GLOBALS['dbhost'] ?? (getenv('DB_HOST') ?: 'db')) . ';dbname=' . $testDbName . ';charset=utf8mb4';

function fx_refund_test_pdo(string $testDsn, string $dbName): PDO {
    $dsnForPdo = $testDsn;
    require_once REFACTORED_LEGACY_ROOT . '/config.php';
    require_once REFACTORED_LEGACY_ROOT . '/function.php';
    $pdo = new PDO($dsnForPdo, (string)($GLOBALS['usernamedb'] ?? getenv('DB_USER')), (string)($GLOBALS['passworddb'] ?? getenv('DB_PASS')), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $actualDb = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
    if ($actualDb !== $dbName || $actualDb === 'faoxima') {
        throw new RuntimeException('Refusing non-test database: ' . $actualDb);
    }
    $GLOBALS['pdo'] = $pdo;
    return $pdo;
}

$mode = $argv[1] ?? 'master';

if ($mode === 'refund' || $mode === 'refund_fail' || $mode === 'credit') {
    $startAt = (float)($argv[2] ?? 0);
    $pdo = fx_refund_test_pdo($dsn, $testDbName);
    while (microtime(true) < $startAt) { usleep(1000); }
    $out = ['mode' => $mode, 'ok' => false, 'reason' => ''];
    try {
        if ($mode === 'credit') {
            $out['ok'] = balance_atomic_credit('700009001', 100);
        } else {
            $pdo->beginTransaction();
            $claim = $pdo->prepare("UPDATE invoice SET Status = 'removebyadmin' WHERE id_invoice = ? AND id_user = ? AND Status <> 'removebyadmin'");
            $claim->execute(['INV-CONC-1', '700009001']);
            if ($claim->rowCount() < 1) {
                $pdo->rollBack();
                $out['reason'] = 'already_claimed';
            } else {
                if (!balance_atomic_credit('700009001', 1000)) throw new RuntimeException('credit_failed');
                if (!wallet_ledger_record('700009001', 'credit', 1000, 'refund', 'concurrency test', 'INV-CONC-1', 'invoice', 'INV-CONC-1')) throw new RuntimeException('ledger_failed');
                if ($mode === 'refund_fail') throw new RuntimeException('forced_failure_after_credit');
                $pdo->commit();
                $out['ok'] = true;
            }
        }
    } catch (Throwable $e) {
        try { if ($pdo->inTransaction()) $pdo->rollBack(); } catch (Throwable $x) {}
        $out['reason'] = $e->getMessage();
    }
    echo json_encode($out, JSON_UNESCAPED_UNICODE) . "\n";
    exit(0);
}

// Master: isolated schema.
$pdo = fx_refund_test_pdo($dsn, $testDbName);
if ($testDbName === 'faoxima') { fwrite(STDERR, "Refusing production DB\n"); exit(1); }
$pdo->exec('DROP TABLE IF EXISTS wallet_ledger');
$pdo->exec('DROP TABLE IF EXISTS invoice');
$pdo->exec('DROP TABLE IF EXISTS user');
$pdo->exec("CREATE TABLE user (id varchar(64) NOT NULL PRIMARY KEY, Balance int NOT NULL DEFAULT 0) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("CREATE TABLE invoice (id_invoice varchar(100) NOT NULL PRIMARY KEY, id_user varchar(64) NOT NULL, Status varchar(40) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("CREATE TABLE wallet_ledger (id int unsigned AUTO_INCREMENT PRIMARY KEY, id_user varchar(200) NOT NULL, direction enum('credit','debit') NOT NULL, amount bigint NOT NULL, balance_after bigint NULL, category varchar(50) NOT NULL, description varchar(500) NULL, id_order varchar(64) NULL, ref_table varchar(50) NULL, ref_id varchar(100) NULL, created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP, fulfillment_key varchar(191) NULL UNIQUE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("INSERT INTO user (id,Balance) VALUES ('700009001',1000)");
$pdo->exec("INSERT INTO invoice (id_invoice,id_user,Status) VALUES ('INV-CONC-1','700009001','active')");

$pass = 0; $fail = 0;
$check = static function (string $label, bool $ok, string $note = '') use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "PASS {$label}\n"; }
    else { $fail++; echo "FAIL {$label}" . ($note !== '' ? " [{$note}]" : '') . "\n"; }
};

$runWorkers = static function (array $modes): array {
    $start = microtime(true) + 1.0;
    $procs = [];
    foreach ($modes as $i => $mode) {
        $cmd = [PHP_BINARY, __FILE__, $mode, (string)$start];
        $procs[$i] = proc_open($cmd, [1 => ['pipe','w'], 2 => ['pipe','w']], $pipes);
        $procPipes[$i] = $pipes;
    }
    $out = [];
    foreach ($procs as $i => $proc) {
        $stdout = stream_get_contents($procPipes[$i][1]);
        $stderr = stream_get_contents($procPipes[$i][2]);
        fclose($procPipes[$i][1]); fclose($procPipes[$i][2]);
        proc_close($proc);
        $out[$i] = json_decode(trim($stdout), true) ?: ['ok' => false, 'reason' => 'bad_json', 'stdout' => $stdout, 'stderr' => $stderr];
    }
    return $out;
};

// Scenario 1: two simultaneous refunds of the same invoice.
$workers = $runWorkers(['refund', 'refund']);
$okCount = count(array_filter($workers, static fn($r) => !empty($r['ok'])));
$balance = (int)$pdo->query("SELECT Balance FROM user WHERE id='700009001'")->fetchColumn();
$ledger = (int)$pdo->query("SELECT COUNT(*) FROM wallet_ledger")->fetchColumn();
$status = (string)$pdo->query("SELECT Status FROM invoice WHERE id_invoice='INV-CONC-1'")->fetchColumn();
$check('only one concurrent refund succeeds', $okCount === 1, json_encode($workers, JSON_UNESCAPED_UNICODE));
$check('concurrent refund credits exactly once', $balance === 2000, 'balance=' . $balance);
$check('concurrent refund writes exactly one ledger row', $ledger === 1, 'ledger=' . $ledger);
$check('invoice ends in removebyadmin', $status === 'removebyadmin', $status);

// Scenario 2: two independent atomic credits on one user must not be lost.
$pdo->exec("UPDATE user SET Balance=0 WHERE id='700009001'");
$workers = $runWorkers(['credit', 'credit']);
$balance = (int)$pdo->query("SELECT Balance FROM user WHERE id='700009001'")->fetchColumn();
$check('two independent atomic credits are both applied', $balance === 200, 'balance=' . $balance);

// Scenario 3: failure after claim/credit must roll back invoice, balance and ledger.
$pdo->exec("UPDATE user SET Balance=1000 WHERE id='700009001'");
$pdo->exec("UPDATE invoice SET Status='active' WHERE id_invoice='INV-CONC-1'");
$pdo->exec("DELETE FROM wallet_ledger");
$workers = $runWorkers(['refund_fail']);
$balance = (int)$pdo->query("SELECT Balance FROM user WHERE id='700009001'")->fetchColumn();
$ledger = (int)$pdo->query("SELECT COUNT(*) FROM wallet_ledger")->fetchColumn();
$status = (string)$pdo->query("SELECT Status FROM invoice WHERE id_invoice='INV-CONC-1'")->fetchColumn();
$check('forced failure rolls back balance', $balance === 1000, 'balance=' . $balance);
$check('forced failure rolls back ledger', $ledger === 0, 'ledger=' . $ledger);
$check('forced failure rolls back invoice status', $status === 'active', $status);

$pdo->exec('DROP TABLE IF EXISTS wallet_ledger');
$pdo->exec('DROP TABLE IF EXISTS invoice');
$pdo->exec('DROP TABLE IF EXISTS user');

echo "\nPASS={$pass} FAIL={$fail}\n";
exit($fail === 0 ? 0 : 1);
