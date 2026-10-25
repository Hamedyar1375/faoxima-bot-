<?php
/**
 * FX-MYPRICES: Reseller «محصولات من» — per-product visibility/pricing manager
 * for reseller-bot admins (reseller-owner facing, main-bot admins never see it).
 *
 * Entry: admin-hub button «🛍 محصولات من» (callback fx_products_menu) —
 * injected by layouts_1.php ONLY when defined('FX_RESELLER_ID') && FX_RESELLER_ID.
 * Text-wait step: fx_res_my_do_retail (numeric price entry; cancel via inline button).
 *
 * Lists assigned products: product | status | price | wholesale (4 columns),
 * with a per-product details page (fx_myprice_dtl_<pid>) showing specs,
 * prices, profit and status plus edit/toggle actions.
 * Saves via fx_set_retail_price() (fx_retail_override.php, loaded via pricing_fx).
 * Lives OUTSIDE any module manifest → require_once'd live from admin bootstrap.
 * All paths __DIR__-absolute (SKILL RULE 7). Agent-facing sends use the
 * reseller bot's own token (SKILL RULE 6 — though here sender IS on the
 * reseller bot already, we pass fx_reseller_bot_token for correctness).
 */

if (!defined('FX_MYPRICES_LOADED')) {
    define('FX_MYPRICES_LOADED', true);

    /** Clean inline cancel keyboard used by the price-entry prompt. */
    function fx_myprices_cancel_keyboard(): string {
        return json_encode([
            'inline_keyboard' => [
                [['text' => '❌ انصراف', 'callback_data' => 'fx_myprices_cancel']],
            ],
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Gate + dispatch for «محصولات من». Returns true if it consumed the update.
     * Callbacks: fx_myprices_menu, fx_myprice_dtl_<pid> (details page),
     * fx_myprice_set_<pid>, fx_myprices_del_<pid>, fx_myvis_on/off_<pid>,
     * fx_myprices_back. Text step: fx_res_my_do_retail.
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
                // scope check: only products actually assigned to this reseller can be toggled
                if (!fx_myprices_product($pid, $rid)) {
                    sendmessage($from_id, "❌ محصول یافت نشد یا به ربات شما اختصاص ندارد.", $backadmin, 'HTML');
                    return true;
                }
                if (!$hide && function_exists('fx_res_product_has_retail_price')
                    && !fx_res_product_has_retail_price($pid, $rid)) {
                    $needPriceKb = json_encode(['inline_keyboard' => [
                        [['text' => '🏷️ ویرایش و تعیین قیمت', 'callback_data' => 'fx_myprice_set_' . $pid]],
                        [['text' => '🔙 بازگشت به لیست', 'callback_data' => 'fx_myprices_menu']],
                    ]], JSON_UNESCAPED_UNICODE);
                    sendmessage($from_id,
                        "برای نمایش این محصول به مشتریان، ابتدا قیمت فروش را تعیین کنید.",
                        $needPriceKb, 'HTML',
                        function_exists('fx_reseller_bot_token') ? fx_reseller_bot_token($rid) : null);
                    return true;
                }
                if (function_exists('fx_res_visibility_set') && fx_res_visibility_set($pid, $rid, $hide)) {
                    sendmessage($from_id,
                        $hide ? "محصول برای مشتریان شما پنهان شد." : "محصول برای مشتریان شما فعال شد.",
                        $backadmin, 'HTML', function_exists('fx_reseller_bot_token') ? fx_reseller_bot_token($rid) : null);
                    // Edit the same message so the status and the hide/show
                    // button update immediately. A second fresh list left the
                    // old message with stale buttons.
                    fx_myprices_send_list($from_id, $message_id, $message_id <= 0);
                } else {
                    sendmessage($from_id, "❌ تغییر وضعیت ناموفق بود.", $backadmin, 'HTML',
                        function_exists('fx_reseller_bot_token') ? fx_reseller_bot_token($rid) : null);
                }
                return true;
            }
            if ($datain === 'fx_myprices_menu' || $datain === 'fx_products_menu') {
                if ($step === 'fx_res_my_do_retail') {
                    step('home', $from_id);
                    update("user", "Processing_value", 0, "id", $from_id);
                }
                fx_myprices_send_list($from_id, $message_id);
                return true;
            }
            if ($datain === 'fx_myprices_cancel') {
                step('home', $from_id);
                update("user", "Processing_value", 0, "id", $from_id);
                fx_myprices_send_list($from_id, $message_id);
                return true;
            }
            // details page: full product info for this reseller
            if (preg_match('/^fx_myprice_dtl_(\d+)$/', $datain, $m)) {
                $pid = (int)$m[1];
                $prod = fx_myprices_product($pid, $rid);
                if (!$prod) {
                    sendmessage($from_id, "❌ محصول یافت نشد یا به ربات شما اختصاص ندارد.", $backadmin, 'HTML');
                    return true;
                }
                $esc = function ($v): string {
                    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
                };
                $whRaw = (int)($prod['wholesale'] ?? 0);
                $whEff = $whRaw > 0 ? $whRaw : (int)($prod['base_price'] ?? 0);
                $retRaw = (int)($prod['retail'] ?? 0);
                $hasPrice = $retRaw > 0;
                $profit = $hasPrice ? ($retRaw - $whEff) : 0;
                $isHidden = function_exists('fx_res_visibility_blocked') && fx_res_visibility_blocked($pid);
                $statusText = !$hasPrice
                    ? "بدون قیمت (پنهان برای مشتریان)"
                    : ($isHidden ? "پنهان برای مشتریان" : "فعال");
                $note = trim((string)($prod['note'] ?? ''));
                $msg = "جزئیات محصول\n\n" .
                    "نام: " . $esc($prod['name_product'] ?? '') . " (#" . $pid . ")\n";
                if (($prod['code_product'] ?? '') !== '') $msg .= "کد: " . $esc($prod['code_product']) . "\n";
                if (($prod['category'] ?? '') !== '') $msg .= "دسته: " . $esc($prod['category']) . "\n";
                if (($prod['Volume_constraint'] ?? '') !== '') $msg .= "حجم: " . $esc($prod['Volume_constraint']) . " گیگ\n";
                if (($prod['Service_time'] ?? '') !== '') $msg .= "مدت: " . $esc($prod['Service_time']) . " روز\n";
                if (($prod['Location'] ?? '') !== '') $msg .= "موقعیت: " . $esc($prod['Location']) . "\n";
                if ($note !== '') $msg .= "توضیحات: " . $esc($note) . "\n";
                $msg .= "\nوضعیت: {$statusText}\n" .
                    "قیمت پایه: " . number_format((int)($prod['base_price'] ?? 0)) . " تومان\n";
                if ($whRaw > 0) {
                    $msg .= "هزینه عمده: " . number_format($whEff) . " تومان\n";
                }
                if ($hasPrice) {
                    $msg .= "قیمت فروش شما: " . number_format($retRaw) . " تومان\n" .
                        "سود شما: " . number_format($profit) . " تومان";
                } else {
                    $msg .= "قیمت فروش شما: تعیین نشده";
                }
                $kb = ['inline_keyboard' => [
                    [
                        ['text' => '🏷️ ویرایش و تعیین قیمت', 'callback_data' => 'fx_myprice_set_' . $pid],
                    ],
                    [
                        ['text' => '👁️ تغییر وضعیت', 'callback_data' => 'fx_myvis_' . ($isHidden ? 'on_' : 'off_') . $pid],
                        ['text' => '🔙 بازگشت به لیست', 'callback_data' => 'fx_myprices_menu'],
                    ],
                ]];
                $kbJson = json_encode($kb, JSON_UNESCAPED_UNICODE);
                $sent = false;
                if ($message_id > 0 && function_exists('Editmessagetext')) {
                    $sent = @Editmessagetext($from_id, $message_id, $msg, $kbJson, 'HTML');
                }
                if (!$sent) {
                    sendmessage($from_id, $msg, $kbJson, 'HTML');
                }
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
                $retDisplay = $retRaw > 0 ? number_format($retRaw) . " تومان" : "تعیین نشده";
                $priceMsg = "تعیین قیمت فروش\n\n" .
                    "محصول: " . $prod['name_product'] . " (#" . $pid . ")\n" .
                    "قیمت پایه: " . number_format((int)($prod['base_price'] ?? 0)) . " تومان\n";
                if ($whRaw > 0) {
                    $priceMsg .= "هزینه عمده: " . number_format($whEff) . " تومان\n";
                }
                $priceMsg .= "قیمت فعلی شما: " . $retDisplay . "\n\n" .
                    "قیمت فروش جدید را به تومان ارسال کنید.";
                sendmessage($from_id, $priceMsg, fx_myprices_cancel_keyboard(), 'HTML');
                return true;
            }
            if (preg_match('/^fx_myprices_del_(\d+)$/', $datain, $m)) {
                $pid = (int)$m[1];
                if (!fx_myprices_product($pid, $rid)) {
                    sendmessage($from_id, "❌ محصول یافت نشد یا به ربات شما اختصاص ندارد.", $backadmin, 'HTML');
                    return true;
                }
                if (function_exists('fx_delete_retail_price')) {
                    fx_delete_retail_price($pid, $rid);
                    sendmessage($from_id, "قیمت فروش حذف شد. این محصول تا تعیین قیمت جدید برای مشتریان شما پنهان است.", $backadmin, 'HTML');
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

        // --- reply-keyboard text entry ---
        // When inlinebtnmain=off, rx_kb_encode() converts buttons to plain reply
        // text and drops callback_data. Handle the restored «محصولات من» menu
        // here so reseller admins can still open the manager in both modes.
        if ($text !== '' && $step !== 'fx_res_my_do_retail') {
            $cleanMenuText = function_exists('rx_cleanEmojiAndSymbols')
                ? rx_cleanEmojiAndSymbols($text)
                : trim($text);
            $menuAliases = [
                'محصولات من',
                'مدیریت محصولات من',
                'قیمتهای من',
                'قیمت های من',
                'قیمت من',
            ];
            if (in_array($cleanMenuText, $menuAliases, true)) {
                fx_myprices_send_list($from_id, $message_id);
                return true;
            }
        }

        // --- text-wait step: numeric price entry ---
        if ($step === 'fx_res_my_do_retail') {
            if ($text === '/cancel' || $text === 'انصراف') {
                fx_res_exit_to_hub($from_id);
                return true;
            }
            if (!ctype_digit($text)) {
                sendmessage($from_id, "فقط عدد وارد کنید (تومان).", fx_myprices_cancel_keyboard(), 'HTML');
                return true;
            }
            $pid = (int)($user['Processing_value'] ?? 0);
            if ($pid <= 0) {
                sendmessage($from_id, "محصول مشخص نیست؛ دوباره از «محصولات من» وارد شوید.", $backadmin, 'HTML');
                fx_res_exit_to_hub($from_id);
                return true;
            }
            $price = (int)$text;
            if ($price <= 0) {
                sendmessage($from_id, "قیمت باید بزرگ‌تر از صفر باشد.", fx_myprices_cancel_keyboard(), 'HTML');
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
                if (function_exists('fx_res_visibility_set')) {
                    fx_res_visibility_set($pid, $rid, false);
                }
                sendmessage($from_id,
                    "قیمت فروش «" . $prod['name_product'] . "» روی " . number_format($price) . " تومان ثبت شد.\n" .
                    "محصول برای مشتریان شما فعال شد.",
                    $backadmin, 'HTML');
            } else {
                sendmessage($from_id, "خطا در ذخیره قیمت. دوباره تلاش کنید.", fx_myprices_cancel_keyboard(), 'HTML');
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
                : "(FIND_IN_SET(:agent, REPLACE(p.agent, ' ', '')) OR p.agent IN ('all', 'allusers'))";
            $stmt = $pdo->prepare(
                "SELECT p.id, p.name_product, p.price_product AS base_price, p.fx_wholesale_price AS wholesale, " .
                "p.note, p.code_product, p.category, p.Volume_constraint, p.Service_time, p.Location, r.retail_price AS retail " .
                "FROM product p LEFT JOIN fx_retail_prices r ON p.id = r.product_id AND r.reseller_id = :rid " .
                "WHERE p.id = :pid AND {$scope} LIMIT 1"
            );
            $stmt->execute([':rid' => $rid, ':pid' => $pid, ':agent' => (string)$rid]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (\Throwable $e) { return null; }
    }

    /**
     * Send (or edit-in-place) the product list — 4 columns per row:
     * product | status | price | wholesale (owner-requested layout).
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
                    : "(FIND_IN_SET(:agent, REPLACE(p.agent, ' ', '')) OR p.agent IN ('all', 'allusers'))";
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
                // fx_get_all_retail_prices() returns key retail_price; direct SQL aliases it as retail
                $ret   = (int)($row['retail'] ?? $row['retail_price'] ?? 0);
                $cur   = $ret > 0 ? $ret : $base;
                $isHidden = in_array((int)$row['id'], $hiddenIds, true);
                $statusText = $ret > 0 ? ($isHidden ? "پنهان" : "فعال") : "بدون قیمت";
                // 4 columns per owner request: product | status | price | wholesale
                $msg .= "{$i}️⃣ " . (string)$row['name_product'] . " (#" . (int)$row['id'] . ")\n" .
                    $statusText . " | " .
                    "💰 فروش: " . ($ret > 0 ? number_format($cur) : "تعیین نشده") . " | " .
                    "💵 عمده: " . number_format($whole) . ($whRaw > 0 ? "" : " (پایه)") . "\n\n";
            }
            $msg .= "برای مشاهده جزئیات، تغییر قیمت یا وضعیت، دکمه محصول را بزنید:";
            foreach ($rows as $row) {
                $isHiddenB = in_array((int)$row['id'], $hiddenIds, true);
                $kb['inline_keyboard'][] = [
                    ['text' => "📋 " . mb_substr((string)$row['name_product'], 0, 22) . " (#" . (int)$row['id'] . ")", 'callback_data' => 'fx_myprice_dtl_' . (int)$row['id']],
                    ['text' => "👁️ وضعیت", 'callback_data' => 'fx_myvis_' . ($isHiddenB ? 'on_' : 'off_') . (int)$row['id']]
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
