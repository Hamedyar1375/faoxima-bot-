<?php
function readJsonFileIfExists($path, $default = [])
{
    if (!is_file($path)) {
        return $default;
    }
    $content = file_get_contents($path);
    if ($content === false || $content === '') {
        return $default;
    }
    $decoded = json_decode($content, true);
    return is_array($decoded) ? $decoded : $default;
}

function DirectPaymentbot($order_id, $image = 'images.jpg'){
    try {
        global $pdo, $ManagePanel, $textbotlang, $keyboardextendfnished, $keyboard, $Confirm_pay, $from_id, $message_id, $datatextbot;
        $setting = select("setting", "*");
        $Payment_report = select("Payment_report", "*", "id_order", $order_id, "select");
        $paymentNote = function_exists('formatPaymentReportNote')
            ? formatPaymentReportNote($Payment_report['dec_not_confirmed'] ?? null)
            : ($Payment_report['dec_not_confirmed'] ?? '');
        $format_price_cart = number_format($Payment_report['price']);
        $Balance_id = select("user", "*", "id", $Payment_report['id_user'], "select");

        update("user","Processing_value","0", "id",$Balance_id['id']);
        update("user","Processing_value_one","0", "id",$Balance_id['id']);
        update("user","Processing_value_tow","0", "id",$Balance_id['id']);
        update("user","Processing_value_four","0", "id",$Balance_id['id']);

        // DB-based atomic credit (Phase 2 fix)
        $amount = intval($Payment_report['price']);
        $uid = $Payment_report['id_user'];
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("UPDATE user SET Balance = Balance + :amt WHERE id = :id");
        $stmt->execute([':amt' => $amount, ':id' => $uid]);
        update("Payment_report","payment_Status","paid","id_order",$Payment_report['id_order']);
        $pdo->commit();

        $Payment_report['price'] = number_format($Payment_report['price'], 0);
        $format_price_cart = $Payment_report['price'];
        if($Payment_report['Payment_Method'] == "cart to cart" or $Payment_report['Payment_Method'] == "arze digital offline"){
            $textconfrom = "⭕️ یک پرداخت جدید انجام شده است
افزایش موجودی.
👤 شناسه کاربر: <code>{$Balance_id['id']}</code>
🛒 کد پیگیری پرداخت: {$Payment_report['id_order']}
⚜️ نام کاربری: @{$Balance_id['username']}
💸 مبلغ پرداختی: $format_price_cart تومان
✍️ توضیحات : {$paymentNote}";
            Editmessagetext($from_id, $message_id, $textconfrom, $Confirm_pay);
        }
        sendmessage($Payment_report['id_user'], "💎 کاربر گرامی مبلغ {$Payment_report['price']} تومان به کیف پول شما واریز گردید با تشکراز پرداخت شما.

🛒 کد پیگیری شما: {$Payment_report['id_order']}", null, 'HTML');
        error_log("[FXDPB] DirectPaymentbot DB-CREDIT OK order=" . $order_id . " uid=" . $uid . " amt=" . $amount);
    } catch (\Throwable $e) {
        if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
        error_log("[FXDPB] DirectPaymentbot FATAL: " . get_class($e) . ": " . $e->getMessage() . " @ " . $e->getFile() . ":" . $e->getLine());
    }
}

function channel_check($id_channel){
    global $from_id;
    $channel_link = array();
    $response = telegram('getChatMember',[
        'chat_id' => $id_channel,
        'user_id' => $from_id
    ]);
    if($response['ok']){
        if(!in_array($response['result']['status'], ['member', 'creator', 'administrator'])){
            $channel_link[] = $id_channel;
        }
    }
    if(count($channel_link) == 0){
        return [];
    }else{
        return $channel_link;
    }
}
