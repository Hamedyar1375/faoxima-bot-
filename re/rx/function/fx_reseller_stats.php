<?php

/**
 * FX-RESELLER stats — time-filtered statistics for the reseller (agent) bot.
 *
 * Mirrors the master bot's time-filtered statistics layout (re/rx/admin/bootstrap_2.php)
 * with an interactive inline filter keyboard, editMessageText updates and strict
 * per-reseller tenant scoping:
 *   user:           FXU_SCOPE (agent = <rid> OR bottype = <reseller token>)
 *   invoice:        FXI_SCOPE (EXISTS tenant user)
 *   service_other:  id_user IN (tenant users)
 *   Payment_report: id_user IN (tenant users)
 *
 * Callbacks (same names as the master stats keyboard):
 *   stat_all_bot / hoursago_stat / today_stat / yesterday_stat /
 *   month_current_stat / month_old_stat / view_stat_time / close_stat
 * Steps: get_time_start / get_time_end
 */

if (!function_exists('fx_res_stats_callbacks')) {
    function fx_res_stats_callbacks()
    {
        return [
            'stat_all_bot',
            'hoursago_stat',
            'today_stat',
            'yesterday_stat',
            'month_current_stat',
            'month_old_stat',
            'view_stat_time',
        ];
    }
}

if (!function_exists('fx_res_stats_is_active')) {
    function fx_res_stats_is_active($datain, $step)
    {
        if (!defined('FX_RESELLER_ID') || !FX_RESELLER_ID) {
            return false;
        }
        return in_array((string) $datain, fx_res_stats_callbacks(), true)
            || in_array((string) $step, ['get_time_start', 'get_time_end'], true);
    }
}

if (!function_exists('fx_res_stats_scopes')) {
    function fx_res_stats_scopes()
    {
        if (!defined('FX_RESELLER_ID') || !FX_RESELLER_ID) {
            return ['1=1', '1=1'];
        }
        $tokenSql = "''";
        if (defined('FX_RESELLER_TOKEN') && FX_RESELLER_TOKEN !== '') {
            $tokenSql = "'" . addslashes((string) FX_RESELLER_TOKEN) . "'";
        }
        $rid = (int) FX_RESELLER_ID;
        $uScope = "(agent = '{$rid}' OR bottype = " . $tokenSql . ")";
        $iScope = "EXISTS (SELECT 1 FROM user ufx WHERE ufx.id = invoice.id_user AND (ufx.agent = '{$rid}' OR ufx.bottype = " . $tokenSql . "))";
        return [$uScope, $iScope];
    }
}

if (!function_exists('fx_res_stats_keyboard')) {
    function fx_res_stats_keyboard()
    {
        return json_encode([
            'inline_keyboard' => [
                [['text' => '⏱ آمار کل', 'callback_data' => 'stat_all_bot']],
                [['text' => '⏱ یک ساعت اخیر', 'callback_data' => 'hoursago_stat']],
                [
                    ['text' => '⛅️ امروز', 'callback_data' => 'today_stat'],
                    ['text' => '☀️ دیروز', 'callback_data' => 'yesterday_stat'],
                ],
                [
                    ['text' => '☀️ ماه فعلی', 'callback_data' => 'month_current_stat'],
                    ['text' => '☁️ ماه قبل', 'callback_data' => 'month_old_stat'],
                ],
                [['text' => '📅 مشاهده آمار در تاریخ مشخص', 'callback_data' => 'view_stat_time']],
                [['text' => '❌ بستن', 'callback_data' => 'close_stat']],
            ],
        ], JSON_UNESCAPED_UNICODE);
    }
}

if (!function_exists('fx_res_stats_window')) {
    function fx_res_stats_window($filter, $customStart = null, $customEnd = null)
    {
        if ($filter === 'custom') {
            $startDay = @strtotime((string) $customStart);
            $endDay = @strtotime((string) $customEnd);
            if ($startDay === false || $endDay === false) {
                return null;
            }
            $startTs = strtotime(date('Y/m/d', $startDay) . ' 00:00:00');
            $endTs = strtotime(date('Y/m/d', $endDay) . ' 23:59:59');
            if ($startTs === false || $endTs === false || $startTs > $endTs) {
                return null;
            }
            $startTs = (int) $startTs;
            $endTs = (int) $endTs;
            return [
                'title' => '🕐 <b>آمار تاریخ انتخابی</b>',
                'range' => date('Y/m/d H:i:s', $startTs) . ' تا ' . date('Y/m/d H:i:s', $endTs),
                'ts_from' => $startTs,
                'ts_to' => $endTs,
                'dt_from' => date('Y/m/d H:i:s', $startTs),
                'dt_to' => date('Y/m/d H:i:s', $endTs),
            ];
        }

        $now = time();
        $yesterday = strtotime('-1 days');
        $defs = [
            'hour' => [
                'title' => '🕐 <b>آمار ۱ ساعت گذشته</b>',
                'range' => false,
                'ts_from' => $now - 3600,
                'ts_to' => $now,
            ],
            'today' => [
                'title' => '🕐 <b>آمار روز فعلی</b>',
                'range' => true,
                'ts_from' => strtotime(date('Y/m/d') . ' 00:00:00'),
                'ts_to' => $now,
            ],
            'yesterday' => [
                'title' => '🕐 <b>آمار روز گذشته</b>',
                'range' => true,
                'ts_from' => strtotime(date('Y/m/d', $yesterday) . ' 00:00:00'),
                'ts_to' => strtotime(date('Y/m/d', $yesterday) . ' 23:59:59'),
            ],
            'month_current' => [
                'title' => '🕐 <b>آمار ماه فعلی</b>',
                'range' => true,
                'ts_from' => strtotime(date('Y/m/d', strtotime('first day of this month')) . ' 00:00:00'),
                'ts_to' => strtotime(date('Y/m/d', strtotime('last day of this month')) . ' 23:59:59'),
            ],
            'month_old' => [
                'title' => '🕐 <b>آمار ماه گذشته</b>',
                'range' => true,
                'ts_from' => strtotime(date('Y/m/d', strtotime('first day of last month')) . ' 00:00:00'),
                'ts_to' => strtotime(date('Y/m/d', strtotime('last day of last month')) . ' 23:59:59'),
            ],
        ];

        if (!isset($defs[$filter])) {
            return null;
        }
        $def = $defs[$filter];
        $tsFrom = (int) $def['ts_from'];
        $tsTo = (int) $def['ts_to'];
        return [
            'title' => $def['title'],
            'range' => $def['range'] ? date('Y/m/d H:i:s', $tsFrom) . ' تا ' . date('Y/m/d H:i:s', $tsTo) : '',
            'ts_from' => $tsFrom,
            'ts_to' => $tsTo,
            'dt_from' => date('Y/m/d H:i:s', $tsFrom),
            'dt_to' => date('Y/m/d H:i:s', $tsTo),
        ];
    }
}

if (!function_exists('fx_res_stats_collect')) {
    function fx_res_stats_collect(\PDO $pdo, array $win, $uScope, $iScope)
    {
        $tenantUsers = "id_user IN (SELECT id FROM user WHERE {$uScope})";
        $salesPred = rx_stats_historical_sale_predicate();
        $activePred = rx_stats_operational_service_predicate();
        $m = [];

        $stmt = $pdo->prepare("SELECT COUNT(*) AS c, COALESCE(SUM(price_product),0) AS s FROM invoice WHERE time_sell BETWEEN :f AND :t AND {$salesPred} AND ({$iScope})");
        $stmt->bindValue(':f', $win['ts_from']);
        $stmt->bindValue(':t', $win['ts_to']);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $m['orders_count'] = (int) ($row['c'] ?? 0);
        $m['orders_sum'] = (float) ($row['s'] ?? 0);

        $stmt = $pdo->prepare("SELECT COUNT(*) AS c FROM invoice WHERE time_sell BETWEEN :f AND :t AND {$activePred} AND ({$iScope})");
        $stmt->bindValue(':f', $win['ts_from']);
        $stmt->bindValue(':t', $win['ts_to']);
        $stmt->execute();
        $m['active_count'] = (int) ($stmt->fetch(PDO::FETCH_ASSOC)['c'] ?? 0);

        $stmt = $pdo->prepare("SELECT COUNT(*) AS c FROM invoice WHERE time_sell BETWEEN :f AND :t AND name_product = 'سرویس تست' AND ({$iScope})");
        $stmt->bindValue(':f', $win['ts_from']);
        $stmt->bindValue(':t', $win['ts_to']);
        $stmt->execute();
        $m['test_count'] = (int) ($stmt->fetch(PDO::FETCH_ASSOC)['c'] ?? 0);

        $stmt = $pdo->prepare("SELECT COUNT(*) AS c FROM user WHERE register BETWEEN :f AND :t AND register != 'none' AND ({$uScope})");
        $stmt->bindValue(':f', $win['ts_from']);
        $stmt->bindValue(':t', $win['ts_to']);
        $stmt->execute();
        $m['users_new'] = (int) ($stmt->fetch(PDO::FETCH_ASSOC)['c'] ?? 0);

        $soStats = static function ($typeSql, $statusSql) use ($pdo, $win, $tenantUsers) {
            $sql = "SELECT COUNT(*) AS c, COALESCE(SUM(price),0) AS s FROM service_other WHERE "
                . $statusSql . " AND " . $typeSql . " AND `time` BETWEEN :f AND :t AND " . $tenantUsers;
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':f', $win['dt_from']);
            $stmt->bindValue(':t', $win['dt_to']);
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return [(int) ($row['c'] ?? 0), (float) ($row['s'] ?? 0)];
        };

        list($m['extend_count'], $m['extend_sum']) = $soStats("type IN ('extend_user','extend_user_by_admin')", "status = 'paid'");
        list($m['extra_volume_count'], $m['extra_volume_sum']) = $soStats("type = 'extra_user'", "1=1");
        list($m['extra_time_count'], $m['extra_time_sum']) = $soStats("type = 'extra_time_user'", "1=1");
        list($m['change_location_count'], $m['change_location_sum']) = $soStats("type = 'change_location'", "1=1");

        $stmt = $pdo->prepare("SELECT Payment_Method, COUNT(*) AS c, COALESCE(SUM(price),0) AS s FROM Payment_report WHERE payment_Status = 'paid' AND `time` BETWEEN :f AND :t AND " . $tenantUsers . " GROUP BY Payment_Method ORDER BY c DESC LIMIT 8");
        $stmt->bindValue(':f', $win['dt_from']);
        $stmt->bindValue(':t', $win['dt_to']);
        $stmt->execute();
        $m['payments'] = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return $m;
    }
}

if (!function_exists('fx_res_stats_filtered_text')) {
    function fx_res_stats_filtered_text(array $win, array $m)
    {
        $lines = [];
        $lines[] = $win['title'];
        $lines[] = '';
        if ($win['range'] !== '') {
            $lines[] = '⏳ بازه تایم  : ' . $win['range'];
            $lines[] = '';
        }
        $lines[] = '🛍 تعداد سفارشات : ' . number_format($m['orders_count']) . ' عدد';
        $lines[] = '💸 جمع مبلغ سفارشات  : ' . number_format($m['orders_sum']) . ' تومان';
        $lines[] = '🧾 سرویس‌های فعال : ' . number_format($m['active_count']) . ' عدد';
        $lines[] = '';
        $lines[] = '🧲 تعداد تمدید  : ' . number_format($m['extend_count']) . ' عدد';
        $lines[] = '💰 جمع مبلغ تمدید: ' . number_format($m['extend_sum']) . ' تومان';
        $lines[] = '';
        $lines[] = '📦 حجم‌های اضافه  :' . number_format($m['extra_volume_count']) . ' عدد';
        $lines[] = '💰 مبلغ حجم‌های اضافه : ' . number_format($m['extra_volume_sum']) . ' تومان';
        $lines[] = '';
        $lines[] = '⏱️ زمان‌های اضافه  : ' . number_format($m['extra_time_count']) . ' عدد';
        $lines[] = '💰 مبلغ زمان‌های اضافه  : ' . number_format($m['extra_time_sum']) . ' تومان';
        $lines[] = '';
        $lines[] = '📍 تغییر لوکیشن  : ' . number_format($m['change_location_count']) . ' عدد';
        $lines[] = '💰 مبلغ تغییر لوکیشن : ' . number_format($m['change_location_sum']) . ' تومان';
        $lines[] = '';
        $lines[] = '🔑 اکانت‌های تست  : ' . number_format($m['test_count']) . ' عدد';
        $lines[] = '👤 تعداد کاربران  : ' . number_format($m['users_new']) . ' نفر';
        if (!empty($m['payments'])) {
            $lines[] = '';
            $lines[] = '<b>پرداخت‌های موفق:</b>';
            foreach ($m['payments'] as $p) {
                $lines[] = '📌 ' . htmlspecialchars((string) ($p['Payment_Method'] ?? '—'), ENT_QUOTES, 'UTF-8')
                    . ': <code>' . number_format((int) $p['c']) . '</code> پرداخت | <code>' . number_format((float) $p['s']) . '</code> تومان';
            }
        }
        return "\n" . implode("\n", $lines) . "\n";
    }
}

if (!function_exists('fx_res_stats_all_time_text')) {
    function fx_res_stats_all_time_text(\PDO $pdo, $uScope, $iScope)
    {
        $fxStats = $pdo->query("SELECT COUNT(*) AS users_count, COALESCE(SUM(Balance),0) AS balance_sum FROM user WHERE {$uScope}")->fetch(PDO::FETCH_ASSOC);
        $fxUsers = (int) ($fxStats['users_count'] ?? 0);
        $fxBalance = (float) ($fxStats['balance_sum'] ?? 0);

        $fxSales = $pdo->query("SELECT COUNT(*) AS sales_count, COALESCE(SUM(price_product),0) AS sales_sum FROM invoice WHERE {$iScope}")->fetch(PDO::FETCH_ASSOC);
        $fxSalesCount = (int) ($fxSales['sales_count'] ?? 0);
        $fxSalesSum = (float) ($fxSales['sales_sum'] ?? 0);

        $fxActive = (int) $pdo->query("SELECT COUNT(*) FROM invoice WHERE " . rx_stats_operational_service_predicate() . " AND {$iScope}")->fetchColumn();
        $fxBuyers = (int) $pdo->query("SELECT COUNT(DISTINCT id_user) FROM invoice WHERE {$iScope}")->fetchColumn();
        $fxTests = (int) $pdo->query("SELECT COUNT(*) FROM invoice WHERE name_product = 'سرویس تست' AND {$iScope}")->fetchColumn();

        $fxTodayStart = (int) strtotime(date('Y-m-d 00:00:00'));
        $fxTodayStmt = $pdo->prepare("SELECT COALESCE(SUM(price_product),0) FROM invoice WHERE time_sell >= :start AND {$iScope}");
        $fxTodayStmt->execute([':start' => $fxTodayStart]);
        $fxTodaySum = (float) $fxTodayStmt->fetchColumn();

        $fxExtendStmt = $pdo->query("SELECT COUNT(*) AS c, COALESCE(SUM(CAST(price AS DECIMAL(20,2))),0) AS s FROM service_other WHERE status = 'paid' AND type IN ('extend_user','extend_user_by_admin') AND id_user IN (SELECT id FROM user WHERE {$uScope})");
        $fxExtend = $fxExtendStmt ? $fxExtendStmt->fetch(PDO::FETCH_ASSOC) : ['c' => 0, 's' => 0];
        $fxExtendCount = (int) ($fxExtend['c'] ?? 0);
        $fxExtendSum = (float) ($fxExtend['s'] ?? 0);

        $fxPanels = (int) $pdo->query("SELECT COUNT(*) FROM marzban_panel WHERE agent = " . (int) FX_RESELLER_ID)->fetchColumn();
        $fxCards = (int) $pdo->query("SELECT COUNT(*) FROM card_number WHERE " . fx_tenant_sql_agent())->fetchColumn();
        $fxChannels = (int) $pdo->query("SELECT COUNT(*) FROM channels WHERE " . fx_tenant_sql_agent())->fetchColumn();

        $fxDebtRow = select("botsaz", "current_debt, debt_ceiling", "id_user", FX_RESELLER_ID, "select", ['cache' => false]);
        $fxDebt = (float) ($fxDebtRow['current_debt'] ?? 0);
        $fxCeiling = (float) ($fxDebtRow['debt_ceiling'] ?? 0);

        // FX-ACCOUNT: unified signed account view (ceiling / B / debt / claim /
        // remaining credit). Replaces the ambiguous "بدهی ربات" wording.
        $fxAcct = function_exists('fx_res_account_state') ? fx_res_account_state((int)FX_RESELLER_ID) : null;
        $fxAcctTxt = '';
        if (is_array($fxAcct) && !empty($fxAcct['ok'])) {
            $fxAcctTxt = "🏦 <b>سقف اعتبار:</b> <code>" . number_format((int)$fxAcct['ceiling']) . "</code> تومان\n"
                . "⚖️ <b>مانده حساب:</b> <code>" . fx_res_fmt_signed((int)$fxAcct['balance']) . "</code> تومان\n"
                . "💸 <b>بدهی شما به ربات اصلی:</b> <code>" . number_format((int)$fxAcct['debt']) . "</code> تومان\n"
                . "💰 <b>طلب شما از ربات اصلی:</b> <code>" . number_format((int)$fxAcct['claim']) . "</code> تومان\n"
                . "🌟 <b>اعتبار بدهی باقی‌مانده:</b> <code>" . number_format((int)$fxAcct['remaining_credit']) . "</code> تومان\n";
        } else {
            $fxAcctTxt = "🧾 <b>بدهی شما به ربات اصلی:</b> <code>" . number_format((int)$fxDebt) . "</code> / سقف <code>" . number_format((int)$fxCeiling) . "</code> تومان\n";
        }

        $fxPayLines = '';
        $fxPayStmt = $pdo->query("SELECT Payment_Method, COUNT(*) AS c, COALESCE(SUM(CAST(price AS DECIMAL(20,2))),0) AS s FROM Payment_report WHERE payment_Status = 'paid' AND id_user IN (SELECT id FROM user WHERE {$uScope}) GROUP BY Payment_Method ORDER BY c DESC LIMIT 8");
        if ($fxPayStmt) {
            foreach ($fxPayStmt->fetchAll(PDO::FETCH_ASSOC) as $fxPayRow) {
                $fxPayLines .= "\n📌 " . htmlspecialchars((string) ($fxPayRow['Payment_Method'] ?? '—'), ENT_QUOTES, 'UTF-8')
                    . ": <code>" . number_format((int) $fxPayRow['c']) . "</code> پرداخت | <code>" . number_format((float) $fxPayRow['s']) . "</code> تومان";
            }
        }

        return "📊 <b>آمار ربات نمایندگی</b>\n"
            . "━━━━━━━━━━━━━━━━━━\n"
            . "👥 <b>کاربران این ربات:</b> <code>" . number_format($fxUsers) . "</code> نفر\n"
            . "💰 <b>موجودی کاربران:</b> <code>" . number_format($fxBalance) . "</code> تومان\n"
            . "🛒 <b>کاربران دارای خرید:</b> <code>" . number_format($fxBuyers) . "</code> نفر\n"
            . "🧪 <b>اکانت‌های تست:</b> <code>" . number_format($fxTests) . "</code> عدد\n\n"
            . "🧾 <b>تعداد فروش:</b> <code>" . number_format($fxSalesCount) . "</code> عدد\n"
            . "🧾 <b>سرویس‌های فعال:</b> <code>" . number_format($fxActive) . "</code> عدد\n"
            . "💵 <b>جمع فروش:</b> <code>" . number_format($fxSalesSum) . "</code> تومان\n"
            . "📅 <b>فروش امروز:</b> <code>" . number_format($fxTodaySum) . "</code> تومان\n"
            . "🔄 <b>تمدیدها:</b> <code>" . number_format($fxExtendCount) . "</code> عدد | <code>" . number_format($fxExtendSum) . "</code> تومان\n\n"
            . "🧩 <b>پنل‌های شما:</b> <code>" . number_format($fxPanels) . "</code> عدد\n"
            . "💳 <b>کارت‌های شما:</b> <code>" . number_format($fxCards) . "</code> عدد\n"
            . "📯 <b>کانال‌های اجباری:</b> <code>" . number_format($fxChannels) . "</code> عدد\n"
            . $fxAcctTxt
            . ($fxPayLines !== '' ? "\n<b>پرداخت‌های موفق:</b>" . $fxPayLines : '')
            . "\n\n<i>این آمار فقط داده‌های همین ربات نمایندگی است.</i>";
    }
}

if (!function_exists('fx_res_stats_handle')) {
    function fx_res_stats_handle($datain, $step, $from_id, $message_id, $user, $text, $pdo, $uScope = null, $iScope = null, $backadmin = null)
    {
        global $textbotlang;

        if (!($pdo instanceof \PDO)) {
            return false;
        }
        if (!is_string($uScope) || $uScope === '' || !is_string($iScope) || $iScope === '') {
            list($uScope, $iScope) = fx_res_stats_scopes();
        }

        $datain = (string) $datain;
        $step = (string) $step;
        $text = trim((string) $text);
        $keyboard = fx_res_stats_keyboard();
        $message_id = (int) $message_id;
        $inDateStep = in_array($step, ['get_time_start', 'get_time_end'], true);

        if ($datain !== '') {
            if ($inDateStep) {
                step('home', $from_id);
            }

            if ($datain === 'stat_all_bot') {
                $allText = fx_res_stats_all_time_text($pdo, $uScope, $iScope);
                if ($message_id > 0) {
                    Editmessagetext($from_id, $message_id, $allText, $keyboard, 'HTML');
                } else {
                    nm_adminInstantReply($from_id, $allText, $keyboard, 'HTML');
                }
                return true;
            }

            $filterMap = [
                'hoursago_stat' => 'hour',
                'today_stat' => 'today',
                'yesterday_stat' => 'yesterday',
                'month_current_stat' => 'month_current',
                'month_old_stat' => 'month_old',
            ];
            if (isset($filterMap[$datain])) {
                $win = fx_res_stats_window($filterMap[$datain]);
                if ($win === null) {
                    return false;
                }
                $metrics = fx_res_stats_collect($pdo, $win, $uScope, $iScope);
                $statText = fx_res_stats_filtered_text($win, $metrics);
                if ($message_id > 0) {
                    Editmessagetext($from_id, $message_id, $statText, $keyboard, 'HTML');
                } else {
                    nm_adminInstantReply($from_id, $statText, $keyboard, 'HTML');
                }
                return true;
            }

            if ($datain === 'view_stat_time') {
                if ($message_id > 0) {
                    savedata("save", "fxr_stat_msg_id", (string) $message_id);
                }
                step("get_time_start", $from_id);
                $promptTpl = isset($textbotlang['Admin']['getstats']) && is_string($textbotlang['Admin']['getstats'])
                    ? $textbotlang['Admin']['getstats']
                    : 'در صورتی که می خواهید آمار را در بازه تاریخی دیگیری ببینید  ابتدا تاریخ شروع را ارسال کنید .\nمثال: \n<code>%s</code>';
                nm_adminInstantReply($from_id, sprintf($promptTpl, date('Y/m/d')), $keyboard, 'HTML');
                return true;
            }

            return false;
        }

        if ($step === 'get_time_start' || $step === 'get_time_end') {
            if ($text === '') {
                return false;
            }
            if (!isValidDate($text)) {
                nm_adminInstantReply($from_id, '❌ تاریخ باید معتبر باشد. مثال: <code>' . date('Y/m/d') . '</code>', $keyboard, 'HTML');
                return true;
            }
            if ($step === 'get_time_start') {
                savedata("save", "fxr_stat_start", date('Y/m/d', strtotime($text)));
                step("get_time_end", $from_id);
                nm_adminInstantReply($from_id, 'تاریخ پایان را ارسال کنید بطور مثال :  \n<code>' . date('Y/m/d') . '</code>', $keyboard, 'HTML');
                return true;
            }

            $procRow = select("user", "Processing_value", "id", $from_id, "select", ['cache' => false]);
            $proc = json_decode((string) (is_array($procRow) ? ($procRow['Processing_value'] ?? '') : ''), true);
            if (!is_array($proc)) {
                $proc = [];
            }
            $startDate = $proc['fxr_stat_start'] ?? null;
            if (!is_string($startDate) || $startDate === '' || strtotime($startDate) === false) {
                step('home', $from_id);
                nm_adminInstantReply($from_id, '❌ اطلاعات تاریخ شروع منقضی شده است. از دکمه «📅 مشاهده آمار در تاریخ مشخص» دوباره شروع کنید.', $keyboard, 'HTML');
                return true;
            }
            $statMsgId = (int) ($proc['fxr_stat_msg_id'] ?? 0);
            $win = fx_res_stats_window('custom', $startDate, date('Y/m/d', strtotime($text)));
            if ($win === null) {
                step('home', $from_id);
                nm_adminInstantReply($from_id, '❌ بازه تاریخ نامعتبر است. تاریخ شروع باید قبل از تاریخ پایان باشد.', $keyboard, 'HTML');
                return true;
            }
            $metrics = fx_res_stats_collect($pdo, $win, $uScope, $iScope);
            $statText = fx_res_stats_filtered_text($win, $metrics);
            if ($statMsgId > 0) {
                Editmessagetext($from_id, $statMsgId, $statText, $keyboard, 'HTML');
            } else {
                nm_adminInstantReply($from_id, $statText, $keyboard, 'HTML');
            }
            step('home', $from_id);
            return true;
        }

        return false;
    }
}
