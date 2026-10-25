<?php
/**
 * FX-MYACCOUNT: Reseller «🏦 حساب من» — owner-facing account screen for the
 * reseller bot (balance / debt / ceiling / recent ledger / settle info /
 * sale-notification toggle). Reseller-owner only; main-bot admins never see it.
 *
 * Entry: admin-hub button «🏦 حساب من» (callback fx_myaccount_menu) or the
 * normalized reply text "🏦 حساب من" — injected by layouts_1.php ONLY when
 * defined('FX_RESELLER_ID') && FX_RESELLER_ID. Also reachable from the weekly
 * statement message buttons (fx_myacc_settle / fx_myacc_ledger).
 *
 * Lives OUTSIDE any module manifest → require_once'd live from admin bootstrap.
 * Data comes from fx_debt helpers (fx_debt_state, fx_res_ledger_data,
 * fx_res_setting_get/patch, fx_reseller_bot_token) and the shared card list.
 */

if (!defined('FX_MYACCOUNT_LOADED')) {
    define('FX_MYACCOUNT_LOADED', true);

    /**
     * Send-or-edit helper (glass UI): edit in place when possible, else send new.
     */
    function fx_myaccount_render(int $from_id, int $message_id, string $msg, ?array $kb): void {
        $kbJson = $kb === null ? null : json_encode($kb, JSON_UNESCAPED_UNICODE);
        $sent = false;
        if ($message_id > 0 && function_exists('Editmessagetext')) {
            $sent = @Editmessagetext($from_id, $message_id, $msg, $kbJson, 'HTML');
        }
        if (!$sent) {
            sendmessage($from_id, $msg, $kbJson, 'HTML');
        }
    }

    /**
     * Gate + dispatch for «حساب من». Returns true if it consumed the update.
     * Callbacks: fx_myaccount_menu/fx_myacc_home, fx_myacc_ledger, fx_myacc_settle,
     * fx_myacc_notify_on/off, fx_myacc_back.
     */
    function fx_myaccount_gate($datain, $text, $from_id, $message_id, $user, $backadmin): bool {
        if (!defined('FX_RESELLER_ID') || !FX_RESELLER_ID) return false;

        $rid = (int)FX_RESELLER_ID;
        $datain = (string)$datain;
        $text = is_string($text) ? trim($text) : '';
        $from_id = (int)$from_id;

        $isOwner = ($from_id === $rid);

        // reply-mode entry: normalized hub text
        if ($datain === '' && $text === '🏦 حساب من') {
            $datain = 'fx_myaccount_menu';
        }
        if ($datain === '') {
            return false;
        }
        $isMyAccCb = ($datain === 'fx_myaccount_menu' || strpos($datain, 'fx_myacc_') === 0);
        if (!$isMyAccCb) {
            return false;
        }

        // Account data is the reseller OWNER's — other bot admins are turned away.
        if (!$isOwner) {
            if ($datain === 'fx_myaccount_menu' || $datain === 'fx_myacc_home') {
                sendmessage($from_id, "🔒 این بخش مخصوص مالک ربات نمایندگی است.", null, 'HTML');
                if (function_exists('fx_res_answer_cb')) fx_res_answer_cb();
                return true;
            }
            $datain = 'fx_myaccount_menu';
        }

        // Entering the account section always clears any pending text-wait step
        // (e.g. a half-finished price entry) to avoid cross-talk.
        if (function_exists('step')) {
            step('home', $from_id);
            update("user", "Processing_value", 0, "id", $from_id);
        }

        if ($datain === 'fx_myaccount_menu' || $datain === 'fx_myacc_home') {
            fx_myaccount_send_home($from_id, (int)$message_id);
            if (function_exists('fx_res_answer_cb')) fx_res_answer_cb();
            return true;
        }

        if ($datain === 'fx_myacc_ledger') {
            fx_myaccount_send_ledger($from_id, (int)$message_id);
            if (function_exists('fx_res_answer_cb')) fx_res_answer_cb();
            return true;
        }

        if ($datain === 'fx_myacc_settle') {
            fx_myaccount_send_settle($from_id, (int)$message_id);
            if (function_exists('fx_res_answer_cb')) fx_res_answer_cb();
            return true;
        }

        if ($datain === 'fx_myacc_notify_on' || $datain === 'fx_myacc_notify_off') {
            fx_res_setting_patch($rid, ['sale_notify' => $datain === 'fx_myacc_notify_on' ? '1' : '0']);
            fx_myaccount_send_home($from_id, (int)$message_id);
            if (function_exists('fx_res_answer_cb')) fx_res_answer_cb();
            return true;
        }

        if ($datain === 'fx_myacc_back') {
            if (function_exists('fx_res_exit_to_hub')) {
                fx_res_exit_to_hub($from_id, (int)$message_id);
            }
            return true;
        }

        return false;
    }

    /**
     * Main account screen.
     */
    function fx_myaccount_send_home(int $from_id, int $message_id = 0): void {
        $rid = (int)FX_RESELLER_ID;
        $st = fx_debt_state($rid);
        $cur = (int)($st['current'] ?? 0);
        $ceiling = (int)($st['ceiling'] ?? 0);
        $bal = function_exists('fx_reseller_db_balance') ? (int)fx_reseller_db_balance($rid) : 0;
        $set = fx_res_setting_get($rid);
        $stopped = ((string)($set['bot_disabled'] ?? '0') === '1');
        $notifyOn = ((string)($set['sale_notify'] ?? '1') !== '0');

        $msg = "🏦 <b>حساب من</b>\n"
            . "━━━━━━━━━━━━━━\n"
            . "💰 موجودی کیف‌پول: " . number_format($bal) . " تومان\n"
            . "💸 بدهی فعلی: " . number_format($cur) . " تومان\n";
        if ($st['mode'] === 'debt' && $ceiling > 0) {
            $msg .= "🏦 سقف اعتبار: " . number_format($ceiling) . " تومان\n"
                . "🌟 اعتبار باقی‌مانده: " . number_format(max(0, $ceiling - $cur)) . " تومان\n";
        } elseif ($st['mode'] === 'debt') {
            $msg .= "🏦 حالت اعتباری — سقف تعیین نشده\n";
        }
        if ($stopped) {
            $msg .= "\n⏸ ربات شما توسط مدیریت موقتاً متوقف شده است.\n";
        }

        $kb = ['inline_keyboard' => [
            [
                ['text' => '📒 گردش حساب', 'callback_data' => 'fx_myacc_ledger'],
                ['text' => '💳 تسویه', 'callback_data' => 'fx_myacc_settle'],
            ],
            [
                ['text' => ($notifyOn ? '🔔 اعلان فروش: روشن ✅' : '🔔 اعلان فروش: خاموش ⛔️'),
                 'callback_data' => ($notifyOn ? 'fx_myacc_notify_off' : 'fx_myacc_notify_on')],
            ],
            [['text' => '🏠 بازگشت به منوی مدیریت', 'callback_data' => 'fx_myacc_back']],
        ]];
        fx_myaccount_render($from_id, $message_id, $msg, $kb);
    }

    /**
     * Recent ledger (shared with the management-side ledger data source).
     */
    function fx_myaccount_send_ledger(int $from_id, int $message_id = 0): void {
        $rid = (int)FX_RESELLER_ID;
        $items = function_exists('fx_res_ledger_data') ? fx_res_ledger_data($rid, 15) : [];
        $st = fx_debt_state($rid);
        $txt = "📒 <b>گردش حساب من</b>\n"
            . "💸 بدهی فعلی: " . number_format((int)($st['current'] ?? 0)) . " تومان\n";
        if (empty($items)) {
            $txt .= "\n📭 هنوز گردشی ثبت نشده است.";
        } else {
            $txt .= "\n";
            foreach ($items as $it) {
                $ts = $it['ts'] > 0
                    ? ((function_exists('jdate') ? jdate('Y/m/d', $it['ts']) : date('Y/m/d', $it['ts'])) . ' ' . date('H:i', $it['ts']))
                    : $it['time'];
                $line = "▫️ {$ts} | {$it['label']} | <b>{$it['amount']}</b>";
                if ($it['kind'] === 'debt' && $it['debt_after'] !== null) {
                    $line .= ((($it['scope'] ?? 'bot') === 'main') ? " (بدهی حساب اصلی: " : " (بدهی: ") . number_format($it['debt_after']) . ")";
                }
                $txt .= $line . "\n";
            }
        }
        $kb = ['inline_keyboard' => [
            [['text' => '⬅️ بازگشت', 'callback_data' => 'fx_myaccount_menu']],
        ]];
        fx_myaccount_render($from_id, $message_id, $txt, $kb);
    }

    /**
     * Settle screen: amount due + shared card numbers + deep link to the main bot.
     */
    function fx_myaccount_send_settle(int $from_id, int $message_id = 0): void {
        global $pdo;
        $rid = (int)FX_RESELLER_ID;
        $st = fx_debt_state($rid);
        $cur = (int)($st['current'] ?? 0);
        $ceiling = (int)($st['ceiling'] ?? 0);
        $set = fx_res_setting_get($rid);
        $mainBot = preg_replace('/[^A-Za-z0-9_]/', '', (string)($set['main_bot'] ?? ''));

        $txt = "💳 <b>تسویه حساب</b>\n"
            . "━━━━━━━━━━━━━━\n"
            . "💸 بدهی فعلی: " . number_format($cur) . " تومان\n";
        if ($ceiling > 0) {
            $txt .= "🌟 اعتبار باقی‌مانده: " . number_format(max(0, $ceiling - $cur)) . " تومان\n";
        }

        $cards = [];
        if ($pdo instanceof PDO) {
            try {
                $stmt = $pdo->query("SELECT cardnumber, namecard FROM card_number WHERE is_active = 1 AND " . fx_tenant_sql_agent() . " LIMIT 5");
                $cards = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } catch (\Throwable $e) { $cards = []; }
        }
        if ($cards) {
            $txt .= "\nمبلغ بدهی را به یکی از شماره کارت‌های زیر واریز کنید:\n";
            foreach ($cards as $c) {
                $txt .= "\n💳 <code>" . (string)$c['cardnumber'] . "</code>";
                if (!empty($c['namecard'])) {
                    $txt .= "\n👤 به نام: " . (string)$c['namecard'];
                }
            }
        } else {
            $note = trim((string)($set['cart_info'] ?? ''));
            $txt .= "\n" . ($note !== '' ? $note : "برای دریافت شماره کارت با پشتیبانی در تماس باشید.");
        }
        $txt .= "\n\nℹ️ پس از واریز، تصویر رسید را در <b>ربات اصلی</b> برای پشتیبانی ارسال کنید؛ پس از تأیید، بدهی شما تسویه می‌شود.";

        $rows = [];
        if ($mainBot !== '') {
            $rows[] = [['text' => '🏦 رفتن به ربات اصلی', 'url' => "https://t.me/{$mainBot}"]];
        }
        $rows[] = [['text' => '⬅️ بازگشت', 'callback_data' => 'fx_myaccount_menu']];
        fx_myaccount_render($from_id, $message_id, $txt, ['inline_keyboard' => $rows]);
    }

} // FX_MYACCOUNT_LOADED
