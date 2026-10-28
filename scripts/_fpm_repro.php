<?php
define("REFACTORED_LEGACY_ROOT", "/var/www/faoxima");
require_once REFACTORED_LEGACY_ROOT . "/vpnbot/6327424512nomayandefoximabot/config.php";
define("FX_RESELLER_ID", 6327424512);
define("FX_RESELLER_TOKEN", $ApiToken);
require_once REFACTORED_LEGACY_ROOT . "/re/rx/function/bootstrap.php";
require_once REFACTORED_LEGACY_ROOT . "/lib/WebhookAuth.php";
$pdo = getDatabaseConnection();
$tok = $ApiToken;
$order = 'fxrepro1';
$pdo->exec("DELETE FROM Payment_report WHERE id_order = '$order'");
$pdo->exec("DELETE FROM payment_fulfillment WHERE id_order = '$order'");
$pdo->exec("INSERT INTO Payment_report (id_order, id_user, price, Payment_Method, payment_Status, dec_not_confirmed, id_invoice, bottype, time) VALUES ('$order', '8400624309', 100, 'cart to cart', 'waiting', 'receipt-submitted', '8400624309-x|y', '" . addslashes($tok) . "', UNIX_TIMESTAMP())");
$row = $pdo->query("SELECT id, id_order, payment_Status FROM Payment_report WHERE id_order='$order'")->fetch(PDO::FETCH_ASSOC);
echo "BEFORE POST: ", json_encode($row), "\n";
$wh = FaoximaWebhookAuth::secret((string) $tok, false);
$body = json_encode([
    'update_id' => time() * 10 + 7,
    'callback_query' => [
        'id' => 'fxrepro_cq_1',
        'from' => ['id' => 6327424512, 'is_bot' => false, 'first_name' => 'fxrepro', 'username' => 'fxrepro'],
        'chat_instance' => 'x',
        'message' => ['message_id' => 5, 'date' => time(), 'chat' => ['id' => -1004449450667, 'type' => 'supergroup'], 'text' => 't'],
        'data' => 'Confirm_pay_' . $order,
    ],
]);
$ch = curl_init('http://faoxima-nginx-1/vpnbot/6327424512nomayandefoximabot/index.php');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-Telegram-Bot-Api-Secret-Token: ' . $wh]]);
$resp = (string) curl_exec($ch);
echo "POST http_code: ", curl_getinfo($ch, CURLINFO_HTTP_CODE), " body: ", substr($resp, 0, 100), "\n";
curl_close($ch);
$row = $pdo->query("SELECT id_order, payment_Status, direct_payment_done FROM Payment_report WHERE id_order='$order'")->fetch(PDO::FETCH_ASSOC);
echo "AFTER POST: ", json_encode($row), "\n";
$rec = $pdo->query("SELECT id_order, state, attempt_count, last_error FROM payment_fulfillment WHERE id_order='$order'")->fetch(PDO::FETCH_ASSOC);
echo "FULFILLMENT: ", json_encode($rec), "\n";
$bal = $pdo->query("SELECT Balance FROM user WHERE id = 8400624309")->fetchColumn();
echo "BALANCE: $bal\n";
