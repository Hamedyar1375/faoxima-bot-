<?php

if (!function_exists('fx_reseller_delete_guard')) {
    function fx_reseller_delete_guard(PDO $pdo, $rid): array
    {
        $ridStr = trim((string) $rid);
        if ($ridStr === '' || !ctype_digit($ridStr)) {
            return [
                'allowed' => false,
                'code' => 'invalid',
                'message' => '❌ شناسه نماینده نامعتبر است.',
                'debt' => 0,
                'reserved' => 0,
                'balance' => null,
            ];
        }

        try {
            $st = $pdo->prepare("SELECT id_user, username, current_debt, debt_mode FROM botsaz WHERE id_user = :rid LIMIT 1");
            $st->execute([':rid' => $ridStr]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return [
                'allowed' => false,
                'code' => 'db_error',
                'message' => '❌ بررسی وضعیت مالی نماینده ممکن نشد؛ برای جلوگیری از حذف ناامن، عملیات متوقف شد.',
                'debt' => 0,
                'reserved' => 0,
                'balance' => null,
            ];
        }

        if (!is_array($row)) {
            return [
                'allowed' => false,
                'code' => 'not_found',
                'message' => '❌ رباتی برای این نماینده یافت نشد.',
                'debt' => 0,
                'reserved' => 0,
                'balance' => null,
            ];
        }

        $debt = max(0, (int) ($row['current_debt'] ?? 0));
        $reserved = 0;
        try {
            $st = $pdo->prepare("SELECT COUNT(*) FROM fx_debt_reservations WHERE reseller_id = :rid AND state = 'reserved'");
            $st->execute([':rid' => $ridStr]);
            $reserved = (int) $st->fetchColumn();
        } catch (\Throwable $e) {
            return [
                'allowed' => false,
                'code' => 'reservation_check_failed',
                'message' => '❌ وضعیت رزروهای مالی نماینده قابل بررسی نیست؛ برای جلوگیری از حذف ناامن، عملیات متوقف شد.',
                'debt' => $debt,
                'reserved' => 0,
                'balance' => null,
            ];
        }

        $balance = null;
        try {
            $st = $pdo->prepare("SELECT Balance FROM user WHERE id = :rid LIMIT 1");
            $st->execute([':rid' => $ridStr]);
            $rawBalance = $st->fetchColumn();
            if ($rawBalance !== false) {
                $balance = (int) round((float) $rawBalance);
            }
        } catch (\Throwable $e) {
        }

        if ($debt > 0) {
            return [
                'allowed' => false,
                'code' => 'debt',
                'message' => "❌ حذف ربات نماینده امکان‌پذیر نیست.\n💸 بدهی تسویه‌نشده: " . number_format($debt) . " تومان\nابتدا از بخش «تسویه‌حساب» بدهی را تسویه کنید.",
                'debt' => $debt,
                'reserved' => $reserved,
                'balance' => $balance,
            ];
        }

        if ($reserved > 0) {
            return [
                'allowed' => false,
                'code' => 'reserved',
                'message' => "❌ حذف ربات نماینده امکان‌پذیر نیست.\n⏳ " . number_format($reserved) . " عملیات مالی در انتظار تکمیل است. چند لحظه بعد دوباره تلاش کنید.",
                'debt' => $debt,
                'reserved' => $reserved,
                'balance' => $balance,
            ];
        }

        if ($balance !== null && $balance < 0) {
            return [
                'allowed' => false,
                'code' => 'negative_balance',
                'message' => "❌ حذف ربات نماینده امکان‌پذیر نیست.\n🏦 مانده حساب نماینده منفی است: " . number_format(abs($balance)) . " تومان بدهی\nابتدا حساب را تسویه کنید.",
                'debt' => $debt,
                'reserved' => $reserved,
                'balance' => $balance,
            ];
        }

        return [
            'allowed' => true,
            'code' => 'ok',
            'message' => '',
            'debt' => $debt,
            'reserved' => $reserved,
            'balance' => $balance,
        ];
    }
}

if (!function_exists('fx_reseller_archive_before_delete')) {
    function fx_reseller_archive_before_delete(PDO $pdo, $rid, array $context = []): array
    {
        $ridStr = trim((string) $rid);
        $root = defined('REFACTORED_LEGACY_ROOT') ? REFACTORED_LEGACY_ROOT : dirname(__DIR__, 3);
        $dir = $root . '/storage/reseller_archives';
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return ['ok' => false, 'message' => '❌ ایجاد مسیر آرشیو امن ممکن نشد؛ حذف انجام نشد.'];
        }

        $data = [
            'rid' => $ridStr,
            'archived_at' => date('Y-m-d H:i:s'),
            'context' => $context,
            'warnings' => [],
        ];

        try {
            $st = $pdo->prepare("SELECT * FROM botsaz WHERE id_user = :rid LIMIT 1");
            $st->execute([':rid' => $ridStr]);
            $data['botsaz'] = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => '❌ خواندن اطلاعات ربات برای آرشیو ممکن نشد؛ حذف انجام نشد.'];
        }

        if (!is_array($data['botsaz'])) {
            return ['ok' => false, 'message' => '❌ ربات برای آرشیو یافت نشد؛ حذف انجام نشد.'];
        }

        try {
            $st = $pdo->prepare("SELECT id, Balance, agent, bottype FROM user WHERE id = :rid LIMIT 1");
            $st->execute([':rid' => $ridStr]);
            $data['owner'] = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (\Throwable $e) {
            $data['warnings'][] = 'owner_read_failed';
        }

        try {
            $st = $pdo->prepare("SELECT COUNT(*) AS c, COALESCE(SUM(price_product),0) AS revenue FROM invoice i JOIN user u ON u.id = i.id_user WHERE (u.agent = :rid OR u.bottype = :tok) AND i.name_product != 'سرویس تست'");
            $st->execute([':rid' => $ridStr, ':tok' => (string) ($data['botsaz']['bot_token'] ?? '')]);
            $data['invoices'] = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (\Throwable $e) {
            $data['warnings'][] = 'invoice_summary_failed';
        }

        try {
            $st = $pdo->prepare("SELECT direction, COUNT(*) AS c, COALESCE(SUM(amount),0) AS total FROM fx_debt_log WHERE reseller_id = :rid GROUP BY direction");
            $st->execute([':rid' => $ridStr]);
            $data['debt_totals'] = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            $data['warnings'][] = 'debt_totals_failed';
        }

        try {
            $st = $pdo->prepare("SELECT * FROM fx_debt_reservations WHERE reseller_id = :rid ORDER BY created_at DESC LIMIT 100");
            $st->execute([':rid' => $ridStr]);
            $data['reservations'] = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            $data['warnings'][] = 'reservations_read_failed';
        }

        try {
            $st = $pdo->prepare("SELECT id, amount, direction, method, scope, invoice_ref, debt_after, time, idempotency_key FROM fx_debt_log WHERE reseller_id = :rid ORDER BY id DESC LIMIT 200");
            $st->execute([':rid' => $ridStr]);
            $data['ledger_tail'] = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            $data['warnings'][] = 'ledger_tail_failed';
        }

        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if (!is_string($json) || $json === '') {
            return ['ok' => false, 'message' => '❌ ساخت فایل آرشیو ممکن نشد؛ حذف انجام نشد.'];
        }

        $file = $dir . '/reseller_' . $ridStr . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.json';
        if (@file_put_contents($file, $json, LOCK_EX) === false) {
            return ['ok' => false, 'message' => '❌ ذخیره آرشیو امن ممکن نشد؛ حذف انجام نشد.'];
        }

        return ['ok' => true, 'path' => $file, 'message' => ''];
    }
}
