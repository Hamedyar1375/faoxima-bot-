<?php
/**
 * FX-MYPRICES: Reseller «قیمت‌های من» — per-product retail-price manager
 * for reseller-bot admins (reseller-owner facing, main-bot admins never see it).
 *
 * Entry: admin-hub button «🏷️ قیمت‌های من» (callback fx_myprices_menu) —
 * injected by layouts_1.php ONLY when defined('FX_RESELLER_ID') && FX_RESELLER_ID.
 * Text-wait step: fx_res_my_do_retail (numeric price entry, /cancel to abort).
 *
 * Lists assigned products: wholesale cost | current retail (mine) | profit.
 * Saves via fx_set_retail_price() (fx_retail_override.php, loaded via pricing_fx).
 * Lives OUTSIDE any module manifest → require_once'd live from admin bootstrap.
 * All paths __DIR__-absolute (SKILL RULE 7). Agent-facing sends use the
 * reseller bot's own token (SKILL RULE 6 — though here sender IS on the
 * reseller bot already, we pass fx_reseller_bot_token for correctness).
 */

if (!defined('FX_MYPRICES_LOADED')) {
    define('FX_MYPRICES_LOADED', true);

    /**
     * Gate + dispatch for «قیمت‌های من». Returns true if it consumed the update.
     * Callbacks: fx_myprices_menu, fx_myprice_set_<pid>, fx_myprices_back,
     * fx_myprices_del_<pid>. Text step: fx_res_my_do_retail.
     */
    function fx_myprices_gate($datain, $text, $from_id, $message_id, $user, $backadmin): bool {
        // reseller-bot admins only
        if (!defined('FX_RESELLER_ID') || !FX_RESELLER_ID) return false;

        $rid = (int)FX_RESELLER_ID;
        $step = (string)($user['step'] ?? '');
        $datain = (string)$datain;
        $text = is_string($text) ? trim($text) : '';

        // --- callback dispatch ---
        if ($datain !== '') {
            // FX-RES-VIS: show/hide product for this reseller's customers
            if (preg_match('/^fx_myvis_(on|off)_(\d+)$/', $datain, $vm)) {
                $pid = (int)$vm[2];
                $hide = $vm[1] === 'off';
                if (function_exists('fx_res_visibility_set') && fx_res_visibility_set($pid, $rid, $hide)) {
                    sendmessage($from_id,
                        $hide ? "🚫 محصول پنهان شد — مشتریان شما دیگر آن را نمی‌بینند." : "👁 محصول فعال شد — مشتریان شما دوباره آن را می‌بینند.",
                        $backadmin, 'HTML', function_exists('fx_reseller_bot_token') ? fx_reseller_bot_token($rid) : null);
                } else {
                    sendmessage($from_id, "❌ تغییر وضعیت ناموفق بود.", $backadmin, 'HTML',
                        function_exists('fx_reseller_bot_token') ? fx_reseller_bot_token($rid) : null);
                }
                fx_myprices_send_list($from_id, $message_id, true);
                return true;
            }
            if ($datain === 'fx_myprices_menu' || $datain === 'fx_products_menu') {
                fx_myprices_send_list($from_id, $message_id);
                return true;
            }
            if (preg_match('/^fx_myprice_set_(\d+)$/', $datain, $m)) {
                $pid = (int)$m[1];
                $prod = fx_myprices_product($pid, $rid);
                if (!$prod) {
                    sendmessage($from_id, "❌ محصول یافت نشد.", $backadmin, 'HTML');
                    return true;
                }
                step('fx_res_my_do_retail', $from_id);
                update("user", "Processing_value", (string)$pid, "id", $from_id);
                $whRaw = (int)($prod['wholesale'] ?? 0);
                $whEff = $whRaw > 0 ? $whRaw : (int)($prod['base_price'] ?? 0);
                $retRaw = (int)($prod['retail'] ?? 0);
                $retDisplay = $retRaw > 0
                    ? number_format($retRaw) . " تومان (اختصاصی شما)"
                    : number_format((int)($prod['base_price'] ?? 0)) . " تومان (قیمت پایه)";
                sendmessage($from_id,
                    "🏷️ قیمت فروش محصول «" . $prod['name_product'] . "» (#" . $pid . ")\n\n" .
                    "💵 هزینه عمده شما: " . number_format($whEff) . " تومان" . ($whRaw > 0 ? "" : " (قیمت پایه)") . "\n" .
                    "💰 قیمت فعلی شما: " . $retDisplay . "\n\n" .
                    "لطفا قیمت فروش جدید را به تومان وارد کنید (فقط عدد):\n" .
                    "❌ انصراف /cancel",
                    $backadmin, 'HTML');
                return true;
            }
            if (preg_match('/^fx_myprices_del_(\d+)$/', $datain, $m)) {
                $pid = (int)$m[1];
                if (function_exists('fx_delete_retail_price')) {
                    fx_delete_retail_price($pid, $rid);
                    sendmessage($from_id, "✅ قیمت اختصاصی این محصول حذف شد — از این به بعد قیمت پایه فروش می‌رود.", $backadmin, 'HTML');
                }
                fx_myprices_send_list($from_id, $message_id, true);
                return true;
            }
            if ($datain === 'fx_myprices_back') {
                fx_res_exit_to_hub($from_id, $message_id);
                return true;
            }
            return false;
        }

        // --- text-wait step: numeric price entry ---
        if ($step === 'fx_res_my_do_retail') {
            if ($text === '/cancel' || $text === 'انصراف') {
                fx_res_exit_to_hub($from_id);
                return true;
            }
            if (!ctype_digit($text)) {
                sendmessage($from_id, "❌ فقط عدد وارد کنید (تومان). انصراف: /cancel", $backadmin, 'HTML');
                return true;
            }
            $pid = (int)($user['Processing_value'] ?? 0);
            if ($pid <= 0) {
                sendmessage($from_id, "❌ محصول مشخص نیست؛ دوباره از «🏷️ قیمت‌های من» وارد شوید.", $backadmin, 'HTML');
                fx_res_exit_to_hub($from_id);
                return true;
            }
            $price = (int)$text;
            if ($price <= 0) {
                sendmessage($from_id, "❌ قیمت باید عددی بزرگ‌تر از صفر باشد. انصراف: /cancel", $backadmin, 'HTML');
                return true;
            }
            $prod = fx_myprices_product($pid, $rid);
            if (!$prod) {
                sendmessage($from_id, "❌ محصول یافت نشد.", $backadmin, 'HTML');
                fx_res_exit_to_hub($from_id);
                return true;
            }
            if (!function_exists('fx_set_retail_price')) {
                sendmessage($from_id, "❌ ماژول قیمت‌گذاری بارگذاری نشده است.", $backadmin, 'HTML');
                fx_res_exit_to_hub($from_id);
                return true;
            }
            $ok = fx_set_retail_price($pid, $rid, $price);
            if ($ok) {
                sendmessage($from_id,
                    "✅ قیمت فروش «" . $prod['name_product'] . "» روی " . number_format($price) . " تومان ثبت شد.\n" .
                    "🛒 از این به بعد مشتریان شما این محصول را با همین قیمت می‌بینند.",
                    $backadmin, 'HTML');
            } else {
                sendmessage($from_id, "❌ خطا در ذخیره قیمت. دوباره تلاش کنید.", $backadmin, 'HTML');
            }
            fx_res_exit_to_hub($from_id);
            return true;
        }

        return false;
    }

    /**
     * Fetch one assigned product + wholesale + current retail for this reseller.
     */
    function fx_myprices_product(int $pid, int $rid): ?array {
        global $pdo;
        if (!$pdo || $pid <= 0 || $rid <= 0) return null;
        try {
            $scope = function_exists('fx_res_product_scope_sql')
                ? fx_res_product_scope_sql('p', ':agent')
                : "(FIND_IN_SET(:agent, REPLACE(p.agent, ' ', '')) OR p.agent IN ('all', 'allusers', 'f', 'n', 'n2') OR p.agent = '')";
            $stmt = $pdo->prepare(
                "SELECT p.id, p.name_product, p.price_product AS base_price, p.fx_wholesale_price AS wholesale, r.retail_price AS retail " .
                "FROM product p LEFT JOIN fx_retail_prices r ON p.id = r.product_id AND r.reseller_id = :rid " .
                "WHERE p.id = :pid AND {$scope} LIMIT 1"
            );
            $stmt->execute([':rid' => $rid, ':pid' => $pid, ':agent' => (string)$rid]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (\Throwable $e) { return null; }
    }

    /**
     * Send (or edit-in-place) the product list with wholesale | my price | profit.
     */
    function fx_myprices_send_list(int $from_id, int $message_id = 0, bool $fresh = false): void {
        global $pdo;
        $rid = (int)FX_RESELLER_ID;

        // wholesale cost helper: fx_wholesale_price if set, else base price
        $wholesaleOf = function(array $row): int {
            $w = (int)($row['wholesale'] ?? 0);
            return $w > 0 ? $w : (int)($row['base_price'] ?? 0);
        };

        $rows = function_exists('fx_get_all_retail_prices') ? fx_get_all_retail_prices($rid) : [];
        $hiddenIds = function_exists('fx_res_visibility_hidden_ids') ? fx_res_visibility_hidden_ids($rid) : []; // FX-RES-VIS
        if (!$rows) {
            try {
                $scope = function_exists('fx_res_product_scope_sql')
                    ? fx_res_product_scope_sql('p', ':agent')
                    : "(FIND_IN_SET(:agent, REPLACE(p.agent, ' ', '')) OR p.agent IN ('all', 'allusers', 'f', 'n', 'n2') OR p.agent = '')";
                $stmt = $pdo->prepare(
                    "SELECT p.id, p.name_product, p.price_product AS base_price, p.fx_wholesale_price AS wholesale, r.retail_price AS retail " .
                    "FROM product p LEFT JOIN fx_retail_prices r ON p.id = r.product_id AND r.reseller_id = :rid " .
                    "WHERE {$scope}"
                );
                $stmt->execute([':rid' => $rid, ':agent' => (string)$rid]);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } catch (\Throwable $e) { $rows = []; }
        }

        $msg = "🛍 محصولات و قیمت‌های من\n━━━━━━━━━━━━━━\n";
        $kb = ['inline_keyboard' => []];
        if (!$rows) {
            $msg .= "📭 هنوز محصولی به ربات شما اختصاص داده نشده است.\nاز «📱 مدیریت ربات‌های نمایندگی» (ربات اصلی) محصول بگیرید.";
        } else {
            $i = 0;
            foreach ($rows as $row) {
                $i++;
                $base  = (int)($row['base_price'] ?? 0);
                $whRaw = (int)($row['wholesale'] ?? 0);
                $whole = $wholesaleOf($row);
                $ret   = (int)($row['retail'] ?? 0);
                $cur   = $ret > 0 ? $ret : $base;
                $profit = $cur - $whole;
                $isHidden = in_array((int)$row['id'], $hiddenIds, true);
                $msg .= "{$i}️⃣ " . (string)$row['name_product'] . " (#" . (int)$row['id'] . ")" . ($isHidden ? " 🚫 (غیرفعال)" : "") . "\n" .
                    "💵 هزینه عمده: " . number_format($whole) . ($whRaw > 0 ? "" : " (قیمت پایه)") . " | " .
                    "💰 فروش من: " . number_format($cur) . ($ret > 0 ? "" : " (پایه)") . " | " .
                    "📈 سود: " . number_format($profit) . "\n\n";
            }
            $msg .= "برای تغییر قیمت هر محصول، دکمه آن را بزنید:";
            foreach ($rows as $row) {
                $isHiddenB = in_array((int)$row['id'], $hiddenIds, true);
                $kb['inline_keyboard'][] = [
                    ['text' => "🏷️ " . mb_substr((string)$row['name_product'], 0, 22) . " (#" . (int)$row['id'] . ")", 'callback_data' => 'fx_myprice_set_' . (int)$row['id']],
                    ['text' => $isHiddenB ? "👁 نمایش" : "🚫 پنهان", 'callback_data' => 'fx_myvis_' . ($isHiddenB ? 'on_' : 'off_') . (int)$row['id']]
                ];
            }
        }
        $kb['inline_keyboard'][] = [
            ['text' => '🏠 بازگشت به منوی مدیریت', 'callback_data' => 'fx_myprices_back']
        ];
        $kbJson = json_encode($kb, JSON_UNESCAPED_UNICODE);

        $sent = false;
        if (!$fresh && $message_id > 0 && function_exists('Editmessagetext')) {
            $sent = @Editmessagetext($from_id, $message_id, $msg, $kbJson, 'HTML');
        }
        if (!$sent) {
            sendmessage($from_id, $msg, $kbJson, 'HTML');
        }
    }

} // FX_MYPRICES_LOADED
