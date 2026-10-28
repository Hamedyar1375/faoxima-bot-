<?php
define("REFACTORED_LEGACY_ROOT", "/var/www/faoxima");
require_once REFACTORED_LEGACY_ROOT . "/vpnbot/6327424512nomayandefoximabot/config.php";
define("FX_RESELLER_ID", 6327424512);
define("FX_RESELLER_TOKEN", $ApiToken);
require_once REFACTORED_LEGACY_ROOT . "/re/rx/function/bootstrap.php";
require_once REFACTORED_LEGACY_ROOT . "/lib/WebhookAuth.php";
$pdo = getDatabaseConnection();
$order = 'fxwatch1';
$pdo->exec("DELETE FROM Payment_report WHERE id_order = '$order'");
$pdo->exec("DELETE FROM payment_fulfillment WHERE id_order = '$order'");
$pdo->exec("INSERT INTO Payment_report (id_order, id_user, price, Payment_Method, payment_Status, dec_not_confirmed, id_invoice, bottype, time) VALUES ('$order', '8400624309', 100, 'cart to cart', 'waiting', 'receipt-submitted', '8400624309-x|y', '" . addslashes($ApiToken) . "', UNIX_TIMESTAMP())");
$wh = FaoximaWebhookAuth::secret((string) $ApiToken, false);
$body = json_encode(['update_id' => time() * 10 + 3, 'callback_query' => ['id' => 'fxwatch_cq', 'from' => ['id' => 6327424512, 'is_bot' => false, 'first_name' => 'w', 'username' => 'w'], 'chat_instance' => 'x', 'message' => ['message_id' => 5, 'date' => time(), 'chat' => ['id' => -1004449450667, 'type' => 'supergroup'], 'text' => 't'], 'data' => 'Confirm_pay_' . $order]]);
$t0 = microtime(true);
$ch = curl_init('http://faoxima-nginx-1/vpnbot/6327424512nomayandefoximabot/index.php');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 90, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-Telegram-Bot-Api-Secret-Token: ' . $wh]]);
$resp = (string) curl_exec($ch);
$secs = round(microtime(true) - $t0, 2);
echo "POST done in {$secs}s code=" . curl_getinfo($ch, CURLINFO_HTTP_CODE) . "\n";
curl_close($ch);
for ($i = 0; $i < 45; $i++) {
    $st = $pdo->query("SELECT payment_Status, direct_payment_done FROM Payment_report WHERE id_order='$order'")->fetch(PDO::FETCH_ASSOC);
    $rec = $pdo->query("SELECT state FROM payment_fulfillment WHERE id_order='$order'")->fetch(PDO::FETCH_ASSOC);
    $bal = $pdo->query("SELECT Balance FROM user WHERE id = 8400624309")->fetchColumn();
    $line = sprintf("t+%3.0fs status=%-10s dpd=%-4s rec=%-12s bal=%s", $i, $st['payment_Status'] ?? '?', var_export($st['direct_payment_done'], true), $rec['state'] ?? '-', $bal);
    echo $line . "\n";
    if (($st['payment_Status'] ?? '') === 'paid') break;
    sleep(1);
}
