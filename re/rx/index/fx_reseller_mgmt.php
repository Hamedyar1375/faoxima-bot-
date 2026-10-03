<?php
@file_put_contents("/tmp/fxedit_trace.log", "[FXLOAD] fx_reseller_mgmt.php loaded\n", FILE_APPEND);
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
    $stmt = $pdo->query("SELECT b.id_user, b.username, b.bot_token, u.Balance, u.agent, u.username AS u_username FROM botsaz b LEFT JOIN user u ON u.id = b.id_user");
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

// =====================================================================
// FX-GLASS-SECTION (2026-10-02): section-scoped glass (inline) UI.
// The REST of the bot stays in reply-keyboard mode (inlinebtnmain=offinline);
// ONLY this section always renders with inline (glass) buttons.
// Bottom reply keyboard: hidden on entry (edit-fallback deletes the hub msg
// which carries it, or a "." remove_keyboard msg in text mode), restored on
// exit (fx_res_backadmin / section end re-sends the hub with reply kb).
// One living message per screen: every level EDITs the previous in place.
// =====================================================================

function fx_res_inline_kb(array $rows): string {
    return json_encode(['inline_keyboard' => $rows, 'resize_keyboard' => true], JSON_UNESCAPED_UNICODE);
}

function fx_res_back_admin_row(): array {
    $label = '🏠 بازگشت به منوی مدیریت';
    $lb = $GLOBALS['textbotlang']['Admin']['backadmin'] ?? '';
    if (is_string($lb) && $lb !== '') $label = $lb;
    return [['text' => $label, 'callback_data' => 'fx_res_backadmin']];
}

function fx_res_list_rows(array $resellers): array {
    $rows = [];
    foreach ($resellers as $r) {
        $bal = number_format(intval($r['Balance'] ?? 0));
        $rows[] = [['text' => "@{$r['username']} | 💰{$bal}", 'callback_data' => "fx_res_sel_{$r['id_user']}"]];
    }
    $rows[] = [['text' => '➕ ساخت ربات نماینده', 'callback_data' => 'fx_res_create_start']];
    $rows[] = fx_res_back_admin_row();
    return $rows;
}

function fx_res_product_rows(string $rid): array {
    global $pdo;
    $rows = [];
    try {
        $stmt = $pdo->query("SELECT id, name_product, agent FROM product LIMIT 30");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $label = (string)$p['name_product'];
            if (isset($p['agent']) && $p['agent'] !== 'all' && (string)$p['agent'] !== (string)$rid) {
                $label .= " [{$p['agent']}]";
            }
            $rows[] = [['text' => $label, 'callback_data' => "fx_res_set_prod_{$rid}_{$p['id']}"]];
        }
    } catch (\Throwable $e) {}
    $rows[] = [['text' => '⬅️ بازگشت به منوی نماینده', 'callback_data' => "fx_res_sel_{$rid}"]];
    return $rows;
}

function fx_res_panel_rows(string $rid): array {
    global $pdo;
    $rows = [];
    try {
        $stmt = $pdo->query("SELECT id, name_panel, agent FROM marzban_panel LIMIT 30");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $label = (string)$p['name_panel'];
            if (isset($p['agent']) && $p['agent'] !== 'all' && (string)$p['agent'] !== (string)$rid) {
                $label .= " [{$p['agent']}]";
            }
            $rows[] = [['text' => $label, 'callback_data' => "fx_res_set_panel_{$rid}_{$p['id']}"]];
        }
    } catch (\Throwable $e) {}
    $rows[] = [['text' => '⬅️ بازگشت به منوی نماینده', 'callback_data' => "fx_res_sel_{$rid}"]];
    return $rows;
}

/**
 * Hide the bottom reply keyboard in text (non-callback) context.
 * Sends a "." remove_keyboard message, deletes it; if deletion fails the
 * message id is parked in user.reply_kb_cleanup_msg_id and flushed by the
 * built-in rx_flushPendingReplyKeyboardCleanup on the next render.
 */
function fx_res_hide_reply_kb(int $from_id): void {
    try {
        $res = telegram('sendmessage', [
            'chat_id' => $from_id,
            'text' => '.',
            'reply_markup' => json_encode(['remove_keyboard' => true], JSON_UNESCAPED_UNICODE),
            '_rx_already_transformed' => true,
        ]);
        $mid = is_array($res) && !empty($res['ok']) ? (int)($res['result']['message_id'] ?? 0) : 0;
        if ($mid <= 0) return;
        usleep(500000);
        $ok = false;
        if (function_exists('deletemessage')) {
            $d = deletemessage($from_id, $mid);
            $ok = is_array($d) && !empty($d['ok']);
        }
        if (!$ok) {
            update('user', 'reply_kb_cleanup_msg_id', (string)$mid, 'id', $from_id);
        }
    } catch (\Throwable $e) {
        error_log('[FXRES] hide reply kb: ' . $e->getMessage());
    }
}

function fx_res_answer_cb(): void {
    global $callback_query_id;
    if (!empty($callback_query_id)) {
        @telegram('answerCallbackQuery', ['callback_query_id' => $callback_query_id, 'cache_time' => 1]);
    }
}

/**
 * Admin hub reply keyboard for section exit. Reuses the live $keyboardadmin
 * (built by layouts on every request); falls back to minimal rows — tapping
 * the back row re-renders the full hub via the built-in handler.
 */
function fx_res_hub_reply_kb(): string {
    global $keyboardadmin;
    if (!empty($keyboardadmin) && is_string($keyboardadmin)) {
        $d = json_decode($keyboardadmin, true);
        if (is_array($d) && (isset($d['keyboard']) || isset($d['inline_keyboard']))) {
            return $keyboardadmin;
        }
    }
    $back = '🏠 بازگشت به منوی مدیریت';
    $lb = $GLOBALS['textbotlang']['Admin']['backadmin'] ?? '';
    if (is_string($lb) && $lb !== '') $back = $lb;
    $rows = [
        ['text' => '📱 مدیریت ربات‌های نمایندگی'],
        ['text' => $back],
    ];
    return json_encode(['keyboard' => $rows, 'resize_keyboard' => true], JSON_UNESCAPED_UNICODE);
}

/**
 * Leave the glass section: delete the glass message, re-render the admin hub
 * with its (reply-mode) keyboard so the bottom keyboard comes back.
 */
function fx_res_exit_to_hub(int $from_id, int $message_id = 0): void {
    step('home', $from_id);
    update("user", "Processing_value", 0, "id", $from_id);
    if ($message_id > 0 && function_exists('deletemessage')) {
        @deletemessage($from_id, $message_id);
    }
    $hubText = '📋 پنل مدیریت';
    $lb = $GLOBALS['textbotlang']['Admin']['textpaneladmin'] ?? '';
    if (is_string($lb) && $lb !== '') $hubText = $lb;
    sendmessage($from_id, $hubText, fx_res_hub_reply_kb(), 'HTML');
}

function fx_res_mgmt_gate($datain, $from_id, $admin_ids, $message_id, $user, $backadmin): bool {
    $c1 = in_array($from_id, (array)$admin_ids);
    $c2 = (strpos((string)$datain, 'fx_res_') === 0 || $datain === 'fx_reseller_mgmt_menu' || $datain === 'fx_res_backadmin');
    if ($c1 && $c2) {
        return (bool)fx_reseller_mgmt_dispatch((string)$datain, (int)$from_id, (int)$message_id, (array)$user, (string)$backadmin);
    }
    return false;
}

function fx_reseller_mgmt_dispatch(string $datain, int $from_id, int $message_id, array $user, string $backuser): bool {
    try {
    global $pdo, $setting, $textbotlang, $datatextbot;

    // --- list (entry point AND back-target of the detail screen) ---
    if ($datain === 'fx_reseller_mgmt_menu') {
        step('home', $from_id);
        update("user", "Processing_value", 0, "id", $from_id);
        $kb = fx_res_inline_kb(fx_res_list_rows(fx_reseller_get_all()));
        // In reply-kb mode the hub message carries a REPLY keyboard: editing it
        // to inline fails with "keyboard expected" and Editmessagetext's built-in
        // fallback deletes it (which also removes the bottom kb) and sends a
        // fresh glass message. In inline mode the same call edits in place.
        Editmessagetext($from_id, $message_id, "🏢 **مدیریت ربات‌های نماینده**\nیک نماینده را انتخاب کنید یا ربات جدید بسازید:", $kb, 'HTML');
        fx_res_answer_cb();
        return true;
    }

    // --- detail ---
    if (preg_match('/^fx_res_sel_(\d+)$/', $datain, $m)) {
        $rid = intval($m[1]);
        $rUser = select("user", "*", "id", $rid, "select");
        if (!$rUser) {
            Editmessagetext($from_id, $message_id, "❌ نماینده یافت نشد.", fx_res_inline_kb(fx_res_back_admin_row()), 'HTML');
            fx_res_answer_cb();
            return true;
        }
        $bal = number_format(fx_reseller_db_balance($rid));
        step('fx_res_view', $from_id);
        update("user", "Processing_value", $rid, "id", $from_id);
        $kb = fx_res_inline_kb([
            [['text' => '➕ شارژ موجودی', 'callback_data' => "fx_res_credit_{$rid}"],
             ['text' => '➖ کسر موجودی', 'callback_data' => "fx_res_debit_{$rid}"]],
            [['text' => '📦 اختصاص محصول', 'callback_data' => "fx_res_asgn_prod_{$rid}"],
             ['text' => '🔌 اختصاص پنل', 'callback_data' => "fx_res_asgn_panel_{$rid}"]],
            [['text' => '🔑 اعتبار پاسارگاد', 'callback_data' => "fx_res_pasargad_{$rid}"],
             ['text' => '⚙ وبهوک ربات', 'callback_data' => "fx_res_wh_{$rid}"]],
            [['text' => '🗑 حذف ربات', 'callback_data' => "fx_res_delconfirm_{$rid}"]],
            [['text' => '⬅️ بازگشت به فهرست نمایندگان', 'callback_data' => 'fx_reseller_mgmt_menu']],
        ]);
        $__fxNameG = (isset($rUser['username']) && $rUser['username'] !== '' && $rUser['username'] !== 'none' && $rUser['username'] !== 'NOT_USERNAME') ? ('@' . $rUser['username'] . ' (ID: ' . $rid . ')') : ('ID ' . $rid);
        $txt = "👤 {$__fxNameG}\n💰 موجودی: {$bal} تومان\n🏷️ نوع: {$rUser['agent']}";
        Editmessagetext($from_id, $message_id, $txt, $kb, 'HTML');
        fx_res_answer_cb();
        return true;
    }

    // --- charge / debit (text-entry steps) ---
    if (preg_match('/^fx_res_credit_(\d+)$/', $datain, $m)) {
        step('fx_res_do_credit', $from_id);
        update("user", "Processing_value", $m[1], "id", $from_id);
        Editmessagetext($from_id, $message_id, "💰 مبلغ شارژ (تومان) را برای نماینده {$m[1]} وارد کنید:\nانصراف: /cancel", null, 'html');
        fx_res_answer_cb();
        return true;
    }
    if (preg_match('/^fx_res_debit_(\d+)$/', $datain, $m)) {
        step('fx_res_do_debit', $from_id);
        update("user", "Processing_value", $m[1], "id", $from_id);
        Editmessagetext($from_id, $message_id, "➖ مبلغ کسر (تومان) را برای نماینده {$m[1]} وارد کنید:\nانصراف: /cancel", null, 'html');
        fx_res_answer_cb();
        return true;
    }

    // --- product list / selection ---
    if (preg_match('/^fx_res_asgn_prod_(\d+)$/', $datain, $m)) {
        $rid = $m[1];
        step('fx_res_prod_list', $from_id);
        update("user", "Processing_value", $rid, "id", $from_id);
        Editmessagetext($from_id, $message_id, "📦 محصول را برای اختصاص به نماینده {$rid} انتخاب کنید:", fx_res_inline_kb(fx_res_product_rows($rid)), 'HTML');
        fx_res_answer_cb();
        return true;
    }
    if (preg_match('/^fx_res_set_prod_(\d+)_(\d+)$/', $datain, $m)) {
        $rid = $m[1]; $pid = $m[2];
        $ok = fx_reseller_assign_product_agent(intval($pid), $rid);
        step('fx_res_view', $from_id);
        update("user", "Processing_value", $rid, "id", $from_id);
        Editmessagetext($from_id, $message_id, ($ok ? "✅ محصول {$pid} به نماینده {$rid} اختصاص یافت." : "❌ خطا در اختصاص محصول."),
            fx_res_inline_kb([['text' => '⬅️ بازگشت به منوی نماینده', 'callback_data' => "fx_res_sel_{$rid}"]]), 'HTML');
        fx_res_answer_cb();
        return true;
    }

    // --- panel list / selection ---
    if (preg_match('/^fx_res_asgn_panel_(\d+)$/', $datain, $m)) {
        $rid = $m[1];
        step('fx_res_panel_list', $from_id);
        update("user", "Processing_value", $rid, "id", $from_id);
        Editmessagetext($from_id, $message_id, "🔌 پنل را برای اختصاص به نماینده {$rid} انتخاب کنید:", fx_res_inline_kb(fx_res_panel_rows($rid)), 'HTML');
        fx_res_answer_cb();
        return true;
    }
    if (preg_match('/^fx_res_set_panel_(\d+)_(\d+)$/', $datain, $m)) {
        $rid = $m[1]; $panId = $m[2];
        $ok = fx_reseller_assign_panel_agent(intval($panId), $rid);
        step('fx_res_view', $from_id);
        update("user", "Processing_value", $rid, "id", $from_id);
        Editmessagetext($from_id, $message_id, ($ok ? "✅ پنل {$panId} به نماینده {$rid} اختصاص یافت." : "❌ خطا در اختصاص پنل."),
            fx_res_inline_kb([['text' => '⬅️ بازگشت به منوی نماینده', 'callback_data' => "fx_res_sel_{$rid}"]]), 'HTML');
        fx_res_answer_cb();
        return true;
    }

    // --- pasargad (text-entry step) ---
    if (preg_match('/^fx_res_pasargad_(\d+)$/', $datain, $m)) {
        step('fx_res_do_pasargad', $from_id);
        update("user", "Processing_value", $m[1], "id", $from_id);
        Editmessagetext($from_id, $message_id, "🔑 فرمت: <code>panel_id|username|password</code>\nمثال: <code>3|admin|secret123</code>\nانصراف: /cancel", null, 'html');
        fx_res_answer_cb();
        return true;
    }

    // --- delete confirm / delete ---
    if (preg_match('/^fx_res_delconfirm_(\d+)$/', $datain, $m)) {
        $rid = $m[1];
        step('fx_res_delbot_confirm', $from_id);
        update("user", "Processing_value", $rid, "id", $from_id);
        $kb = fx_res_inline_kb([
            [['text' => '✅ بله، حذف شود', 'callback_data' => "fx_res_delbot_{$rid}"]],
            [['text' => '❌ انصراف', 'callback_data' => "fx_res_sel_{$rid}"]],
        ]);
        Editmessagetext($from_id, $message_id, "🗑 آیا ربات نماینده {$rid} حذف شود؟", $kb, 'HTML');
        fx_res_answer_cb();
        return true;
    }
    if (preg_match('/^fx_res_delbot_(\d+)$/', $datain, $m)) {
        $rid = $m[1];
        $res = fx_reseller_delete_bot(intval($rid));
        step('home', $from_id);
        update("user", "Processing_value", 0, "id", $from_id);
        Editmessagetext($from_id, $message_id, (string)$res[1],
            fx_res_inline_kb([['text' => '🏢 فهرست نمایندگان', 'callback_data' => 'fx_reseller_mgmt_menu']]), 'HTML');
        fx_res_answer_cb();
        return true;
    }

    // --- webhook reset ---
    if (preg_match('/^fx_res_wh_(\d+)$/', $datain, $m)) {
        $rid = $m[1];
        $bot = select("botsaz", "*", "id_user", $rid, "select", ['cache' => false]);
        if (!$bot) {
            Editmessagetext($from_id, $message_id, "❌ رباتی برای این نماینده وجود ندارد.",
                fx_res_inline_kb([['text' => '⬅️ بازگشت به منوی نماینده', 'callback_data' => "fx_res_sel_{$rid}"]]), 'HTML');
            fx_res_answer_cb();
            return true;
        }
        $domain = $GLOBALS['domainhosts'] ?? 'faoxima.downloadbazi.fun/faoxima';
        $secret = hash('sha256', $bot['bot_token'] . '_faoxima_webhook_secret');
        $wh = telegram('setWebhook', [
            'url' => "https://{$domain}/vpnbot/{$rid}{$bot['username']}/index.php",
            'secret_token' => $secret,
            'allowed_updates' => ['message', 'callback_query', 'edited_message']
        ], $bot['bot_token']);
        Editmessagetext($from_id, $message_id, ((is_array($wh) && !empty($wh['ok'])) ? '✅ وبهوک ربات نماینده ریست شد.' : '❌ ست وبهوک ناموفق بود.'),
            fx_res_inline_kb([['text' => '⬅️ بازگشت به منوی نماینده', 'callback_data' => "fx_res_sel_{$rid}"]]), 'HTML');
        fx_res_answer_cb();
        return true;
    }

    // --- create (text-entry step) ---
    if ($datain === 'fx_res_create_start') {
        step('fx_res_do_create', $from_id);
        update("user", "Processing_value", 0, "id", $from_id);
        Editmessagetext($from_id, $message_id, "➕ <b>ساخت ربات نماینده (مدیریت مرکزی)</b>\n\nفرمت:\n<code>آیدی_نماینده|توکن_ربات</code>\n\nمثال:\n<code>6327424512|123456:AAxxx</code>\n\nانصراف: /cancel", null, 'html');
        fx_res_answer_cb();
        return true;
    }

    // --- exit the section: delete glass msg + re-render admin hub (reply kb restored) ---
    if ($datain === 'fx_res_backadmin') {
        fx_res_exit_to_hub($from_id, (int)$message_id);
        fx_res_answer_cb();
        return true;
    }

    return false;
    } catch (\Throwable $fxres_e) {
        @error_log("[FXRES_TRACE] dispatch EXCEPTION: " . get_class($fxres_e) . ": " . $fxres_e->getMessage() . " @ " . $fxres_e->getFile() . ":" . $fxres_e->getLine() . "\n", 3, (defined("REFACTORED_LOG_DIR") ? REFACTORED_LOG_DIR : "/tmp") . "/fx_res_trace.log");
        throw $fxres_e;
    }
}

function fx_reseller_text_handler(string $text, array $user, int $from_id, string $backuser): bool {
    global $ApiToken;

    if ($user['step'] === 'fx_res_do_credit') {
        if (trim($text) === '/cancel') { fx_res_exit_to_hub($from_id); return true; }
        if (!ctype_digit($text)) { sendmessage($from_id, "❌ فقط عدد وارد کنید.", $backuser, 'html'); return true; }
        $rid = intval($user['Processing_value']);
        if (fx_reseller_db_credit($rid, intval($text), 'admin_credit', $ApiToken)) {
            sendmessage($from_id, "✅ مبلغ {$text} تومان به نماینده {$rid} شارژ شد.", $backuser, 'html');
            sendmessage($rid, "💎 مبلغ {$text} تومان به موجودی شما اضافه شد.", null, 'HTML');
        } else {
            sendmessage($from_id, "❌ خطا در شارژ.", $backuser, 'html');
        }
        fx_res_exit_to_hub($from_id);
        return true;
    }

    if ($user['step'] === 'fx_res_do_debit') {
        if (trim($text) === '/cancel') { fx_res_exit_to_hub($from_id); return true; }
        if (!ctype_digit($text)) { sendmessage($from_id, "❌ فقط عدد وارد کنید.", $backuser, 'html'); return true; }
        $rid = intval($user['Processing_value']);
        if (fx_reseller_db_debit($rid, intval($text), 'admin_debit', $ApiToken)) {
            sendmessage($from_id, "✅ مبلغ {$text} تومان از نماینده {$rid} کسر شد.", $backuser, 'html');
            sendmessage($rid, "⚠️ مبلغ {$text} تومان از موجودی شما کسر شد.", null, 'HTML');
        } else {
            sendmessage($from_id, "❌ موجودی کافی نیست یا خطا رخ داد.", $backuser, 'html');
        }
        fx_res_exit_to_hub($from_id);
        return true;
    }

    if ($user['step'] === 'fx_res_do_pasargad') {
        if (trim($text) === '/cancel') { fx_res_exit_to_hub($from_id); return true; }
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
        fx_res_exit_to_hub($from_id);
        return true;
    }

    if ($user['step'] === 'fx_res_do_create') {
        if (trim($text) === '/cancel') { fx_res_exit_to_hub($from_id); return true; }
        $parts = explode('|', $text);
        if (count($parts) !== 2) { sendmessage($from_id, '❌ فرمت: <code>آیدی_نماینده|توکن_ربات</code>', $backuser, 'html'); return true; }
        $uid = trim($parts[0]);
        if (!ctype_digit($uid)) { sendmessage($from_id, '❌ آیدی نماینده باید عددی باشد.', $backuser, 'html'); fx_res_exit_to_hub($from_id); return true; }
        $res = fx_reseller_create_bot(intval($uid), trim($parts[1]));
        sendmessage($from_id, $res[1], $backuser, 'html');
        fx_res_exit_to_hub($from_id);
        return true;
    }
    return false;
}

/**
 * TEXT-mode entry for the reseller management section (reply-keyboard mode:
 * the hub button arrives as EXACT button text). Enters the glass section.
 * Text-entry steps (charge/debit/pasargad/create) are consumed here as a
 * safety net after the bootstrap_1 gate. Returns true when $text was consumed.
 */
function fx_res_mgmt_text_entry(string $text, array $user, int $from_id, string $backadmin): bool {
    global $pdo;
    if (defined('FX_RESELLER_ID')) return false; // main-admin module only
    $text = trim($text);
    if ($text === '' || !($pdo instanceof PDO)) return false;

    static $fxres_admin_cache = null;
    if ($fxres_admin_cache === null) {
        $fxres_admin_cache = [];
        try {
            foreach ($pdo->query("SELECT id_admin FROM admin WHERE id_admin IS NOT NULL AND id_admin <> '' AND id_admin <> '0'")->fetchAll(PDO::FETCH_COLUMN) as $a) {
                $fxres_admin_cache[(string)$a] = true;
            }
        } catch (\Throwable $e) {
            return false;
        }
    }
    if (!isset($fxres_admin_cache[(string)$from_id])) return false;

    $step = (string)($user['step'] ?? '');

    // --- section entry from the hub (reply-kb button text) ---
    if ($text === '📱 مدیریت ربات‌های نمایندگی') {
        fx_res_hide_reply_kb($from_id);
        step('home', $from_id);
        update("user", "Processing_value", 0, "id", $from_id);
        sendmessage($from_id, "🏢 <b>مدیریت ربات‌های نماینده</b>\nیک نماینده را انتخاب کنید یا ربات جدید بسازید:", fx_res_inline_kb(fx_res_list_rows(fx_reseller_get_all())), 'HTML');
        return true;
    }

    // --- text-entry steps (primary consumer: bootstrap_1 gate; this is a net) ---
    if (in_array($step, ['fx_res_do_credit', 'fx_res_do_debit', 'fx_res_do_pasargad', 'fx_res_do_create'], true)) {
        if (fx_reseller_text_handler($text, (array)$user, $from_id, $backadmin)) {
            return true;
        }
    }

    // --- /cancel inside the section ---
    if ($text === '/cancel' && in_array($step, ['fx_res_view', 'fx_res_prod_list', 'fx_res_panel_list', 'fx_res_delbot_confirm'], true)) {
        fx_res_exit_to_hub($from_id);
        return true;
    }

    return false;
}
