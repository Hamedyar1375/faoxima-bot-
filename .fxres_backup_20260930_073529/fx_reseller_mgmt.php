<?php
/**
 * Phase 2 — Reseller Management Helper (additive)
 *
 * Provides:
 * 1. DB-based balance ops for reseller bots (replaces legacy JSON files)
 * 2. Main-admin UI for per-reseller product/panel assignment + balance management
 * 3. Pasargad panel credential assignment to resellers
 *
 * Loaded via require_once OUTSIDE the .compiled.php bundle (like agent_bot_creator.php).
 */

function fx_reseller_db_credit(int $uid, int $amount, string $method = 'reseller_credit', ?string $bottype = null): bool {
    global $pdo;
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("UPDATE user SET Balance = Balance + :amt WHERE id = :id");
        $stmt->execute([':amt' => $amount, ':id' => $uid]);
        $orderId = bin2hex(random_bytes(5));
        $now = date('Y/m/d H:i:s');
        $ins = $pdo->prepare("INSERT INTO Payment_report (id_user,id_order,time,price,payment_Status,Payment_Method,id_invoice,bottype) VALUES (?,?,?,?,?,?,?,?)");
        $ins->execute([$uid, $orderId, $now, $amount, 'paid', $method, null, $bottype]);
        $pdo->commit();
        return true;
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log("[FX_RES] credit failed uid=$uid: " . $e->getMessage());
        return false;
    }
}

function fx_reseller_db_debit(int $uid, int $amount, string $method = 'reseller_debit', ?string $bottype = null): bool {
    global $pdo;
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("UPDATE user SET Balance = Balance - :amt WHERE id = :id AND Balance >= :amt2");
        $stmt->execute([':amt' => $amount, ':id' => $uid, ':amt2' => $amount]);
        if ($stmt->rowCount() === 0) {
            $pdo->rollBack();
            return false;
        }
        $orderId = bin2hex(random_bytes(5));
        $now = date('Y/m/d H:i:s');
        $ins = $pdo->prepare("INSERT INTO Payment_report (id_user,id_order,time,price,payment_Status,Payment_Method,id_invoice,bottype) VALUES (?,?,?,?,?,?,?,?)");
        $ins->execute([$uid, $orderId, $now, -$amount, 'paid', $method, null, $bottype]);
        $pdo->commit();
        return true;
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log("[FX_RES] debit failed uid=$uid: " . $e->getMessage());
        return false;
    }
}

function fx_reseller_db_balance(int $uid): int {
    global $pdo;
    $stmt = $pdo->prepare("SELECT Balance FROM user WHERE id = :id");
    $stmt->execute([':id' => $uid]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? intval($row['Balance']) : 0;
}

function fx_reseller_get_all(): array {
    global $pdo;
    $stmt = $pdo->query("SELECT b.id_user, b.username, b.bot_token, u.Balance, u.agent FROM botsaz b LEFT JOIN user u ON u.id = b.id_user");
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function fx_reseller_assign_product_agent(int $productId, string $agentId): bool {
    global $pdo;
    $stmt = $pdo->prepare("UPDATE product SET agent = :agent WHERE id = :id");
    $stmt->execute([':agent' => $agentId, ':id' => $productId]);
    return $stmt->rowCount() > 0;
}

function fx_reseller_assign_panel_agent(int $panelId, string $agentId): bool {
    global $pdo;
    $stmt = $pdo->prepare("UPDATE marzban_panel SET agent = :agent WHERE id = :id");
    $stmt->execute([':agent' => $agentId, ':id' => $panelId]);
    return $stmt->rowCount() > 0;
}

function fx_reseller_set_pasargad_creds(int $panelId, string $username, string $password, string $authMode = 'password'): bool {
    global $pdo;
    $stmt = $pdo->prepare("UPDATE marzban_panel SET username_panel = :u, password_panel = :p, pasarguard_auth_mode = :m WHERE id = :id");
    $stmt->execute([':u' => $username, ':p' => $password, ':m' => $authMode, ':id' => $panelId]);
    return $stmt->rowCount() > 0;
}

/**
 * Central reseller bot creation (admin-driven only).
 */
function fx_reseller_create_bot(int $targetUid, string $tokenRaw): array {
    global $pdo;
    $tokenRaw = trim($tokenRaw);
    if (!preg_match('/^\d+:[A-Za-z0-9_-]{20,}$/', $tokenRaw)) {
        return [false, '❌ توکن نامعتبر است. فرمت: 123456789:AAxxx'];
    }
    $targetUser = select("user", "*", "id", $targetUid, "select", ['cache' => false]);
    if (!$targetUser || !is_array($targetUser)) return [false, '❌ کاربر/نماینده با این آیدی وجود ندارد.'];
    $existing = select("botsaz", "*", "id_user", $targetUid, "select", ['cache' => false]);
    if ($existing) return [false, '❌ این نماینده قبلا ربات فعال دارد.'];
    $countBots = select("botsaz", "*", null, null, "count", ['cache' => false]);
    if ($countBots >= 15) return [false, '❌ به سقف ۱۵ ربات نماینده رسیدیم.'];
    $dup = select("botsaz", "*", "bot_token", $tokenRaw, "count", ['cache' => false]);
    if ($dup != 0) return [false, '❌ این توکن قبلا ثبت شده است.'];
    $ctx = stream_context_create(['http' => ['timeout' => 10]]);
    $getMe = @json_decode(@file_get_contents("https://api.telegram.org/bot{$tokenRaw}/getMe", false, $ctx), true);
    if (!$getMe || empty($getMe['ok']) || empty($getMe['result']['username'])) return [false, '❌ توکن معتبر نیست یا ربات یافت نشد.'];
    $remoteUsername = (string)$getMe['result']['username'];
    if (!preg_match('/^[A-Za-z0-9_]{1,32}$/', $remoteUsername)) return [false, '❌ نام کاربری ربات نامعتبر است.'];
    $root = REFACTORED_LEGACY_ROOT;
    $defaultSource = $root . '/vpnbot/Default';
    $dirsource = $root . "/vpnbot/{$targetUid}{$remoteUsername}";
    if (!is_dir($defaultSource)) return [false, '❌ قالب ربات (vpnbot/Default) یافت نشد.'];
    if (is_dir($dirsource) && function_exists('deleteDirectory') && !deleteDirectory($dirsource)) return [false, '❌ پاکسازی دایرکتوری قبلی ناموفق بود.'];
    if (!function_exists('copyDirectoryContents') || !copyDirectoryContents($defaultSource, $dirsource)) return [false, '❌ کپی فایل‌های ربات ناموفق بود.'];
    $configFile = $dirsource . "/config.php";
    if (is_file($configFile)) {
        $cfg = file_get_contents($configFile);
        file_put_contents($configFile, str_replace('BotTokenNew', $tokenRaw, $cfg));
    }
    $wrapperFile = $dirsource . "/index.php";
    if (is_file($wrapperFile)) {
        $w = file_get_contents($wrapperFile);
        if (strpos($w, 'BotUserIdNew') !== false) {
            file_put_contents($wrapperFile, str_replace('BotUserIdNew', (string)$targetUid, $w));
        } elseif (strpos($w, "define('FX_RESELLER_ID',") !== false) {
            $w = preg_replace("/define\('FX_RESELLER_ID',[^;]*\);/", "define('FX_RESELLER_ID', " . (string)$targetUid . ");", $w, 1);
            file_put_contents($wrapperFile, $w);
        }
    }
    $domain = $GLOBALS['domainhosts'] ?? 'faoxima.downloadbazi.fun/faoxima';
    $secret = hash('sha256', $tokenRaw . '_faoxima_webhook_secret');
    $wh = telegram('setWebhook', [
        'url' => "https://{$domain}/vpnbot/{$targetUid}{$remoteUsername}/index.php",
        'secret_token' => $secret,
        'allowed_updates' => ['message', 'callback_query', 'edited_message']
    ], $tokenRaw);
    if (!(is_array($wh) && !empty($wh['ok']))) return [false, '⚠️ ربات ساخته شد ولی ست وبهوک ناموفق بود.'];
    @file_get_contents("https://api.telegram.org/bot{$tokenRaw}/sendMessage?chat_id={$targetUid}&text=" . urlencode("✅ ربات نماینده شما از طریق مدیریت مرکزی ساخته و فعال شد!"));
    $datasetting = json_encode([
        "minpricetime" => 4000, "pricetime" => 4000, "minpricevolume" => 4000, "pricevolume" => 4000,
        "support_username" => "@support", "Channel_Report" => 0,
        "cart_info" => "جهت پرداخت مبلغ را به شماره کارت زیر واریز نمایید", 'show_product' => true,
    ], JSON_UNESCAPED_UNICODE);
    try {
        $stmt = $pdo->prepare("INSERT INTO botsaz (id_user, bot_token, admin_ids, username, time, setting, hide_panel) VALUES (:u, :t, :a, :x, :tm, :s, :h)");
        $stmt->bindValue(':u', (string)$targetUid, PDO::PARAM_STR);
        $stmt->bindValue(':t', $tokenRaw, PDO::PARAM_STR);
        $stmt->bindValue(':a', json_encode([(string)$targetUid]), PDO::PARAM_STR);
        $stmt->bindValue(':x', $remoteUsername, PDO::PARAM_STR);
        $stmt->bindValue(':tm', date('Y/m/d H:i:s'), PDO::PARAM_STR);
        $stmt->bindValue(':s', $datasetting, PDO::PARAM_STR);
        $stmt->bindValue(':h', "{}", PDO::PARAM_STR);
        $stmt->execute();
    } catch (\Throwable $e) {
        return [false, '❌ خطا در ذخیره ربات: ' . $e->getMessage()];
    }
    return [true, "✅ ربات نماینده @{$remoteUsername} برای کاربر {$targetUid} ساخته و فعال شد."];
}

/**
 * Central reseller bot deletion.
 */
function fx_reseller_delete_bot(int $targetUid): array {
    global $pdo;
    $contentbto = select("botsaz", "*", "id_user", $targetUid, "select", ['cache' => false]);
    if (!$contentbto) return [false, '❌ رباتی برای این نماینده یافت نشد.'];
    $root = REFACTORED_LEGACY_ROOT;
    $dirsource = "$root/vpnbot/{$targetUid}{$contentbto['username']}";
    if (is_dir($dirsource) && function_exists('deleteDirectory')) deleteDirectory($dirsource);
    if (!empty($contentbto['bot_token'])) @file_get_contents("https://api.telegram.org/bot{$contentbto['bot_token']}/deletewebhook");
    $stmt = $pdo->prepare("DELETE FROM botsaz WHERE id_user = :u");
    $stmt->execute([':u' => (string)$targetUid]);
    return [true, '❌ ربات نماینده با موفقیت حذف شد.'];
}

function fx_render_reseller_management_button(): array {
    return [
        'text' => '🏢 مدیریت نمایندگان',
        'callback_data' => 'fx_reseller_mgmt_menu'
    ];
}

function fx_reseller_mgmt_dispatch(string $datain, int $from_id, int $message_id, array $user, string $backuser): bool {
    global $pdo, $setting, $textbotlang, $datatextbot;

    if ($datain === 'fx_reseller_mgmt_menu') {
        $resellers = fx_reseller_get_all();
        $kb = ['inline_keyboard' => []];
        foreach ($resellers as $r) {
            $bal = number_format(intval($r['Balance'] ?? 0));
            $kb['inline_keyboard'][] = [[
                'text' => "@{$r['username']} | 💰{$bal}",
                'callback_data' => "fx_res_sel_{$r['id_user']}"
            ]];
        }
        $kb['inline_keyboard'][] = [['text' => '➕ ساخت ربات نماینده', 'callback_data' => 'fx_res_create_start']];
        $kb['inline_keyboard'][] = [['text' => '⬅️ بازگشت', 'callback_data' => 'backadmin']];
        Editmessagetext($from_id, $message_id, "🏢 **مدیریت ربات‌های نماینده**\nیک نماینده را انتخاب کنید یا ربات جدید بسازید:", json_encode($kb, JSON_UNESCAPED_UNICODE), 'HTML');
        return true;
    }

    if (preg_match('/^fx_res_sel_(\d+)$/', $datain, $m)) {
        $rid = intval($m[1]);
        $rUser = select("user", "*", "id", $rid, "select");
        if (!$rUser) { sendmessage($from_id, "❌ نماینده یافت نشد.", $backuser, 'html'); return true; }
        $bal = number_format(fx_reseller_db_balance($rid));
        $kb = ['inline_keyboard' => [
            [['text' => '➕ شارژ موجودی', 'callback_data' => "fx_res_credit_{$rid}"],
             ['text' => '➖ کسر موجودی', 'callback_data' => "fx_res_debit_{$rid}"]],
            [['text' => '📦 اختصاص محصول', 'callback_data' => "fx_res_asgn_prod_{$rid}"],
             ['text' => '🔌 اختصاص پنل', 'callback_data' => "fx_res_asgn_panel_{$rid}"]],
            [['text' => '🔑 اعتبار پاسارگاد', 'callback_data' => "fx_res_pasargad_{$rid}"]],
            [['text' => '⬅️ بازگشت', 'callback_data' => 'fx_reseller_mgmt_menu']]
        ]];
        $txt = "👤 @{$rUser['username']} (ID: {$rid})\n💰 موجودی DB: {$bal} تومان\n🏷️ نوع: {$rUser['agent']}";
        Editmessagetext($from_id, $message_id, $txt, json_encode($kb, JSON_UNESCAPED_UNICODE), 'HTML');
        return true;
    }

    if (preg_match('/^fx_res_credit_(\d+)$/', $datain, $m)) {
        step('fx_res_do_credit', $from_id);
        update("user", "Processing_value", $m[1], "id", $from_id);
        Editmessagetext($from_id, $message_id, "💰 مبلغ شارژ (تومان) را برای نماینده {$m[1]} وارد کنید:", $backuser, 'html');
        return true;
    }

    if (preg_match('/^fx_res_debit_(\d+)$/', $datain, $m)) {
        step('fx_res_do_debit', $from_id);
        update("user", "Processing_value", $m[1], "id", $from_id);
        Editmessagetext($from_id, $message_id, "➖ مبلغ کسر (تومان) را برای نماینده {$m[1]} وارد کنید:", $backuser, 'html');
        return true;
    }

    if (preg_match('/^fx_res_asgn_prod_(\d+)$/', $datain, $m)) {
        $rid = $m[1];
        $stmt = $pdo->query("SELECT id, name_product, agent FROM product LIMIT 30");
        $prods = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $kb = ['inline_keyboard' => []];
        foreach ($prods as $p) {
            $label = $p['name_product'] . ($p['agent'] !== 'all' && $p['agent'] !== $rid ? " [{$p['agent']}]" : '');
            $kb['inline_keyboard'][] = [['text' => $label, 'callback_data' => "fx_res_set_prod_{$rid}_{$p['id']}"]];
        }
        $kb['inline_keyboard'][] = [['text' => '⬅️ بازگشت', 'callback_data' => "fx_res_sel_{$rid}"]];
        Editmessagetext($from_id, $message_id, "📦 محصول را برای اختصاص به نماینده {$rid} انتخاب کنید:", json_encode($kb, JSON_UNESCAPED_UNICODE), 'HTML');
        return true;
    }

    if (preg_match('/^fx_res_set_prod_(\d+)_(\d+)$/', $datain, $m)) {
        $rid = $m[1]; $pid = $m[2];
        if (fx_reseller_assign_product_agent(intval($pid), $rid)) {
            Editmessagetext($from_id, $message_id, "✅ محصول {$pid} به نماینده {$rid} اختصاص یافت.", $backuser, 'html');
        } else {
            Editmessagetext($from_id, $message_id, "❌ خطا در اختصاص محصول.", $backuser, 'html');
        }
        return true;
    }

    if (preg_match('/^fx_res_asgn_panel_(\d+)$/', $datain, $m)) {
        $rid = $m[1];
        $stmt = $pdo->query("SELECT id, name_panel, agent FROM marzban_panel LIMIT 30");
        $panels = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $kb = ['inline_keyboard' => []];
        foreach ($panels as $p) {
            $label = $p['name_panel'] . ($p['agent'] !== 'all' && $p['agent'] !== $rid ? " [{$p['agent']}]" : '');
            $kb['inline_keyboard'][] = [['text' => $label, 'callback_data' => "fx_res_set_panel_{$rid}_{$p['id']}"]];
        }
        $kb['inline_keyboard'][] = [['text' => '⬅️ بازگشت', 'callback_data' => "fx_res_sel_{$rid}"]];
        Editmessagetext($from_id, $message_id, "🔌 پنل را برای اختصاص به نماینده {$rid} انتخاب کنید:", json_encode($kb, JSON_UNESCAPED_UNICODE), 'HTML');
        return true;
    }

    if (preg_match('/^fx_res_set_panel_(\d+)_(\d+)$/', $datain, $m)) {
        $rid = $m[1]; $panId = $m[2];
        if (fx_reseller_assign_panel_agent(intval($panId), $rid)) {
            Editmessagetext($from_id, $message_id, "✅ پنل {$panId} به نماینده {$rid} اختصاص یافت.", $backuser, 'html');
        } else {
            Editmessagetext($from_id, $message_id, "❌ خطا در اختصاص پنل.", $backuser, 'html');
        }
        return true;
    }

    if (preg_match('/^fx_res_pasargad_(\d+)$/', $datain, $m)) {
        step('fx_res_do_pasargad', $from_id);
        update("user", "Processing_value", $m[1], "id", $from_id);
        Editmessagetext($from_id, $message_id, "🔑 فرمت: `panel_id|username|password`\nمثال: `3|admin|secret123`", $backuser, 'html');
        return true;
    }

    // === Central bot creation (admin-driven) ===
    if ($datain === 'fx_res_create_start') {
        step('fx_res_do_create', $from_id);
        Editmessagetext($from_id, $message_id, "➕ <b>ساخت ربات نماینده (مدیریت مرکزی)</b>\n\nفرمت:\n<code>آیدی_نماینده|توکن_ربات</code>\n\nمثال:\n<code>6327424512|123456:AAxxx</code>\n\nانصراف: /cancel", $backuser, 'html');
        return true;
    }
    if (preg_match('/^fx_res_wh_(\d+)$/', $datain, $m)) {
        $rid = $m[1];
        $bot = select("botsaz", "*", "id_user", $rid, "select", ['cache' => false]);
        if (!$bot) { Editmessagetext($from_id, $message_id, "❌ رباتی برای این نماینده وجود ندارد.", $backuser, 'html'); return true; }
        $domain = $GLOBALS['domainhosts'] ?? 'faoxima.downloadbazi.fun/faoxima';
        $secret = hash('sha256', $bot['bot_token'] . '_faoxima_webhook_secret');
        $wh = telegram('setWebhook', [
            'url' => "https://{$domain}/vpnbot/{$rid}{$bot['username']}/index.php",
            'secret_token' => $secret,
            'allowed_updates' => ['message', 'callback_query', 'edited_message']
        ], $bot['bot_token']);
        Editmessagetext($from_id, $message_id, ((is_array($wh) && !empty($wh['ok'])) ? '✅ وبهوک ربات نماینده ریست شد.' : '❌ ست وبهوک ناموفق بود.'), $backuser, 'html');
        return true;
    }
    if (preg_match('/^fx_res_delbot_(\d+)$/', $datain, $m)) {
        $res = fx_reseller_delete_bot(intval($m[1]));
        Editmessagetext($from_id, $message_id, $res[1], $backuser, 'html');
        return true;
    }
    return false;
}

function fx_reseller_text_handler(string $text, array $user, int $from_id, string $backuser): bool {
    global $ApiToken;

    if ($user['step'] === 'fx_res_do_credit') {
        if (!ctype_digit($text)) { sendmessage($from_id, "❌ فقط عدد وارد کنید.", $backuser, 'html'); return true; }
        $rid = intval($user['Processing_value']);
        if (fx_reseller_db_credit($rid, intval($text), 'admin_credit', $ApiToken)) {
            sendmessage($from_id, "✅ مبلغ {$text} تومان به نماینده {$rid} شارژ شد.", $backuser, 'html');
            sendmessage($rid, "💎 مبلغ {$text} تومان به موجودی شما اضافه شد.", null, 'HTML');
        } else {
            sendmessage($from_id, "❌ خطا در شارژ.", $backuser, 'html');
        }
        step('home', $from_id);
        return true;
    }

    if ($user['step'] === 'fx_res_do_debit') {
        if (!ctype_digit($text)) { sendmessage($from_id, "❌ فقط عدد وارد کنید.", $backuser, 'html'); return true; }
        $rid = intval($user['Processing_value']);
        if (fx_reseller_db_debit($rid, intval($text), 'admin_debit', $ApiToken)) {
            sendmessage($from_id, "✅ مبلغ {$text} تومان از نماینده {$rid} کسر شد.", $backuser, 'html');
            sendmessage($rid, "⚠️ مبلغ {$text} تومان از موجودی شما کسر شد.", null, 'HTML');
        } else {
            sendmessage($from_id, "❌ موجودی کافی نیست یا خطا رخ داد.", $backuser, 'html');
        }
        step('home', $from_id);
        return true;
    }

    if ($user['step'] === 'fx_res_do_pasargad') {
        $parts = explode('|', $text);
        if (count($parts) !== 3) { sendmessage($from_id, "❌ فرمت: panel_id|username|password", $backuser, 'html'); return true; }
        $panelId = intval(trim($parts[0]));
        $uname = trim($parts[1]);
        $pass = trim($parts[2]);
        if (fx_reseller_set_pasargad_creds($panelId, $uname, $pass)) {
            sendmessage($from_id, "✅ اعتبار پاسارگاد پنل {$panelId} تنظیم شد.", $backuser, 'html');
        } else {
            sendmessage($from_id, "❌ خطا در تنظیم اعتبار.", $backuser, 'html');
        }
        step('home', $from_id);
        return true;
    }

    if ($user['step'] === 'fx_res_do_create') {
        if (trim($text) === '/cancel') { step('home', $from_id); sendmessage($from_id, '❌ ساخت ربات نماینده لغو شد.', $backuser, 'html'); return true; }
        $parts = explode('|', $text);
        if (count($parts) !== 2) { sendmessage($from_id, '❌ فرمت: <code>آیدی_نماینده|توکن_ربات</code>', $backuser, 'html'); return true; }
        $uid = trim($parts[0]);
        if (!ctype_digit($uid)) { sendmessage($from_id, '❌ آیدی نماینده باید عددی باشد.', $backuser, 'html'); step('home', $from_id); return true; }
        $res = fx_reseller_create_bot(intval($uid), trim($parts[1]));
        step('home', $from_id);
        sendmessage($from_id, $res[1], $backuser, 'html');
        return true;
    }
    return false;
}
