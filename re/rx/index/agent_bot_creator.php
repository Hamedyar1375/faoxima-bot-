<?php
/**
 * Faoxima v1.0.5+ Reseller Bot Creator — Clean Fix (v2, scope bugs fixed)
 *
 * Adds the missing UI for n/n2 agents to create their own reseller bot.
 * Fixes: path bug (REFACTORED_LEGACY_ROOT), webhook secret, and variable
 * scope ($pdo/$setting/$backuser must be global'd inside functions).
 *
 * Lives at: /opt/faoxima/re/rx/index/agent_bot_creator.php
 */

if (!defined('REFACTORED_LEGACY_ROOT')) {
    define('REFACTORED_LEGACY_ROOT', dirname(__DIR__, 3));
}
require_once REFACTORED_LEGACY_ROOT . '/re/rx/function/fx_reseller_bot_registry.php'; // FX-RESELLER bot cap + atomic register (2026-10-15)
require_once REFACTORED_LEGACY_ROOT . '/re/rx/function/fx_reseller_delete_guard.php';
function fx_dbg($evt, $arr) {
    $line = date('Y-m-d H:i:s') . " $evt " . json_encode($arr, JSON_UNESCAPED_UNICODE) . "\n";
    @file_put_contents(REFACTORED_LEGACY_ROOT . '/agentbot_debug.log', $line, FILE_APPEND);
}


/**
 * Step handler: user sends their bot token (step == 'get_agent_bot_token')
 */
function fx_handle_agent_bot_token($from_id, $message_id, $text, $user, $setting, $datatextbot, $domainhosts) {
    fx_dbg('TOKEN_HANDLER', ['from'=>$from_id,'text_len'=>is_string($text)?strlen($text):null,'agent'=>$user['agent']??null]);
    global $pdo, $backuser;

    if (!isset($text) || trim((string)$text) === '') {
        return;
    }

    $tokenRaw = trim((string)$text);
    if (!preg_match('/^\d+:[A-Za-z0-9_-]{20,}$/', $tokenRaw)) {
        nm_adminInstantReply($from_id, "❌ توکن نامعتبر است. فرمت درست: <code>123456789:AAxxxxxxxxxxxxxxxxxxxx</code>\n\nلطفاً توکن را از @BotFather کپی و دوباره ارسال کنید، یا /cancel را بفرستید.", $backuser, 'HTML');
        return;
    }

    // Verify token with Telegram
    $ctx = stream_context_create(['http' => ['timeout' => 10]]);
    $getInfoToken = @json_decode(@file_get_contents("https://api.telegram.org/bot{$tokenRaw}/getMe", false, $ctx), true);
    if (!$getInfoToken || empty($getInfoToken['ok']) || empty($getInfoToken['result']['username'])) {
        nm_adminInstantReply($from_id, "❌ توکن معتبر نیست یا ربات یافت نشد. دوباره بررسی کنید.", $backuser, 'HTML');
        return;
    }

    $remoteUsername = (string) $getInfoToken['result']['username'];
    if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $remoteUsername)) {
        nm_adminInstantReply($from_id, "❌ نام کاربری ربات نامعتبر است.", $backuser, 'HTML');
        return;
    }

    // FX-RESELLER: central cap/count — shared with the admin panel and the API.
    $fxOwn = fx_reseller_bot_count_owner($pdo, (string) $from_id);
    $fxTotal = $fxOwn >= 0 ? fx_reseller_bot_count_total($pdo) : -1;
    $fxTok = $fxTotal >= 0 ? fx_reseller_bot_count_token($pdo, $tokenRaw) : -1;
    if ($fxOwn < 0 || $fxTotal < 0 || $fxTok < 0) {
        nm_adminInstantReply($from_id, "❌ خطا در بررسی وضعیت ربات‌های نماینده. دوباره تلاش کنید.", $backuser, 'HTML');
        step('home', $from_id);
        return;
    }
    if ($fxOwn != 0) {
        nm_adminInstantReply($from_id, "❌ شما قبلاً یک ربات نماینده فعال دارید.", $backuser, 'HTML');
        step('home', $from_id);
        return;
    }
    $fxCap = fx_reseller_bot_cap();
    if ($fxTotal >= $fxCap) {
        nm_adminInstantReply($from_id, "❌ به سقف " . $fxCap . " ربات نماینده رسیده‌ایم.", $backuser, 'HTML');
        step('home', $from_id);
        return;
    }
    if ($fxTok != 0) {
        nm_adminInstantReply($from_id, "❌ این توکن قبلاً ثبت شده است.", $backuser, 'HTML');
        step('home', $from_id);
        return;
    }

    // ---- PATH FIX: use REFACTORED_LEGACY_ROOT ----
    $destination = REFACTORED_LEGACY_ROOT;
    $defaultSource = $destination . '/vpnbot/Default';
    $dirsource = $destination . "/vpnbot/{$from_id}{$remoteUsername}";

    if (!is_dir($defaultSource)) {
        error_log('[agent_bot_creator] Default bot template missing at: ' . $defaultSource);
        nm_adminInstantReply($from_id, "❌ قالب ربات روی سرور یافت نشد. با پشتیبانی تماس بگیرید.", $backuser, 'HTML');
        step('home', $from_id);
        return;
    }

    if (is_dir($dirsource) && !deleteDirectory($dirsource)) {
        error_log('[agent_bot_creator] Failed to remove existing bot directory: ' . $dirsource);
        nm_adminInstantReply($from_id, "❌ پاک‌سازی دایرکتوری قبلی ناموفق بود.", $backuser, 'HTML');
        step('home', $from_id);
        return;
    }

    if (!copyDirectoryContents($defaultSource, $dirsource)) {
        error_log('[agent_bot_creator] Failed to copy bot files to: ' . $dirsource);
        nm_adminInstantReply($from_id, "❌ کپی فایل‌های ربات ناموفق بود.", $backuser, 'HTML');
        step('home', $from_id);
        return;
    }

    // Write the token into the new bot's config.php
    $configFile = $dirsource . "/config.php";
    if (!is_file($configFile)) {
        error_log('[agent_bot_creator] config.php missing in new bot dir: ' . $configFile);
        nm_adminInstantReply($from_id, "❌ فایل کانفیگ ربات یافت نشد.", $backuser, 'HTML');
        step('home', $from_id);
        return;
    }
    $contentconfig = file_get_contents($configFile);
    $new_code = str_replace('BotTokenNew', $tokenRaw, $contentconfig);
    // Phase 2: also write the reseller owner id into the new bot's engine-wrapper index.php
    $_fxWrapperFile = $dirsource . '/index.php';
    if (is_file($_fxWrapperFile)) {
        $_fxW = file_get_contents($_fxWrapperFile);
        if (strpos($_fxW, 'BotUserIdNew') !== false) {
            file_put_contents($_fxWrapperFile, str_replace('BotUserIdNew', (string)$from_id, $_fxW));
        }
    }
    file_put_contents($configFile, $new_code);

    // FX-RESELLER: atomic re-check + insert (named lock inside the registry).
    $fxReg = fx_reseller_bot_register($pdo, (string) $from_id, $tokenRaw, $remoteUsername);
    if (!$fxReg['ok']) {
        $fxCode = (string) ($fxReg['code'] ?? '');
        if ($fxCode === 'db' && is_dir($dirsource) && function_exists('deleteDirectory')) {
            @deleteDirectory($dirsource);
        }
        if ($fxCode === 'user_exists') {
            $fxMsg = "❌ شما قبلاً یک ربات نماینده فعال دارید.";
        } elseif ($fxCode === 'cap') {
            $fxMsg = "❌ به سقف " . (int) $fxReg['cap'] . " ربات نماینده رسیده‌ایم.";
        } elseif ($fxCode === 'token_exists') {
            $fxMsg = "❌ این توکن قبلاً ثبت شده است.";
        } elseif ($fxCode === 'busy') {
            $fxMsg = "⏳ ساخت ربات دیگری در حال انجام است؛ چند لحظه بعد دوباره تلاش کنید.";
        } elseif ($fxCode === 'db') {
            $fxMsg = "❌ ثبت ربات در دیتابیس ناموفق بود: " . (string) ($fxReg['error'] ?? 'unknown');
        } else {
            $fxMsg = "❌ ثبت ربات ناموفق بود. دوباره تلاش کنید.";
        }
        nm_adminInstantReply($from_id, $fxMsg, $backuser, 'HTML');
        step('home', $from_id);
        return;
    }

    // ---- WEBHOOK FIX: include secret_token (reseller index.php enforces it) ----
    $secret_token = hash('sha256', $tokenRaw . '_faoxima_webhook_secret');
    $webhook_url = "https://{$domainhosts}/vpnbot/{$from_id}{$remoteUsername}/index.php";
    $wh_result = telegram('setWebhook', [
        'url' => $webhook_url,
        'secret_token' => $secret_token,
        'allowed_updates' => ['message', 'callback_query', 'edited_message']
    ], $tokenRaw);

    if (!(is_array($wh_result) && !empty($wh_result['ok']))) {
        error_log('[agent_bot_creator] setWebhook failed for ' . $from_id . ': ' . json_encode($wh_result));
        // not fatal — admin can re-run "🔗 وبهوک ربات‌های نماینده"
    }

    // Notify the new bot owner via THEIR bot
    @file_get_contents("https://api.telegram.org/bot{$tokenRaw}/sendMessage?chat_id={$from_id}&text=" . urlencode("✅ ربات نماینده شما با موفقیت نصب و فعال شد!"));

    step('home', $from_id);
    nm_adminInstantReply($from_id, "✅ <b>ربات نماینده شما با موفقیت ساخته شد!</b>\n\n🤖 نام کاربری: @{$remoteUsername}\n🔗 وبهوک: <code>{$webhook_url}</code>\n\nاکنون مشتریان می‌توانند با ربات جدید شما کار کنند.", $backuser, 'HTML');
}

/**
 * Render the agent panel WITH the reseller-bot button
 */
function fx_render_agent_bot_creator_button($from_id, $message_id, $user, $datatextbot, $backuser) {
    global $setting, $textbotlang, $keyboardagent, $pdo;

    if ($user['agent'] === 'f') return;

    $has_bot = fx_reseller_bot_count_owner($pdo, (string) $from_id);
    if ($has_bot) {
        $bot_info = select("botsaz", "*", "id_user", $from_id, "select");
        $keyboard = json_encode([
            'inline_keyboard' => [
                [['text' => "🤖 ربات شما: @{$bot_info['username']}", 'callback_data' => 'agentpanel']],
                [['text' => "❌ حذف ربات نماینده", 'callback_data' => "remove_agent_bot_{$from_id}"]],
                [['text' => $textbotlang['users']['backbtn'] ?? '⬅️ بازگشت', 'callback_data' => 'backuser']]
            ]
        ]);
        nm_adminInstantReply($from_id, "🤖 شما ربات نماینده فعالی دارید:", $keyboard, 'HTML');
        return;
    }

    // Inject "Create Bot" button into the existing agent keyboard
    $keyboard = json_decode((string)$keyboardagent, true);
    if (isset($keyboard['inline_keyboard'])) {
        array_unshift($keyboard['inline_keyboard'], [
            // Disabled self-service callback
        ]);
    } elseif (isset($keyboard['keyboard'])) {
        // Disabled self-service creation
    }
    $keyboardagent = json_encode($keyboard);

    nm_adminInstantReply($from_id, $textbotlang['Admin']['agent']['agenttext'], $keyboardagent, 'HTML');
}

/**
 * Callback: create_agent_bot → start token input flow
 */
function fx_agent_bot_create_start($from_id, $message_id, $user, $datatextbot, $backuser) {
    fx_dbg('CREATE_START', ['from'=>$from_id,'agent'=>$user['agent']??null,'step'=>$user['step']??null,'bal'=>$user['Balance']??null]);
    global $setting;

    fx_dbg('CREATE_START_gate', ['agentreqprice'=>$setting['agentreqprice']??null]);
    if (!empty($setting['agentreqprice']) && $setting['agentreqprice'] > 0 && $user['agent'] !== 'n2') {
        if ($user['Balance'] < $setting['agentreqprice']) {
            $price = number_format((int)$setting['agentreqprice']);
            nm_adminInstantReply($from_id, "❌ موجودی شما کمتر از هزینه نمایندگی ({$price} تومان) است.", $backuser, 'HTML');
            return;
        }
    }

    fx_dbg('CREATE_START_setstep', ['from'=>$from_id]);
    step('get_agent_bot_token', $from_id);
    nm_adminInstantReply($from_id,
        "🤖 <b>توکن ربات تلگرام خود را ارسال کنید:</b>\n\n" .
        "۱. در @BotFather با دستور /newbot یک ربات جدید بسازید\n" .
        "۲. توکن را کپی و همین‌جا بفرستید\n\n" .
        "⚠️ ربات نباید قبلاً وبهوک داشته باشد.\n" .
        "برای انصراف /cancel را بفرستید.",
        $backuser, 'HTML'
    );
}

/**
 * Callback: remove_agent_bot_{id} → delete the reseller bot
 */
function fx_agent_bot_remove($from_id, $message_id, $target_id, $user, $datatextbot, $backuser, $domainhosts) {
    global $pdo;

    if ((string)$from_id !== (string)$target_id) {
        nm_adminInstantReply($from_id, "❌ دسترسی ندارید.", $backuser, 'HTML');
        return;
    }

    $contentbto = select("botsaz", "*", "id_user", $target_id, "select");
    if (!$contentbto) {
        nm_adminInstantReply($from_id, "❌ ربات یافت نشد.", $backuser, 'HTML');
        return;
    }

    $guard = fx_reseller_delete_guard($pdo, (string)$target_id);
    if (empty($guard['allowed'])) {
        nm_adminInstantReply($from_id, (string)($guard['message'] ?? '❌ حذف ربات انجام نشد.'), $backuser, 'HTML');
        return;
    }

    $archive = fx_reseller_archive_before_delete($pdo, (string)$target_id, ['source' => 'agent_bot_creator']);
    if (empty($archive['ok'])) {
        nm_adminInstantReply($from_id, (string)($archive['message'] ?? '❌ آرشیو امن انجام نشد.'), $backuser, 'HTML');
        return;
    }
    if (!empty($archive['path'])) error_log('[agent_bot_creator] delete archive: ' . $archive['path']);

    $destination = REFACTORED_LEGACY_ROOT;
    $dirsource = "{$destination}/vpnbot/{$target_id}{$contentbto['username']}";

    if (is_dir($dirsource) && !deleteDirectory($dirsource)) {
        error_log('[agent_bot_creator] Failed to remove bot directory: ' . $dirsource);
    }

    if (!empty($contentbto['bot_token'])) {
        @file_get_contents("https://api.telegram.org/bot{$contentbto['bot_token']}/deleteWebhook");
    }

    try {
        $stmt = $pdo->prepare("DELETE FROM botsaz WHERE id_user = :id_user");
        $stmt->bindValue(':id_user', (string)$target_id, PDO::PARAM_STR);
        $stmt->execute();
    } catch (Throwable $e) {
        error_log('[agent_bot_creator] delete failed: ' . $e->getMessage());
    }

    step('home', $from_id);
    nm_adminInstantReply($from_id, "✅ ربات نماینده با موفقیت حذف شد.", $backuser, 'HTML');
}