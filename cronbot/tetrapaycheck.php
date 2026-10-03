<?php
require_once __DIR__ . '/_init.php';
rx_cron_boot('tetrapaycheck', 60);

ini_set('error_log', 'error_log');

$ctx = rx_cron_load_payment_context();
if (empty($ctx['db_ready'])) {
    return;
}
require_once __DIR__ . '/../lib/PaymentConfirm.php';

global $pdo, $ManagePanel, $setting;
$ManagePanel = $ctx['managePanel'];
$setting = $ctx['setting'];

if (!($pdo instanceof PDO)) {
    error_log('[tetrapaycheck] no PDO connection');
    return;
}

if (!function_exists('tetrapayCheckStatus')) {
    error_log('[tetrapaycheck] tetrapay functions not available');
    return;
}

try {
    $stmt = $pdo->prepare(
        "SELECT id_order, tetrapay_token, price
           FROM Payment_report
          WHERE payment_Status = 'Unpaid'
            AND Payment_Method = 'tetrapay'
            AND tetrapay_token IS NOT NULL
            AND tetrapay_token <> ''
          ORDER BY id DESC
          LIMIT 30"
    );
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('[tetrapaycheck] select pending failed: ' . $e->getMessage());
    return;
}

if (empty($rows)) {
    return;
}

$successStatuses = ['paid', 'confirmed', 'completed', 'settled', 'success'];
$deadStatuses = ['expired', 'cancelled', 'canceled', 'failed', 'rejected', 'receipt_rejected'];

foreach ($rows as $row) {
    if (function_exists('rx_cron_time_up') && rx_cron_time_up()) break;

    $orderId = (string) $row['id_order'];
    $token = trim((string) $row['tetrapay_token']);
    if ($token === '') {
        continue;
    }

    $check = tetrapayCheckStatus($token);
    if (!is_array($check) || empty($check['ok']) || (string) ($check['token'] ?? '') !== $token) {
        continue;
    }

    $status = strtolower(trim((string) ($check['status'] ?? '')));

    $isDead = in_array($status, $deadStatuses, true);
    $isSuccess = in_array($status, $successStatuses, true);

    if ($isDead) {
        if ($status === 'expired' || $status === 'cancelled' || $status === 'canceled') {
            payment_mark_expired($orderId);
        } else {
            $reasonFa = $status === 'receipt_rejected'
                ? 'رسید پرداخت شما توسط تتراپی رد شد'
                : 'پرداخت توسط تتراپی رد شد';
            payment_notify_user_failed($orderId, $reasonFa);
        }
        continue;
    }

    if (!$isSuccess) {
        continue;
    }

    $finalAmount = isset($check['amount']) ? (int) $check['amount'] : (int) $row['price'];
    $extra = [
        'method'      => 'tetrapay',
        'thread_id'   => $ctx['paymentreports'] ?? null,
        'extra_lines' => array_filter([
            '💸 مبلغ نهایی تأییدشده توسط تتراپی : ' . number_format($finalAmount) . ' تومان',
            '🔁 تایید از طریق پولر',
        ]),
    ];

    payment_confirm_paid($orderId, 'chashbacktetrapay', $extra);
}
