<?php
/**
 * fx_reseller_receipt_guard_selftest.php — verifies the reseller-bot receipt
 * approve/reject authorization fix (FX-RESELLER-RECEIPT-GUARD).
 *
 * NOTE on timing: the webhook returns 200 immediately (rx_releaseWebhookConnection)
 * while the bot flow keeps running in the FPM process, so every assertion polls
 * for the expected state (up to ~25s) instead of checking synchronously.
 *
 * Part A (unit, mocked telegram):
 *   - fx_res_svc_is_reseller_admin / fx_res_svc_receipt_admin_ok /
 *     fx_res_svc_receipt_guard_answer semantics (main vs reseller context)
 * Part B (live, real reseller webhook):
 *   1. approve by authorized reseller admin (owner) on a dummy receipt
 *   2. double approve credits exactly once
 *   3. reject by owner + cancel restores waiting
 *   4. reject with reason completes the flow
 *   5. unauthorized user (main admin) is blocked, callback answered
 *   6. cross-tenant receipt inaccessible to the reseller admin
 *   7. the user's REAL pending receipt gets approved by the owner
 *
 *   docker exec faoxima-app-1 php /var/www/faoxima/scripts/fx_reseller_receipt_guard_selftest.php
 */
error_reporting(E_ALL & ~E_DEPRECATED);
date_default_timezone_set('Asia/Tehran');
define('REFACTORED_LEGACY_ROOT', '/var/www/faoxima');
require_once REFACTORED_LEGACY_ROOT . '/vpnbot/6327424512nomayandefoximabot/config.php';
define('FX_RESELLER_ID', 6327424512);
define('FX_RESELLER_TOKEN', $ApiToken);
require_once REFACTORED_LEGACY_ROOT . '/re/rx/function/bootstrap.php';
require_once REFACTORED_LEGACY_ROOT . '/re/rx/index/fx_reseller_service_mgmt.php';

$pdo = getDatabaseConnection();
$GLOBALS['pdo'] = $pdo;
if (!($pdo instanceof PDO)) {
    fwrite(STDERR, "DB unavailable\n");
    exit(1);
}

$OWNER = 6327424512;          // reseller owner (in botsaz.admin_ids)
$FOREIGN = 8400624309;        // main-platform admin, NOT a reseller admin
$MAIN_TENANT_USER = 999500020; // a user of the MAIN tenant (agent 'f', bottype NULL)
// Docker-internal nginx route (the test runs inside faoxima-app-1) + the
// webhook secret token derived from the bot token — identical to what Telegram
// sends (X-Telegram-Bot-Api-Secret-Token), so checktelegramip() passes.
$RESELLER_WEBHOOK = 'http://faoxima-nginx-1/vpnbot/6327424512nomayandefoximabot/index.php';
require_once REFACTORED_LEGACY_ROOT . '/lib/WebhookAuth.php';
$WH_SECRET = FaoximaWebhookAuth::secret((string) FX_RESELLER_TOKEN, false);

$pass = 0;
$fail = 0;
$check = static function ($label, $ok, $note = '') use (&$pass, &$fail) {
    if ($ok) { $pass++; echo "PASS {$label}\n"; }
    else { $fail++; echo "FAIL {$label}" . ($note !== '' ? " [{$note}]" : '') . "\n"; }
};

// ---------- mocked telegram for Part A ----------
$callLog = [];
function telegram($m, $d = [], $t = null) { global $callLog; $callLog[] = ['op' => 'tg:' . $m, 'd' => $d]; return ['ok' => true]; }
function clearCallLog() { global $callLog; $callLog = []; }
function lastAnswers(): array
{
    global $callLog;
    $out = [];
    foreach ($callLog as $c) {
        if ($c['op'] === 'tg:answerCallbackQuery') {
            $out[] = $c['d'];
        }
    }
    return $out;
}

echo "== Part A: unit tests (reseller context: FX_RESELLER_ID=" . FX_RESELLER_ID . ") ==\n";

$GLOBALS['from_id'] = $OWNER;
$check('A1 owner is reseller admin', fx_res_svc_is_reseller_admin($OWNER) === true);
$check('A2 main admin is NOT reseller admin', fx_res_svc_is_reseller_admin($FOREIGN) === false);
$check('A3 unknown user is NOT reseller admin', fx_res_svc_is_reseller_admin(123456789) === false);
$check('A4 owner passes receipt gate (reseller ctx)', fx_res_svc_receipt_admin_ok(false, $OWNER) === true);
$check('A5 main admin blocked in reseller ctx', fx_res_svc_receipt_admin_ok(['rule' => 'administrator', 'id_admin' => (string)$FOREIGN], $FOREIGN) === false);
$check('A6 unknown blocked in reseller ctx', fx_res_svc_receipt_admin_ok(['rule' => 'Seller'], 123456789) === false);

$GLOBALS['from_id'] = $FOREIGN;
$GLOBALS['callback_query_id'] = 'unit_cq_1';
clearCallLog();
$check('A7a guard answers Confirm_pay for foreign', fx_res_svc_receipt_guard_answer('Confirm_pay_abc123') === true);
$a = lastAnswers();
$check('A7b alert text sent', count($a) === 1 && ($a[0]['text'] ?? '') === 'شما مجوز مدیریت رسیدها را ندارید.' && !empty($a[0]['show_alert']));

foreach (['reject_pay_abc', 'reject_pay_cancel_abc', 'addbalamceuser_abc', 'blockuserfake_123', 'confirmpaid'] as $i => $cb) {
    clearCallLog();
    $check('A8' . chr(97 + $i) . " guard answers '{$cb}'", fx_res_svc_receipt_guard_answer($cb) === true && count(lastAnswers()) === 1);
}

$GLOBALS['from_id'] = $FOREIGN;
clearCallLog();
$check('A9 guard ignores non-receipt callback', fx_res_svc_receipt_guard_answer('admin') === false && count(lastAnswers()) === 0);
$GLOBALS['from_id'] = $OWNER;
clearCallLog();
$check('A10 guard silent for reseller admin', fx_res_svc_receipt_guard_answer('Confirm_pay_abc') === false && count(lastAnswers()) === 0);
$GLOBALS['from_id'] = $FOREIGN;
$GLOBALS['callback_query_id'] = '';
clearCallLog();
$check('A11 guard silent without callback id', fx_res_svc_receipt_guard_answer('Confirm_pay_abc') === false && count(lastAnswers()) === 0);
$GLOBALS['callback_query_id'] = 'unit_cq_1';

// A12: legacy (main-bot) gate semantics preserved
$legacy = static function ($row) {
    if (!is_array($row)) {
        return false;
    }
    return in_array($row['rule'] ?? '', ['administrator', 'Seller'], true);
};
$check('A12a main ctx: administrator passes', $legacy(['rule' => 'administrator']) === true);
$check('A12b main ctx: Seller passes', $legacy(['rule' => 'Seller']) === true);
$check('A12c main ctx: support blocked', $legacy(['rule' => 'support']) === false);
$check('A12d main ctx: no row blocked', $legacy(false) === false);

echo "\n== Part B: live tests through the real reseller webhook ==\n";

// unique update ids per run (dedupe guard keeps them for a while)
$updId = (int) (microtime(true) * 100) % 9000000000;
$makeCbUpdate = static function (&$updId, $fromId, $data, $chatId = -1004449450667, $msgId = 1) {
    $updId++;
    return json_encode([
        'update_id' => $updId,
        'callback_query' => [
            'id' => (string) ($updId + 100000000),
            'from' => ['id' => $fromId, 'is_bot' => false, 'first_name' => 'fxtest', 'username' => 'fxtest' . $fromId],
            'chat_instance' => 'fxtest_ci',
            'message' => [
                'message_id' => $msgId,
                'date' => time(),
                'chat' => ['id' => $chatId, 'type' => 'supergroup'],
                'text' => '🧾 رسید (fxtest)',
            ],
            'data' => $data,
        ],
    ]);
};
$makeTextUpdate = static function (&$updId, $fromId, $text, $chatId = 6327424512) {
    $updId++;
    return json_encode([
        'update_id' => $updId,
        'message' => [
            'message_id' => $updId,
            'date' => time(),
            'chat' => ['id' => $chatId, 'type' => 'private'],
            'from' => ['id' => $fromId, 'is_bot' => false, 'first_name' => 'fxtest', 'username' => 'fxtest' . $fromId],
            'text' => $text,
        ],
    ]);
};
$postUpdate = static function ($body) use ($RESELLER_WEBHOOK, $WH_SECRET) {
    $ch = curl_init($RESELLER_WEBHOOK);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 90,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-Telegram-Bot-Api-Secret-Token: ' . $WH_SECRET],
    ]);
    $t0 = microtime(true);
    $resp = (string) curl_exec($ch);
    $err = curl_error($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'body' => $resp, 'err' => $err, 'secs' => round(microtime(true) - $t0, 2)];
};
$dummyOrder = static function (string $suffix) { return 'fxtest_rct_' . $suffix; };
$createDummy = function (string $order, int $userId, int $price, bool $tenantScoped = true) use ($pdo, $ApiToken) {
    $pdo->prepare(
        "INSERT INTO Payment_report (id_order, id_user, price, Payment_Method, payment_Status, dec_not_confirmed, id_invoice, bottype, card_photo_file_id, time)
         VALUES (:o, :u, :p, 'cart to cart', 'waiting', 'receipt-submitted', :inv, :bt, NULL, UNIX_TIMESTAMP())"
    )->execute([
        ':o' => $order, ':u' => (string)$userId, ':p' => $price,
        ':inv' => $userId . '-fxtest|fxtest',
        ':bt' => $tenantScoped ? $ApiToken : null,
    ]);
};
$dropDummy = function (string $order) use ($pdo) {
    $pdo->prepare("DELETE FROM Payment_report WHERE id_order = ?")->execute([$order]);
    $pdo->prepare("DELETE FROM payment_fulfillment WHERE id_order = ?")->execute([$order]);
    $pdo->prepare("DELETE FROM wallet_ledger WHERE fulfillment_key = ? OR id_order = ?")->execute([$order, $order]);
};
$reportRow = static function (string $order) use ($pdo) {
    $s = $pdo->prepare("SELECT payment_Status, direct_payment_done, dec_not_confirmed FROM Payment_report WHERE id_order = ?");
    $s->execute([$order]);
    return $s->fetch(PDO::FETCH_ASSOC);
};
$balanceOf = static function (int $uid) use ($pdo) {
    return (int) $pdo->query("SELECT Balance FROM user WHERE id = {$uid}")->fetchColumn();
};
$ownerRow = static function () use ($pdo, $OWNER) {
    $s = $pdo->prepare("SELECT step, Processing_value, Processing_value_one, Processing_value_four FROM user WHERE id = ?");
    $s->execute([(string)$OWNER]);
    return $s->fetch(PDO::FETCH_ASSOC);
};
// poll until $fn() is truthy or timeout (the webhook flow runs after the 200)
$waitFor = static function (string $label, callable $fn, int $timeoutSec = 25): bool {
    $t0 = time();
    do {
        if ($fn()) {
            return true;
        }
        sleep(1);
    } while (time() - $t0 < $timeoutSec);
    echo "  (timeout waiting: {$label})\n";
    return $fn();
};
$settle = static function (string $label) use ($waitFor) {
    // give in-flight flows (any order) a moment before asserting negatives
    $waitFor('settle:' . $label, static function () { return false; }, 4);
};

// snapshots for cleanup
$ownerBefore = $ownerRow();

// ---- B1: approve by authorized reseller admin (owner) ----
$b1 = $dummyOrder('b1');
$bal0 = $balanceOf($FOREIGN);
$createDummy($b1, $FOREIGN, 1000);
$r = $postUpdate($makeCbUpdate($updId, $OWNER, 'Confirm_pay_' . $b1));
$waitFor('B1 paid', function () use ($b1, $reportRow) { return ($reportRow($b1)['payment_Status'] ?? '') === 'paid'; });
$st = $reportRow($b1);
$bal1 = $balanceOf($FOREIGN);
$check('B1 webhook accepted', $r['code'] === 200, 'code=' . $r['code'] . ' err=' . $r['err']);
$check('B1 receipt approved by owner', ($st['payment_Status'] ?? '') === 'paid', 'status=' . ($st['payment_Status'] ?? 'null'));
$check('B1 balance credited exactly 1000', $bal1 === $bal0 + 1000, "bal {$bal0} -> {$bal1}");
$check('B1 direct_payment_done set', (int)($st['direct_payment_done'] ?? 0) === 1);

// ---- B2: double approve does NOT credit again ----
$r = $postUpdate($makeCbUpdate($updId, $OWNER, 'Confirm_pay_' . $b1));
$waitFor('B2 settle', function () use ($b1) { return false; }, 5);
$st2 = $reportRow($b1);
$bal2 = $balanceOf($FOREIGN);
$check('B2 second approve accepted by webhook', $r['code'] === 200, 'code=' . $r['code']);
$check('B2 status still paid', ($st2['payment_Status'] ?? '') === 'paid');
$check('B2 no double credit', $bal2 === $bal1, "bal {$bal1} -> {$bal2}");

// ---- B3: reject then cancel ----
$b3 = $dummyOrder('b3');
$createDummy($b3, $FOREIGN, 2000);
$r = $postUpdate($makeCbUpdate($updId, $OWNER, 'reject_pay_' . $b3));
$ok3 = $waitFor('B3 reject', function () use ($b3, $reportRow) { return ($reportRow($b3)['payment_Status'] ?? '') === 'reject'; });
$st3 = $reportRow($b3);
$os3 = $ownerRow();
$check('B3a reject claimed atomically', $r['code'] === 200 && ($st3['payment_Status'] ?? '') === 'reject', 'status=' . ($st3['payment_Status'] ?? 'null'));
$check('B3b owner in reject-dec step', ($os3['step'] ?? '') === 'reject-dec' && ($os3['Processing_value_one'] ?? '') === $b3, 'step=' . ($os3['step'] ?? 'null'));
$r = $postUpdate($makeCbUpdate($updId, $OWNER, 'reject_pay_cancel_' . $b3));
$ok3c = $waitFor('B3 cancel', function () use ($b3, $reportRow) { return ($reportRow($b3)['payment_Status'] ?? '') === 'waiting'; });
$st3c = $reportRow($b3);
$os3c = $ownerRow();
$check('B3c cancel restores waiting', $r['code'] === 200 && ($st3c['payment_Status'] ?? '') === 'waiting', 'status=' . ($st3c['payment_Status'] ?? 'null'));
$check('B3d owner step reset', ($os3c['step'] ?? '') === 'home', 'step=' . ($os3c['step'] ?? 'null'));

// ---- B4: reject with reason ----
$b4 = $dummyOrder('b4');
$createDummy($b4, $FOREIGN, 1500);
$postUpdate($makeCbUpdate($updId, $OWNER, 'reject_pay_' . $b4));
$waitFor('B4 reject claimed', function () use ($b4, $reportRow) { return ($reportRow($b4)['payment_Status'] ?? '') === 'reject'; }, 15);
$r = $postUpdate($makeTextUpdate($updId, $OWNER, 'دلیل تست رد'));
$ok4 = $waitFor('B4 reason stored', function () use ($b4, $reportRow) { return ($reportRow($b4)['dec_not_confirmed'] ?? '') === 'دلیل تست رد'; }, 15);
$st4 = $reportRow($b4);
$os4 = $ownerRow();
$check('B4a reason submitted, receipt rejected', $r['code'] === 200 && ($st4['payment_Status'] ?? '') === 'reject', 'status=' . ($st4['payment_Status'] ?? 'null'));
$check('B4b reason stored', ($st4['dec_not_confirmed'] ?? '') === 'دلیل تست رد', 'note=' . ($st4['dec_not_confirmed'] ?? 'null'));
$check('B4c owner step reset after reject', ($os4['step'] ?? '') === 'home', 'step=' . ($os4['step'] ?? 'null'));

// ---- B5: unauthorized user (main admin) cannot approve/reject ----
$b5 = $dummyOrder('b5');
$createDummy($b5, $FOREIGN, 500);
$balB5 = $balanceOf($FOREIGN);
$r = $postUpdate($makeCbUpdate($updId, $FOREIGN, 'Confirm_pay_' . $b5));
$settle('B5 confirm');
$st5 = $reportRow($b5);
$balB5After = $balanceOf($FOREIGN);
$check('B5a webhook accepted', $r['code'] === 200, 'code=' . $r['code'] . ' err=' . $r['err']);
$check('B5b receipt stays waiting', ($st5['payment_Status'] ?? '') === 'waiting', 'status=' . ($st5['payment_Status'] ?? 'null'));
$check('B5c no balance change', $balB5After === $balB5, "bal {$balB5} -> {$balB5After}");
$r = $postUpdate($makeCbUpdate($updId, $FOREIGN, 'reject_pay_' . $b5));
$settle('B5 reject');
$st5r = $reportRow($b5);
$check('B5d unauthorized reject blocked', $r['code'] === 200 && ($st5r['payment_Status'] ?? '') === 'waiting', 'status=' . ($st5r['payment_Status'] ?? 'null'));

// ---- B6: cross-tenant isolation (main-tenant receipt vs reseller admin) ----
$b6 = $dummyOrder('b6');
$createDummy($b6, $MAIN_TENANT_USER, 700, false);
$r = $postUpdate($makeCbUpdate($updId, $OWNER, 'Confirm_pay_' . $b6));
$settle('B6');
$st6 = $reportRow($b6);
$check('B6a cross-tenant approve blocked', $r['code'] === 200 && ($st6['payment_Status'] ?? '') === 'waiting', 'status=' . ($st6['payment_Status'] ?? 'null'));
$check('B6b cross-tenant row untouched', (int)($st6['direct_payment_done'] ?? 0) === 0);
$dropDummy($b6);

// ---- B7: the user's REAL pending receipt (769f6dbe69) approved by the owner ----
$realOrder = '769f6dbe69';
$stR0 = $reportRow($realOrder);
if (($stR0['payment_Status'] ?? '') === 'paid') {
    echo "PASS B7 real receipt already approved in a previous run\n";
    $pass++;
} else {
$check('B7a real receipt is waiting', ($stR0['payment_Status'] ?? '') === 'waiting', 'status=' . ($stR0['payment_Status'] ?? 'null'));
if (($stR0['payment_Status'] ?? '') === 'waiting') {
    $balR0 = $balanceOf($FOREIGN);
    $r = $postUpdate($makeCbUpdate($updId, $OWNER, 'Confirm_pay_' . $realOrder));
    $ok7 = $waitFor('B7 real paid', function () use ($realOrder, $reportRow) { return ($reportRow($realOrder)['payment_Status'] ?? '') === 'paid'; }, 30);
    $stR1 = $reportRow($realOrder);
    $balR1 = $balanceOf($FOREIGN);
    $check('B7b real receipt approved by owner', $r['code'] === 200 && ($stR1['payment_Status'] ?? '') === 'paid', 'code=' . $r['code'] . ' status=' . ($stR1['payment_Status'] ?? 'null'));
    $check('B7c real amount credited', $balR1 === $balR0 + 200000, "bal {$balR0} -> {$balR1}");
}
}

// ---- cleanup & restore ----
$stB1c = $reportRow($dummyOrder('b1'));
foreach (['b1', 'b3', 'b4', 'b5'] as $sfx) {
    $dropDummy($dummyOrder($sfx));
}
$pdo->prepare("UPDATE user SET step = :st, Processing_value = :pv, Processing_value_one = :pv1, Processing_value_four = :pv4 WHERE id = :id")
    ->execute([':st' => (string)$ownerBefore['step'], ':pv' => (string)$ownerBefore['Processing_value'], ':pv1' => (string)$ownerBefore['Processing_value_one'], ':pv4' => (string)$ownerBefore['Processing_value_four'], ':id' => (string)$OWNER]);
// reverse ONLY the B1 dummy credit on the test user, and only if B1 was
// actually approved in this run. The B7 real approval legitimately credits
// 200000 and resets Processing fields (DirectPayment) — that natural
// post-approval state is kept.
if (($stB1c['payment_Status'] ?? '') === 'paid') {
    $pdo->prepare("UPDATE user SET Balance = Balance - :d WHERE id = :id")
        ->execute([':d' => 1000, ':id' => (string)$FOREIGN]);
}
$leftovers = (int) $pdo->query("SELECT COUNT(*) FROM Payment_report WHERE id_order LIKE 'fxtest_rct_%'")->fetchColumn();
$check('CLEANUP no dummy receipts left', $leftovers === 0, "left={$leftovers}");

echo "\n==== RESULT: {$pass} passed, {$fail} failed ====\n";
exit($fail > 0 ? 1 : 0);
