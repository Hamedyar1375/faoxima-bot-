<?php

if (!function_exists('rx_stats_historical_sale_predicate')) {
    function rx_stats_historical_sale_predicate($alias = '')
    {
        $col = $alias !== '' ? rtrim($alias, '.') . '.' : '';
        return "{$col}Status != 'Unpaid' AND {$col}Status != 'Unsuccessful' AND {$col}Status != 'removedbyadmin' AND {$col}Status != 'removebyuser' AND {$col}Status != 'removeTime' AND {$col}Status != 'removevolume' AND {$col}name_product != 'سرویس تست'";
    }
}

if (!function_exists('rx_stats_operational_service_predicate')) {
    function rx_stats_operational_service_predicate($alias = '')
    {
        $col = $alias !== '' ? rtrim($alias, '.') . '.' : '';
        return "({$col}Status = 'active' OR {$col}Status = 'end_of_time' OR {$col}Status = 'end_of_volume' OR {$col}Status = 'sendedwarn' OR {$col}Status = 'send_on_hold') AND {$col}name_product != 'سرویس تست'";
    }
}

if (!function_exists('rx_stats_real_payment_predicate')) {
    function rx_stats_real_payment_predicate($alias = '')
    {
        $col = $alias !== '' ? rtrim($alias, '.') . '.' : '';
        return "{$col}payment_Status = 'paid' AND {$col}Payment_Method NOT IN ('add balance by admin', 'low balance by admin')";
    }
}

if (!function_exists('rx_stats_total_users')) {
    function rx_stats_total_users(\PDO $pdo)
    {
        $stmt = $pdo->query("SELECT COUNT(*) FROM user");
        return (int) $stmt->fetchColumn();
    }
}

if (!function_exists('rx_stats_total_sales_count')) {
    function rx_stats_total_sales_count(\PDO $pdo)
    {
        $predicate = rx_stats_historical_sale_predicate();
        $stmt = $pdo->query("SELECT COUNT(*) FROM invoice WHERE {$predicate}");
        return (int) $stmt->fetchColumn();
    }
}

if (!function_exists('rx_stats_total_sales_amount')) {
    function rx_stats_total_sales_amount(\PDO $pdo)
    {
        $predicate = rx_stats_historical_sale_predicate();
        $stmt = $pdo->query("SELECT COALESCE(SUM(price_product),0) FROM invoice WHERE {$predicate}");
        return (float) $stmt->fetchColumn();
    }
}

if (!function_exists('rx_stats_paying_users_count')) {
    function rx_stats_paying_users_count(\PDO $pdo)
    {
        $predicate = rx_stats_historical_sale_predicate();
        $stmt = $pdo->query("SELECT COUNT(DISTINCT id_user) FROM invoice WHERE {$predicate}");
        return (int) $stmt->fetchColumn();
    }
}

if (!function_exists('rx_stats_sales_between')) {
    function rx_stats_sales_between(\PDO $pdo, $from, $to)
    {
        $predicate = rx_stats_historical_sale_predicate();
        $stmt = $pdo->prepare("SELECT COUNT(*) AS count, COALESCE(SUM(price_product),0) AS sum FROM invoice WHERE time_sell >= :from AND time_sell < :to AND {$predicate}");
        $stmt->bindValue(':from', $from);
        $stmt->bindValue(':to', $to);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return [
            'count' => (int) ($row['count'] ?? 0),
            'sum'   => (float) ($row['sum'] ?? 0),
        ];
    }
}

if (!function_exists('rx_stats_income_between')) {
    function rx_stats_income_between(\PDO $pdo, $from, $to)
    {
        return rx_stats_sales_between($pdo, $from, $to)['sum'];
    }
}

if (!function_exists('rx_stats_orders_between')) {
    function rx_stats_orders_between(\PDO $pdo, $from, $to)
    {
        return rx_stats_sales_between($pdo, $from, $to)['count'];
    }
}

if (!function_exists('rx_stats_extend_paid_sum')) {
    function rx_stats_extend_paid_sum(\PDO $pdo)
    {
        $stmt = $pdo->query("SELECT SUM(price) FROM service_other WHERE type = 'extend_user' AND status = 'paid'");
        $result = $stmt->fetchColumn();
        return $result !== false && $result !== null ? (float) $result : 0.0;
    }
}

if (!function_exists('rx_stats_extend_paid_between')) {
    function rx_stats_extend_paid_between(\PDO $pdo, $from, $to)
    {
        $stmt = $pdo->prepare("SELECT COUNT(*) AS count, SUM(price) AS sum FROM service_other WHERE time BETWEEN :from AND :to AND type = 'extend_user' AND status = 'paid'");
        $stmt->bindValue(':from', $from);
        $stmt->bindValue(':to', $to);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return [
            'count' => (int) ($row['count'] ?? 0),
            'sum'   => (float) ($row['sum'] ?? 0),
        ];
    }
}

if (!function_exists('rx_stats_real_payment_totals')) {
    function rx_stats_real_payment_totals(\PDO $pdo)
    {
        $predicate = rx_stats_real_payment_predicate();
        $stmt = $pdo->query("SELECT SUM(price) AS sumpay, Payment_Method, COUNT(price) AS countpay FROM Payment_report WHERE {$predicate} GROUP BY Payment_Method");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('rx_stats_wallet_balance_breakdown')) {
    /**
     * FX-ACCOUNT (2026-10-27): the REAL source of the global "bot balance" line
     * in the main-bot overall report.
     *
     * It is the algebraic sum of EVERY user wallet in the database:
     *   net = SUM(user.Balance)            (positive + negative)
     *   positive = SUM(Balance) where > 0  — total credit users hold at the bot
     *   negative = SUM(Balance) where < 0  — net debt users owe the bot
     *              (in the NM model an agent's negative Balance IS his debt;
     *               reseller wallets live in the same table, so they are
     *               included exactly as stored — no re-derivation is done here)
     *
     * A negative `net` therefore means: total user debt > total user credit.
     * It is NOT "cash in the bot" and NOT a single reseller's account balance.
     *
     * @return array{users:int,net:int,positive:int,negative:int,positive_users:int,negative_users:int,zero_users:int}
     */
    function rx_stats_wallet_balance_breakdown(\PDO $pdo): array
    {
        $row = $pdo->query(
            "SELECT COUNT(*) AS users,
                    COALESCE(SUM(Balance),0) AS net,
                    COALESCE(SUM(CASE WHEN Balance > 0 THEN Balance ELSE 0 END),0) AS positive,
                    COALESCE(SUM(CASE WHEN Balance < 0 THEN Balance ELSE 0 END),0) AS negative,
                    SUM(CASE WHEN Balance > 0 THEN 1 ELSE 0 END) AS positive_users,
                    SUM(CASE WHEN Balance < 0 THEN 1 ELSE 0 END) AS negative_users
             FROM user"
        )->fetch(PDO::FETCH_ASSOC) ?: [];
        $users = (int)($row['users'] ?? 0);
        $posU = (int)($row['positive_users'] ?? 0);
        $negU = (int)($row['negative_users'] ?? 0);
        return [
            'users'          => $users,
            'net'            => (int)round((float)($row['net'] ?? 0)),
            'positive'       => (int)round((float)($row['positive'] ?? 0)),
            'negative'       => (int)round((float)($row['negative'] ?? 0)),
            'positive_users' => $posU,
            'negative_users' => $negU,
            'zero_users'     => max(0, $users - $posU - $negU),
        ];
    }
}
