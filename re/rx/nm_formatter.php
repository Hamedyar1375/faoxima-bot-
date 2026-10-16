<?php
/**
 * nm_formatter.php — قالب‌بندی و ساخت پیام وضعیت مدیریت نماینده (v2baz)
 * =====================================================================
 * STEP_2: Message Formatter
 *
 * وظیفه:
 *  - قالب‌بندی اعداد: ارقام فارسی + جداکننده هزارگان
 *  - نمایش بدهکار (منفی) با علامت منفی در پایان عدد: «۱,۰۴۰,۰۰۰- تومان»
 *  - استخراج داده‌های وضعیت نماینده از دیتابیس (فقط SELECT — بدون تغییر)
 *  - ساخت متن نهایی پیام وضعیت
 *
 * منابع داده (فقط‌خوان، بدون تغییر ساختار):
 *  - آیدی مالک      : Requestagent.id (نخستین درخواست نماینده)
 *  - آیدی ربات/توکن : user.bottype  (فرمت «botid:token» در سطرهای user)
 *  - تعداد کاربران  : user (ستون id)، سطر سیستمی id=0 محاسبه نمی‌شود
 *  - اکانت ساخته‌شده: remnawave_users
 *  - موجودی         : user.Balance (سطر مالک)
 *  - سقف بدهکاری    : nm_default_debt_limit() از STEP_1 (env NM_DEBT_LIMIT یا 12,000,000)
 *  - وضعیت ربات    : setting.Bot_Status (botstatuson / botstatusoff)
 *
 * require ایمن است: بدون side-effect، فقط تعریف تابع.
 */

if (!function_exists('nm_default_debt_limit')) {
    // STEP_1 — برای سقف بدهکاری
    require_once __DIR__ . '/nm_financials.php';
}

if (!function_exists('nm_persian_digits')) {
    /**
     * تبدیل ارقام لاتین به فارسی.
     *
     * @param mixed $value
     * @return string
     */
    function nm_persian_digits($value)
    {
        return strtr((string)$value, [
            '0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴',
            '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹',
        ]);
    }
}

if (!function_exists('nm_fmt_money')) {
    /**
     * قالب‌بندی مبلغ به تومان: ارقام فارسی + جداکننده هزارگان.
     *
     * عدد منفی (بدهکار): علامت منفی در پایان عدد:
     *   nm_fmt_money(-1040000)  => «۱,۰۴۰,۰۰۰-»
     *   nm_fmt_money(1040000)   => «۱,۰۴۰,۰۰۰»
     *   nm_fmt_money(0)         => «۰»
     *   nm_fmt_money(-12000000) => «۱۲,۰۰۰,۰۰۰-»
     *
     * @param mixed $amount
     * @return string
     */
    function nm_fmt_money($amount)
    {
        $amount = (int)$amount;
        $s = nm_persian_digits(number_format(abs($amount), 0, '.', ','));
        return $amount < 0 ? $s . '-' : $s;
    }
}

if (!function_exists('nm_fmt_count')) {
    /**
     * قالب‌بندی تعداد/آیدی: ارقام فارسی بدون جداکننده.
     *
     * @param mixed $n
     * @return string
     */
    function nm_fmt_count($n)
    {
        return nm_persian_digits((int)$n);
    }
}

if (!function_exists('nm_bot_status_is_active')) {
    /**
     * تفسیر ستون setting.Bot_Status.
     *
     * @param mixed $raw مثال: botstatuson / botstatusoff
     * @return bool|null true=فعال، false=غیرفعال، null=نامشخص/خالی
     */
    function nm_bot_status_is_active($raw)
    {
        $raw = strtolower(trim((string)$raw));
        if ($raw === '') {
            return null;
        }
        if (strpos($raw, 'off') !== false) {
            return false;
        }
        if (strpos($raw, 'on') !== false) {
            return true;
        }
        return null;
    }
}

if (!function_exists('nm_build_manager_status')) {
    /**
     * استخراج داده‌های وضعیت نماینده از دیتابیس — فقط SELECT، بدون هیچ تغییری.
     *
     * @param \PDO $pdo
     * @param int|null $owner_id آیدی TG مالک؛ null = خواندن از Requestagent
     * @return array{
     *   owner_id: int|null,
     *   bot_id: string,
     *   bot_token: string,
     *   user_count: int,
     *   account_count: int,
     *   credit_limit: int,
     *   balance: int,
     *   bot_active: bool|null
     * }
     */
    function nm_build_manager_status($pdo, $owner_id = null)
    {
        // ۱) آیدی مالک
        if ($owner_id === null) {
            $row = $pdo->query("SELECT id FROM Requestagent ORDER BY time ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
            $owner_id = $row ? (int)$row['id'] : null;
        } else {
            $owner_id = (int)$owner_id;
        }

        // ۲) آیدی ربات + توکن (از user.bottype)
        $bot_id = '';
        $bot_token = '';
        $bt = $pdo->query("SELECT bottype FROM user WHERE bottype != '' LIMIT 1")->fetchColumn();
        if ($bt !== false && $bt !== '') {
            $pos = strpos($bt, ':');
            if ($pos !== false) {
                $bot_id    = substr($bt, 0, $pos);
                $bot_token = substr($bt, $pos + 1);
            } else {
                $bot_token = (string)$bt;
            }
        }

        // ۳) تعداد کاربران (سطر سیستمی id=0 — NOT_USERNAME — حذف می‌شود)
        $user_count = (int)$pdo->query("SELECT COUNT(*) FROM user WHERE id != '0'")->fetchColumn();

        // ۴) تعداد اکانت‌های ساخته‌شده در پنل
        $account_count = (int)$pdo->query("SELECT COUNT(*) FROM remnawave_users")->fetchColumn();

        // ۵) موجودی حساب مالک
        $balance = 0;
        if ($owner_id !== null) {
            $st = $pdo->prepare("SELECT Balance FROM user WHERE id = :id LIMIT 1");
            $st->execute([':id' => (string)$owner_id]);
            $b = $st->fetchColumn();
            $balance = ($b === false || $b === null) ? 0 : (int)$b;
        }

        // ۶) وضعیت ربات
        $raw_status = $pdo->query("SELECT Bot_Status FROM setting LIMIT 1")->fetchColumn();

        return [
            'owner_id'      => $owner_id,
            'bot_id'        => $bot_id,
            'bot_token'     => $bot_token,
            'user_count'    => $user_count,
            'account_count' => $account_count,
            'credit_limit'  => nm_default_debt_limit(),
            'balance'       => $balance,
            'bot_active'    => nm_bot_status_is_active($raw_status),
        ];
    }
}

if (!function_exists('nm_format_manager_status')) {
    /**
     * ساخت متن نهایی پیام وضعیت از آرایه داده.
     *
     * قالب:
     *   📊 وضعیت نماینده
     *
     *   👤 آیدی مالک: ...
     *   🤖 آیدی ربات: ...
     *   🔑 توکن ربات: ...
     *   👥 تعداد کاربران: ...
     *   🧩 تعداد اکانت‌های ساخته‌شده: ...
     *   💳 سقف بدهکاری: ... تومان
     *   💰 موجودی حساب: ... تومان
     *   🟢 وضعیت ربات: فعال
     *
     * @param array $data خروجی nm_build_manager_status (یا هر زیرمجموعه‌ای از کلیدها)
     * @param array $opts ['mask_token' => bool] — true: نمایش کوتاه‌شده توکن (۸+۴ کاراکتر)
     * @return string
     */
    function nm_format_manager_status(array $data, array $opts = [])
    {
        $mask  = !empty($opts['mask_token']);
        $owner = (isset($data['owner_id']) && $data['owner_id'] !== null) ? nm_fmt_count($data['owner_id']) : '—';
        $bot   = !empty($data['bot_id']) ? nm_fmt_count($data['bot_id']) : '—';

        $token = (string)($data['bot_token'] ?? '');
        if ($mask && strlen($token) > 12) {
            $token = substr($token, 0, 8) . '…' . substr($token, -4);
        }
        $token = $token !== '' ? $token : '—';

        $users  = nm_fmt_count($data['user_count'] ?? 0);
        $accts  = nm_fmt_count($data['account_count'] ?? 0);
        $limit  = nm_fmt_money($data['credit_limit'] ?? 0);
        $bal    = nm_fmt_money($data['balance'] ?? 0);

        $act   = array_key_exists('bot_active', $data) ? $data['bot_active'] : null;
        if ($act === true) {
            $status = "🟢 وضعیت ربات: فعال";
        } elseif ($act === false) {
            $status = "🔴 وضعیت ربات: غیرفعال";
        } else {
            $status = "⚪ وضعیت ربات: نامشخص";
        }

        return "📊 وضعیت نماینده\n\n"
             . "👤 آیدی مالک: {$owner}\n"
             . "🤖 آیدی ربات: {$bot}\n"
             . "🔑 توکن ربات: {$token}\n"
             . "👥 تعداد کاربران: {$users}\n"
             . "🧩 تعداد اکانت‌های ساخته‌شده: {$accts}\n"
             . "💳 سقف بدهکاری: {$limit} تومان\n"
             . "💰 موجودی حساب: {$bal} تومان\n"
             . $status;
    }
}
