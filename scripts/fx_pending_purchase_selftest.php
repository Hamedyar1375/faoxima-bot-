<?php

/**
 * fx_pending_purchase_selftest.php — isolated checks for pending purchase
 * session handling and cancellation.
 *
 * Runs only against faoxima_botcap_test. Production data is never modified.
 *
 *   docker compose exec -T app php /var/www/faoxima/scripts/fx_pending_purchase_selftest.php
 */

declare(strict_types=1);

error_reporting(E_ALL & ~E_DEPRECATED);
date_default_timezone_set('Asia/Tehran');

define('REFACTORED_LEGACY_ROOT', dirname(__DIR__));
require_once REFACTORED_LEGACY_ROOT . '/config.php';
require_once REFACTORED_LEGACY_ROOT . '/function.php';
require_once REFACTORED_LEGACY_ROOT . '/re/rx/index/fx_debt.php';
if (!defined('FX_RESELLER_ID')) {
    define('FX_RESELLER_ID', 6327424512);
}

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
$check('pending helper loaded', function_exists('rx_pending_purchase_get_active'));

$__catalogProduct = [
    'name_product' => 'نماینده',
    'code_product' => 'TEST002',
    'price_product' => '55000',
    'fx_base_price' => '55000',
    'fx_wholesale_price' => null,
];
fx_apply_reseller_retail($__catalogProduct, []);
$__wholesale = fx_credit_wholesale_product($__catalogProduct, [], '6327424512');
$check('catalog product gets wholesale fallback from base', (int) ($__catalogProduct['fx_wholesale_price'] ?? 0) === 55000);
$check('wholesale resolver succeeds for pending fulfillment', !empty($__wholesale['ok']) && (int) $__wholesale['amount'] === 55000, json_encode($__wholesale, JSON_UNESCAPED_UNICODE));

$pdoTest->exec('DROP TABLE IF EXISTS invoice');
$pdoTest->exec('DROP TABLE IF EXISTS Payment_report');
$pdoTest->exec('DROP TABLE IF EXISTS user');
$pdoTest->exec("CREATE TABLE user (
    id varchar(64) NOT NULL PRIMARY KEY,
    Balance varchar(64) NULL DEFAULT '0',
    Processing_value text NULL,
    Processing_value_one text NULL,
    Processing_value_tow text NULL,
    Processing_value_four text NULL,
    agent varchar(64) NULL,
    bottype varchar(200) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdoTest->exec("CREATE TABLE invoice (
    id_invoice varchar(200) NOT NULL PRIMARY KEY,
    id_user varchar(200) NULL,
    username varchar(300) NULL,
    time_sell varchar(200) NULL,
    Service_location varchar(300) NULL,
    name_product varchar(200) NULL,
    price_product varchar(200) NULL,
    Volume varchar(200) NULL,
    Service_time varchar(200) NULL,
    Status varchar(200) NULL,
    invalidated_at int NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdoTest->exec("CREATE TABLE Payment_report (
    id bigint unsigned NOT NULL AUTO_INCREMENT,
    id_order varchar(64) NOT NULL UNIQUE,
    id_user varchar(64) NOT NULL,
    id_invoice varchar(500) NULL,
    payment_Status varchar(64) NULL,
    Payment_Method varchar(100) NULL,
    bottype varchar(200) NULL,
    dec_not_confirmed varchar(300) NULL,
    time varchar(200) NULL,
    at_updated varchar(200) NULL,
    direct_payment_done tinyint NOT NULL DEFAULT 0,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$uid = '700000001';
$otherUid = '700000002';
$pdoTest->prepare("INSERT INTO user (id, Balance, Processing_value, Processing_value_one, Processing_value_tow, Processing_value_four) VALUES (?, '1000000', '0', '0', '0', '0')")->execute([$uid]);
$pdoTest->prepare("INSERT INTO user (id, Balance, Processing_value, Processing_value_one, Processing_value_tow, Processing_value_four) VALUES (?, '9000000', '0', '0', '0', '0')")->execute([$otherUid]);
$pdoTest->prepare("INSERT INTO invoice (id_invoice, id_user, username, time_sell, Service_location, name_product, price_product, Volume, Service_time, Status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
    ->execute(['INV-PEND-1', $uid, 'pending-user', (string) time(), 'panel-a', 'پلن تست', '5000000', '30', '30', 'unpaid']);

$invoice = rx_pending_purchase_get_active($uid);
$check('active pending invoice found', is_array($invoice) && ($invoice['id_invoice'] ?? '') === 'INV-PEND-1');
$check('other user has no pending invoice', rx_pending_purchase_get_active($otherUid) === null);
$check('remaining due uses current balance', rx_pending_purchase_remaining_due($invoice, 1000000) === 4000000);
$check('remaining due cannot be negative', rx_pending_purchase_remaining_due($invoice, 9000000) === 0);

$__oldTs = (string) (time() - 90000);
$pdoTest->prepare("INSERT INTO invoice (id_invoice, id_user, username, time_sell, Service_location, name_product, price_product, Volume, Service_time, Status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
    ->execute(['INV-OLD-1', $uid, 'old-pending', $__oldTs, 'panel-a', 'پلن قدیمی', '3000000', '10', '30', 'unpaid']);
$pdoTest->prepare("INSERT INTO invoice (id_invoice, id_user, username, time_sell, Service_location, name_product, price_product, Volume, Service_time, Status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
    ->execute(['INV-OLD-WAIT', $uid, 'old-waiting', $__oldTs, 'panel-a', 'پلن در انتظار', '3000000', '10', '30', 'unpaid']);
$pdoTest->prepare("INSERT INTO Payment_report (id_order, id_user, id_invoice, payment_Status) VALUES (?, ?, ?, ?)")
    ->execute(['ORD-OLD-WAIT', $uid, 'getconfigafterpay|old-waiting', 'waiting']);
$__expiredCount = rx_pending_purchase_expire_stale($uid, 86400, 50);
$__oldStatus = $pdoTest->query("SELECT Status FROM invoice WHERE id_invoice = 'INV-OLD-1'")->fetchColumn();
$__waitStatus = $pdoTest->query("SELECT Status FROM invoice WHERE id_invoice = 'INV-OLD-WAIT'")->fetchColumn();
$check('stale unpaid invoice expires after 24h', $__expiredCount >= 1 && (string) $__oldStatus === 'expired', 'count=' . $__expiredCount . ' status=' . $__oldStatus);
$check('unpaid invoice with waiting payment is preserved', (string) $__waitStatus === 'unpaid');
$check('visible service count excludes unpaid/expired', rx_user_visible_invoice_count($uid) === 0);
$lowUid = '700000003';
$pdoTest->prepare("INSERT INTO user (id, Balance, Processing_value, Processing_value_one, Processing_value_tow, Processing_value_four) VALUES (?, '100000', '0', 'low-user', 'getconfigafterpay', '0')")->execute([$lowUid]);
$pdoTest->prepare("INSERT INTO invoice (id_invoice, id_user, username, time_sell, Service_location, name_product, price_product, Volume, Service_time, Status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
    ->execute(['INV-LOW-DUE', $lowUid, 'low-user', (string) time(), 'panel-a', 'پلن کسری', '101000', '10', '30', 'unpaid']);
$lowState = $pdoTest->query("SELECT id, Balance, Processing_value_tow FROM user WHERE id = '{$lowUid}'")->fetch(PDO::FETCH_ASSOC);
$check('deficiency below normal min is detected exactly', rx_pending_purchase_deficiency_due($lowState) === 1000);


$__dueKb = json_decode(rx_pending_purchase_keyboard(4000000), true);
$__dueButton = $__dueKb['inline_keyboard'][0][0] ?? [];
$__cancelButton = $__dueKb['inline_keyboard'][1][0] ?? [];
$check('keyboard shows exact due amount', strpos((string) ($__dueButton['text'] ?? ''), '4,000,000') !== false, (string) ($__dueButton['text'] ?? ''));
$check('keyboard has no copy-text button', strpos((string) json_encode($__dueKb, JSON_UNESCAPED_UNICODE), 'copy_text') === false);
$check('keyboard keeps cancel button', ($__cancelButton['callback_data'] ?? '') === 'pending_purchase_cancel');

rx_pending_purchase_set_session($uid, $invoice);
$state = $pdoTest->query("SELECT Processing_value_one, Processing_value_tow FROM user WHERE id = '{$uid}'")->fetch(PDO::FETCH_ASSOC);
$check('session markers set for auto-completion', (string) $state['Processing_value_one'] === 'pending-user' && (string) $state['Processing_value_tow'] === 'getconfigafterpay');

$blocked = rx_pending_purchase_cancel($uid);
$check('cancel succeeds without waiting payment', !empty($blocked['ok']));
$invoiceStatus = $pdoTest->query("SELECT Status FROM invoice WHERE id_invoice = 'INV-PEND-1'")->fetchColumn();
$check('cancel marks invoice cancelled', (string) $invoiceStatus === 'cancelled');
$state = $pdoTest->query("SELECT Processing_value_one, Processing_value_tow FROM user WHERE id = '{$uid}'")->fetch(PDO::FETCH_ASSOC);
$check('cancel clears session markers', (string) $state['Processing_value_one'] === '0' && (string) $state['Processing_value_tow'] === '0');

$pdoTest->prepare("INSERT INTO invoice (id_invoice, id_user, username, time_sell, Service_location, name_product, price_product, Volume, Service_time, Status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
    ->execute(['INV-PEND-2', $uid, 'pending-user-2', (string) time(), 'panel-a', 'پلن تست ۲', '3000000', '10', '30', 'unpaid']);
$pdoTest->prepare("INSERT INTO Payment_report (id_order, id_user, id_invoice, payment_Status) VALUES (?, ?, ?, ?)")
    ->execute(['ORD-WAIT-1', $uid, 'getconfigafterpay|pending-user-2', 'waiting']);

$waitingCancel = rx_pending_purchase_cancel($uid);
$check('cancel blocked while receipt is waiting', empty($waitingCancel['ok']) && ($waitingCancel['reason'] ?? '') === 'payment_in_progress');
$invoice2Status = $pdoTest->query("SELECT Status FROM invoice WHERE id_invoice = 'INV-PEND-2'")->fetchColumn();
$check('waiting payment does not cancel invoice', (string) $invoice2Status === 'unpaid');

$pdoTest->prepare("UPDATE Payment_report SET payment_Status = 'paid' WHERE id_order = 'ORD-WAIT-1'")->execute();
$afterPaidCancel = rx_pending_purchase_cancel($uid);
$check('cancel works after payment is finalized', !empty($afterPaidCancel['ok']));
$invoice2Status = $pdoTest->query("SELECT Status FROM invoice WHERE id_invoice = 'INV-PEND-2'")->fetchColumn();
$check('finalized payment then invoice cancelled', (string) $invoice2Status === 'cancelled');

$uidFlow = '700000004';
if (!function_exists('deletemessage')) {
    function deletemessage($chat, $mid, $token = null) {
        $GLOBALS['rx_test_deleted'][] = [(string) $chat, (int) $mid];
        return ['ok' => true];
    }
}
$GLOBALS['rx_test_deleted'] = [];
rx_flow_message_store($uidFlow, 'purchase', 111);
rx_flow_message_store($uidFlow, 'wallet', 112);
$cleared = rx_flow_message_clear_all($uidFlow);
$remaining = (int) $pdoTest->query("SELECT COUNT(*) FROM user_flow_messages WHERE user_id = " . $pdoTest->quote($uidFlow))->fetchColumn();
$check('flow cleanup deletes all stored messages', $cleared === 2 && $remaining === 0, 'cleared=' . $cleared . ' remaining=' . $remaining);
$check('flow cleanup removes purchase message from chat', in_array([$uidFlow, 111], $GLOBALS['rx_test_deleted'], true));

$check('receipt prompt is detected as interactive', rx_flow_message_is_interactive('🖼 تصویر رسید خود را ارسال نمایید', null));
$check('message with keyboard is interactive', rx_flow_message_is_interactive('اطلاعات سرویس شما', '{"inline_keyboard":[]}'));
$check('plain service info is not interactive', !rx_flow_message_is_interactive('✅ سرویس شما با موفقیت ساخته شد. لینک اشتراک: https://example.test/sub', null));
$GLOBALS['rx_test_deleted'] = [];
rx_flow_message_track_outgoing($uidFlow, 201, 'تصویر رسید خود را ارسال نمایید', null);
rx_flow_message_track_outgoing($uidFlow, 202, 'مبلغ را وارد کنید', null);
$x = $pdoTest->prepare('SELECT message_id FROM user_flow_messages WHERE user_id = ? AND scope LIKE ? ORDER BY message_id');
$x->execute([$uidFlow, 'auto:%']);
$autoIds = array_map('intval', $x->fetchAll(PDO::FETCH_COLUMN));
$check('outgoing tracker keeps all interactive messages', $autoIds === [201, 202], json_encode($autoIds));
$check('outgoing tracker never deletes previous interactive message', !in_array([$uidFlow, 201], $GLOBALS['rx_test_deleted'], true));
rx_flow_message_track_outgoing($uidFlow, 203, '✅ سرویس شما با موفقیت ساخته شد.', null);
$x->execute([$uidFlow, 'auto:%']);
$autoIds = array_map('intval', $x->fetchAll(PDO::FETCH_COLUMN));
$check('informational message is not tracked', $autoIds === [201, 202], json_encode($autoIds));
$rxClearedTracked = rx_flow_message_clear_all($uidFlow);
$check('start/cancel can still delete all tracked interactive messages', $rxClearedTracked === 2 && in_array([$uidFlow, 201], $GLOBALS['rx_test_deleted'], true) && in_array([$uidFlow, 202], $GLOBALS['rx_test_deleted'], true));
$pdoTest->exec('DROP TABLE IF EXISTS user_flow_messages');

$pdoTest->prepare("INSERT INTO invoice (id_invoice, id_user, username, time_sell, Service_location, name_product, price_product, Volume, Service_time, Status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
    ->execute(['INV-RCPT-1', $uid, 'receipt-user', (string) time(), 'panel-a', 'رسید تست', '100000', '10', '30', 'unpaid']);
$pdoTest->prepare("INSERT INTO Payment_report (id_order, id_user, id_invoice, payment_Status, Payment_Method, dec_not_confirmed, time, at_updated) VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
    ->execute(['ORD-RCPT-1', $uid, 'getconfigafterpay|receipt-user', 'waiting', 'cart to cart', 'receipt-submitted', date('Y/m/d H:i:s'), date('Y/m/d H:i:s')]);
$cancelReceipt = rxReceiptUserCancel('ORD-RCPT-1', $uid, 'cancelled_by_user');
$receiptStatus = $pdoTest->query("SELECT payment_Status FROM Payment_report WHERE id_order = 'ORD-RCPT-1'")->fetchColumn();
$receiptInvoice = $pdoTest->query("SELECT Status FROM invoice WHERE id_invoice = 'INV-RCPT-1'")->fetchColumn();
$check('user can cancel own waiting receipt', !empty($cancelReceipt['ok']) && (string) $receiptStatus === 'reject', json_encode($cancelReceipt, JSON_UNESCAPED_UNICODE));
$check('user receipt cancel cancels related invoice', (string) $receiptInvoice === 'cancelled', (string) $receiptInvoice);

if (!function_exists('sendmessage')) {
    function sendmessage($chat, $text, $keyboard = null, $parse = 'HTML', $token = null) { return true; }
}
$pdoTest->exec('DROP TABLE IF EXISTS payment_fulfillment');
$pdoTest->exec("CREATE TABLE payment_fulfillment (
    id_order varchar(191) NOT NULL PRIMARY KEY,
    state varchar(20) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Central fulfillment completion must move waiting/pending receipts to paid.
$pdoTest->prepare("INSERT INTO Payment_report (id_order, id_user, id_invoice, payment_Status, Payment_Method, dec_not_confirmed, time, at_updated, direct_payment_done) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)")
    ->execute(['ORD-MARK-PAID', $uid, 'mark-token', 'waiting', 'cart to cart', 'receipt-submitted', date('Y/m/d H:i:s'), date('Y/m/d H:i:s'), 0]);
rx_pf_mark_paid_done($pdoTest, 'ORD-MARK-PAID');
$marked = $pdoTest->query("SELECT payment_Status, direct_payment_done FROM Payment_report WHERE id_order = 'ORD-MARK-PAID'")->fetch(PDO::FETCH_ASSOC);
$check('fulfillment completion marks waiting receipt paid', (string) ($marked['payment_Status'] ?? '') === 'paid' && (int) ($marked['direct_payment_done'] ?? 0) === 1, json_encode($marked, JSON_UNESCAPED_UNICODE));

// Old waiting receipt with a successful direct payment must be healed to paid.
$pdoTest->prepare("INSERT INTO Payment_report (id_order, id_user, id_invoice, payment_Status, Payment_Method, dec_not_confirmed, time, at_updated, direct_payment_done) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)")
    ->execute(['ORD-RCPT-DONE', $uid, 'done-token', 'waiting', 'cart to cart', 'receipt-submitted', date('Y/m/d H:i:s', time() - 7200), date('Y/m/d H:i:s', time() - 7200), 1]);

// Completed fulfillment + active invoice must never be auto-expired.
$pdoTest->prepare("INSERT INTO invoice (id_invoice, id_user, username, time_sell, Service_location, name_product, price_product, Volume, Service_time, Status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
    ->execute(['INV-FULFILL', $uid, 'fulfill-user', (string) time(), 'panel-a', 'سرویس موفق', '100000', '10', '30', 'active']);
$pdoTest->prepare("INSERT INTO Payment_report (id_order, id_user, id_invoice, payment_Status, Payment_Method, dec_not_confirmed, time, at_updated, direct_payment_done) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)")
    ->execute(['ORD-RCPT-FULFILL', $uid, 'getconfigafterpay|fulfill-user', 'waiting', 'cart to cart', 'receipt-submitted', date('Y/m/d H:i:s', time() - 7200), date('Y/m/d H:i:s', time() - 7200), 0]);
$pdoTest->prepare("INSERT INTO payment_fulfillment (id_order, state) VALUES (?, ?)")->execute(['ORD-RCPT-FULFILL', 'completed']);

$pdoTest->prepare("INSERT INTO Payment_report (id_order, id_user, id_invoice, payment_Status, Payment_Method, dec_not_confirmed, time, at_updated, direct_payment_done) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)")
    ->execute(['ORD-RCPT-OLD', $uid, 'old-crypto-token', 'waiting', 'cart to cart', 'receipt-submitted', date('Y/m/d H:i:s', time() - 7200), date('Y/m/d H:i:s', time() - 7200), 0]);
$expiredReceipts = rxExpireStalePendingReceipts(1800, 20);
$oldReceiptStatus = $pdoTest->query("SELECT payment_Status FROM Payment_report WHERE id_order = 'ORD-RCPT-OLD'")->fetchColumn();
$doneReceiptStatus = $pdoTest->query("SELECT payment_Status FROM Payment_report WHERE id_order = 'ORD-RCPT-DONE'")->fetchColumn();
$fulfilledReceiptStatus = $pdoTest->query("SELECT payment_Status FROM Payment_report WHERE id_order = 'ORD-RCPT-FULFILL'")->fetchColumn();
$check('stale waiting receipt is never auto-expired', $expiredReceipts === 0 && (string) $oldReceiptStatus === 'waiting', 'count=' . $expiredReceipts . ' status=' . $oldReceiptStatus);
$check('successful direct payment receipt heals to paid', (string) $doneReceiptStatus === 'paid', (string) $doneReceiptStatus);
$check('completed fulfillment receipt heals to paid', (string) $fulfilledReceiptStatus === 'paid', (string) $fulfilledReceiptStatus);
$check('completed fulfillment is not counted as expired', $expiredReceipts === 0, 'count=' . $expiredReceipts);

$pdoTest->exec('DROP TABLE IF EXISTS botsaz');

echo "\nPASS={$pass} FAIL={$fail}\n";
exit($fail === 0 ? 0 : 1);
