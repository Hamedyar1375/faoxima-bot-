<?php
/**
 * FX-RESELLER-SVCMGMT: reseller «🛠 مدیریت سرویس‌های من» — self-service
 * management of the reseller's OWN panels (marzban_panel) from inside the
 * reseller bot.
 *
 * Four operations, exactly mirroring the main bot's panel management but
 * STRICTLY scoped to this bot's tenant (FX_RESELLER_ID):
 *   🔐 تغییر رمز عبور      -> password_panel  (+ datelogin reset)
 *   👤 تغییر نام کاربری   -> username_panel  (+ datelogin reset)
 *   🔗 آدرس/دامنه پنل      -> url_panel       (+ datelogin reset)
 *   🔗 دامنه لینک ساب      -> linksubx
 *   🖥 سرویس روشن/خاموش    -> status          (active <-> disable)
 *   💰 فروش/تمدید روشن/خاموش -> status_extend  (on_extend <-> off_extend)
 *
 * All reads/writes go through the shared tenant-scoped core
 * (re/rx/function/fx_reseller_service_mgmt.php), which constrains every query
 * to panels owned by FX_RESELLER_ID. A reseller can therefore only ever see or
 * mutate its own panels — never another tenant's.
 *
 * Entry: admin-hub button «🛠 مدیریت سرویس‌های من» (callback fx_res_svc_menu) or
 * the normalized reply text — injected by layouts_1.php ONLY when
 * defined('FX_RESELLER_ID') && FX_RESELLER_ID.
 *
 * Lives OUTSIDE any module manifest → require_once'd live from admin bootstrap.
 *
 * Callbacks (all prefixed fx_res_svc_):
 *   fx_res_svc_menu            panel list
 *   fx_res_svc_exit            back to admin hub
 *   fx_res_svc_panel_<id>      per-panel action screen
 *   fx_res_svc_setpass_<id>    start "new password" text step
 *   fx_res_svc_setuser_<id>    start "new username" text step
 *   fx_res_svc_seturl_<id>     start "new panel url" text step
 *   fx_res_svc_setsub_<id>     start "new sub domain" text step
 *   fx_res_svc_togstatus_<id>  toggle service on/off (status)
 *   fx_res_svc_togextend_<id>  toggle sale/renew on/off (status_extend)
 *
 * Text-entry steps (user.step; panel id kept in user.Processing_value JSON):
 *   fx_res_svc_setpass / fx_res_svc_setuser / fx_res_svc_seturl / fx_res_svc_setsub
 */

if (!defined('FX_RESELLER_SVCMGMT_LOADED')) {
    define('FX_RESELLER_SVCMGMT_LOADED', true);

    if (!function_exists('fx_res_svc_field_steps')) {
        function fx_res_svc_field_steps(): array
        {
            return ['fx_res_svc_setpass', 'fx_res_svc_setuser', 'fx_res_svc_seturl', 'fx_res_svc_setsub'];
        }
    }

    if (!function_exists('fx_res_svc_actor_allowed')) {
        /**
         * The reseller owner (botsaz.id_user) or a delegated reseller admin
         * (botsaz.admin_ids) may manage this reseller's own services.
         */
        function fx_res_svc_actor_allowed($from_id, $rid): bool
        {
            $from_id = (int) $from_id;
            $rid = (int) $rid;
            if ($from_id <= 0 || $rid <= 0) {
                return false;
            }
            if ($from_id === $rid) {
                return true;
            }
            if (!function_exists('select')) {
                return false;
            }
            $row = select("botsaz", "admin_ids", "id_user", (string) $rid, "select", ['cache' => false]);
            $ids = is_array($row) ? json_decode((string) ($row['admin_ids'] ?? '[]'), true) : null;
            if (!is_array($ids)) {
                return false;
            }
            return in_array((string) $from_id, array_map('strval', $ids), true);
        }
    }

    if (!function_exists('fx_res_svc_proc_panel_id')) {
        function fx_res_svc_proc_panel_id($user): int
        {
            $raw = is_array($user) ? (string) ($user['Processing_value'] ?? '') : '';
            $data = json_decode($raw, true);
            if (!is_array($data)) {
                return 0;
            }
            return (int) ($data['panel'] ?? 0);
        }
    }

    if (!function_exists('fx_res_svc_esc')) {
        function fx_res_svc_esc($s): string
        {
            return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        }
    }

    if (!function_exists('fx_res_svc_normalize_url')) {
        /**
         * Accept a bare domain and coerce it to a scheme-prefixed URL so the
         * core's FILTER_VALIDATE_URL check passes. Returns the (trimmed) value.
         */
        function fx_res_svc_normalize_url($s): string
        {
            $s = trim((string) $s);
            if ($s === '') {
                return '';
            }
            if (!preg_match('#^https?://#i', $s)) {
                $s = 'https://' . ltrim($s, '/');
            }
            return $s;
        }
    }

    if (!function_exists('fx_res_svc_public_base')) {
        /**
         * FX-RESELLER-LINKS: public https origin (no trailing slash) used for
         * this tenant's web panel and mini-app links. Returns '' when
         * domainhosts is empty/invalid, so no broken button is ever sent.
         */
        function fx_res_svc_public_base(): string
        {
            $host = rtrim((string) preg_replace('#^https?://#i', '', trim((string) ($GLOBALS['domainhosts'] ?? ''))), '/');
            if ($host === '') {
                return '';
            }
            $base = 'https://' . $host;
            if (filter_var($base . '/', FILTER_VALIDATE_URL) === false
                || strtolower((string) parse_url($base . '/', PHP_URL_SCHEME)) !== 'https'
                || (string) parse_url($base . '/', PHP_URL_HOST) === '') {
                return '';
            }
            return $base;
        }
    }

    if (!function_exists('fx_res_svc_render')) {
        /**
         * Edit-in-place when possible (bot message), else send a new message.
         */
        function fx_res_svc_render(int $from_id, int $message_id, string $msg, ?array $kb): void
        {
            $kbJson = $kb === null ? null : json_encode($kb, JSON_UNESCAPED_UNICODE);
            $sent = false;
            if ($message_id > 0 && function_exists('Editmessagetext')) {
                $sent = @Editmessagetext($from_id, $message_id, $msg, $kbJson, 'HTML');
            }
            if (!$sent) {
                sendmessage($from_id, $msg, $kbJson, 'HTML');
            }
        }
    }

    if (!function_exists('fx_res_svc_kb')) {
        function fx_res_svc_kb(array $rows): ?array
        {
            return empty($rows) ? null : ['inline_keyboard' => array_values(array_filter($rows))];
        }
    }

    if (!function_exists('fx_res_svc_send_link')) {
        /**
         * FX-RESELLER-LINKS: send the tenant's Web Panel (plain URL button) or
         * Mini App (web_app button) as a dedicated inline button. The link is
         * also shown as tappable text for clients that cannot render web_app.
         */
        function fx_res_svc_send_link(int $from_id, int $message_id, string $which): void
        {
            $base = fx_res_svc_public_base();
            if ($base === '') {
                fx_res_svc_render($from_id, $message_id, "⚠️ دامنه عمومی ربات روی سرور تنظیم نشده است؛ امکان ساخت لینک پنل و مینی‌اپ وجود ندارد.", null);
                return;
            }
            if ($which === 'app') {
                $url = $base . '/app/';
                if (defined('FX_RESELLER_ID') && (int) FX_RESELLER_ID > 0) {
                    $url .= '?tenant=' . (int) FX_RESELLER_ID;
                }
                $msg = "🚀 <b>مینی‌اپ مدیریت</b>\n━━━━━━━━━━━━━━\n"
                     . "برای باز کردن مینی‌اپ روی دکمه زیر بزنید.\n\n"
                     . "<a href=\"{$url}\">{$url}</a>";
                $kb = fx_res_svc_kb([[['text' => '🚀 باز کردن مینی‌اپ', 'web_app' => ['url' => $url]]]]);
            } else {
                $url = $base . '/panel/reseller/';
                $msg = "🌐 <b>پنل وب مدیریت نمایندگی</b>\n━━━━━━━━━━━━━━\n"
                     . "برای ورود به پنل روی دکمه زیر بزنید.\n"
                     . "💡 ورود نیازمند نام کاربری و رمز عبور پنل نمایندگی است.\n\n"
                     . "<a href=\"{$url}\">{$url}</a>";
                $kb = fx_res_svc_kb([[['text' => '🌐 ورود به پنل وب نمایندگی', 'url' => $url]]]);
            }
            fx_res_svc_render($from_id, $message_id, $msg, $kb);
        }
    }

    if (!function_exists('fx_res_svc_send_menu')) {
        function fx_res_svc_send_menu(int $from_id, int $message_id = 0): void
        {
            $rid = (int) FX_RESELLER_ID;
            $panels = fx_res_svc_list_panels(null, $rid, 50);
            $msg = "🛠 <b>مدیریت سرویس‌های من</b>\n━━━━━━━━━━━━━━\n";
            if (empty($panels)) {
                $msg .= "\n📭 هیچ پنلی برای شما تعریف نشده است.";
                $kb = fx_res_svc_kb([
                    [['text' => '🏠 بازگشت به منوی مدیریت', 'callback_data' => 'fx_res_svc_exit']],
                ]);
                fx_res_svc_render($from_id, $message_id, $msg, $kb);
                return;
            }
            $msg .= "یک پنل را برای مدیریت انتخاب کنید:\n";
            $rows = [];
            foreach ($panels as $p) {
                $name = (string) ($p['name_panel'] !== '' ? $p['name_panel'] : ('پنل #' . (int) $p['id']));
                $st = ((string) ($p['status'] ?? '') === 'active') ? 'روشن' : 'خاموش';
                $rows[] = [['text' => "🖥 " . fx_res_svc_esc(mb_substr($name, 0, 28)) . "  ({$st})", 'callback_data' => 'fx_res_svc_panel_' . (int) $p['id']]];
            }
            $rows[] = [['text' => '🏠 بازگشت به منوی مدیریت', 'callback_data' => 'fx_res_svc_exit']];
            fx_res_svc_render($from_id, $message_id, $msg, fx_res_svc_kb($rows));
        }
    }

    if (!function_exists('fx_res_svc_send_panel')) {
        function fx_res_svc_send_panel(int $from_id, int $message_id, int $panelId): void
        {
            $rid = (int) FX_RESELLER_ID;
            $row = fx_res_svc_get_panel(null, $rid, $panelId);
            if ($row === null) {
                fx_res_svc_render($from_id, $message_id, "⚠️ این پنل در اختیار شما نیست یا پیدا نشد.", fx_res_svc_kb([
                    [['text' => '⬅️ بازگشت به فهرست', 'callback_data' => 'fx_res_svc_menu']],
                ]));
                return;
            }
            $id = (int) $row['id'];
            $name = (string) ($row['name_panel'] !== '' ? $row['name_panel'] : ('پنل #' . $id));
            $svcOn = ((string) ($row['status'] ?? '') === 'active');
            $extOn = ((string) ($row['status_extend'] ?? '') === 'on_extend');
            $msg = "🖥 <b>" . fx_res_svc_esc($name) . "</b>\n"
                . "━━━━━━━━━━━━━━\n"
                . "🔧 نوع: " . fx_res_svc_esc((string) ($row['type'] ?: '—')) . "\n"
                . "🔗 آدرس پنل: " . fx_res_svc_esc((string) ($row['url_panel'] ?: '—')) . "\n"
                . "👤 نام کاربری: " . fx_res_svc_esc((string) ($row['username_panel'] ?: '—')) . "\n"
                . "🔗 دامنه ساب: " . fx_res_svc_esc((string) (($row['linksubx'] ?? '') ?: ($row['sublink'] ?: '—'))) . "\n"
                . "🖥 سرویس: " . ($svcOn ? 'روشن ✅' : 'خاموش ⛔️') . "\n"
                . "💰 فروش/تمدید: " . ($extOn ? 'روشن ✅' : 'خاموش ⛔️') . "\n"
                . "━━━━━━━━━━━━━━\nیک عملیات انتخاب کنید:";
            $kb = fx_res_svc_kb([
                [
                    ['text' => '🔐 تغییر رمز عبور', 'callback_data' => 'fx_res_svc_setpass_' . $id],
                    ['text' => '👤 تغییر نام کاربری', 'callback_data' => 'fx_res_svc_setuser_' . $id],
                ],
                [
                    ['text' => '🔗 آدرس/دامنه پنل', 'callback_data' => 'fx_res_svc_seturl_' . $id],
                    ['text' => '🔗 دامنه لینک ساب', 'callback_data' => 'fx_res_svc_setsub_' . $id],
                ],
                [
                    ['text' => ($svcOn ? '🖥 سرویس: خاموش کن' : '🖥 سرویس: روشن کن'), 'callback_data' => 'fx_res_svc_togstatus_' . $id],
                    ['text' => ($extOn ? '💰 فروش/تمدید: خاموش کن' : '💰 فروش/تمدید: روشن کن'), 'callback_data' => 'fx_res_svc_togextend_' . $id],
                ],
                [
                    ['text' => '⬅️ بازگشت به فهرست', 'callback_data' => 'fx_res_svc_menu'],
                    ['text' => '🏠 منوی مدیریت', 'callback_data' => 'fx_res_svc_exit'],
                ],
            ]);
            fx_res_svc_render($from_id, $message_id, $msg, $kb);
        }
    }

    if (!function_exists('fx_res_svc_send_field_prompt')) {
        function fx_res_svc_send_field_prompt(int $from_id, int $message_id, string $field, int $panelId): void
        {
            $rid = (int) FX_RESELLER_ID;
            $row = fx_res_svc_get_panel(null, $rid, $panelId);
            if ($row === null) {
                fx_res_svc_render($from_id, $message_id, "⚠️ این پنل در اختیار شما نیست.", fx_res_svc_kb([
                    [['text' => '⬅️ بازگشت به فهرست', 'callback_data' => 'fx_res_svc_menu']],
                ]));
                return;
            }
            $id = (int) $row['id'];
            $name = (string) ($row['name_panel'] !== '' ? $row['name_panel'] : ('پنل #' . $id));
            $prompts = [
                'fx_res_svc_setpass' => "🔐 رمز عبور جدید پنل «" . fx_res_svc_esc($name) . "» را ارسال کنید.",
                'fx_res_svc_setuser' => "👤 نام کاربری جدید پنل «" . fx_res_svc_esc($name) . "» را ارسال کنید.",
                'fx_res_svc_seturl'  => "🔗 آدرس (دامنه) جدید پنل «" . fx_res_svc_esc($name) . "» را ارسال کنید.\nمثال: <code>https://panel.example.com</code>",
                'fx_res_svc_setsub'  => "🔗 دامنه لینک ساب پنل «" . fx_res_svc_esc($name) . "» را ارسال کنید.\nمثال: <code>https://sub.example.com/sub</code>",
            ];
            $msg = $prompts[$field] ?? 'مقدار جدید را ارسال کنید.';
            $kb = fx_res_svc_kb([
                [['text' => '↩️ انصراف و بازگشت', 'callback_data' => 'fx_res_svc_panel_' . $id]],
            ]);
            // remember the target panel for the text-entry step
            if (function_exists('step')) {
                step($field, $from_id);
            }
            if (function_exists('update')) {
                update("user", "Processing_value", json_encode(['panel' => $id]), "id", $from_id);
            }
            fx_res_svc_render($from_id, $message_id, $msg, $kb);
        }
    }

    if (!function_exists('fx_res_svc_apply_field')) {
        /**
         * Apply a text-entry value for a pending field step. Returns true on
         * success. Re-renders the panel action screen afterwards.
         */
        function fx_res_svc_apply_field(int $from_id, int $message_id, string $step, string $text, $user): bool
        {
            $rid = (int) FX_RESELLER_ID;
            $panelId = fx_res_svc_proc_panel_id($user);
            $row = fx_res_svc_get_panel(null, $rid, $panelId);
            $ok = false;
            if ($row !== null) {
                switch ($step) {
                    case 'fx_res_svc_setpass':
                        $ok = fx_res_svc_set_password(null, $rid, $panelId, $text);
                        break;
                    case 'fx_res_svc_setuser':
                        $ok = fx_res_svc_set_username(null, $rid, $panelId, $text);
                        break;
                    case 'fx_res_svc_seturl':
                        $ok = fx_res_svc_set_url(null, $rid, $panelId, fx_res_svc_normalize_url($text));
                        break;
                    case 'fx_res_svc_setsub':
                        $ok = fx_res_svc_set_sublink(null, $rid, $panelId, $text);
                        break;
                }
            }
            // clear the pending step regardless of outcome
            if (function_exists('step')) {
                step('home', $from_id);
            }
            if (function_exists('update')) {
                update("user", "Processing_value", 0, "id", $from_id);
            }
            if (!$ok) {
                fx_res_svc_render($from_id, $message_id, "❌ مقدار واردشده معتبر نیست یا اعمال نشد. دوباره تلاش کنید.", fx_res_svc_kb([
                    [['text' => '⬅️ بازگشت به پنل', 'callback_data' => 'fx_res_svc_panel_' . $panelId]],
                    [['text' => '🏠 منوی مدیریت', 'callback_data' => 'fx_res_svc_exit']],
                ]));
                return false;
            }
            fx_res_svc_render($from_id, $message_id, "✅ با موفقیت اعمال شد.\n", null);
            fx_res_svc_send_panel($from_id, 0, $panelId);
            return true;
        }
    }

    if (!function_exists('fx_res_svc_gate')) {
        /**
         * Gate + dispatch for «مدیریت سرویس‌های من». Returns true when it
         * consumed the update.
         */
        function fx_res_svc_gate($datain, $text, $from_id, $message_id, $user, $backadmin): bool
        {
            if (!defined('FX_RESELLER_ID') || !FX_RESELLER_ID) {
                return false;
            }
            $rid = (int) FX_RESELLER_ID;
            $datain = (string) $datain;
            $text = is_string($text) ? trim($text) : '';
            $from_id = (int) $from_id;
            $step = is_array($user) ? (string) ($user['step'] ?? '') : '';
            $inMyStep = in_array($step, fx_res_svc_field_steps(), true);

            $answer = static function () {
                if (function_exists('fx_res_answer_cb')) {
                    fx_res_answer_cb();
                }
            };

            // ---- text-entry continuation (highest priority while in a step) ----
            if ($datain === '' && $inMyStep && $text !== '') {
                if (!fx_res_svc_actor_allowed($from_id, $rid)) {
                    step('home', $from_id);
                    update("user", "Processing_value", 0, "id", $from_id);
                    fx_res_svc_render($from_id, (int) $message_id, "🔒 شما اجازه مدیریت سرویس‌ها را ندارید.", null);
                    $answer();
                    return true;
                }
                fx_res_svc_apply_field($from_id, (int) $message_id, $step, $text, $user);
                $answer();
                return true;
            }

            // ---- reply-mode entry (typed hub label) ----
            if ($datain === '' && $text === '🛠 مدیریت سرویس‌های من') {
                $datain = 'fx_res_svc_menu';
            }
            if ($datain === '' && $text === '🌐 پنل وب مدیریت') {
                $datain = 'fx_res_svc_link_panel';
            }
            if ($datain === '' && $text === '🚀 مینی‌اپ') {
                $datain = 'fx_res_svc_link_app';
            }
            if ($datain === '') {
                return false;
            }

            $isMyCb = ($datain === 'fx_res_svc_menu'
                || $datain === 'fx_res_svc_exit'
                || $datain === 'fx_res_svc_link_panel'
                || $datain === 'fx_res_svc_link_app'
                || strpos($datain, 'fx_res_svc_panel_') === 0
                || strpos($datain, 'fx_res_svc_setpass_') === 0
                || strpos($datain, 'fx_res_svc_setuser_') === 0
                || strpos($datain, 'fx_res_svc_seturl_') === 0
                || strpos($datain, 'fx_res_svc_setsub_') === 0
                || strpos($datain, 'fx_res_svc_togstatus_') === 0
                || strpos($datain, 'fx_res_svc_togextend_') === 0);
            if (!$isMyCb) {
                return false;
            }

            if (!fx_res_svc_actor_allowed($from_id, $rid)) {
                fx_res_svc_render($from_id, (int) $message_id, "🔒 این بخش مخصوص مدیران این ربات نمایندگی است.", null);
                $answer();
                return true;
            }

            // entering any callback screen clears a pending text step (no cross-talk)
            if (function_exists('step') && $inMyStep) {
                step('home', $from_id);
                update("user", "Processing_value", 0, "id", $from_id);
            }

            if ($datain === 'fx_res_svc_menu') {
                fx_res_svc_send_menu($from_id, (int) $message_id);
                $answer();
                return true;
            }

            if ($datain === 'fx_res_svc_exit') {
                if (function_exists('step')) {
                    step('home', $from_id);
                }
                if (function_exists('fx_res_exit_to_hub')) {
                    fx_res_exit_to_hub($from_id, (int) $message_id);
                }
                $answer();
                return true;
            }

            if ($datain === 'fx_res_svc_link_panel') {
                fx_res_svc_send_link($from_id, (int) $message_id, 'panel');
                $answer();
                return true;
            }

            if ($datain === 'fx_res_svc_link_app') {
                fx_res_svc_send_link($from_id, (int) $message_id, 'app');
                $answer();
                return true;
            }

            if (preg_match('/^fx_res_svc_panel_(\d+)$/', $datain, $m)) {
                fx_res_svc_send_panel($from_id, (int) $message_id, (int) $m[1]);
                $answer();
                return true;
            }
            if (preg_match('/^fx_res_svc_setpass_(\d+)$/', $datain, $m)) {
                fx_res_svc_send_field_prompt($from_id, (int) $message_id, 'fx_res_svc_setpass', (int) $m[1]);
                $answer();
                return true;
            }
            if (preg_match('/^fx_res_svc_setuser_(\d+)$/', $datain, $m)) {
                fx_res_svc_send_field_prompt($from_id, (int) $message_id, 'fx_res_svc_setuser', (int) $m[1]);
                $answer();
                return true;
            }
            if (preg_match('/^fx_res_svc_seturl_(\d+)$/', $datain, $m)) {
                fx_res_svc_send_field_prompt($from_id, (int) $message_id, 'fx_res_svc_seturl', (int) $m[1]);
                $answer();
                return true;
            }
            if (preg_match('/^fx_res_svc_setsub_(\d+)$/', $datain, $m)) {
                fx_res_svc_send_field_prompt($from_id, (int) $message_id, 'fx_res_svc_setsub', (int) $m[1]);
                $answer();
                return true;
            }
            if (preg_match('/^fx_res_svc_togstatus_(\d+)$/', $datain, $m)) {
                $new = fx_res_svc_toggle(null, $rid, (int) $m[1], 'status');
                if ($new === null) {
                    fx_res_svc_render($from_id, (int) $message_id, "⚠️ تغییر وضعیت ممکن نشد.", fx_res_svc_kb([
                        [['text' => '⬅️ بازگشت به فهرست', 'callback_data' => 'fx_res_svc_menu']],
                    ]));
                } else {
                    fx_res_svc_send_panel($from_id, (int) $message_id, (int) $m[1]);
                }
                $answer();
                return true;
            }
            if (preg_match('/^fx_res_svc_togextend_(\d+)$/', $datain, $m)) {
                $new = fx_res_svc_toggle(null, $rid, (int) $m[1], 'status_extend');
                if ($new === null) {
                    fx_res_svc_render($from_id, (int) $message_id, "⚠️ تغییر وضعیت ممکن نشد.", fx_res_svc_kb([
                        [['text' => '⬅️ بازگشت به فهرست', 'callback_data' => 'fx_res_svc_menu']],
                    ]));
                } else {
                    fx_res_svc_send_panel($from_id, (int) $message_id, (int) $m[1]);
                }
                $answer();
                return true;
            }

            return false;
        }
    }

    if (!function_exists('fx_res_svc_is_reseller_admin')) {
        /**
         * FX-RESELLER-RECEIPT-GUARD: true only when running inside a reseller bot
         * (FX_RESELLER_ID defined) AND the actor is the reseller owner
         * (botsaz.id_user) or a delegated reseller admin (botsaz.admin_ids).
         * Always false for the main bot, so main-bot authorization is untouched.
         */
        function fx_res_svc_is_reseller_admin($from_id): bool
        {
            if (!defined('FX_RESELLER_ID') || !FX_RESELLER_ID) {
                return false;
            }
            return fx_res_svc_actor_allowed($from_id, FX_RESELLER_ID);
        }
    }

    if (!function_exists('fx_res_svc_receipt_admin_ok')) {
        /**
         * FX-RESELLER-RECEIPT-GUARD: unified authorization for the receipt
         * approve/reject buttons.
         *
         * Main bot (FX_RESELLER_ID undefined): exactly the legacy rule check on
         * the shared admin table (administrator/Seller) — behaviour unchanged.
         *
         * Reseller bot: true when the actor is a real reseller admin (owner or
         * botsaz.admin_ids), regardless of whether they also have a row in the
         * shared admin table. This is the actual admin configuration of the
         * reseller bot, which is what the section-load gate already uses.
         */
        function fx_res_svc_receipt_admin_ok($adminrulecheck, $from_id): bool
        {
            if (defined('FX_RESELLER_ID') && FX_RESELLER_ID) {
                return fx_res_svc_is_reseller_admin($from_id);
            }
            if (!is_array($adminrulecheck)) {
                return false;
            }
            return in_array($adminrulecheck['rule'] ?? '', ['administrator', 'Seller'], true);
        }
    }

    if (!function_exists('fx_res_svc_receipt_guard_answer')) {
        /**
         * FX-RESELLER-RECEIPT-GUARD: answer receipt-management callbacks that no
         * handler will process for this actor (not a reseller admin → the admin
         * section is not loaded for them at all), so Telegram never leaves the
         * button in the "checking..." state.
         *
         * Reseller-only: no-op for the main bot. Returns true when it answered.
         */
        function fx_res_svc_receipt_guard_answer($datain): bool
        {
            if (!defined('FX_RESELLER_ID') || !FX_RESELLER_ID) {
                return false;
            }
            $datain = (string) $datain;
            if (!preg_match('/^(Confirm_pay_|reject_pay_|addbalamceuser_|blockuserfake_)|^confirmpaid$/', $datain)) {
                return false;
            }
            $from_id = (int) ($GLOBALS['from_id'] ?? 0);
            if (fx_res_svc_is_reseller_admin($from_id)) {
                return false;
            }
            $callback_query_id = (string) ($GLOBALS['callback_query_id'] ?? '');
            if ($callback_query_id === '' || !function_exists('telegram')) {
                return false;
            }
            telegram('answerCallbackQuery', array(
                'callback_query_id' => $callback_query_id,
                'text' => 'شما مجوز مدیریت رسیدها را ندارید.',
                'show_alert' => true,
                'cache_time' => 5,
            ));
            return true;
        }
    }

} // FX_RESELLER_SVCMGMT_LOADED
