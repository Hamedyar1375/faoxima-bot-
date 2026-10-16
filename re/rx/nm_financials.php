<?php
/**
 * nm_financials.php — منطق مالی جدید سیستم مدیریت نمایندگی (v2baz)
 * =================================================================
 * STEP_1: Financial Logic
 *
 * قوانین:
 *  1) موجودی (user.Balance / mande_hesab) می‌تواند منفی شود = بدهکاری.
 *  2) سقف بدهکاری یک عدد مثبت است (پیش‌فرض 12,000,000 تومان).
 *  3) شرط خرید: |موجودی بعد از کسر| <= سقف بدهکاری — در غیر این صورت خرید مسدود.
 *  4) نمایش با علامت منفی (مثلاً ۱,۰۴۰,۰۰۰- تومان) → در STEP_2 (Formatter).
 *
 * الزامات:
 *  - هیچ دستوری روی ساختار دیتابیس اجرا نمی‌کند (بدون ALTER/CREATE).
 *  - فقط از ستون‌های موجود استفاده می‌کند: user.Balance + جدول ledger موجود fx_debt_log.
 *  - همه کسر/واریزها اتمیک هستند (UPDATE شرطی در یک statement).
 *  - لاگ رویدادهای بدهی در fx_debt_log (جدول موجود، فقط INSERT).
 *
 * require ایمن است: بدون side-effect، فقط تعریف تابع.
 */

if (!defined('REFACTORED_LEGACY_ROOT')) {
    define('REFACTORED_LEGACY_ROOT', __DIR__);
}

if (!function_exists('nm_default_debt_limit')) {
    /**
     * سقف پیش‌فرض بدهکاری (تومان) — عدد مثبت.
     * می‌تواند در STEP_4 با مقدار مدیریت‌شونده جایگزین شود؛
     * فعلاً ثابت است چون دیتابیس اجازه تغییر ساختار ندارد.
     */
    define('NM_DEBT_LIMIT_DEFAULT', 12000000);

    /**
     * سقف بدهکاری فعال (تومان).
     *
     * اولویت: ۱) مقدار positive از env NM_DEBT_LIMIT ۲) ثابت پیش‌فرض.
     *
     * @return int
     */
    function nm_default_debt_limit()
    {
        $env = getenv('NM_DEBT_LIMIT');
        if ($env !== false && $env !== '' && ctype_digit((string)$env)) {
            return (int)$env;
        }
        return NM_DEBT_LIMIT_DEFAULT;
    }
}

if (!function_exists('nm_debt_of')) {
    /**
     * بدهی جاری از روی موجودی: اگر منفی باشد، قدر مطلق آن، وگرنه ۰.
     *
     * @param int $mande_hesab موجودی (می‌تواند منفی)
     * @return int بدهی (همیشه >= 0)
     */
    function nm_debt_of($mande_hesab)
    {
        return $mande_hesab < 0 ? -$mande_hesab : 0;
    }
}

if (!function_exists('nm_can_purchase')) {
    /**
     * اعتبارسنجی خرید با منطق بدهی جدید.
     *
     * @param int $mande_hesab موجودی فعلی کاربر (user.Balance)
     * @param int $price       مبلغ خرید (تومان, > 0)
     * @param int $limit       سقف بدهکاری (تومان, >= 0)
     * @return array{
     *   ok: bool,
     *   reason: string,            // ok | empty_price | debt_limit_exceeded
     *   balance_after: int,        // موجودی بعد از خرید (در صورت مجاز بودن)
     *   debt_after: int,           // بدهی بعد از خرید (در صورت مجاز بودن)
     *   debt_limit: int
     * }
     */
    function nm_can_purchase($mande_hesab, $price, $limit = null)
    {
        $limit = ($limit === null) ? nm_default_debt_limit() : (int)$limit;
        $mande_hesab = (int)$mande_hesab;
        $price = (int)$price;

        if ($price <= 0) {
            return [
                'ok'            => false,
                'reason'        => 'empty_price',
                'balance_after' => $mande_hesab,
                'debt_after'    => nm_debt_of($mande_hesab),
                'debt_limit'    => $limit,
            ];
        }

        $balance_after = $mande_hesab - $price;
        $debt_after    = nm_debt_of($balance_after);

        // قانون ۳: بدهیِ بعد از کسر (فقط بخش منفی موجودی) <= سقف بدهکاری
        // نکته: موجودی مثبت هرگز به‌عنوان بدهی محاسبه نمی‌شود؛
        // یعنی کاربر با موجودی مثبت (بدون بدهی) حتی اگر بعد از خرید هنوز مثبت باشد، مسدود نمی‌شود.
        // (با abs() اشتباه می‌کرد: مثلاً موجودی ۵M - قیمت ۳M = +۲M → abs=2M که با سقف ۱M نادرست مسدود می‌شد)
        $ok = $debt_after <= $limit;

        return [
            'ok'            => $ok,
            'reason'        => $ok ? 'ok' : 'debt_limit_exceeded',
            'balance_after' => $balance_after,
            'debt_after'    => $debt_after,
            'debt_limit'    => $limit,
        ];
    }
}

if (!function_exists('nm_debit_balance')) {
    /**
     * کسر اتمیک از موجودی با رعایت سقف بدهکاری.
     *
     * UPDATE شرطی: اگر (Balance - amount) از سقف بدهی فراتر رود،
     * هیچ ردی تغییر نمی‌کند (rowCount = 0).
     *
     * @param \PDO $pdo
     * @param int  $user_id id کاربر در جدول user
     * @param int  $price   مبلغ کسر (تومان, > 0)
     * @param int  $limit   سقف بدهکاری (null = پیش‌فرض)
     * @param string $ref   مرجع سفارش (invoice_ref برای ledger)
     * @return array{ok: bool, reason: string, balance_after: int, debt_after: int}
     */
    function nm_debit_balance($pdo, $user_id, $price, $limit = null, $ref = '')
    {
        $limit = ($limit === null) ? nm_default_debt_limit() : (int)$limit;
        $price = (int)$price;
        $user_id = (int)$user_id;

        if ($price <= 0 || $user_id <= 0) {
            return ['ok' => false, 'reason' => 'invalid_input', 'balance_after' => 0, 'debt_after' => 0];
        }

        try {
            $stmt = $pdo->prepare(
                "UPDATE user
                 SET Balance = Balance - :amt
                 WHERE id = :uid
                   AND (Balance - :amt2) >= -:limit"
            );
            $stmt->execute([':amt' => $price, ':uid' => $user_id, ':amt2' => $price, ':limit' => $limit]);

            if ($stmt->rowCount() === 0) {
                // یا کاربر وجود ندارد، یا بدهی از سقف عبور می‌کرد.
                $check = $pdo->prepare("SELECT Balance FROM user WHERE id = :uid");
                $check->execute([':uid' => $user_id]);
                $row = $check->fetch(\PDO::FETCH_ASSOC);
                $reason = $row ? 'debt_limit_exceeded' : 'user_not_found';
                error_log("[NMF] nm_debit_balance BLOCKED uid=$user_id amt=$price reason=$reason limit=$limit");
                return [
                    'ok'            => false,
                    'reason'        => $reason,
                    'balance_after' => $row ? (int)$row['Balance'] : 0,
                    'debt_after'    => $row ? nm_debt_of((int)$row['Balance']) : 0,
                ];
            }

            // موجودی نهایی را دقیق بخوان
            $check = $pdo->prepare("SELECT Balance FROM user WHERE id = :uid");
            $check->execute([':uid' => $user_id]);
            $balance_after = (int)$check->fetchColumn();
            $debt_after    = nm_debt_of($balance_after);

            // ثبت در ledger بدهی (جدول موجود — فقط INSERT)
            // direction=out: خروج از حساب → افزایش بدهی (از دید بدهی: ورود)
            // مطابق کنوانسیون موجود جدول: purchase → direction=in, debt_after = بدهی جدید
            if ($debt_after > 0) {
                $log = $pdo->prepare(
                    "INSERT INTO fx_debt_log (reseller_id, amount, direction, method, invoice_ref, debt_after, time)
                     VALUES (:rid, :amt, 'in', 'purchase', :ref, :debt, NOW())"
                );
                $log->execute([
                    ':rid'  => $user_id,
                    ':amt'  => $price,
                    ':ref'  => $ref,
                    ':debt' => $debt_after,
                ]);
            }

            error_log("[NMF] nm_debit_balance OK uid=$user_id amt=$price bal_after=$balance_after debt=$debt_after ref=" . $ref);
            return ['ok' => true, 'reason' => 'ok', 'balance_after' => $balance_after, 'debt_after' => $debt_after];
        } catch (\Throwable $e) {
            error_log("[NMF] nm_debit_balance FATAL uid=$user_id amt=$price: " . $e->getMessage());
            return ['ok' => false, 'reason' => 'error', 'balance_after' => 0, 'debt_after' => 0];
        }
    }
}

if (!function_exists('nm_credit_balance')) {
    /**
     * واریز اتمیک به موجودی (کاهش بدهی / افزایش موجودی).
     *
     * @param \PDO $pdo
     * @param int  $user_id
     * @param int  $amount  مبلغ واریز (تومان, > 0)
     * @param string $ref
     * @param string $method روش ثبت در ledger: settlement | credit | refund
     * @return array{ok: bool, balance_after: int, debt_after: int}
     */
    function nm_credit_balance($pdo, $user_id, $amount, $ref = '', $method = 'credit')
    {
        $user_id = (int)$user_id;
        $amount  = (int)$amount;

        if ($amount <= 0 || $user_id <= 0) {
            return ['ok' => false, 'balance_after' => 0, 'debt_after' => 0];
        }

        try {
            $stmt = $pdo->prepare("UPDATE user SET Balance = Balance + :amt WHERE id = :uid");
            $stmt->execute([':amt' => $amount, ':uid' => $user_id]);

            if ($stmt->rowCount() === 0) {
                error_log("[NMF] nm_credit_balance NO-OP uid=$user_id amt=$amount (user not found)");
                return ['ok' => false, 'balance_after' => 0, 'debt_after' => 0];
            }

            $check = $pdo->prepare("SELECT Balance FROM user WHERE id = :uid");
            $check->execute([':uid' => $user_id]);
            $balance_after = (int)$check->fetchColumn();
            $debt_after    = nm_debt_of($balance_after);

            // ledger: خروج از بدهی
            $log = $pdo->prepare(
                "INSERT INTO fx_debt_log (reseller_id, amount, direction, method, invoice_ref, debt_after, time)
                 VALUES (:rid, :amt, 'out', :method, :ref, :debt, NOW())"
            );
            $log->execute([
                ':rid'    => $user_id,
                ':amt'    => $amount,
                ':method' => $method,
                ':ref'    => $ref,
                ':debt'   => $debt_after,
            ]);

            error_log("[NMF] nm_credit_balance OK uid=$user_id amt=$amount bal_after=$balance_after debt=$debt_after method=$method");
            return ['ok' => true, 'balance_after' => $balance_after, 'debt_after' => $debt_after];
        } catch (\Throwable $e) {
            error_log("[NMF] nm_credit_balance FATAL uid=$user_id amt=$amount: " . $e->getMessage());
            return ['ok' => false, 'balance_after' => 0, 'debt_after' => 0];
        }
    }
}
