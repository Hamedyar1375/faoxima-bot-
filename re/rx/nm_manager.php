<?php
/**
 * nm_manager.php — STEP_3/4: مدیریت نماینده (Handler + دکمه‌ها + منطق خرید با بدهی)
 * =================================================================================
 * بخش ۱ (STEP_4): پنل مدیریت نمایندگی برای ادمین اصلی (main bot):
 *   - دکمه «🗂 مدیریت نمایندگی» در هاب پنل مدیریت (شیشه‌ای + offinline)
 *   - منوی نمایندگی: وضعیت | محصولات | پنل‌ها | تخفیف‌ها | صورت‌حساب‌ها | سقف بدهکاری | تغییر موجودی
 *   - «تعیین سقف بدهکاری»: ورود ادمین → اعمال در user.maxbuyagent (نماینده اصلی)
 *   - «تغییر موجودی حساب»: افزایش/کاهش → اعمال اتمیک روی user.Balance
 *   - همه‌چیز در دو حالت رندر: offinline (ریپلای‌کیبورد + متن) و شیشه‌ای (inline + callback)
 *
 * بخش ۲ (STEP_3): منطق خرید نماینده با موجودی منفی:
 *   - nm_agent_purchase_limit() : سقف فعال = user.maxbuyagent (اگر > 0) وگرنه پیش‌فرض env/12M
 *   - nm_agent_purchase_guard() : بررسی پیش از ساخت سرویس (|موجودی-قیمت| <= سقف)
 *   - nm_agent_debit()          : کسر اتمیک با سقف (rowCount=0 => از سقف گذشت => بدون ردپا)
 *   - nm_agent_compensate()     : بازگشت پول اگر ساخت پنل بعد از کسر شکست
 *   - متصل به مسیر خرید تکی و انبوه در user_flow.php
 *
 * الزامات:
 *  - کاملاً additive: بدون تغییر ساختار دیتابیس؛ فقط ستون‌های موجود (user.maxbuyagent, user.Balance)
 *  - require ایمن: فقط تعریف تابع (function_exists guards)
 */

if (!defined('REFACTORED_LEGACY_ROOT')) {
    define('REFACTORED_LEGACY_ROOT', __DIR__);
}
if (!function_exists('nm_default_debt_limit')) {
    require_once __DIR__ . '/nm_financials.php';
}
if (!function_exists('nm_fmt_money')) {
    require_once __DIR__ . '/nm_formatter.php';
}

/* ============================================================
 * 1) شناسایی نماینده (manager)
 * ============================================================ */

if (!function_exists('nm_admin')) {
    /**
     * نماینده اصلی = نخستین کاربر با agent='n2' (قدیمی‌ترین).
     *
     * @return array|null سطر user
     */
    function nm_admin()
    {
        global $pdo;
        try {
            $st = $pdo->query("SELECT * FROM user WHERE agent = 'n2' AND id != '0' ORDER BY id ASC LIMIT 1");
            $row = $st ? $st->fetch(PDO::FETCH_ASSOC) : false;
            return $row ?: null;
        } catch (Throwable $e) {
            error_log('[NM] nm_admin FATAL: ' . $e->getMessage());
            return null;
        }
    }
}

/* ============================================================
 * 2) منطق خرید نماینده (STEP_3) — سقف بدهکاری + کسر اتمیک
 * ============================================================ */

if (!function_exists('nm_agent_purchase_limit')) {
    /**
     * سقف بدهکاری فعال برای نماینده (تومان).
     * user.maxbuyagent اگر > 0 باشد همان، وگرنه پیش‌فرض (env NM_DEBT_LIMIT یا 12,000,000).
     *
     * @param array $user سطر user
     * @return int
     */
    function nm_agent_purchase_limit($user)
    {
        $m = (int)($user['maxbuyagent'] ?? 0);
        return $m > 0 ? $m : nm_default_debt_limit();
    }
}

if (!function_exists('nm_agent_purchase_guard')) {
    /**
     * بررسی مجوز خرید با بدهی برای نماینده — پیش از ساخت سرویس.
     * فقط برای agent='n2' و قیمت > 0.
     *
     * @return array{ok: bool, msg: string, limit: int, balance_after: int, debt_after: int}
     */
    function nm_agent_purchase_guard($user, $price)
    {
        $price = (int)$price;
        if (($user['agent'] ?? '') !== 'n2' || $price <= 0) {
            return ['ok' => true, 'msg' => '', 'limit' => 0, 'balance_after' => (int)($user['Balance'] ?? 0), 'debt_after' => 0];
        }
        $limit = nm_agent_purchase_limit($user);
        $r = nm_can_purchase((int)$user['Balance'], $price, $limit);
        if ($r['ok']) {
            return ['ok' => true, 'msg' => '', 'limit' => $limit, 'balance_after' => $r['balance_after'], 'debt_after' => $r['debt_after']];
        }
        return [
            'ok'            => false,
            'msg'           => nm_debt_limit_message($limit, (int)$user['Balance'], $price, $r['balance_after'], $r['debt_after']),
            'limit'         => $limit,
            'balance_after' => $r['balance_after'],
            'debt_after'    => $r['debt_after'],
        ];
    }
}

if (!function_exists('nm_debt_limit_message')) {
    /**
     * پیام مسدودی خرید به دلیل عبور از سقف بدهکاری.
     */
    function nm_debt_limit_message($limit, $balance_now, $price, $balance_after, $debt_after)
    {
        return "❌ خرید شما مسدود شد — سقف بدهکاری مجاز برآورده نیست.\n\n"
             . "💳 سقف بدهکاری مجاز: " . nm_fmt_money($limit) . " تومان\n"
             . "💰 موجودی فعلی: " . nm_fmt_money($balance_now) . " تومان\n"
             . "🧮 بدهی بعد از این خرید: " . nm_fmt_money($debt_after) . " تومان\n"
             . "🛒 مبلغ خرید: " . nm_fmt_money($price) . " تومان\n\n"
             . "لطفاً ابتدا موجودی حساب خود را شارژ کنید یا با مدیریت تماس بگیرید.";
    }
}

if (!function_exists('nm_agent_debit')) {
    /**
     * کسر اتمیک از موجودی نماینده با رعایت سقف بدهکاری (STEP_1: nm_debit_balance).
     * اگر از سقف بگذرد هیچ ردی تغییر نمی‌کند (rowCount=0).
     *
     * @param int $user_id
     * @param int $amount  مبلغ (تومان, > 0)
     * @param int $limit   سقف بدهکاری
     * @param string $ref  کد پیگیری
     * @return array{ok: bool, reason: string, balance_after: int, debt_after: int}
     */
    function nm_agent_debit($user_id, $amount, $limit, $ref = '')
    {
        global $pdo;
        return nm_debit_balance($pdo, $user_id, $amount, $limit, $ref);
    }
}

if (!function_exists('nm_agent_compensate')) {
    /**
     * بازگشت پول (جبران) — وقتی سرویس روی پنل ساخته شد اما کسر از حساب ممکن نشد.
     *
     * @return bool
     */
    function nm_agent_compensate($user_id, $amount, $ref = '', $method = 'refund')
    {
        global $pdo;
        $r = nm_credit_balance($pdo, $user_id, $amount, $ref, $method);
        return !empty($r['ok']);
    }
}

/* ============================================================
 * 3) پنل مدیریت نمایندگی — رندر لیست‌ها (STEP_4)
 * ============================================================ */

if (!function_exists('nm_inline_kb')) {
    /**
     * ساخت JSON کیبورد inline (حالت شیشه‌ای).
     *
     * @param array $rows آرایه ردیف‌ها: [[['text'=>..,'callback_data'=>..], ...], ...]
     * @return string|null
     */
    function nm_inline_kb(array $rows)
    {
        if (empty($rows)) {
            return null;
        }
        return json_encode(['inline_keyboard' => $rows, 'resize_keyboard' => true], JSON_UNESCAPED_UNICODE);
    }
}

if (!function_exists('nm_backadmin_row')) {
    /**
     * ردیف دکمه بازگشت (شیشه‌ای).
     */
    function nm_backadmin_row()
    {
        return [[['text' => '🏠 بازگشت به منوی مدیریت', 'callback_data' => 'nm_admin_back']]];
    }
}

if (!function_exists('nm_menu_reply_kb')) {
    /**
     * کیبورد ریپلای (حالت offinline) — منوی مدیریت نمایندگی.
     * در ریپلای‌کیبورد فقط text فرستاده می‌شود (callback_data نادیده گرفته می‌شود).
     */
    function nm_menu_reply_kb()
    {
        return [
            [['text' => '📊 وضعیت نماینده']],
            [['text' => '🏷️ محصولات']],
            [['text' => '🖥️ پنل‌ها']],
            [['text' => '🎁 تخفیف‌ها']],
            [['text' => '🧾 صورت‌حساب‌ها']],
            [['text' => '💳 سقف بدهکاری']],
            [['text' => '💰 تغییر موجودی']],
            [['text' => '🏠 بازگشت به منوی مدیریت']],
        ];
    }
}

if (!function_exists('nm_balance_op_reply_kb')) {
    /**
     * کیبورد ریپلای — انتخاب عملیات موجودی.
     */
    function nm_balance_op_reply_kb()
    {
        return [
            [['text' => '➕ افزایش موجودی']],
            [['text' => '➖ کاهش موجودی']],
            [['text' => '🏠 بازگشت به منوی مدیریت']],
        ];
    }
}

if (!function_exists('nm_menu_kb')) {
    /**
     * کیبورد منوی مدیریت نمایندگی (شیشه‌ای).
     */
    function nm_menu_kb()
    {
        return [
            [['text' => '📊 وضعیت نماینده', 'callback_data' => 'nm_status']],
            [['text' => '🏷️ محصولات', 'callback_data' => 'nm_products']],
            [['text' => '🖥️ پنل‌ها', 'callback_data' => 'nm_panels']],
            [['text' => '🎁 تخفیف‌ها', 'callback_data' => 'nm_discounts']],
            [['text' => '🧾 صورت‌حساب‌ها', 'callback_data' => 'nm_invoices']],
            [
                ['text' => '💳 سقف بدهکاری', 'callback_data' => 'nm_set_debt_limit'],
                ['text' => '💰 تغییر موجودی', 'callback_data' => 'nm_balance_op'],
            ],
            nm_backadmin_row()[0],
        ];
    }
}

if (!function_exists('nm_render_products')) {
    /**
     * رندر لیست محصولات نماینده. (rows اختیاری = تست‌پذیری)
     *
     * @param array|null $rows سطرهای product
     * @return string
     */
    function nm_render_products(array $rows = null)
    {
        if ($rows === null) {
            global $pdo;
            $adm = nm_admin();
            $rows = [];
            if ($adm) {
                $st = $pdo->prepare("SELECT * FROM product WHERE FIND_IN_SET(:agent, REPLACE(agent, ' ', '')) > 0 OR agent IN ('all','allusers') LIMIT 50");
                $st->execute([':agent' => (string)(int)$adm['id']]);
                $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
            }
        }
        if (empty($rows)) {
            return "🏷️ <b>محصولات نماینده</b>\n\n⭕️ هیچ محصولی به نماینده تخصیص داده نشده است.\n(از بخش «🏬 تنظیمات فروشگاه» محصول بسازید و آیدی نماینده را در فیلد agent قرار دهید)";
        }
        $lines = ["🏷️ <b>محصولات نماینده</b> (" . nm_fmt_count(count($rows)) . " محصول):", ""];
        foreach ($rows as $i => $p) {
            $time   = ((int)($p['Service_time'] ?? 0) === 0) ? 'نامحدود' : nm_fmt_count($p['Service_time']) . ' روز';
            $vol    = ((int)($p['Volume_constraint'] ?? 0) === 0) ? 'نامحدود' : nm_fmt_count($p['Volume_constraint']) . ' گیگابایت';
            $price  = nm_fmt_money($p['price_product'] ?? 0);
            $lines[] = (nm_fmt_count($i + 1)) . ". " . ($p['name_product'] ?? '—')
                . "\n   ⏱ زمان: " . $time
                . "\n   📥 حجم: " . $vol
                . "\n   💵 قیمت: " . $price . " تومان";
        }
        return implode("\n", $lines);
    }
}

if (!function_exists('nm_render_panels')) {
    /**
     * رندر لیست پنل‌های نماینده.
     */
    function nm_render_panels(array $rows = null)
    {
        if ($rows === null) {
            global $pdo;
            $adm = nm_admin();
            $rows = [];
            if ($adm) {
                $st = $pdo->prepare("SELECT * FROM marzban_panel WHERE FIND_IN_SET(:agent, REPLACE(agent, ' ', '')) > 0 OR agent IN ('all','allusers') ORDER BY id ASC LIMIT 50");
                $st->execute([':agent' => (string)(int)$adm['id']]);
                $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
            }
        }
        if (empty($rows)) {
            return "🖥️ <b>پنل‌های نماینده</b>\n\n⭕️ هیچ پنلی به نماینده تخصیص داده نشده است.";
        }
        $lines = ["🖥️ <b>پنل‌های نماینده</b> (" . nm_fmt_count(count($rows)) . " پنل):", ""];
        foreach ($rows as $i => $p) {
            $url = (string)($p['url_panel'] ?? '');
            if (strlen($url) > 45) {
                $url = substr($url, 0, 45) . '…';
            }
            $lines[] = (nm_fmt_count($i + 1)) . ". " . ($p['name_panel'] ?? '—')
                . (($url !== '') ? "\n   🔗 " . $url : '');
        }
        return implode("\n", $lines);
    }
}

if (!function_exists('nm_render_discounts')) {
    /**
     * رندر لیست کدهای تخفیف نماینده.
     */
    function nm_render_discounts(array $rows = null)
    {
        if ($rows === null) {
            global $pdo;
            $adm = nm_admin();
            $rows = [];
            if ($adm) {
                $st = $pdo->prepare("SELECT * FROM DiscountSell WHERE FIND_IN_SET(:agent, REPLACE(agent, ' ', '')) > 0 OR agent IN ('all','allusers') ORDER BY id ASC LIMIT 50");
                $st->execute([':agent' => (string)(int)$adm['id']]);
                $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
            }
        }
        if (empty($rows)) {
            return "🎁 <b>تخفیف‌های نماینده</b>\n\n⭕️ هیچ کد تخفیفی برای نماینده ثبت نشده است.";
        }
        $lines = ["🎁 <b>تخفیف‌های نماینده</b> (" . nm_fmt_count(count($rows)) . " کد):", ""];
        foreach ($rows as $i => $d) {
            $lines[] = (nm_fmt_count($i + 1)) . ". کد: <code>" . ($d['codeDiscount'] ?? '—') . "</code>"
                . "\n   💵 مبلغ: " . nm_fmt_money($d['price'] ?? 0) . " تومان"
                . (($d['code_product'] ?? '') !== '' ? "\n   🏷️ محصول: " . $d['code_product'] : '')
                . (($d['status'] ?? '') !== '' ? "\n   📌 وضعیت: " . $d['status'] : '');
        }
        return implode("\n", $lines);
    }
}

if (!function_exists('nm_invoice_status_fa')) {
    function nm_invoice_status_fa($s)
    {
        $map = [
            'active' => '✅ فعال', 'end_of_time' => '⏱ منقضی (زمان)', 'end_of_volume' => '📉 منقضی (حجم)',
            'sendedwarn' => '⚠️ هشدار ارسال‌شده', 'send_on_hold' => '⏸ مکث', 'removebyadmin' => '🗑 حذف‌شده',
            'remove' => '🗑 حذف‌شده', 'expired' => '⌛ منقضی',
        ];
        $s = (string)$s;
        return $map[$s] ?? $s;
    }
}

if (!function_exists('nm_render_invoices')) {
    /**
     * رندر صورت‌حساب‌های (سفارش‌های) نماینده.
     */
    function nm_render_invoices(array $rows = null)
    {
        if ($rows === null) {
            global $pdo;
            $adm = nm_admin();
            $rows = [];
            if ($adm) {
                $st = $pdo->prepare("SELECT i.* FROM invoice i INNER JOIN user u ON u.id = i.id_user WHERE u.id = :uid ORDER BY i.id_invoice DESC LIMIT 15");
                $st->execute([':uid' => (string)(int)$adm['id']]);
                $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
            }
        }
        if (empty($rows)) {
            return "🧾 <b>صورت‌حساب‌های نماینده</b>\n\n⭕️ هنوز سفارشی ثبت نشده است.";
        }
        $lines = ["🧾 <b>صورت‌حساب‌های نماینده</b> (" . nm_fmt_count(count($rows)) . " مورد، جدیدترین):", ""];
        foreach ($rows as $i => $v) {
            $lines[] = (nm_fmt_count($i + 1)) . ". " . ($v['name_product'] ?? '—')
                . "\n   💵 قیمت: " . nm_fmt_money($v['price_product'] ?? 0) . " تومان"
                . "\n   📌 وضعیت: " . nm_invoice_status_fa($v['Status'] ?? '')
                . "\n   🆔 کد: <code>" . ($v['id_invoice'] ?? '—') . "</code>";
        }
        return implode("\n", $lines);
    }
}

/* ============================================================
 * 4) اکشن‌های مالی ادمین (STEP_4)
 * ============================================================ */

if (!function_exists('nm_manager_current_step')) {
    function nm_manager_current_step($user)
    {
        return (string)($user['step'] ?? '');
    }
}

if (!function_exists('nm_manager_reset')) {
    /**
     * بازنشانی step + Processing مقادیر (پس از هر اکشن موفق).
     */
    function nm_manager_reset($from_id)
    {
        global $pdo;
        try {
            $st = $pdo->prepare("UPDATE user SET step='home', Processing_value='0', Processing_value_one='0', Processing_value_tow='0', Processing_value_four='0' WHERE id = :uid");
            $st->execute([':uid' => (string)$from_id]);
        } catch (Throwable $e) {
            error_log('[NM] nm_manager_reset FATAL: ' . $e->getMessage());
        }
    }
}

if (!function_exists('nm_manager_reply')) {
    /**
     * پاسخ اکشن: در حالت شیشه‌ای Editmessagetext، وگرنه sendmessage تازه.
     *
     * @param string $mode 'glass' => inline/edit، 'text' => sendmessage + reply_kb
     */
    function nm_manager_reply($from_id, $message_id, $text, $glassKb = null, $mode = 'glass', $backadmin = null)
    {
        if ($mode === 'glass' && $message_id) {
            Editmessagetext($from_id, $message_id, $text, $glassKb, 'HTML');
            return;
        }
        $kb = ($mode === 'text') ? (is_array($backadmin) ? $backadmin : null) : null;
        sendmessage($from_id, $text, $kb, 'HTML');
    }
}

if (!function_exists('nm_admin_set_debt_limit')) {
    /**
     * اعمال سقف بدهکاری اعلام‌شده به‌عبارت عددی روی نماینده (user.maxbuyagent).
     *
     * @return string پیام نتیجه (برای ارسال به ادمین)
     */
    function nm_admin_set_debt_limit($amount)
    {
        $amount = (int)$amount;
        $adm = nm_admin();
        if (!$adm) {
            return "❌ نماینده‌ای در سیستم ثبت نشده است.";
        }
        if ($amount <= 0) {
            return "❌ مقدار نامعتبر است. فقط عدد صحیح مثبت (تومان) بفرستید.";
        }
        update("user", "maxbuyagent", $amount, "id", $adm['id']);
        $bal = (int)$adm['Balance'];
        return "✅ سقف بدهکاری با موفقیت تنظیم شد.\n\n"
             . "👤 نماینده: <code>" . (int)$adm['id'] . "</code>"
             . "\n💳 سقف بدهکاری جدید: " . nm_fmt_money($amount) . " تومان"
             . "\n💰 موجودی فعلی: " . nm_fmt_money($bal) . " تومان"
             . (($bal < 0) ? "\n🧮 بدهی فعلی: " . nm_fmt_money(nm_debt_of($bal)) . " تومان" : '');
    }
}

if (!function_exists('nm_admin_apply_balance')) {
    /**
     * اعمال افزایش/کاهش موجودی حساب نماینده — اتمیک.
     *
     * @param string $op 'increase' | 'decrease'
     * @param int $amount مبلغ (تومان, > 0)
     * @return string پیام نتیجه
     */
    function nm_admin_apply_balance($op, $amount)
    {
        global $pdo;
        $amount = (int)$amount;
        $adm = nm_admin();
        if (!$adm) {
            return "❌ نماینده‌ای در سیستم ثبت نشده است.";
        }
        if ($amount <= 0) {
            return "❌ مبلغ نامعتبر است. فقط عدد صحیح مثبت (تومان) بفرستید.";
        }
        $uid = (int)$adm['id'];
        $before = (int)$adm['Balance'];

        if ($op === 'increase') {
            $r = nm_credit_balance($pdo, $uid, $amount, 'admin_charge', 'credit');
            if (empty($r['ok'])) {
                return "❌ خطا در واریز. جزئیات در لاگ سرور ثبت شد.";
            }
            return "✅ موجودی حساب نماینده افزایش یافت (اتمیک).\n\n"
                 . "👤 نماینده: <code>{$uid}</code>"
                 . "\n💰 موجودی قبل: " . nm_fmt_money($before) . " تومان"
                 . "\n➕ مبلغ واریزی: " . nm_fmt_money($amount) . " تومان"
                 . "\n💰 موجودی بعد: " . nm_fmt_money($r['balance_after']) . " تومان"
                 . (($r['balance_after'] < 0) ? "\n🧮 بدهی باقی‌مانده: " . nm_fmt_money($r['debt_after']) . " تومان" : '');
        }

        // decrease — با سقف بدهکاری
        $limit = nm_agent_purchase_limit($adm);
        $r = nm_debit_balance($pdo, $uid, $amount, $limit, 'admin_debit');
        if (empty($r['ok'])) {
            if ($r['reason'] === 'user_not_found') {
                return "❌ نماینده یافت نشد.";
            }
            return "❌ کاهش موجودی امکان‌پذیر نیست — از سقف بدهکاری مجاز عبور می‌کند.\n\n"
                 . "💳 سقف بدهکاری: " . nm_fmt_money($limit) . " تومان"
                 . "\n💰 موجودی فعلی: " . nm_fmt_money($r['balance_after']) . " تومان";
        }
        return "✅ موجودی حساب نماینده کاهش یافت (اتمیک).\n\n"
             . "👤 نماینده: <code>{$uid}</code>"
             . "\n💰 موجودی قبل: " . nm_fmt_money($before) . " تومان"
             . "\n➖ مبلغ کسرشده: " . nm_fmt_money($amount) . " تومان"
             . "\n💰 موجودی بعد: " . nm_fmt_money($r['balance_after']) . " تومان"
             . (($r['balance_after'] < 0) ? "\n🧮 بدهی فعلی: " . nm_fmt_money($r['debt_after']) . " تومان" : '');
    }
}

/* ============================================================
 * 5) دیسپچ — حالت شیشه‌ای (callback)
 * ============================================================ */

if (!function_exists('nm_manager_dispatch')) {
    /**
     * دیسپچ callbackهای منوی مدیریت نمایندگی (شیشه‌ای).
     *
     * @return bool true = پردازش شد
     */
    function nm_manager_dispatch($datain, $from_id, $message_id, $user, $backadmin = null)
    {
        $datain = (string)$datain;
        if ($datain === '' || strpos($datain, 'nm_') !== 0) {
            return false;
        }
        global $pdo;
        $kbMenu = nm_inline_kb(nm_menu_kb());
        $kbBack = nm_inline_kb(nm_backadmin_row());
        $mode = 'glass';
        $step = nm_manager_current_step($user);

        // اگر در یک step متنی هستم و کلیک دیگری زد → بازنشانی (برای جلوگیری از گیر کردن)
        if (in_array($step, ['nm_set_debt_limit', 'nm_balance_op', 'nm_balance_amt'], true) && $datain !== 'cancel') {
            update("user", "step", "home", "id", $from_id);
        }

        switch (true) {
            case $datain === 'nm_manager_menu':
                nm_manager_reset($from_id);
                nm_manager_reply($from_id, $message_id, "🗂 <b>مدیریت نمایندگی</b>\n\nیک گزینه را انتخاب کنید:", $kbMenu, $mode, $backadmin);
                return true;

            case $datain === 'nm_status':
                nm_manager_reset($from_id);
                $adm = nm_admin();
                if (!$adm) {
                    nm_manager_reply($from_id, $message_id, "❌ نماینده‌ای در سیستم ثبت نشده است.", $kbBack, $mode, $backadmin);
                    return true;
                }
                $data = nm_build_manager_status($pdo, (int)$adm['id']);
                // سقف فعال = maxbuyagent (اگر > 0)
                $limit = nm_agent_purchase_limit($adm);
                $data['credit_limit'] = $limit;
                nm_manager_reply($from_id, $message_id, nm_format_manager_status($data, ['mask_token' => true]), $kbBack, $mode, $backadmin);
                return true;

            case $datain === 'nm_products':
                nm_manager_reset($from_id);
                nm_manager_reply($from_id, $message_id, nm_render_products(), $kbBack, $mode, $backadmin);
                return true;

            case $datain === 'nm_panels':
                nm_manager_reset($from_id);
                nm_manager_reply($from_id, $message_id, nm_render_panels(), $kbBack, $mode, $backadmin);
                return true;

            case $datain === 'nm_discounts':
                nm_manager_reset($from_id);
                nm_manager_reply($from_id, $message_id, nm_render_discounts(), $kbBack, $mode, $backadmin);
                return true;

            case $datain === 'nm_invoices':
                nm_manager_reset($from_id);
                nm_manager_reply($from_id, $message_id, nm_render_invoices(), $kbBack, $mode, $backadmin);
                return true;

            case $datain === 'nm_set_debt_limit':
                update("user", "step", "nm_set_debt_limit", "id", $from_id);
                $adm = nm_admin();
                $cur = $adm ? nm_agent_purchase_limit($adm) : nm_default_debt_limit();
                nm_manager_reply($from_id, $message_id,
                    "💳 <b>تعیین سقف بدهکاری</b>\n\n"
                    . "سقف فعلی: " . nm_fmt_money($cur) . " تومان\n\n"
                    . "📌 سقف جدید را به‌عنوان عدد (تومان) ارسال کنید.\nمثال: 15000000",
                    $kbBack, $mode, $backadmin);
                return true;

            case $datain === 'nm_balance_op':
                $rowsOp = [
                    [['text' => '➕ افزایش موجودی', 'callback_data' => 'nm_balance_inc']],
                    [['text' => '➖ کاهش موجودی', 'callback_data' => 'nm_balance_dec']],
                    nm_backadmin_row()[0],
                ];
                nm_manager_reply($from_id, $message_id, "💰 <b>تغییر موجودی حساب نماینده</b>\n\nنوع عملیات را انتخاب کنید:", nm_inline_kb($rowsOp), $mode, $backadmin);
                return true;

            case $datain === 'nm_balance_inc':
            case $datain === 'nm_balance_dec':
                $op = ($datain === 'nm_balance_inc') ? 'increase' : 'decrease';
                update("user", "step", "nm_balance_amt", "id", $from_id);
                $st = $pdo ? $pdo->prepare("UPDATE user SET Processing_value_one = :opv WHERE id = :uid") : null;
                if ($st) {
                    $st->execute([':opv' => $op, ':uid' => (string)$from_id]);
                }
                $lbl = ($op === 'increase') ? '➕ افزایش' : '➖ کاهش';
                nm_manager_reply($from_id, $message_id,
                    "💰 <b>تغییر موجودی — " . $lbl . "</b>\n\n"
                    . "📌 مبلغ را به‌عنوان عدد (تومان) ارسال کنید.\nمثال: 5000000",
                    $kbBack, $mode, $backadmin);
                return true;

            case $datain === 'nm_admin_back':
                nm_manager_reset($from_id);
                nm_manager_reply($from_id, $message_id, "🏠 به منوی مدیریت بازگشتید.", $backadmin, 'text', $backadmin);
                return true;
        }
        return false;
    }
}

/* ============================================================
 * 6) دیسپچ — حالت offinline (ریپلای‌کیبورد + متن)
 * ============================================================ */

if (!function_exists('nm_manager_text_dispatch')) {
    /**
     * دیسپچ ورودی متنی در حالت offinline:
     *  - برچسب دکمه‌ها (متن دقیق) → اکشن
     *  - اعداد در stepهای nm_* → اعمال
     *
     * @return bool true = پردازش شد
     */
    function nm_manager_text_dispatch($text, $user, $from_id, $message_id, $backadmin = null)
    {
        global $pdo;
        $text = trim((string)$text);
        if ($text === '') {
            return false;
        }
        $step = nm_manager_current_step($user);
        $mode = 'text';
        $kbBack = nm_menu_reply_kb();

        // stepهای فعال = ورودی عددی
        if (in_array($step, ['nm_set_debt_limit', 'nm_balance_amt'], true)) {
            if (!ctype_digit($text)) {
                nm_manager_reply($from_id, $message_id, "❌ مقدار نامعتبر است. فقط اعداد (بدون جداکننده) بفرستید.\nمثال: 15000000", $kbBack, $mode, $backadmin);
                return true;
            }
            $amount = (int)$text;
            if ($step === 'nm_set_debt_limit') {
                $out = nm_admin_set_debt_limit($amount);
            } else {
                $opv = 'increase';
                if ($pdo) {
                    $q = $pdo->prepare("SELECT Processing_value_one FROM user WHERE id = :uid");
                    $q->execute([':uid' => (string)$from_id]);
                    $v = $q->fetchColumn();
                    if ($v === 'decrease' || $v === 'increase') {
                        $opv = $v;
                    }
                }
                $out = nm_admin_apply_balance($opv, $amount);
            }
            nm_manager_reset($from_id);
            nm_manager_reply($from_id, $message_id, $out . "\n\n🗂 <b>مدیریت نمایندگی</b> — یک گزینه را انتخاب کنید:", $kbBack, $mode, $backadmin);
            return true;
        }

        // برچسب دکمه‌ها (offinline: متن دقیق دکمه می‌رسد)
        switch ($text) {
            case '🗂 مدیریت نمایندگی':
                nm_manager_reset($from_id);
                nm_manager_reply($from_id, $message_id, "🗂 <b>مدیریت نمایندگی</b>\n\nیک گزینه را انتخاب کنید:", $kbBack, $mode, $backadmin);
                return true;
            case '📊 وضعیت نماینده':
                nm_manager_reset($from_id);
                $adm = nm_admin();
                if (!$adm) {
                    nm_manager_reply($from_id, $message_id, "❌ نماینده‌ای در سیستم ثبت نشده است.", $kbBack, $mode, $backadmin);
                    return true;
                }
                $data = nm_build_manager_status($pdo, (int)$adm['id']);
                $data['credit_limit'] = nm_agent_purchase_limit($adm);
                nm_manager_reply($from_id, $message_id, nm_format_manager_status($data, ['mask_token' => true]), $kbBack, $mode, $backadmin);
                return true;
            case '🏷️ محصولات':
                nm_manager_reset($from_id);
                nm_manager_reply($from_id, $message_id, nm_render_products(), $kbBack, $mode, $backadmin);
                return true;
            case '🖥️ پنل‌ها':
                nm_manager_reset($from_id);
                nm_manager_reply($from_id, $message_id, nm_render_panels(), $kbBack, $mode, $backadmin);
                return true;
            case '🎁 تخفیف‌ها':
                nm_manager_reset($from_id);
                nm_manager_reply($from_id, $message_id, nm_render_discounts(), $kbBack, $mode, $backadmin);
                return true;
            case '🧾 صورت‌حساب‌ها':
                nm_manager_reset($from_id);
                nm_manager_reply($from_id, $message_id, nm_render_invoices(), $kbBack, $mode, $backadmin);
                return true;
            case '💳 سقف بدهکاری':
                update("user", "step", "nm_set_debt_limit", "id", $from_id);
                $adm2 = nm_admin();
                $cur = $adm2 ? nm_agent_purchase_limit($adm2) : nm_default_debt_limit();
                nm_manager_reply($from_id, $message_id,
                    "💳 <b>تعیین سقف بدهکاری</b>\n\n"
                    . "سقف فعلی: " . nm_fmt_money($cur) . " تومان\n\n"
                    . "📌 سقف جدید را به‌عنوان عدد (تومان) ارسال کنید.\nمثال: 15000000",
                    $kbBack, $mode, $backadmin);
                return true;
            case '💰 تغییر موجودی':
                nm_manager_reply($from_id, $message_id, "💰 <b>تغییر موجودی حساب نماینده</b>\n\nنوع عملیات را انتخاب کنید:", nm_balance_op_reply_kb(), $mode, $backadmin);
                return true;
            case '➕ افزایش موجودی':
                update("user", "step", "nm_balance_amt", "id", $from_id);
                update("user", "Processing_value_one", "increase", "id", $from_id);
                nm_manager_reply($from_id, $message_id, "💰 <b>تغییر موجودی — ➕ افزایش</b>\n\n📌 مبلغ را به‌عنوان عدد (تومان) ارسال کنید.\nمثال: 5000000", $kbBack, $mode, $backadmin);
                return true;
            case '➖ کاهش موجودی':
                update("user", "step", "nm_balance_amt", "id", $from_id);
                update("user", "Processing_value_one", "decrease", "id", $from_id);
                nm_manager_reply($from_id, $message_id, "💰 <b>تغییر موجودی — ➖ کاهش</b>\n\n📌 مبلغ را به‌عنوان عدد (تومان) ارسال کنید.\nمثال: 5000000", $kbBack, $mode, $backadmin);
                return true;
        }
        return false;
    }
}
