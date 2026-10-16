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
require_once REFACTORED_LEGACY_ROOT . '/re/rx/index/fx_debt.php'; // FX-DEBT helpers (2026-10-03)
require_once (defined('REFACTORED_LEGACY_ROOT') ? REFACTORED_LEGACY_ROOT : dirname(__DIR__, 3)) . '/re/rx/function/fx_reseller_bot_registry.php'; // FX-RESELLER bot cap + atomic register (2026-10-15)
// FX-RESELLER (2026-10-16): per-reseller product manager needs retail override + visibility helpers.
if (!function_exists('fx_set_retail_price') && is_file(__DIR__ . '/fx_retail_override.php')) {
    require_once __DIR__ . '/fx_retail_override.php';
}
if (!function_exists('fx_res_visibility_set') && is_file(__DIR__ . '/fx_res_visibility.php')) {
    require_once __DIR__ . '/fx_res_visibility.php';
}

function fx_reseller_db_credit(int $uid, int $amount, string $method = 'reseller_credit', ?string $bottype = null): bool {
    global $pdo;
    if ($amount <= 0) return true;
    $ownTx = false;
    try {
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $ownTx = true;
        }
        $stmt = $pdo->prepare("UPDATE user SET Balance = Balance + :amt WHERE id = :id");
        $stmt->execute([':amt' => $amount, ':id' => $uid]);
        $orderId = bin2hex(random_bytes(5));
        $now = date('Y/m/d H:i:s');
        $ins = $pdo->prepare("INSERT INTO Payment_report (id_user,id_order,time,price,payment_Status,Payment_Method,id_invoice,bottype) VALUES (?,?,?,?,?,?,?,?)");
        $ins->execute([$uid, $orderId, $now, $amount, 'paid', $method, null, $bottype]);
        if ($ownTx) $pdo->commit();
        return true;
    } catch (\Throwable $e) {
        if ($ownTx && $pdo->inTransaction()) $pdo->rollBack();
        error_log("[FX_RES] credit failed uid=$uid: " . $e->getMessage());
        return false;
    }
}

function fx_reseller_db_debit(int $uid, int $amount, string $method = 'reseller_debit', ?string $bottype = null): bool {
    global $pdo;
    if ($amount <= 0) return true;
    $ownTx = false;
    try {
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $ownTx = true;
        }
        $stmt = $pdo->prepare("UPDATE user SET Balance = Balance - :amt WHERE id = :id AND Balance >= :amt2");
        $stmt->execute([':amt' => $amount, ':id' => $uid, ':amt2' => $amount]);
        if ($stmt->rowCount() === 0) {
            if ($ownTx) $pdo->rollBack();
            return false;
        }
        $orderId = bin2hex(random_bytes(5));
        $now = date('Y/m/d H:i:s');
        $ins = $pdo->prepare("INSERT INTO Payment_report (id_user,id_order,time,price,payment_Status,Payment_Method,id_invoice,bottype) VALUES (?,?,?,?,?,?,?,?)");
        $ins->execute([$uid, $orderId, $now, -$amount, 'paid', $method, null, $bottype]);
        if ($ownTx) $pdo->commit();
        return true;
    } catch (\Throwable $e) {
        if ($ownTx && $pdo->inTransaction()) $pdo->rollBack();
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
    $stmt = $pdo->query("SELECT b.id_user, b.username, b.bot_token, b.debt_mode, b.current_debt, u.Balance, u.agent, u.username AS u_username,
            (SELECT COUNT(*) FROM invoice i JOIN user uz ON uz.id = i.id_user
              WHERE (uz.agent = b.id_user OR uz.bottype = b.bot_token)
                AND i.Status IN ('active','sendedwarn','send_on_hold')
                AND i.name_product != 'سرویس تست') AS active_cfgs
        FROM botsaz b LEFT JOIN user u ON u.id = b.id_user");
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
    $fxCap = fx_reseller_bot_cap();
    $fxOwn = fx_reseller_bot_count_owner($pdo, (string) $targetUid);
    $fxTotal = $fxOwn >= 0 ? fx_reseller_bot_count_total($pdo) : -1;
    $fxTok = $fxTotal >= 0 ? fx_reseller_bot_count_token($pdo, $tokenRaw) : -1;
    if ($fxOwn < 0 || $fxTotal < 0 || $fxTok < 0) return [false, '❌ خطا در بررسی وضعیت ربات‌های نماینده. لطفا دوباره تلاش کنید.'];
    if ($fxOwn > 0) return [false, '❌ این نماینده قبلا ربات فعال دارد.'];
    if ($fxTotal >= $fxCap) return [false, '❌ به سقف ' . $fxCap . ' ربات نماینده رسیدیم.'];
    if ($fxTok != 0) return [false, '❌ این توکن قبلا ثبت شده است.'];
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
    $fxReg = fx_reseller_bot_register($pdo, (string) $targetUid, $tokenRaw, $remoteUsername);
    if (!$fxReg['ok']) {
        $fxCode = (string) ($fxReg['code'] ?? '');
        if ($fxCode === 'db' && is_dir($dirsource) && function_exists('deleteDirectory')) {
            @deleteDirectory($dirsource);
        }
        if ($fxCode === 'user_exists') return [false, '❌ این نماینده قبلا ربات فعال دارد.'];
        if ($fxCode === 'cap') return [false, '❌ به سقف ' . (int) $fxReg['cap'] . ' ربات نماینده رسیدیم.'];
        if ($fxCode === 'token_exists') return [false, '❌ این توکن قبلا ثبت شده است.'];
        if ($fxCode === 'busy') return [false, '⏳ ساخت ربات دیگری در حال انجام است؛ چند لحظه بعد دوباره تلاش کنید.'];
        return [false, '❌ خطا در ذخیره ربات: ' . (string) ($fxReg['error'] ?? 'unknown')];
    }
    // Token ownership is recorded before setWebhook, so a concurrent request can
    // never overwrite the webhook of a bot that was not yet registered.
    $domain = $GLOBALS['domainhosts'] ?? 'faoxima.downloadbazi.fun/faoxima';
    $secret = hash('sha256', $tokenRaw . '_faoxima_webhook_secret');
    $wh = telegram('setWebhook', [
        'url' => "https://{$domain}/vpnbot/{$targetUid}{$remoteUsername}/index.php",
        'secret_token' => $secret,
        'allowed_updates' => ['message', 'callback_query', 'edited_message']
    ], $tokenRaw);
    if (!(is_array($wh) && !empty($wh['ok']))) {
        try {
            $dd = $pdo->prepare("DELETE FROM botsaz WHERE id = :i");
            $dd->execute([':i' => (int) $fxReg['id']]);
        } catch (\Throwable $e) {
        }
        if (is_dir($dirsource) && function_exists('deleteDirectory')) @deleteDirectory($dirsource);
        @file_get_contents("https://api.telegram.org/bot{$tokenRaw}/deleteWebhook");
        return [false, '⚠️ ست وبهوک ناموفق بود؛ ثبت ربات لغو شد. دوباره تلاش کنید.'];
    }

    // Clone the main bot's command menu onto the new reseller bot (best-effort).
    try {
        $mainTok = (string)($GLOBALS['ApiToken'] ?? '');
        if ($mainTok === '') $mainTok = (string)($GLOBALS['APIKEY'] ?? '');
        $cmds = null;
        if ($mainTok !== '') {
            $gm = telegram('getMyCommands', [], $mainTok);
            if (is_array($gm) && !empty($gm['ok']) && !empty($gm['result']) && is_array($gm['result'])) $cmds = $gm['result'];
        }
        if (!is_array($cmds) || !$cmds) {
            $cmds = [['command' => 'start', 'description' => 'شروع / منوی اصلی']];
        }
        @telegram('setMyCommands', ['commands' => $cmds], $tokenRaw);
    } catch (\Throwable $e) { /* non-fatal */ }

    // Onboarding guide for the reseller (via the reseller bot's own token).
    try {
        $welcome = "🎉 <b>ربات نماینده شما ساخته و فعال شد!</b>\n\n"
            . "🔗 آدرس ربات: https://t.me/{$remoteUsername}\n\n"
            . "📋 مراحل راه‌اندازی:\n"
            . "1️⃣ ربات را در گروه گزارشات خود اضافه و ادمین کنید.\n"
            . "2️⃣ از منوی مدیریت ربات، «گزارشات» را باز کرده و گروه گزارشات را ثبت کنید.\n"
            . "3️⃣ برای دریافت محصول/پنل، قیمت‌های فروش و شارژ با مدیریت مرکزی در تماس باشید.";
        @telegram('sendMessage', ['chat_id' => $targetUid, 'text' => $welcome, 'parse_mode' => 'HTML'], $tokenRaw);
    } catch (\Throwable $e) { /* non-fatal */ }

    // FX-RESELLER (2026-10-16): remember the CENTRAL bot's username in
    // botsaz.setting.main_bot — the reseller «تسویه» screen deep-links to it.
    try {
        $mainTok = (string)($GLOBALS['ApiToken'] ?? '');
        if ($mainTok === '') $mainTok = (string)($GLOBALS['APIKEY'] ?? '');
        if ($mainTok !== '' && function_exists('fx_res_setting_patch')) {
            $gmMe = telegram('getMe', [], $mainTok);
            $mainUserName = (is_array($gmMe) && !empty($gmMe['ok'])) ? (string)($gmMe['result']['username'] ?? '') : '';
            if ($mainUserName !== '') {
                fx_res_setting_patch($targetUid, ['main_bot' => $mainUserName]);
            }
        }
    } catch (\Throwable $e) { /* non-fatal */ }

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
    return [true, '✅ ربات نماینده با موفقیت حذف شد.'];
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
        $debt = intval($r['current_debt'] ?? 0);
        $cfgs = intval($r['active_cfgs'] ?? 0);
        $mark = ($r['Balance'] === null && !isset($r['agent'])) ? '⚠️' : '✅';
        $dir = (defined('REFACTORED_LEGACY_ROOT') ? REFACTORED_LEGACY_ROOT : '') . "/vpnbot/{$r['id_user']}{$r['username']}";
        if ($dir === '' || !is_dir($dir)) $mark = '⚠️';
        $suffix = ($debt > 0 || (string)($r['debt_mode'] ?? '') === 'debt')
            ? ' | 💸 ' . number_format($debt)
            : '';
        $label = "{$mark} @{$r['username']} | 🧩{$cfgs} | 💰{$bal}{$suffix}";
        if (function_exists('mb_strlen') && mb_strlen($label) > 60) $label = mb_substr($label, 0, 57) . '…';
        $rows[] = [['text' => $label, 'callback_data' => "fx_res_sel_{$r['id_user']}"]];
    }
    $rows[] = [['text' => '➕ ساخت ربات نماینده', 'callback_data' => 'fx_res_create_start']];
    $rows[] = fx_res_back_admin_row();
    return $rows;
}

function fx_res_product_rows(string $rid, int $page = 1): array {
    global $pdo;

    $rows = [];
    $page = max(1, $page);
    $per = 30;
    $off = ($page - 1) * $per;
    $total = 0;
    try {
        $total = (int)$pdo->query("SELECT COUNT(*) FROM product")->fetchColumn();
        $stmt = $pdo->query("SELECT id, name_product, agent FROM product ORDER BY id ASC LIMIT {$per} OFFSET {$off}");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $label = (string)$p['name_product'];
            if (isset($p['agent']) && $p['agent'] !== 'all' && (string)$p['agent'] !== (string)$rid) {
                $label .= " [{$p['agent']}]";
            }
            $rows[] = [['text' => $label, 'callback_data' => "fx_res_set_prod_{$rid}_{$p['id']}"]];
        }
    } catch (\Throwable $e) {}
    $nav = [];
    if ($page > 1) $nav[] = ['text' => '⬅️ صفحه قبل', 'callback_data' => "fx_res_prodpage_{$rid}_" . ($page - 1)];
    if ($page * $per < $total) $nav[] = ['text' => 'صفحه بعد ➡️', 'callback_data' => "fx_res_prodpage_{$rid}_" . ($page + 1)];
    if ($nav) $rows[] = $nav;
    $rows[] = [['text' => '⬅️ بازگشت به منوی نماینده', 'callback_data' => "fx_res_sel_{$rid}"]];
    return $rows;
}

function fx_res_panel_rows(string $rid, int $page = 1): array {
    global $pdo;

    $rows = [];
    $page = max(1, $page);
    $per = 30;
    $off = ($page - 1) * $per;
    $total = 0;
    try {
        $total = (int)$pdo->query("SELECT COUNT(*) FROM marzban_panel")->fetchColumn();
        $stmt = $pdo->query("SELECT id, name_panel, agent FROM marzban_panel ORDER BY id ASC LIMIT {$per} OFFSET {$off}");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $label = (string)$p['name_panel'];
            if (isset($p['agent']) && $p['agent'] !== 'all' && (string)$p['agent'] !== (string)$rid) {
                $label .= " [{$p['agent']}]";
            }
            $rows[] = [['text' => $label, 'callback_data' => "fx_res_set_panel_{$rid}_{$p['id']}"]];
        }
    } catch (\Throwable $e) {}
    $nav = [];
    if ($page > 1) $nav[] = ['text' => '⬅️ صفحه قبل', 'callback_data' => "fx_res_panelpage_{$rid}_" . ($page - 1)];
    if ($page * $per < $total) $nav[] = ['text' => 'صفحه بعد ➡️', 'callback_data' => "fx_res_panelpage_{$rid}_" . ($page + 1)];
    if ($nav) $rows[] = $nav;
    $rows[] = [['text' => '⬅️ بازگشت به منوی نماینده', 'callback_data' => "fx_res_sel_{$rid}"]];
    return $rows;
}

// =====================================================================
// FX-RESELLER (2026-10-16): per-reseller PRODUCT MANAGER (admin view).
// Lists products visible to a reseller (exclusive + shared), with the
// effective wholesale/retail/profit, visibility toggle and release.
// =====================================================================

function fx_res_admin_products_data(string $rid, int $page = 1, int $per = 10): array {
    global $pdo;

    $ridI = (int)$rid;
    $page = max(1, $page);
    $per = max(1, $per);
    $off = ($page - 1) * $per;
    $rows = [];
    $total = 0;
    if ($ridI <= 0) return [$rows, $total, $page, $per];
    try {
        $scope = function_exists('fx_res_product_scope_sql')
            ? fx_res_product_scope_sql('p', ':rid')
            : "(FIND_IN_SET(:rid, REPLACE(p.agent, ' ', '')) OR p.agent IN ('all', 'allusers', 'f', 'n', 'n2') OR p.agent = '')";
        $cnt = $pdo->prepare("SELECT COUNT(*) FROM product p WHERE {$scope}");
        $cnt->execute([':rid' => (string)$ridI]);
        $total = (int)$cnt->fetchColumn();
        $stmt = $pdo->prepare(
            "SELECT p.id, p.name_product, p.agent, p.price_product AS base_price, p.fx_wholesale_price AS wholesale, r.retail_price AS retail " .
            "FROM product p LEFT JOIN fx_retail_prices r ON r.product_id = p.id AND r.reseller_id = :rid2 " .
            "WHERE {$scope} " .
            "ORDER BY p.id ASC LIMIT {$per} OFFSET {$off}"
        );
        $stmt->execute([':rid' => (string)$ridI, ':rid2' => $ridI]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) {}
    return [$rows, $total, $page, $per];
}

function fx_res_render_admin_products(int $from_id, int $message_id, int $rid, int $page = 1): void {
    global $pdo;

    $ridS = (string)$rid;
    [$rows, $total, $page, $per] = fx_res_admin_products_data($ridS, $page);
    $hidden = function_exists('fx_res_visibility_hidden_ids') ? fx_res_visibility_hidden_ids($rid) : [];
    $pages = max(1, (int)ceil($total / $per));

    $msg = "🛍 محصولات نماینده {$ridS} — صفحه {$page} از {$pages}\n"
        . "━━━━━━━━━━━━━━\n";
    $kbRows = [];
    if (!$rows) {
        $msg .= "📭 محصولی برای این نماینده یافت نشد.\nاز «📦 اختصاص محصول» اقدام کنید.";
    } else {
        foreach ($rows as $row) {
            $pid = (int)$row['id'];
            $base = (int)($row['base_price'] ?? 0);
            $whRaw = (int)($row['wholesale'] ?? 0);
            $wh = $whRaw > 0 ? $whRaw : $base;
            $ret = (int)($row['retail'] ?? 0);
            $cur = $ret > 0 ? $ret : $base;
            $isExc = in_array($ridS, array_filter(array_map('trim', explode(',', (string)$row['agent']))), true);
            $isHid = in_array($pid, $hidden, true);
            $msg .= ($isHid ? "🚫" : "▫️") . " " . (string)$row['name_product']
                . " [" . ($isExc ? 'اختصاصی' : 'مشترک') . "]\n"
                . "   💵 عمده: " . number_format($wh) . " | 💰 فروش: " . number_format($cur)
                . ($ret > 0 ? " (سفارشی)" : " (پایه)") . " | 📈 سود: " . number_format($cur - $wh) . "\n";
            $line = [['text' => "🏷️ " . mb_substr((string)$row['name_product'], 0, 18), 'callback_data' => "fx_res_set_wh_{$ridS}_{$pid}"]];
            $line[] = ['text' => $isHid ? '👁 نمایش' : '🚫 پنهان', 'callback_data' => "fx_res_pvis_{$ridS}_{$pid}_" . ($isHid ? '0' : '1') . "_{$page}"];
            if ($isExc) {
                $line[] = ['text' => '♻️ آزادسازی', 'callback_data' => "fx_res_prelease_{$ridS}_{$pid}_{$page}"];
            }
            $kbRows[] = $line;
        }
    }
    $nav = [];
    if ($page > 1) $nav[] = ['text' => '⬅️ صفحه قبل', 'callback_data' => "fx_res_products_{$ridS}_" . ($page - 1)];
    if ($page < $pages) $nav[] = ['text' => 'صفحه بعد ➡️', 'callback_data' => "fx_res_products_{$ridS}_" . ($page + 1)];
    if ($nav) $kbRows[] = $nav;
    $kbRows[] = [['text' => '⬅️ بازگشت به منوی نماینده', 'callback_data' => "fx_res_sel_{$ridS}"]];

    Editmessagetext($from_id, (int)$message_id, $msg, fx_res_inline_kb($kbRows), 'HTML');
}


// =====================================================================
// FX-BUYERS-SECTION (2026-10-04): per-reseller buyer/service list with
// live remaining volume + admin-driven extend/remove. Step 1 of the
// reseller buyer-management feature.
// =====================================================================

function fx_res_buyer_services(string $rid, int $limit = 15): array {
    global $pdo;
    $out = [];
    $rtok = function_exists('fx_reseller_bot_token') ? (string)(fx_reseller_bot_token((int)$rid) ?? '') : '';
    try {
        $st = $pdo->prepare("SELECT i.id_invoice, i.id_user, i.username, i.name_product, i.Service_location, i.Service_time, i.Volume, i.Status FROM invoice i JOIN user u ON u.id = i.id_user WHERE (u.agent = :rid OR u.bottype = :rtok) AND i.Status IN ('active','end_of_time','end_of_volume','sendedwarn','send_on_hold') ORDER BY i.time_sell DESC LIMIT " . max(1, (int)$limit));
        $st->execute([':rid' => (string)$rid, ':rtok' => $rtok]);
        $out = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) {
        $out = [];
    }
    return $out;
}

/**
 * Live service stats from the panel. Returns null when the panel is
 * unreachable (callers should then stop probing to avoid N x timeout).
 */
function fx_res_live_service_stat(string $panelName, string $svcUsername): ?array {
    global $ManagePanel;
    if (!($ManagePanel instanceof ManagePanel)) return null;
    try {
        $d = $ManagePanel->DataUser($panelName, $svcUsername);
        if (!is_array($d) || ($d['status'] ?? '') === 'Unsuccessful') return null;
        $rem = null;
        if (is_numeric($d['data_limit'] ?? null) && (float)$d['data_limit'] > 0.0) {
            $rem = max(0.0, (float)$d['data_limit'] - (float)($d['used_traffic'] ?? 0));
        }
        return [
            'remaining' => $rem,
            'expire' => is_numeric($d['expire'] ?? null) ? (int)$d['expire'] : 0,
        ];
    } catch (\Throwable $e) {
        return null;
    }
}

function fx_res_fmt_volume(?float $remBytes): string {
    if ($remBytes === null) return 'نامحدود';
    if (function_exists('formatBytes')) return formatBytes($remBytes);
    return round($remBytes / 1073741824, 1) . ' گیگ';
}

/**
 * Financial + config + credit report for a reseller (admin view, read-only).
 * Revenue = customers' paid retail (invoice.price_product); Cost = reseller
 * wholesale (product.fx_wholesale_price, defaulting to retail => zero margin);
 * Net = Revenue - Cost. Periods: today / week / month (host-local boundaries).
 * Configs: active (active/sendedwarn/send_on_hold) vs expired (end_of_time/end_of_volume).
 * Credit: debt ceiling / current / remaining allowed for a new config + balance.
 */
function fx_res_report_data(int $rid): array {
    global $pdo;
    $sinceToday = (int)strtotime('today');
    $sinceWeek  = (int)strtotime('monday this week');
    $sinceMonth = (int)strtotime('first day of this month');

    $rtok = function_exists('fx_reseller_bot_token') ? (string)(fx_reseller_bot_token($rid) ?? '') : '';
    $base = "FROM invoice i
             JOIN user u ON u.id = i.id_user
             LEFT JOIN product p ON p.name_product = i.name_product
             WHERE (u.agent = :rid OR u.bottype = :rtok)
               AND i.name_product != 'سرویس تست'
               AND i.Status NOT IN ('removebyadmin')
               AND CAST(i.time_sell AS UNSIGNED) >= :since";

    $periods = [];
    foreach (['today' => $sinceToday, 'week' => $sinceWeek, 'month' => $sinceMonth] as $key => $since) {
        $p = ['count' => 0, 'revenue' => 0, 'cost' => 0, 'net' => 0];
        try {
            $st = $pdo->prepare("SELECT COUNT(*) AS c,
                     COALESCE(SUM(CAST(i.price_product AS DECIMAL(20,0))),0) AS rev,
                     COALESCE(SUM(COALESCE(p.fx_wholesale_price, CAST(i.price_product AS DECIMAL(20,0))),0)) AS cost
                  " . $base);
            $st->execute([':rid' => (string)$rid, ':rtok' => $rtok, ':since' => (string)$since]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $p['count']   = (int)$row['c'];
                $p['revenue'] = (int)$row['rev'];
                $p['cost']    = (int)$row['cost'];
                $p['net']     = $p['revenue'] - $p['cost'];
            }
        } catch (\Throwable $e) { /* leave zeros on any DB hiccup */ }
        $periods[$key] = $p;
    }

    $cfg = ['active' => 0, 'expired' => 0, 'total' => 0];
    try {
        $st = $pdo->prepare("SELECT
                    SUM(CASE WHEN i.Status IN ('active','sendedwarn','send_on_hold') THEN 1 ELSE 0 END) AS a,
                    SUM(CASE WHEN i.Status IN ('end_of_time','end_of_volume') THEN 1 ELSE 0 END) AS e,
                    COUNT(*) AS t
                 FROM invoice i
                 JOIN user u ON u.id = i.id_user
                 WHERE (u.agent = :rid OR u.bottype = :rtok) AND i.name_product != 'سرویس تست'");
        $st->execute([':rid' => (string)$rid, ':rtok' => $rtok]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $cfg['active']  = (int)$row['a'];
            $cfg['expired'] = (int)$row['e'];
            $cfg['total']   = (int)$row['t'];
        }
    } catch (\Throwable $e) { /* leave zeros */ }

    $credit = ['mode' => 'balance', 'ceiling' => 0, 'current' => 0, 'remaining' => 0];
    if (function_exists('fx_debt_state')) {
        $stt = fx_debt_state($rid);
        $credit['mode']    = $stt['mode'] ?? 'balance';
        $credit['ceiling'] = (int)($stt['ceiling'] ?? 0);
        $credit['current'] = (int)($stt['current'] ?? 0);
        $credit['remaining'] = max(0, $credit['ceiling'] - $credit['current']);
    }
    $balance = function_exists('fx_reseller_db_balance') ? fx_reseller_db_balance($rid) : 0;

    return ['periods' => $periods, 'configs' => $cfg, 'credit' => $credit, 'balance' => (int)$balance];
}

/**
 * Render the reseller financial/credit report (edit-in-place, glass UI, read-only).
 */
function fx_res_render_report(int $from_id, int $message_id, string $rid): void {
    step('fx_res_report', $from_id);
    update("user", "Processing_value", $rid, "id", $from_id);
    $d = fx_res_report_data((int)$rid);
    $p = $d['periods'];
    $c = $d['credit'];
    $fmt = function ($n) { return number_format((int)$n); };
    $lines = [];
    $lines[] = "📊 <b>گزارش مالی و اعتباری نماینده</b>";
    $lines[] = "";
    $lines[] = "💵 فروش / سود خالص (به تومان)";
    $lines[] = "▫️ امروز: فروش " . $fmt($p['today']['revenue']) . " | سود " . $fmt($p['today']['net']) . " (" . $p['today']['count'] . " خرید)";
    $lines[] = "▫️ این هفته: فروش " . $fmt($p['week']['revenue']) . " | سود " . $fmt($p['week']['net']) . " (" . $p['week']['count'] . " خرید)";
    $lines[] = "▫️ این ماه: فروش " . $fmt($p['month']['revenue']) . " | سود " . $fmt($p['month']['net']) . " (" . $p['month']['count'] . " خرید)";
    $lines[] = "";
    $lines[] = "🧩 کانفیگ‌ها: " . $fmt($d['configs']['active']) . " فعال | " . $fmt($d['configs']['expired']) . " منقضی (مجموع " . $fmt($d['configs']['total']) . ")";
    $lines[] = "";
    $modeLabel = $c['mode'] === 'debt' ? 'اعتباری (بدهی)' : 'پیش‌پرداخت (موجودی)';
    $lines[] = "🏦 حالت: " . $modeLabel;
    $lines[] = "📏 سقف بدهی (Debt_Ceiling): " . $fmt($c['ceiling']);
    $lines[] = "💸 بدهی فعلی (Current_Debt): " . $fmt($c['current']);
    $lines[] = "✅ اعتبار باقی‌مانده (مجاز برای ساخت کانفیگ جدید): " . $fmt($c['remaining']);
    $lines[] = "💰 موجودی کیف‌پول: " . $fmt($d['balance']);
    $lines[] = "";
    $lines[] = "همه مبالغ به تومان است.";
    $kb = fx_res_inline_kb([
        [['text' => '⬅️ بازگشت به منوی نماینده', 'callback_data' => "fx_res_sel_{$rid}"]],
    ]);
    Editmessagetext($from_id, $message_id, implode("\n", $lines), $kb, 'HTML');
}

/**
 * Merged account ledger for a reseller: debt movements (fx_debt_log) and
 * admin wallet operations (Payment_report admin_credit/admin_debit),
 * newest first. Read-only admin view.
 */
function fx_res_ledger_data(int $rid, int $limit = 20): array {
    global $pdo;
    $items = [];
    try {
        $st = $pdo->prepare("SELECT amount, direction, method, invoice_ref, debt_after, time FROM fx_debt_log WHERE reseller_id = :rid ORDER BY id DESC LIMIT 40");
        $st->execute([':rid' => $rid]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $amt = (int)$r['amount'];
            if ($r['direction'] === 'in') {
                $label = $r['method'] === 'purchase' ? '🧾 خرید عمده' : ($r['method'] === 'adjustment' ? '⚖️ اصلاح حساب' : '📥 افزایش بدهی');
                $amtTxt = '+' . number_format($amt);
            } else {
                $label = $r['method'] === 'settlement' ? '💳 تسویه از بدهی' : ($r['method'] === 'credit' ? '💰 شارژ/تسویه بدهی' : '📤 کاهش بدهی');
                $amtTxt = '-' . number_format($amt);
            }
            if (!empty($r['invoice_ref'])) $label .= ' (' . $r['invoice_ref'] . ')';
            $items[] = [
                'ts' => (int)strtotime(str_replace('/', '-', (string)$r['time'])),
                'label' => $label,
                'amount' => $amtTxt,
                'debt_after' => (int)$r['debt_after'],
                'time' => (string)$r['time'],
                'kind' => 'debt',
            ];
        }
    } catch (\Throwable $e) { /* leave as-is */ }
    try {
        $st = $pdo->prepare("SELECT time, price, Payment_Method FROM Payment_report WHERE id_user = :uid AND payment_Status = 'paid' AND Payment_Method IN ('admin_credit','admin_debit') ORDER BY id DESC LIMIT 40");
        $st->execute([':uid' => (string)$rid]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $amt = abs((int)round((float)$r['price']));
            if ($amt <= 0) continue;
            $isCredit = $r['Payment_Method'] === 'admin_credit';
            $items[] = [
                'ts' => (int)strtotime(str_replace('/', '-', (string)$r['time'])),
                'label' => $isCredit ? '➕ شارژ کیف‌پول (مدیر)' : '➖ کسر کیف‌پول (مدیر)',
                'amount' => ($isCredit ? '+' : '-') . number_format($amt),
                'debt_after' => null,
                'time' => (string)$r['time'],
                'kind' => 'wallet',
            ];
        }
    } catch (\Throwable $e) { /* leave as-is */ }
    usort($items, function ($a, $b) { return $b['ts'] <=> $a['ts']; });
    return array_slice($items, 0, max(1, $limit));
}

/**
 * Render the reseller account ledger (edit-in-place, glass UI, read-only).
 */
function fx_res_render_ledger(int $from_id, int $message_id, string $rid): void {
    step('fx_res_ledger', $from_id);
    update("user", "Processing_value", $rid, "id", $from_id);
    $items = fx_res_ledger_data((int)$rid, 20);
    $st = function_exists('fx_debt_state') ? fx_debt_state((int)$rid) : ['mode' => 'balance', 'current' => 0, 'ceiling' => 0];
    $bal = number_format(fx_reseller_db_balance((int)$rid));
    $txt = "📒 <b>دفتر حساب نماینده {$rid}</b>\n"
        . "💰 موجودی: {$bal} تومان | 💸 بدهی: " . number_format((int)($st['current'] ?? 0)) . " تومان\n";
    if (empty($items)) {
        $txt .= "\n📭 هنوز گردشی ثبت نشده است.";
    } else {
        $txt .= "\n";
        foreach ($items as $it) {
            $ts = $it['ts'] > 0
                ? ((function_exists('jdate') ? jdate('Y/m/d', $it['ts']) : date('Y/m/d', $it['ts'])) . ' ' . date('H:i', $it['ts']))
                : $it['time'];
            $line = "▫️ {$ts} | {$it['label']} | <b>{$it['amount']}</b>";
            if ($it['kind'] === 'debt' && $it['debt_after'] !== null) $line .= " (بدهی: " . number_format($it['debt_after']) . ")";
            $txt .= $line . "\n";
        }
    }
    $kb = fx_res_inline_kb([['text' => '⬅️ بازگشت به منوی نماینده', 'callback_data' => "fx_res_sel_{$rid}"]]);
    Editmessagetext($from_id, $message_id, $txt, $kb, 'HTML');
}

/**
 * FX-RESELLER (2026-10-16): build the bot-admin list view (text + rows) for the
 * central reseller management panel. Owner is always #1 and not removable.
 */
function fx_res_bot_admins_view(int $rid): array {
    $bot = select("botsaz", "admin_ids, id_user, username", "id_user", $rid, "select", ['cache' => false]);
    if (!is_array($bot)) {
        return [
            'txt' => "❌ رباتی برای نماینده {$rid} ثبت نشده است.",
            'rows' => [['text' => '⬅️ بازگشت به منوی نماینده', 'callback_data' => "fx_res_sel_{$rid}"]],
        ];
    }
    $owner = (string)$bot['id_user'];
    $ids = json_decode((string)($bot['admin_ids'] ?? '[]'), true);
    if (!is_array($ids)) $ids = [];
    $ids = array_values(array_unique(array_map('strval', $ids)));
    if (!in_array($owner, $ids, true)) array_unshift($ids, $owner);

    $txt = "👥 <b>مدیران ربات نماینده {$rid}</b>\n"
        . "🤖 @{$bot['username']}\n\n";
    $rows = [];
    foreach ($ids as $i => $aid) {
        $tag = ($aid === $owner) ? ' (مالک)' : '';
        $txt .= ($i + 1) . ". <code>{$aid}</code>{$tag}\n";
        if ($aid !== $owner) {
            $rows[] = [['text' => "🗑 حذف {$aid}", 'callback_data' => "fx_res_admdel_{$rid}_{$i}"]];
        }
    }
    $txt .= "\nℹ️ مدیران می‌توانند وارد پنل مدیریت این ربات شوند. («حساب من» مخصوص مالک است.)";
    $rows[] = [['text' => '➕ افزودن مدیر', 'callback_data' => "fx_res_admadd_{$rid}"]];
    $rows[] = [['text' => '⬅️ بازگشت به منوی نماینده', 'callback_data' => "fx_res_sel_{$rid}"]];
    return ['txt' => $txt, 'rows' => $rows];
}

function fx_res_render_bot_admins(int $from_id, int $message_id, int $rid): void {
    $v = fx_res_bot_admins_view($rid);
    step('fx_res_admins', $from_id);
    update("user", "Processing_value", $rid, "id", $from_id);
    Editmessagetext($from_id, $message_id, $v['txt'], fx_res_inline_kb($v['rows']), 'HTML');
}

function fx_res_send_bot_admins(int $from_id, int $rid): void {
    $v = fx_res_bot_admins_view($rid);
    step('fx_res_admins', $from_id);
    update("user", "Processing_value", $rid, "id", $from_id);
    sendmessage($from_id, $v['txt'], fx_res_inline_kb($v['rows']), 'HTML');
}

/**
 * Render the buyer/service list for a reseller (edit-in-place, glass UI).
 */
function fx_res_render_buyers(int $from_id, int $message_id, string $rid): void {
    step('fx_res_buyers', $from_id);
    update("user", "Processing_value", $rid, "id", $from_id);
    $svcs = fx_res_buyer_services($rid);
    $rows = [];
    $txt = "👥 <b>سرویس‌های خریداران نماینده {$rid}</b>\n";
    $liveOk = true;
    if (empty($svcs)) {
        $txt .= "📭 هنوز سرویس فعالی ثبت نشده است.";
    } else {
        foreach ($svcs as $s) {
            $stat = null;
            if ($liveOk) {
                $stat = fx_res_live_service_stat((string)$s['Service_location'], (string)$s['username']);
                if ($stat === null) $liveOk = false;
            }
            $vol = $stat === null ? '—' : fx_res_fmt_volume($stat['remaining']);
            if ($stat === null) {
                $exp = '—';
            } elseif ($stat['expire'] > 0) {
                $exp = function_exists('jdate') ? jdate('Y/m/d', $stat['expire']) : date('Y/m/d', $stat['expire']);
            } else {
                $exp = 'نامحدود';
            }
            $txt .= "\n🔹 <code>" . $s['username'] . "</code> | {$s['name_product']}\n📥 باقی‌مانده: {$vol} | 📅 اتمام: {$exp}\n👤 خریدار: " . $s['id_user'];
            $rows[] = [
                ['text' => "⏳ تمدید " . $s['username'], 'callback_data' => "fx_res_bext_" . $s['id_invoice']],
                ['text' => "🗑 حذف " . $s['username'], 'callback_data' => "fx_res_bdel_" . $s['id_invoice']],
            ];
        }
        if (!$liveOk) $txt .= "\n⚠️ استعلام زنده حجم از پنل ممکن نبود (پنل در دسترس نیست).";
        $txt .= "\n\n" . count($svcs) . " سرویس فعال نمایش داده شد.";
    }
    $rows[] = [['text' => '⬅️ بازگشت به منوی نماینده', 'callback_data' => "fx_res_sel_{$rid}"]];
    Editmessagetext($from_id, $message_id, $txt, fx_res_inline_kb($rows), 'HTML');
}

/**
 * Resolve the reseller id that owns a given invoice.
 * Tenant model: new signups carry user.agent=<rid>; legacy engine users carry
 * user.bottype=<reseller bot token>. Both must resolve to the botsaz id_user.
 */
function fx_res_buyer_rid($iid): int {
    $nameloc = select("invoice", "*", "id_invoice", $iid, "select");
    if (!is_array($nameloc)) return 0;
    $bu = select("user", "*", "id", $nameloc['id_user'], "select");
    if (!is_array($bu)) return 0;
    $agent = (string)($bu['agent'] ?? '');
    if ($agent !== '' && ctype_digit($agent) && (int)$agent > 0) {
        $b = select("botsaz", "*", "id_user", (int)$agent, "select", ['cache' => false]);
        if (is_array($b)) return (int)$agent;
    }
    $tok = (string)($bu['bottype'] ?? '');
    if ($tok !== '') {
        $b = select("botsaz", "*", "bot_token", $tok, "select", ['cache' => false]);
        if (is_array($b)) return (int)$b['id_user'];
    }
    return ctype_digit($agent) ? (int)$agent : 0;
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
    if (defined('FX_RESELLER_ID')) return false; // main-admin module only
    if (!in_array($from_id, (array)$admin_ids)) return false;
    if (strpos((string)$datain, 'fx_res_') !== 0 && $datain !== 'fx_reseller_mgmt_menu' && $datain !== 'fx_res_backadmin') return false;
    return (bool)fx_reseller_mgmt_dispatch((string)$datain, (int)$from_id, (int)$message_id, (array)$user, (string)$backadmin);
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
        $__fxDebtSt = fx_debt_state($rid);
        $__fxDebtLine = $__fxDebtSt['mode'] === 'debt'
            ? "\n🏦 حالت: اعتباری (بدهی) — سقف: " . number_format($__fxDebtSt['ceiling']) . " | بدهی فعلی: " . number_format($__fxDebtSt['current']) . " تومان"
            : "\n🏦 حالت: پیش‌پرداخت (موجودی)";
        $__fxNameG = (isset($rUser['username']) && $rUser['username'] !== '' && $rUser['username'] !== 'none' && $rUser['username'] !== 'NOT_USERNAME') ? ('@' . $rUser['username'] . ' (ID: ' . $rid . ')') : ('ID ' . $rid);
        $__fxBot = select("botsaz", "*", "id_user", $rid, "select", ['cache' => false]);
        $__fxBotLine = (is_array($__fxBot) && !empty($__fxBot['username']))
            ? "\n🤖 ربات: @{$__fxBot['username']} | https://t.me/{$__fxBot['username']}"
            : "\n⚠️ رباتی برای این نماینده ثبت نشده";
        // FX-RESELLER (2026-10-16): bot stop/start from the central panel + status line.
        $__fxBotSet = function_exists('fx_res_setting_get') ? fx_res_setting_get($rid) : [];
        $__fxBotStopped = ((string)($__fxBotSet['bot_disabled'] ?? '0') === '1');
        if ($__fxBotStopped) {
            $__fxBotLine .= "\n⏸ ربات متوقف است (کاربران نمی‌توانند استفاده کنند)";
        }
        $kb = fx_res_inline_kb([
            [['text' => '➕ شارژ موجودی', 'callback_data' => "fx_res_credit_{$rid}"],
             ['text' => '➖ کسر موجودی', 'callback_data' => "fx_res_debit_{$rid}"]],
            [['text' => '📦 اختصاص محصول', 'callback_data' => "fx_res_asgn_prod_{$rid}"],
             ['text' => '🔌 اختصاص پنل', 'callback_data' => "fx_res_asgn_panel_{$rid}"]],
            [['text' => '💳 اعتباری (بدهی)', 'callback_data' => "fx_res_debt_{$rid}"],
             ['text' => '🔑 اعتبار پاسارگاد', 'callback_data' => "fx_res_pasargad_{$rid}"]],
            [['text' => '👥 خریداران (سرویس‌ها)', 'callback_data' => "fx_res_buyers_{$rid}"],
             ['text' => '🏷️ قیمت عمده‌فروشی', 'callback_data' => "fx_res_whprice_{$rid}"]],
            [['text' => '🛍 محصولات نماینده', 'callback_data' => "fx_res_products_{$rid}"]],
            [['text' => '⚙ وبهوک ربات', 'callback_data' => "fx_res_wh_{$rid}"],
             ['text' => '🗑 حذف ربات', 'callback_data' => "fx_res_delconfirm_{$rid}"]],
            [['text' => '⚙️ تنظیم سقف بدهی', 'callback_data' => "fx_res_debt_ceiling_{$rid}"],
             ['text' => '💳 ثبت تسویه‌حساب', 'callback_data' => "fx_res_settle_{$rid}"]],
            [['text' => '📊 گزارش مالی و اعتباری', 'callback_data' => "fx_res_report_{$rid}"],
             ['text' => '📒 دفتر حساب', 'callback_data' => "fx_res_ledger_{$rid}"]],
            [['text' => '👥 مدیران ربات', 'callback_data' => "fx_res_admins_{$rid}"],
             ['text' => ($__fxBotStopped ? '▶️ فعال‌سازی ربات' : '⏸ توقف ربات'), 'callback_data' => "fx_res_bottoggle_{$rid}"]],
            [['text' => '⬅️ بازگشت به فهرست نمایندگان', 'callback_data' => 'fx_reseller_mgmt_menu']],
        ]);
        $txt = "👤 {$__fxNameG}\n💰 موجودی: {$bal} تومان" . $__fxBotLine . $__fxDebtLine;
        Editmessagetext($from_id, $message_id, $txt, $kb, 'HTML');
        fx_res_answer_cb();
        return true;
    }

    // --- bot admins + stop/start (FX-RESELLER 2026-10-16, central panel) ---
    if (preg_match('/^fx_res_admins_(\d+)$/', $datain, $m)) {
        fx_res_render_bot_admins($from_id, (int)$message_id, (int)$m[1]);
        fx_res_answer_cb();
        return true;
    }
    if (preg_match('/^fx_res_bottoggle_(\d+)$/', $datain, $m)) {
        $rid = (int)$m[1];
        $cur = function_exists('fx_res_setting_get') ? fx_res_setting_get($rid) : [];
        $stopped = ((string)($cur['bot_disabled'] ?? '0') === '1');
        fx_res_setting_patch($rid, ['bot_disabled' => $stopped ? '0' : '1']);
        // re-render the detail screen in place (button + status reflect the new state)
        return fx_reseller_mgmt_dispatch("fx_res_sel_{$rid}", $from_id, (int)$message_id, $user, $backuser);
    }
    if (preg_match('/^fx_res_admadd_(\d+)$/', $datain, $m)) {
        step('fx_res_do_addadmin', $from_id);
        update("user", "Processing_value", $m[1], "id", $from_id);
        Editmessagetext($from_id, (int)$message_id, "👥 آیدی عددی مدیر جدید را برای ربات نماینده {$m[1]} وارد کنید:\nانصراف: /cancel", null, 'html');
        fx_res_answer_cb();
        return true;
    }
    if (preg_match('/^fx_res_admdel_(\d+)_(\d+)$/', $datain, $m)) {
        $rid = (int)$m[1];
        $idx = (int)$m[2];
        $bot = select("botsaz", "admin_ids, id_user", "id_user", $rid, "select", ['cache' => false]);
        if (is_array($bot)) {
            $owner = (string)$bot['id_user'];
            $ids = json_decode((string)($bot['admin_ids'] ?? '[]'), true);
            if (!is_array($ids)) $ids = [];
            $ids = array_values(array_unique(array_map('strval', $ids)));
            if (!in_array($owner, $ids, true)) array_unshift($ids, $owner);
            if (isset($ids[$idx]) && $ids[$idx] !== $owner) {
                unset($ids[$idx]);
                $ids = array_values($ids);
                try {
                    $pdo->prepare("UPDATE botsaz SET admin_ids = :a WHERE id_user = :u")
                        ->execute([':a' => json_encode($ids), ':u' => (string)$rid]);
                } catch (\Throwable $e) {}
            }
        }
        fx_res_render_bot_admins($from_id, (int)$message_id, $rid);
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
        $__fxResProductMode = 'assign';
        step('fx_res_prod_list', $from_id);
        update("user", "Processing_value", $rid, "id", $from_id);
        update("user", "Processing_value_one", 'assign', "id", $from_id);
        Editmessagetext($from_id, $message_id, "📦 محصول را برای اختصاص به نماینده {$rid} انتخاب کنید:", fx_res_inline_kb(fx_res_product_rows($rid)), 'HTML');
        fx_res_answer_cb();
        return true;
    }
    if (preg_match('/^fx_res_set_prod_(\d+)_(\d+)$/', $datain, $m)) {
        $rid = $m[1]; $pid = $m[2];
        // Shared product list: if the current step is the wholesale-price screen,
        // the same row click sets the wholesale price instead of assigning.
        if ((string)($user['step'] ?? '') === 'fx_res_whprice_list') {
            $pidI = intval($pid);
            $st = $pdo->prepare("SELECT price_product FROM product WHERE id = :pid LIMIT 1");
            $st->execute([':pid' => $pidI]);
            $retail = $st->fetchColumn();
            step('fx_res_do_whprice_val', $from_id);
            update("user", "Processing_value", $rid, "id", $from_id);
            update("user", "Processing_value_one", $pidI, "id", $from_id);
            Editmessagetext($from_id, $message_id, "🏷️ قیمت عمده‌فروشی (هزینه هر خرید نماینده، به تومان) را برای این محصول وارد کنید:\n💵 قیمت فروش مشتری: " . number_format((float)($retail ?: 0)) . " تومان\nتا زمانی تنظیم نکنید، هزینه = قیمت فروش (حاشیه صفر).\nانصراف: /cancel", null, 'html');
            fx_res_answer_cb();
            return true;
        }
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

    // --- product/panel list pagination ---
    if (preg_match('/^fx_res_prodpage_(\d+)_(\d+)$/', $datain, $m)) {
        $rid = $m[1];
        $page = max(1, intval($m[2]));
        $isWh = ((string)($user['step'] ?? '') === 'fx_res_whprice_list');
        step($isWh ? 'fx_res_whprice_list' : 'fx_res_prod_list', $from_id);
        update("user", "Processing_value", $rid, "id", $from_id);
        $title = $isWh
            ? "🏷️ محصولی که قیمت عمده‌فروشی (هزینه نماینده) آن را می‌خواهید تنظیم کنید انتخاب کنید (صفحه {$page}):"
            : "📦 محصول را برای اختصاص به نماینده {$rid} انتخاب کنید (صفحه {$page}):";
        Editmessagetext($from_id, $message_id, $title, fx_res_inline_kb(fx_res_product_rows($rid, $page)), 'HTML');
        fx_res_answer_cb();
        return true;
    }
    if (preg_match('/^fx_res_panelpage_(\d+)_(\d+)$/', $datain, $m)) {
        $rid = $m[1];
        $page = max(1, intval($m[2]));
        step('fx_res_panel_list', $from_id);
        update("user", "Processing_value", $rid, "id", $from_id);
        Editmessagetext($from_id, $message_id, "🔌 پنل را برای اختصاص به نماینده {$rid} انتخاب کنید (صفحه {$page}):", fx_res_inline_kb(fx_res_panel_rows($rid, $page)), 'HTML');
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

    // --- credit sales (debt mode): toggle + ceiling setting ---
    if (preg_match('/^fx_res_debt_(\d+)$/', $datain, $m)) {
        $rid = intval($m[1]);
        $st = fx_debt_state($rid);
        if ($st['mode'] === 'debt') {
            $kb = fx_res_inline_kb([
                [['text' => '✏️ سقف اعتبار', 'callback_data' => "fx_res_debt_ceiling_{$rid}"]],
                [['text' => '🚫 غیرفعال‌کردن حالت اعتباری', 'callback_data' => "fx_res_debt_off_{$rid}"]],
                [['text' => '⬅️ بازگشت به منوی نماینده', 'callback_data' => "fx_res_sel_{$rid}"]],
            ]);
            $txt = "🏦 نماینده {$rid} در حالت <b>اعتباری (فروش به‌حساب)</b> است.\n"
                . "💵 بدهی فعلی: " . number_format($st['current']) . " تومان\n"
                . "🏦 سقف اعتبار: " . number_format($st['ceiling']) . " تومان\n"
                . "مشتری‌ها با کیف پول خود می‌خرند و هزینه عمده‌فروشی سرویس‌ها به‌حساب نماینده ثبت می‌شود.\n"
                . "📥 تسویه: از «➕ شارژ موجودی» استفاده کنید (به بدهی تعلق می‌گیرد).";
        } else {
            $kb = fx_res_inline_kb([
                [['text' => '✅ فعال‌کردن حالت اعتباری', 'callback_data' => "fx_res_debt_on_{$rid}"]],
                [['text' => '⬅️ بازگشت به منوی نماینده', 'callback_data' => "fx_res_sel_{$rid}"]],
            ]);
            $txt = "🏦 نماینده {$rid} فعلاً در حالت <b>پیش‌پرداخت</b> است.\n"
                . "با فعال‌کردن حالت اعتباری، نماینده می‌تواند بدون موجودی سرویس بفروشد و هزینه عمده‌فروشی به‌حساب او ثبت شود (تا سقفی که تعیین کنید).\n"
                . "مشتری‌های ربات او همچنان از کیف پول خود پرداخت می‌کنند.";
        }
        Editmessagetext($from_id, $message_id, $txt, $kb, 'HTML');
        fx_res_answer_cb();
        return true;
    }
    if (preg_match('/^fx_res_debt_on_(\d+)$/', $datain, $m)) {
        $rid = intval($m[1]);
        @update("botsaz", "debt_mode", "debt", "id_user", $rid);
        @update("botsaz", "debt_alert_80", 0, "id_user", $rid);
        @update("botsaz", "debt_alert_95", 0, "id_user", $rid);
        step('fx_res_do_debt_ceiling', $from_id);
        update("user", "Processing_value", $rid, "id", $from_id);
        Editmessagetext($from_id, $message_id, "🏦 سقف اعتبار (به تومان) را وارد کنید:\nمثال: 50000000\n(هر خرید تا این سقف به‌حساب ثبت می‌شود)", null, 'html');
        fx_res_answer_cb();
        return true;
    }
    if (preg_match('/^fx_res_debt_off_(\d+)$/', $datain, $m)) {
        $rid = intval($m[1]);
        @update("botsaz", "debt_mode", "balance", "id_user", $rid);
        @update("botsaz", "debt_alert_80", 0, "id_user", $rid);
        @update("botsaz", "debt_alert_95", 0, "id_user", $rid);
        $st = fx_debt_state($rid);
        $msg = "✅ حالت اعتباری غیرفعال شد. نماینده {$rid} دوباره در حالت پیش‌پرداخت است."
            . (($st['current'] > 0) ? "\n⚠️ هنوز " . number_format($st['current']) . " تومان بدهی باقی است — با شارژ موجودی تسویه می‌شود." : '');
        step('fx_res_view', $from_id);
        update("user", "Processing_value", $rid, "id", $from_id);
        Editmessagetext($from_id, $message_id, $msg, fx_res_inline_kb([['text' => '⬅️ بازگشت به منوی نماینده', 'callback_data' => "fx_res_sel_{$rid}"]]), 'HTML');
        fx_res_answer_cb();
        return true;
    }
    if (preg_match('/^fx_res_debt_ceiling_(\d+)$/', $datain, $m)) {
        $rid = intval($m[1]);
        step('fx_res_do_debt_ceiling', $from_id);
        update("user", "Processing_value", $rid, "id", $from_id);
        $st = fx_debt_state($rid);
        Editmessagetext($from_id, $message_id, "🏦 سقف اعتبار جدید (به تومان) را وارد کنید:\nسقف فعلی: " . number_format($st['ceiling']), null, 'html');
        fx_res_answer_cb();
        return true;
    }

    // --- wholesale price per product (two-tier pricing) ---
    if (preg_match('/^fx_res_whprice_(\d+)$/', $datain, $m)) {
        $rid = $m[1];
        step('fx_res_whprice_list', $from_id);
        update("user", "Processing_value", $rid, "id", $from_id);
        Editmessagetext($from_id, $message_id, "🏷️ محصولی که قیمت عمده‌فروشی (هزینه نماینده) آن را می‌خواهید تنظیم کنید انتخاب کنید:", fx_res_inline_kb(fx_res_product_rows($rid)), 'HTML');
        fx_res_answer_cb();
        return true;
    }
    if (preg_match('/^fx_res_set_wh_(\d+)_(\d+)$/', $datain, $m)) {
        $rid = $m[1]; $pid = intval($m[2]);
        step('fx_res_do_whprice_val', $from_id);
        update("user", "Processing_value", $rid, "id", $from_id);
        update("user", "Processing_value_one", $pid, "id", $from_id);
        Editmessagetext($from_id, $message_id, "🏷️ قیمت عمده‌فروشی (هزینه هر خرید نماینده، به تومان) را برای محصول {$pid} وارد کنید:\nتا زمانی تنظیم نکنید، هزینه = قیمت فروش (حاشیه صفر).\nانصراف: /cancel", null, 'html');
        fx_res_answer_cb();
        return true;
    }

    // --- per-reseller product manager (FX-RESELLER 2026-10-16) ---
    if (preg_match('/^fx_res_products_(\d+)(?:_(\d+))?$/', $datain, $m)) {
        $rid = (int)$m[1];
        step('fx_res_view', $from_id);
        update("user", "Processing_value", $rid, "id", $from_id);
        fx_res_render_admin_products($from_id, (int)$message_id, $rid, max(1, (int)($m[2] ?? 1)));
        fx_res_answer_cb();
        return true;
    }
    if (preg_match('/^fx_res_pvis_(\d+)_(\d+)_([01])_(\d+)$/', $datain, $m)) {
        $rid = (int)$m[1];
        $pid = (int)$m[2];
        $hide = ($m[3] === '1');
        $page = max(1, (int)$m[4]);
        if (function_exists('fx_res_visibility_set')) {
            fx_res_visibility_set($pid, $rid, $hide);
        }
        fx_res_render_admin_products($from_id, (int)$message_id, $rid, $page);
        fx_res_answer_cb();
        return true;
    }
    if (preg_match('/^fx_res_prelease_(\d+)_(\d+)_(\d+)$/', $datain, $m)) {
        $rid = (int)$m[1];
        $pid = (int)$m[2];
        $page = max(1, (int)$m[3]);
        try {
            $st = $pdo->prepare("SELECT agent FROM product WHERE id = :pid LIMIT 1");
            $st->execute([':pid' => $pid]);
            $curAg = (string)($st->fetchColumn() ?? '');
            $parts = array_values(array_filter(
                array_map('trim', explode(',', str_replace(' ', '', $curAg))),
                static function ($x) use ($rid) { return $x !== '' && $x !== (string)$rid; }
            ));
            $newAg = $parts ? implode(',', $parts) : 'all';
            if ($newAg !== $curAg) {
                $pdo->prepare("UPDATE product SET agent = :a WHERE id = :pid")->execute([':a' => $newAg, ':pid' => $pid]);
            }
        } catch (\Throwable $e) {}
        fx_res_render_admin_products($from_id, (int)$message_id, $rid, $page);
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

    // --- buyer/service list (step 1: view buyers, live volume, extend/remove) ---
    if (preg_match('/^fx_res_buyers_(\d+)$/', $datain, $m)) {
        $rid = $m[1];
        fx_res_render_buyers($from_id, $message_id, $rid);
        fx_res_answer_cb();
        return true;
    }
    if (preg_match('/^fx_res_bext_(\w+)$/', $datain, $m)) {
        $iid = $m[1];
        $rid = (string)fx_res_buyer_rid($iid);
        $kb = fx_res_inline_kb([
            [['text' => '🏠 بازگشت به فهرست سرویس‌ها', 'callback_data' => "fx_res_buyers_{$rid}"]],
        ]);
        step('fx_res_buyer_extend', $from_id);
        update("user", "Processing_value", $iid, "id", $from_id);
        Editmessagetext($from_id, $message_id, "⏳ <b>تمدید سرویس خریدار</b>\nتعداد روز تمدید را ارسال کنید:\nمثال: <code>30</code>\n\nانصراف: /cancel", $kb, 'html');
        fx_res_answer_cb();
        return true;
    }
    if (preg_match('/^fx_res_bdel_(\w+)$/', $datain, $m)) {
        $iid = $m[1];
        $rid = (string)fx_res_buyer_rid($iid);
        step('fx_res_bdel_confirm', $from_id);
        update("user", "Processing_value", $iid, "id", $from_id);
        $kb = fx_res_inline_kb([
            [['text' => '✅ بله، حذف شود', 'callback_data' => "fx_res_bdelgo_{$iid}"],
             ['text' => '❌ انصراف', 'callback_data' => "fx_res_buyers_{$rid}"]],
        ]);
        Editmessagetext($from_id, $message_id, "🗑 آیا سرویس خریدار این فاکتور حذف شود؟\n<code>{$iid}</code>", $kb, 'html');
        fx_res_answer_cb();
        return true;
    }
    if (preg_match('/^fx_res_bdelgo_(\w+)$/', $datain, $m)) {
        $iid = $m[1];
        global $ManagePanel;
        $rid = (string)fx_res_buyer_rid($iid);
        $nameloc = select("invoice", "*", "id_invoice", $iid, "select");
        if (is_array($nameloc) && class_exists('ManagePanel')) {
            try {
                $ManagePanel->RemoveUser($nameloc['Service_location'], $nameloc['username']);
            } catch (\Throwable $e) {}
            update('invoice', 'Status', 'removebyadmin', 'id_invoice', $iid);
        }
        Editmessagetext($from_id, $message_id, "✅ سرویس خریدار حذف شد.", fx_res_inline_kb([['text' => '🏠 بازگشت به فهرست سرویس‌ها', 'callback_data' => "fx_res_buyers_{$rid}"]]), 'HTML');
        fx_res_answer_cb();
        return true;
    }

    // --- financial/credit report (step 2: read-only dashboard) ---
    if (preg_match('/^fx_res_report_(\d+)$/', $datain, $m)) {
        $rid = $m[1];
        fx_res_render_report($from_id, $message_id, $rid);
        fx_res_answer_cb();
        return true;
    }

    // --- account ledger (debt movements + admin wallet ops) ---
    if (preg_match('/^fx_res_ledger_(\d+)$/', $datain, $m)) {
        fx_res_render_ledger($from_id, $message_id, $m[1]);
        fx_res_answer_cb();
        return true;
    }

    // --- settlement: register a payment/deposit against the reseller's debt ---
    if (preg_match('/^fx_res_settle_(\d+)$/', $datain, $m)) {
        $rid = intval($m[1]);
        step('fx_res_do_settle', $from_id);
        update("user", "Processing_value", $rid, "id", $from_id);
        $st = fx_debt_state($rid);
        Editmessagetext($from_id, $message_id, "💳 <b>ثبت تسویه‌حساب / واریزی</b>\nمبلغ واریزی (به تومان) را برای نماینده {$rid} وارد کنید:\n💸 بدهی فعلی: " . number_format($st['current']) . " تومان\n(مبلغ تا سقف بدهی از Current_Debt کسر می‌شود؛ مازاد به موجودی اضافه می‌گردد.)\nانصراف: /cancel", null, 'html');
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
    global $ApiToken, $pdo;

    if ($user['step'] === 'fx_res_do_credit') {
        if (trim($text) === '/cancel') { fx_res_exit_to_hub($from_id); return true; }
        if (!ctype_digit($text)) { sendmessage($from_id, "❌ فقط عدد وارد کنید.", $backuser, 'html'); return true; }
        $rid = intval($user['Processing_value']);
        $amt = intval($text);
        $debtUsed = 0;
        try {
            $pdo->beginTransaction();
            $dbt = fx_debt_state($rid);
            if ($dbt['mode'] === 'debt' && $dbt['current'] > 0) {
                $debtUsed = min($amt, $dbt['current']);
                $pdo->prepare("UPDATE botsaz SET current_debt = GREATEST(0, current_debt - :amt) WHERE id_user = :rid")
                    ->execute([':amt' => $debtUsed, ':rid' => (string)$rid]);
                $after = fx_debt_state($rid)['current'];
                $pdo->prepare("INSERT INTO fx_debt_log (reseller_id, amount, direction, method, invoice_ref, debt_after, time) VALUES (:rid, :amt, 'out', :m, NULL, :after, NOW())")
                    ->execute([':rid' => $rid, ':amt' => $debtUsed, ':m' => 'settlement', ':after' => $after]);
                if ($after * 100 < $dbt['ceiling'] * 80) {
                    @update("botsaz", "debt_alert_80", 0, "id_user", $rid);
                    @update("botsaz", "debt_alert_95", 0, "id_user", $rid);
                }
            }
            $creditRemainder = $amt - $debtUsed;
            if ($creditRemainder > 0 && !fx_reseller_db_credit($rid, $creditRemainder, 'admin_credit', $ApiToken)) {
                throw new \RuntimeException('wallet credit failed');
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('[FX_RES] credit step failed rid=' . $rid . ': ' . $e->getMessage());
            sendmessage($from_id, "❌ خطا در شارژ. عملیات انجام نشد.", $backuser, 'html');
            fx_res_exit_to_hub($from_id);
            return true;
        }
        $msg = $debtUsed > 0
            ? "✅ تسویه شد: " . number_format($debtUsed) . " تومان بدهی نماینده {$rid} کسر شد" . (($amt - $debtUsed) > 0 ? " و " . number_format($amt - $debtUsed) . " تومان به موجودی اضافه شد." : ".")
            : "✅ مبلغ " . number_format($amt) . " تومان به نماینده {$rid} شارژ شد.";
        sendmessage($from_id, $msg, $backuser, 'html');
        // FX-ROUTE: notify the agent through the RESELLER bot's own token
        sendmessage($rid, "💎 " . (($debtUsed > 0) ? (number_format($debtUsed) . " تومان بدهی شما تسویه شد" . (($amt - $debtUsed) > 0 ? " و " . number_format($amt - $debtUsed) . " تومان به موجودی اضافه شد." : ".")) : (number_format($amt) . " تومان به موجودی شما اضافه شد.")), null, 'HTML', fx_reseller_bot_token($rid));
        fx_res_exit_to_hub($from_id);
        return true;
    }

    if ($user['step'] === 'fx_res_do_settle') {
        if (trim($text) === '/cancel') { fx_res_exit_to_hub($from_id); return true; }
        if (!ctype_digit($text)) { sendmessage($from_id, "❌ فقط عدد وارد کنید.", $backuser, 'html'); return true; }
        $amt = intval($text);
        if ($amt <= 0) { sendmessage($from_id, "❌ مبلغ باید بزرگ‌تر از صفر باشد.", $backuser, 'html'); return true; }
        $rid = intval($user['Processing_value']);
        $before = 0; $after = 0; $applied = 0; $excess = 0; $ceiling = 0;
        try {
            $pdo->beginTransaction();
            $st = fx_debt_state($rid);
            $before = $st['current'];
            $ceiling = (int)($st['ceiling'] ?? 0);
            $applied = min($amt, $before);
            $excess = $amt - $applied;
            if ($applied > 0) {
                $pdo->prepare("UPDATE botsaz SET current_debt = GREATEST(0, current_debt - :amt) WHERE id_user = :rid")
                    ->execute([':amt' => $applied, ':rid' => (string)$rid]);
            }
            $stmt = $pdo->prepare("SELECT current_debt FROM botsaz WHERE id_user = :rid LIMIT 1");
            $stmt->execute([':rid' => (string)$rid]);
            $after = (int)$stmt->fetchColumn();
            if ($applied > 0) {
                $pdo->prepare("INSERT INTO fx_debt_log (reseller_id, amount, direction, method, invoice_ref, debt_after, time) VALUES (:rid, :amt, 'out', :m, NULL, :after, NOW())")
                    ->execute([':rid' => $rid, ':amt' => $applied, ':m' => 'settlement', ':after' => $after]);
            }
            if ($ceiling > 0 && $after * 100 < $ceiling * 80) {
                @update("botsaz", "debt_alert_80", 0, "id_user", $rid);
                @update("botsaz", "debt_alert_95", 0, "id_user", $rid);
            }
            if ($excess > 0 && !fx_reseller_db_credit($rid, $excess, 'admin_credit', $ApiToken)) {
                throw new \RuntimeException('excess credit failed');
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('[FX_DEBT] settle failed rid=' . $rid . ' amt=' . $amt . ': ' . $e->getMessage());
            sendmessage($from_id, "❌ خطا در ثبت تسویه‌حساب.", $backuser, 'html');
            fx_res_exit_to_hub($from_id);
            return true;
        }
        $creditLeft = max(0, $ceiling - $after);
        $msgAdmin = "💳 تسویه‌حساب ثبت شد:\n"
            . (($applied > 0) ? ("✅ کسرشده از بدهی: " . number_format($applied) . " تومان\n") : "⚠️ بدهی فعالی وجود نداشت (کسری نشد).\n")
            . "💸 بدهی باقی‌مانده: " . number_format($after) . " تومان";
        $msgAgent = "💳 <b>تسویه‌حساب ثبت شد</b>\n"
            . ((($applied > 0)) ? ("✅ مبلغ تسویه‌شده: " . number_format($applied) . " تومان\n") : "⚠️ بدهی فعالی وجود نداشت (کسری نشد).\n")
            . "💸 بدهی باقی‌مانده: " . number_format($after) . " تومان";
        if ($ceiling > 0) {
            $msgAgent .= "\n🏦 سقف اعتبار: " . number_format($ceiling) . " تومان\n✅ اعتبار مجاز خرید: " . number_format($creditLeft) . " تومان";
        }
        if ($excess > 0) {
            $msgAdmin .= "\n💰 مازاد واریزی به موجودی اضافه شد: " . number_format($excess) . " تومان";
            $msgAgent .= "\n💰 مازاد واریزی به موجودی اضافه شد: " . number_format($excess) . " تومان";
        }
        sendmessage($from_id, $msgAdmin, $backuser, 'html');
        // FX-ROUTE: notify the agent through the RESELLER bot's own token
        sendmessage($rid, $msgAgent, null, 'HTML', fx_reseller_bot_token($rid));
        fx_res_exit_to_hub($from_id);
        return true;
    }

    if ($user['step'] === 'fx_res_do_debit') {
        if (trim($text) === '/cancel') { fx_res_exit_to_hub($from_id); return true; }
        if (!ctype_digit($text)) { sendmessage($from_id, "❌ فقط عدد وارد کنید.", $backuser, 'html'); return true; }
        $rid = intval($user['Processing_value']);
        if (fx_reseller_db_debit($rid, intval($text), 'admin_debit', $ApiToken)) {
            sendmessage($from_id, "✅ مبلغ {$text} تومان از نماینده {$rid} کسر شد.", $backuser, 'html');
            // FX-ROUTE: notify the agent through the RESELLER bot's own token
            sendmessage($rid, "⚠️ مبلغ {$text} تومان از موجودی شما کسر شد.", null, 'HTML', fx_reseller_bot_token($rid));
        } else {
            sendmessage($from_id, "❌ موجودی کافی نیست یا خطا رخ داد.", $backuser, 'html');
        }
        fx_res_exit_to_hub($from_id);
        return true;
    }

    if ($user['step'] === 'fx_res_do_debt_ceiling') {
        if (trim($text) === '/cancel') { fx_res_exit_to_hub($from_id); return true; }
        if (!ctype_digit($text)) { sendmessage($from_id, "❌ فقط عدد وارد کنید.", $backuser, 'html'); return true; }
        $amt = intval($text);
        if ($amt <= 0) { sendmessage($from_id, "❌ مبلغ باید بزرگ‌تر از صفر باشد.", $backuser, 'html'); return true; }
        $rid = intval($user['Processing_value']);
        @update("botsaz", "debt_ceiling", $amt, "id_user", $rid);
        @update("botsaz", "debt_alert_80", 0, "id_user", $rid);
        @update("botsaz", "debt_alert_95", 0, "id_user", $rid);
        sendmessage($from_id, "✅ سقف اعتبار نماینده {$rid} روی " . number_format($amt) . " تومان تنظیم شد.", $backuser, 'html');
        // FX-ROUTE: notify the agent through the RESELLER bot's own token
        sendmessage($rid, "🏦 سقف اعتبار (فروش به‌حساب) شما روی " . number_format($amt) . " تومان تنظیم شد.", null, 'HTML', fx_reseller_bot_token($rid));
        fx_res_exit_to_hub($from_id);
        return true;
    }

    if ($user['step'] === 'fx_res_do_whprice_val') {
        if (trim($text) === '/cancel') { fx_res_exit_to_hub($from_id); return true; }
        if (!ctype_digit($text)) { sendmessage($from_id, "❌ فقط عدد وارد کنید.", $backuser, 'html'); return true; }
        $amt = intval($text);
        $rid = intval($user['Processing_value']);
        $pid = intval($user['Processing_value_one'] ?? 0);
        if ($pid <= 0) { sendmessage($from_id, "❌ محصول مشخص نیست؛ دوباره از «🏷️ قیمت عمده‌فروشی» وارد شوید.", $backuser, 'html'); fx_res_exit_to_hub($from_id); return true; }
        $pdo->prepare("UPDATE product SET fx_wholesale_price = :w WHERE id = :pid")->execute([':w' => $amt, ':pid' => $pid]);
        sendmessage($from_id, "✅ قیمت عمده‌فروشی محصول {$pid} روی " . number_format($amt) . " تومان تنظیم شد.\n💵 از این به بعد هر خرید مشتری از این محصول، به‌عنوان هزینه به نماینده {$rid} محاسبه می‌شود (در حالت اعتباری: به بدهی).", $backuser, 'html');
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

    if ($user['step'] === 'fx_res_buyer_extend') {
        if (trim($text) === '/cancel') { fx_res_exit_to_hub($from_id); return true; }
        if (!ctype_digit(trim($text)) || intval(trim($text)) <= 0) { sendmessage($from_id, "❌ فقط تعداد روز (عدد) وارد کنید.", $backuser, 'html'); return true; }
        $days = intval(trim($text));
        $iid = (string)$user['Processing_value'];
        $rid = (string)fx_res_buyer_rid($iid);
        $nameloc = select("invoice", "*", "id_invoice", $iid, "select");
        if (!is_array($nameloc)) { sendmessage($from_id, "❌ فاکتور یافت نشد.", $backuser, 'html'); fx_res_exit_to_hub($from_id); return true; }
        $marzban_list_get = select("marzban_panel", "*", "name_panel", $nameloc['Service_location'], "select");
        if (!is_array($marzban_list_get)) { sendmessage($from_id, "❌ پنل سرویس یافت نشد.", $backuser, 'html'); fx_res_exit_to_hub($from_id); return true; }
        $ext = null;
        $extErr = '';
        try {
            global $ManagePanel;
            $ext = $ManagePanel->extend($marzban_list_get['Methodextend'], $nameloc['Volume'], $days, $nameloc['username'], 'custom_volume', $marzban_list_get['code_panel']);
        } catch (\Throwable $e) {
            $extErr = $e->getMessage();
        }
        if (is_array($ext) && !empty($ext['status'])) {
            try {
                $pdo2 = $GLOBALS['pdo'];
                $st2 = $pdo2->prepare("INSERT IGNORE INTO service_other (id_user, username, value, type, time, price, output) VALUES (:id_user, :username, :value, :type, :time, :price, :output)");
                $st2->execute([
                    ':id_user' => $nameloc['id_user'],
                    ':username' => $nameloc['username'],
                    ':value' => $nameloc['Volume'] . "_" . $days,
                    ':type' => 'extend_user_by_admin',
                    ':time' => date('Y/m/d H:i:s'),
                    ':price' => 0,
                    ':output' => json_encode($ext),
                ]);
            } catch (\Throwable $e) {}
            update("invoice", "Status", "active", "id_invoice", $iid);
            sendmessage($from_id, "✅ سرویس " . $nameloc['username'] . " برای {$days} روز تمدید شد.", $backuser, 'html');
            // FX-ROUTE: notify the buyer through the RESELLER bot's own token
            sendmessage((int)$nameloc['id_user'], "⏳ سرویس " . $nameloc['username'] . " شما برای {$days} روز تمدید شد.", null, 'HTML', fx_reseller_bot_token((int)$rid));
        } else {
            $msg = is_array($ext) ? ($ext['msg'] ?? 'نامشخص') : $extErr;
            sendmessage($from_id, "❌ تمدید ناموفق بود: " . $msg, $backuser, 'html');
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

    if ($user['step'] === 'fx_res_do_addadmin') {
        $rid = intval($user['Processing_value']);
        if (trim($text) === '/cancel') {
            sendmessage($from_id, "لغو شد.", $backuser, 'html');
            step('home', $from_id);
            update("user", "Processing_value", 0, "id", $from_id);
            if ($rid > 0) fx_res_send_bot_admins($from_id, $rid);
            return true;
        }
        $aid = trim($text);
        if (!ctype_digit($aid) || strlen($aid) < 5) {
            sendmessage($from_id, "❌ آیدی عددی معتبر وارد کنید (مثال: 123456789).\nانصراف: /cancel", $backuser, 'html');
            return true;
        }
        if ($rid <= 0) {
            sendmessage($from_id, "❌ نماینده مشخص نیست؛ دوباره از فهرست وارد شوید.", $backuser, 'html');
            fx_res_exit_to_hub($from_id);
            return true;
        }
        $bot = select("botsaz", "admin_ids, id_user", "id_user", $rid, "select", ['cache' => false]);
        if (!is_array($bot)) {
            sendmessage($from_id, "❌ رباتی برای این نماینده ثبت نشده است.", $backuser, 'html');
            fx_res_exit_to_hub($from_id);
            return true;
        }
        $owner = (string)$bot['id_user'];
        $ids = json_decode((string)($bot['admin_ids'] ?? '[]'), true);
        if (!is_array($ids)) $ids = [];
        $ids = array_values(array_unique(array_map('strval', $ids)));
        if (!in_array($owner, $ids, true)) array_unshift($ids, $owner);
        if (in_array($aid, $ids, true)) {
            sendmessage($from_id, "ℹ️ این آیدی از قبل مدیر این ربات است.", $backuser, 'html');
            step('home', $from_id);
            update("user", "Processing_value", 0, "id", $from_id);
            fx_res_send_bot_admins($from_id, $rid);
            return true;
        }
        $ids[] = $aid;
        $ok = false;
        try {
            $stmt = $pdo->prepare("UPDATE botsaz SET admin_ids = :a WHERE id_user = :u");
            $stmt->execute([':a' => json_encode($ids, JSON_UNESCAPED_UNICODE), ':u' => (string)$rid]);
            $ok = true;
        } catch (\Throwable $e) {
            error_log('[FX_RES] add admin failed rid=' . $rid . ' aid=' . $aid . ': ' . $e->getMessage());
        }
        if ($ok) {
            sendmessage($from_id, "✅ کاربر {$aid} به مدیران ربات نماینده {$rid} اضافه شد.", $backuser, 'html');
            try {
                sendmessage((int)$aid, "🎉 شما به عنوان مدیر ربات نمایندگی @{$bot['username']} اضافه شدید.", null, 'HTML', fx_reseller_bot_token($rid));
            } catch (\Throwable $e) { /* admin may not have started the bot yet */ }
        } else {
            sendmessage($from_id, "❌ خطا در ذخیره مدیر. دوباره تلاش کنید.", $backuser, 'html');
        }
        step('home', $from_id);
        update("user", "Processing_value", 0, "id", $from_id);
        fx_res_send_bot_admins($from_id, $rid);
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
    // (fx_res_my_do_retail is consumed by fx_myprices_gate in fx_myprices.php)
    if (in_array($step, ['fx_res_do_credit', 'fx_res_do_debit', 'fx_res_do_pasargad', 'fx_res_do_create', 'fx_res_do_debt_ceiling', 'fx_res_do_settle', 'fx_res_do_whprice_val', 'fx_res_buyer_extend', 'fx_res_do_addadmin'], true)) {
        if (fx_reseller_text_handler($text, (array)$user, $from_id, $backadmin)) {
            return true;
        }
    }

    // --- /cancel inside the section ---
    if ($text === '/cancel' && in_array($step, ['fx_res_view', 'fx_res_prod_list', 'fx_res_panel_list', 'fx_res_delbot_confirm', 'fx_res_buyers', 'fx_res_bdel_confirm', 'fx_res_report', 'fx_res_ledger'], true)) {
        fx_res_exit_to_hub($from_id);
        return true;
    }

    return false;
}
