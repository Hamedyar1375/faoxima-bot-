<?php

/**
 * Pending purchase helpers.
 *
 * A pending purchase reuses the existing invoice row (Status = unpaid) and the
 * existing getconfigafterpay|username marker in Payment_report. No schema
 * migration is required.
 */

if (!function_exists('rx_pending_purchase_expire_stale')) {
    /**
     * Expire old unpaid invoices that never turned into a paid/pending purchase.
     * Invoices tied to an active payment/receipt are preserved.
     */
    function rx_pending_purchase_expire_stale($userId = null, $ttlSeconds = 86400, $limit = 200): int
    {
        $pdo = getDatabaseConnection();
        if (!($pdo instanceof PDO)) {
            return 0;
        }
        $ttlSeconds = max(3600, (int) $ttlSeconds);
        $limit = max(1, min(1000, (int) $limit));
        $cutoff = time() - $ttlSeconds;

        $where = "Status IN ('unpaid', 'Unpaid')
                  AND name_product <> 'سرویس تست'
                  AND CAST(time_sell AS UNSIGNED) < :cutoff";
        $params = [':cutoff' => (string) $cutoff];
        if ($userId !== null && $userId !== '') {
            $where .= " AND id_user = :uid";
            $params[':uid'] = (string) $userId;
        }

        try {
            $stmt = $pdo->prepare(
                "SELECT id_invoice, id_user, username FROM invoice
                  WHERE {$where}
                  ORDER BY CAST(time_sell AS UNSIGNED) ASC
                  LIMIT {$limit}"
            );
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            error_log('rx_pending_purchase_expire_stale: ' . $e->getMessage());
            return 0;
        }

        $expired = 0;
        foreach ($rows as $row) {
            $invoiceId = (string) ($row['id_invoice'] ?? '');
            $ownerId = (string) ($row['id_user'] ?? '');
            $username = trim((string) ($row['username'] ?? ''));
            if ($invoiceId === '' || $ownerId === '' || $username === '') {
                continue;
            }
            $marker = 'getconfigafterpay|' . $username;

            try {
                $active = $pdo->prepare(
                    "SELECT COUNT(*) FROM Payment_report
                      WHERE id_user = :uid
                        AND id_invoice = :marker
                        AND payment_Status IN ('pending', 'waiting', 'processing', 'reconciling', 'paid', 'manual_review', 'AwaitingHash')"
                );
                $active->execute([':uid' => $ownerId, ':marker' => $marker]);
                if ((int) $active->fetchColumn() > 0) {
                    continue;
                }

                $upd = $pdo->prepare(
                    "UPDATE invoice
                        SET Status = 'expired', invalidated_at = :at
                      WHERE id_invoice = :inv
                        AND id_user = :uid
                        AND Status IN ('unpaid', 'Unpaid')"
                );
                $upd->execute([':at' => time(), ':inv' => $invoiceId, ':uid' => $ownerId]);
                if ($upd->rowCount() < 1) {
                    continue;
                }

                $pdo->prepare(
                    "UPDATE Payment_report
                        SET payment_Status = 'expire'
                      WHERE id_user = :uid
                        AND id_invoice = :marker
                        AND payment_Status IN ('Unpaid', 'unpaid')"
                )->execute([':uid' => $ownerId, ':marker' => $marker]);
                $expired++;
            } catch (Throwable $e) {
                error_log('rx_pending_purchase_expire_stale row failed: ' . $e->getMessage());
            }
        }

        if ($expired > 0 && function_exists('clearSelectCache')) {
            clearSelectCache('invoice');
            clearSelectCache('Payment_report');
        }
        return $expired;
    }
}

if (!function_exists('rx_user_visible_invoice_count')) {
    function rx_user_visible_invoice_count($userId): int
    {
        $pdo = getDatabaseConnection();
        if (!($pdo instanceof PDO)) {
            return 0;
        }
        try {
            $stmt = $pdo->prepare(
                "SELECT COUNT(*) FROM invoice
                  WHERE id_user = :uid
                    AND Status IN ('active', 'end_of_time', 'end_of_volume', 'sendedwarn', 'send_on_hold', 'disabled', 'disablebyadmin')"
            );
            $stmt->execute([':uid' => (string) $userId]);
            return (int) $stmt->fetchColumn();
        } catch (Throwable $e) {
            error_log('rx_user_visible_invoice_count: ' . $e->getMessage());
            return 0;
        }
    }
}

if (!function_exists('rx_flow_message_table_ready')) {
    function rx_flow_message_table_ready(): bool
    {
        static $ready = false;
        if ($ready) return true;
        $pdo = getDatabaseConnection();
        if (!($pdo instanceof PDO)) return false;
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS user_flow_messages (
                user_id varchar(64) NOT NULL,
                scope varchar(40) NOT NULL,
                message_id bigint NOT NULL DEFAULT 0,
                updated_at int unsigned NOT NULL DEFAULT 0,
                PRIMARY KEY (user_id, scope)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $ready = true;
            return true;
        } catch (Throwable $e) {
            error_log('user_flow_messages table: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('rx_flow_message_store')) {
    function rx_flow_message_store($userId, string $scope, $messageId): void
    {
        $userId = trim((string) $userId);
        $messageId = (int) $messageId;
        if ($userId === '' || $messageId <= 0 || !rx_flow_message_table_ready()) return;
        $pdo = getDatabaseConnection();
        if (!($pdo instanceof PDO)) return;
        try {
            $stmt = $pdo->prepare('INSERT INTO user_flow_messages (user_id, scope, message_id, updated_at) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE message_id = VALUES(message_id), updated_at = VALUES(updated_at)');
            $stmt->execute([$userId, $scope, $messageId, time()]);
        } catch (Throwable $e) {
            error_log('rx_flow_message_store: ' . $e->getMessage());
        }
    }
}

if (!function_exists('rx_flow_message_clear')) {
    function rx_flow_message_clear($userId, string $scope): void
    {
        $userId = trim((string) $userId);
        if ($userId === '') return;
        $pdo = getDatabaseConnection();
        if (!($pdo instanceof PDO)) return;
        try {
            $pdo->prepare('DELETE FROM user_flow_messages WHERE user_id = ? AND scope = ?')->execute([$userId, $scope]);
        } catch (Throwable $e) {
        }
    }
}

if (!function_exists('rx_flow_message_clear_all')) {
    /**
     * Delete every active purchase/flow message recorded for this user.
     * Used by /start, /cancel and back-to-main-menu transitions.
     */
    function rx_flow_message_clear_all($userId): int
    {
        $userId = trim((string) $userId);
        if ($userId === '' || !rx_flow_message_table_ready()) return 0;
        $pdo = getDatabaseConnection();
        if (!($pdo instanceof PDO)) return 0;
        try {
            $stmt = $pdo->prepare('SELECT message_id FROM user_flow_messages WHERE user_id = ?');
            $stmt->execute([$userId]);
            $ids = array_values(array_unique(array_filter(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []), static fn($v) => $v > 0)));
            if (function_exists('deletemessage')) {
                foreach ($ids as $mid) {
                    @deletemessage($userId, $mid);
                }
            }
            $pdo->prepare('DELETE FROM user_flow_messages WHERE user_id = ?')->execute([$userId]);
            return count($ids);
        } catch (Throwable $e) {
            error_log('rx_flow_message_clear_all: ' . $e->getMessage());
            return 0;
        }
    }
}

if (!function_exists('rx_flow_message_is_interactive')) {
    /**
     * Heuristic used by the global outgoing-message tracker.
     * We only retain messages that expect an answer (input prompt) or expose
     * callback buttons. Informational messages (service credentials, reports)
     * are deliberately NOT deleted on /start.
     */
    function rx_flow_message_is_interactive($text, $keyboard): bool
    {
        $kb = $keyboard;
        if (is_array($kb)) {
            if (!empty($kb)) return true;
        } elseif (is_string($kb)) {
            $kb = trim($kb);
            if ($kb !== '' && $kb !== 'null' && $kb !== '{}' && $kb !== '[]') return true;
        } elseif ($kb !== null && $kb !== false && $kb !== '') {
            return true;
        }

        $plain = trim(preg_replace('/\s+/u', ' ', strip_tags(html_entity_decode((string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8'))));
        if ($plain === '') return false;
        return (bool) preg_match('/(ارسال|بفرستید|وارد کنید|وارد نمایید|بنویسید|انتخاب کنید|آپلود|بارگذاری|تصویر|عکس|رسید|مبلغ|هش|تراکنش|۴\s*رقم|چهار\s*رقم|شماره\s*کارت|کد\s*تخفیف|نام\s*کاربری|یوزرنیم)/u', $plain);
    }
}

if (!function_exists('rx_flow_message_track_outgoing')) {
    /**
     * GLOBAL outgoing tracker. Called from telegram() for every successful
     * sendMessage/sendPhoto/sendVideo/sendDocument response.
     *
     * IMPORTANT: never delete an older message here. Service delivery messages
     * (subscription links/configs) have inline keyboards too; deleting the
     * previous "auto" message used to remove them right after a successful
     * purchase. We store each tracked message in its own scope and only clean
     * them on /start, /cancel or back-to-main.
     */
    function rx_flow_message_track_outgoing($chatId, $messageId, $text = '', $keyboard = null): void
    {
        $chatId = (string) $chatId;
        $messageId = (int) $messageId;
        if ($messageId <= 0 || !ctype_digit($chatId) || (int) $chatId <= 0) return;
        if (!rx_flow_message_is_interactive($text, $keyboard)) return;
        if (!rx_flow_message_table_ready()) return;
        rx_flow_message_store($chatId, 'auto:' . $messageId, $messageId);
    }
}

if (!function_exists('rx_flow_message_delete_previous')) {
    function rx_flow_message_delete_previous($userId, string $scope, $keepMessageId = 0): void
    {
        $userId = trim((string) $userId);
        $keepMessageId = (int) $keepMessageId;
        if ($userId === '' || !rx_flow_message_table_ready()) return;
        $pdo = getDatabaseConnection();
        if (!($pdo instanceof PDO)) return;
        try {
            $stmt = $pdo->prepare('SELECT message_id FROM user_flow_messages WHERE user_id = ? AND scope = ? LIMIT 1');
            $stmt->execute([$userId, $scope]);
            $old = (int) $stmt->fetchColumn();
            if ($old > 0 && $old !== $keepMessageId && function_exists('deletemessage')) {
                @deletemessage($userId, $old);
            }
            $pdo->prepare('DELETE FROM user_flow_messages WHERE user_id = ? AND scope = ?')->execute([$userId, $scope]);
        } catch (Throwable $e) {
        }
    }
}

if (!function_exists('rx_pending_purchase_get_active')) {
    /**
     * Return the newest unpaid non-test invoice for this user.
     *
     * @return array<string,mixed>|null
     */
    function rx_pending_purchase_get_active($userId)
    {
        if (function_exists('rx_pending_purchase_expire_stale')) {
            rx_pending_purchase_expire_stale($userId, 86400, 25);
        }
        $pdo = getDatabaseConnection();
        if (!($pdo instanceof PDO) || !is_scalar($userId)) {
            return null;
        }
        try {
            $stmt = $pdo->prepare(
                "SELECT * FROM invoice
                  WHERE id_user = :uid
                    AND Status IN ('unpaid', 'Unpaid')
                    AND name_product <> 'سرویس تست'
                  ORDER BY time_sell DESC, id_invoice DESC
                  LIMIT 1"
            );
            $stmt->execute([':uid' => (string) $userId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return is_array($row) ? $row : null;
        } catch (Throwable $e) {
            error_log('rx_pending_purchase_get_active: ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('rx_pending_purchase_set_session')) {
    function rx_pending_purchase_set_session($userId, array $invoice): bool
    {
        $username = trim((string) ($invoice['username'] ?? ''));
        if ($username === '') {
            return false;
        }
        update('user', 'Processing_value', '0', 'id', $userId);
        update('user', 'Processing_value_one', $username, 'id', $userId);
        update('user', 'Processing_value_tow', 'getconfigafterpay', 'id', $userId);
        update('user', 'Processing_value_four', '0', 'id', $userId);
        return true;
    }
}

if (!function_exists('rx_pending_purchase_clear_session')) {
    function rx_pending_purchase_clear_session($userId): void
    {
        update('user', 'Processing_value', '0', 'id', $userId);
        update('user', 'Processing_value_one', '0', 'id', $userId);
        update('user', 'Processing_value_tow', '0', 'id', $userId);
        update('user', 'Processing_value_four', '0', 'id', $userId);
    }
}

if (!function_exists('rx_pending_purchase_remaining_due')) {
    function rx_pending_purchase_remaining_due(array $invoice, $balance): int
    {
        $price = (float) ($invoice['price_product'] ?? 0);
        $balance = is_numeric($balance) ? (float) $balance : 0.0;
        $due = (int) ceil($price - $balance);
        return max(0, $due);
    }
}

if (!function_exists('rx_pending_purchase_deficiency_due')) {
    /**
     * Remaining due when the current checkout is completing a pending order.
     * Returns 0 for normal free wallet top-ups.
     */
    function rx_pending_purchase_deficiency_due($user): int
    {
        if (!is_array($user) || (string) ($user['Processing_value_tow'] ?? '') !== 'getconfigafterpay') {
            return 0;
        }
        $invoice = rx_pending_purchase_get_active($user['id'] ?? 0);
        if (!is_array($invoice)) {
            return 0;
        }
        return rx_pending_purchase_remaining_due($invoice, $user['Balance'] ?? 0);
    }
}

if (!function_exists('rx_pending_purchase_keyboard')) {
    function rx_pending_purchase_keyboard($due = null, $backText = null): string
    {
        $due = is_numeric($due) ? max(0, (int) $due) : 0;
        $rows = [];
        if ($due > 0) {
            $dueLabel = number_format($due);
            $rows[] = [
                ['text' => '💳 پرداخت کسری: ' . $dueLabel . ' تومان', 'callback_data' => 'pending_purchase_pay'],
            ];
        } else {
            $rows[] = [
                ['text' => '💳 پرداخت کسری سفارش', 'callback_data' => 'pending_purchase_pay'],
            ];
        }
        $rows[] = [
            ['text' => '❌ انصراف از سفارش معوق', 'callback_data' => 'pending_purchase_cancel'],
        ];
        if (is_string($backText) && $backText !== '') {
            $rows[] = [
                ['text' => $backText, 'callback_data' => 'account'],
            ];
        }
        return json_encode([
            'inline_keyboard' => $rows,
        ], JSON_UNESCAPED_UNICODE);
    }
}

if (!function_exists('rx_pending_purchase_cancel')) {
    /**
     * Cancel the active pending invoice. Refuses while a receipt/payment for
     * the same invoice is waiting or being processed.
     *
     * @return array{ok:bool,reason?:string,invoice?:array<string,mixed>}
     */
    function rx_pending_purchase_cancel($userId): array
    {
        $invoice = rx_pending_purchase_get_active($userId);
        if (!is_array($invoice)) {
            return ['ok' => false, 'reason' => 'not_found'];
        }
        $username = trim((string) ($invoice['username'] ?? ''));
        $invoiceId = (string) ($invoice['id_invoice'] ?? '');
        $marker = 'getconfigafterpay|' . $username;

        $pdo = getDatabaseConnection();
        if (!($pdo instanceof PDO)) {
            return ['ok' => false, 'reason' => 'db_unavailable'];
        }

        try {
            $check = $pdo->prepare(
                "SELECT COUNT(*) FROM Payment_report
                  WHERE id_user = :uid
                    AND id_invoice = :marker
                    AND payment_Status IN ('pending', 'waiting', 'processing', 'reconciling')"
            );
            $check->execute([':uid' => (string) $userId, ':marker' => $marker]);
            if ((int) $check->fetchColumn() > 0) {
                return ['ok' => false, 'reason' => 'payment_in_progress', 'invoice' => $invoice];
            }

            $pdo->prepare(
                "UPDATE Payment_report
                    SET payment_Status = 'expire'
                  WHERE id_user = :uid
                    AND id_invoice = :marker
                    AND payment_Status IN ('Unpaid', 'unpaid')"
            )->execute([':uid' => (string) $userId, ':marker' => $marker]);

            $pdo->prepare(
                "UPDATE invoice
                    SET Status = 'cancelled', invalidated_at = :at
                  WHERE id_invoice = :inv
                    AND id_user = :uid
                    AND Status IN ('unpaid', 'Unpaid')"
            )->execute([':at' => time(), ':inv' => $invoiceId, ':uid' => (string) $userId]);
        } catch (Throwable $e) {
            error_log('rx_pending_purchase_cancel: ' . $e->getMessage());
            return ['ok' => false, 'reason' => 'db_error', 'invoice' => $invoice];
        }

        if (function_exists('rx_release_unpaid_discount')) {
            $refTime = !empty($invoice['time_sell']) ? (int) $invoice['time_sell'] : null;
            rx_release_unpaid_discount((string) $userId, null, $refTime ?: null);
        }
        rx_pending_purchase_clear_session($userId);
        if (function_exists('clearSelectCache')) {
            clearSelectCache('invoice');
            clearSelectCache('Payment_report');
        }

        return ['ok' => true, 'invoice' => $invoice];
    }
}
