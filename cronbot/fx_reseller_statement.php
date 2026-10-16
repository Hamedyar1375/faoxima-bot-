<?php
/**
 * FX-RESELLER weekly statement cron (2026-10-16).
 * Sends each reseller a weekly account statement via their own bot token and
 * stamps botsaz.setting.last_stmt_ts. Cadence is per-reseller (7 days), so the
 * schedule itself can run hourly without spamming.
 */
require_once __DIR__ . '/_init.php';
rx_cron_boot('fx_reseller_statement', 120);

ini_set('error_log', 'error_log');
if (!rx_cron_require_or_skip('fx_reseller_statement', [
    __DIR__ . '/../config.php',
    __DIR__ . '/../botapi.php',
    __DIR__ . '/../function.php',
    __DIR__ . '/../re/rx/index/fx_debt.php',
])) {
    return;
}
if (!rx_cron_db_ready('fx_reseller_statement')) {
    return;
}

$rxWeek = 7 * 86400;
$rxSent = 0;
$rxSkipped = 0;

try {
    $rxStmt = $pdo->query("SELECT id_user, bot_token, setting FROM botsaz WHERE bot_token IS NOT NULL AND bot_token <> ''");
    $rxRows = $rxStmt ? $rxStmt->fetchAll(PDO::FETCH_ASSOC) : [];
} catch (Throwable $e) {
    error_log('[FX_STMT] list failed: ' . $e->getMessage());
    return;
}

foreach ($rxRows as $rxRow) {
    if (function_exists('rx_cron_time_up') && rx_cron_time_up()) {
        break;
    }
    $rxRid = (int)($rxRow['id_user'] ?? 0);
    if ($rxRid <= 0) {
        continue;
    }
    $rxSet = json_decode((string)($rxRow['setting'] ?? ''), true);
    if (!is_array($rxSet)) {
        $rxSet = [];
    }
    $rxLast = (int)($rxSet['last_stmt_ts'] ?? 0);
    if ($rxLast > 0 && (time() - $rxLast) < $rxWeek) {
        $rxSkipped++;
        continue;
    }
    $rxSince = $rxLast > 0 ? $rxLast : (time() - $rxWeek);
    try {
        if (fx_reseller_statement_send($rxRid, $rxSince)) {
            $rxSent++;
        }
    } catch (Throwable $e) {
        error_log('[FX_STMT] send failed rid=' . $rxRid . ': ' . $e->getMessage());
    }
}

echo "fx_reseller_statement sent={$rxSent} skipped={$rxSkipped}\n";
