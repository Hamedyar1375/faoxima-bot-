<?php

declare(strict_types=1);

require_once __DIR__ . '/BaseHandler.php';

final class VerifyHandler
{
    public static function run(): void
    {
        global $APIKEY, $pdo;

        if (!is_string($APIKEY) || $APIKEY === '') {
            FaoximaLogger::critical('Bot APIKEY is not configured');
            self::sendVerifyError(500, 'Server is not configured (missing bot token)');
        }

        $candidates = FaoximaAuth::collectInitDataCandidates();
        if (empty($candidates)) {
            self::sendVerifyError(400, 'Telegram init data is missing or invalid');
        }

        $userData = null;
        $resellerId = 0;
        $resellerIsAdmin = false;
        $resellerToken = '';
        $lastException = null;

        $tenantHint = self::requestTenantHint();

        // 1) Explicit tenant: validate with that reseller bot token only.
        if ($tenantHint !== null && ($pdo instanceof PDO)) {
            foreach ($candidates as $candidate) {
                $resolved = self::tryResolveReseller($candidate, $pdo, $tenantHint);
                if ($resolved !== null) {
                    $userData = $resolved['user'];
                    $resellerId = (int) $resolved['reseller_id'];
                    $resellerIsAdmin = !empty($resolved['is_admin']);
                    $resellerToken = (string) ($resolved['reseller_token'] ?? '');
                    break;
                }
            }
        }

        // 2) Main-bot token. This runs for every request without a resolved
        // reseller, and also as a safe fallback when a stale/invalid tenant hint
        // was present but the initData is actually signed by the main bot.
        if ($userData === null) {
            foreach ($candidates as $candidate) {
                try {
                    $userData = FaoximaAuth::validateInitData($candidate, $APIKEY);
                    break;
                } catch (InvalidArgumentException $e) {
                    $lastException = $e;
                    continue;
                } catch (RuntimeException $e) {
                    $lastException = $e;
                    continue;
                }
            }
        }

        // 3) Legacy reseller links without ?tenant: identify the reseller by its
        // signed bot token. This keeps older BotFather Mini App URLs working.
        if ($userData === null && ($pdo instanceof PDO)) {
            foreach ($candidates as $candidate) {
                $resolved = self::tryResolveReseller($candidate, $pdo, null);
                if ($resolved !== null) {
                    $userData = $resolved['user'];
                    $resellerId = (int) $resolved['reseller_id'];
                    $resellerIsAdmin = !empty($resolved['is_admin']);
                    $resellerToken = (string) ($resolved['reseller_token'] ?? '');
                    break;
                }
            }
        }

        // If this is a main-bot verification but the initData explicitly names a
        // different bot, reject it. This blocks stale/mixed reseller initData from
        // being accepted in the main context (and vice versa).
        $requestBot = self::requestBotIdentity($candidates);
        if ($userData !== null && (int)$resellerId === 0 && is_array($requestBot)) {
            $mainBotUsername = trim((string)($GLOBALS['usernamebot'] ?? ''));
            $requestBotUsername = trim((string)($requestBot['username'] ?? ''));
            if ($mainBotUsername !== '' && $requestBotUsername !== '' && strcasecmp($mainBotUsername, $requestBotUsername) !== 0) {
                self::sendVerifyError(403, 'This Mini App link belongs to another bot. Please open it from the correct bot.');
            }
        }

        if ($userData === null) {
            $message = $lastException ? $lastException->getMessage() : 'Telegram init data is missing or invalid';
            $status = $lastException instanceof RuntimeException ? 403 : 400;
            FaoximaLogger::warn('initData verification failed', ['msg' => $message, 'tenant' => $tenantHint]);
            self::sendVerifyError($status, $message);
        }

        $userId = (int)$userData['id'];
        $userRecord = select('user', '*', 'id', $userId, 'select');

        if ($resellerId > 0) {
            // Bind this request to the authenticated reseller tenant so channel
            // gates and PaySetting reads use the reseller bot's configuration.
            if ($resellerToken !== '') {
                $GLOBALS['APIKEY'] = $resellerToken;
                $APIKEY = $resellerToken;
            }
            if (function_exists('fx_set_tenant_agent_id')) {
                fx_set_tenant_agent_id($resellerId);
            }

            if ($resellerIsAdmin) {
                // Reseller self-service context: register the admin only if missing
                // (as a tenant user) and skip the customer gates (force-join/phone).
                if (empty($userRecord) || !is_array($userRecord)) {
                    $userRecord = self::registerResellerUser($userId, $userData, $resellerId);
                    if (empty($userRecord) || !is_array($userRecord)) {
                        FaoximaLogger::warn('Reseller admin registration failed', ['tg_id' => $userId, 'reseller_id' => $resellerId]);
                        self::sendVerifyError(500, 'Failed to register reseller account');
                    }
                }
                self::finalizeReseller($userId, is_array($userRecord) ? $userRecord : [], $resellerId);
                return;
            }

            // Normal customer of a reseller bot: initData is signed with the
            // reseller bot token, so authenticate them as a tenant customer and
            // then apply the normal phone/force-join gates.
            if (empty($userRecord) || !is_array($userRecord)) {
                $userRecord = self::registerResellerUser($userId, $userData, $resellerId);
                if (empty($userRecord) || !is_array($userRecord)) {
                    FaoximaLogger::warn('Reseller customer registration failed', ['tg_id' => $userId, 'reseller_id' => $resellerId]);
                    self::sendVerifyError(500, 'Failed to register reseller customer');
                }
            } else {
                // Legacy rows created in the main bot may still have a group
                // agent (f/n/n2). Bind those to the reseller tenant; never move
                // a user who already belongs to a different numeric tenant.
                $currentAgent = trim((string) ($userRecord['agent'] ?? ''));
                if ($currentAgent === '' || in_array($currentAgent, ['f', 'n', 'n2', 'none', '0'], true)) {
                    update('user', 'agent', (string) $resellerId, 'id', $userId);
                    $userRecord['agent'] = (string) $resellerId;
                }
            }

            self::finalizeForUser($userId, $userData, is_array($userRecord) ? $userRecord : [], (int) $resellerId);
            return;
        }

        // Main-bot customer context (unchanged).
        if (empty($userRecord)) {
            $userRecord = self::autoRegisterUser($userId, $userData);
            if (empty($userRecord) || !is_array($userRecord)) {
                FaoximaLogger::warn('Auto-registration failed for miniapp user', ['tg_id' => $userId]);
                self::sendVerifyError(500, 'Failed to register user');
            }
        }

        self::finalizeForUser($userId, $userData, is_array($userRecord) ? $userRecord : []);
    }

    /**
     * FX-RESELLER-SVCMGMT: resolve a reseller-bot initData candidate. Returns
     * ['user'=>userData,'reseller_id'=>int,'reseller_token'=>string] or null.
     */
    private static function tryResolveReseller($candidate, PDO $pdo, $tenantHint = null): ?array
    {
        if (is_array($candidate)) {
            $initData = $candidate;
        } else {
            $raw = trim((string) $candidate);
            if ($raw === '') {
                return null;
            }
            parse_str($raw, $initData);
        }
        if (!is_array($initData)) {
            return null;
        }

        $rows = [];
        $hasTenantHint = is_numeric($tenantHint) && (int) $tenantHint > 0;
        $botRaw = $initData['bot'] ?? null;
        $botData = is_string($botRaw) ? json_decode($botRaw, true) : (is_array($botRaw) ? $botRaw : null);
        if ($hasTenantHint) {
            // Explicit tenant wins over any (optional) bot field.
            $stmt = $pdo->prepare("SELECT id_user, bot_token, admin_ids, username FROM botsaz WHERE id_user = :id LIMIT 1");
            $stmt->execute([':id' => (string) (int) $tenantHint]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (is_array($row)) {
                $rows[] = $row;
            } else {
                return null;
            }
        } elseif (is_array($botData) && !empty($botData['username'])) {
            $stmt = $pdo->prepare("SELECT id_user, bot_token, admin_ids, username FROM botsaz WHERE username = :u LIMIT 1");
            $stmt->execute([':u' => (string) $botData['username']]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (is_array($row)) {
                $rows[] = $row;
            }
        }

        // Legacy compatibility: only when no explicit tenant or bot hint exists,
        // try available reseller tokens. Main-bot requests never enter this path
        // because run() only calls this resolver when a tenant hint is present.
        if (empty($rows) && !$hasTenantHint && !is_array($botData)) {
            $stmt = $pdo->query("SELECT id_user, bot_token, admin_ids, username FROM botsaz WHERE bot_token IS NOT NULL AND bot_token <> '' ORDER BY id ASC LIMIT 200");
            $rows = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        }

        foreach ($rows as $brow) {
            if (!is_array($brow) || empty($brow['bot_token'])) {
                continue;
            }
            $resellerToken = (string) $brow['bot_token'];
            try {
                $user = FaoximaAuth::validateInitData($candidate, $resellerToken);
            } catch (Throwable $e) {
                continue;
            }
            if (!is_array($user)) {
                continue;
            }
            $userId = (int) ($user['id'] ?? 0);
            if ($userId <= 0) {
                continue;
            }
            $rid = (string) $brow['id_user'];
            $adminIds = json_decode((string) ($brow['admin_ids'] ?? '[]'), true);
            $adminIds = is_array($adminIds) ? array_map('strval', $adminIds) : [];
            $isAdmin = ((string) $userId === $rid) || in_array((string) $userId, $adminIds, true);
            return [
                'user' => $user,
                'reseller_id' => (int) $brow['id_user'],
                'reseller_token' => $resellerToken,
                'is_admin' => $isAdmin,
            ];
        }

        return null;
    }

    private static function requestBotIdentity(array $candidates): ?array
    {
        foreach ($candidates as $candidate) {
            if (is_array($candidate)) {
                $initData = $candidate;
            } elseif (is_string($candidate)) {
                parse_str(trim($candidate), $initData);
            } else {
                continue;
            }
            $botRaw = $initData['bot'] ?? null;
            $bot = is_array($botRaw) ? $botRaw : (is_string($botRaw) ? json_decode($botRaw, true) : null);
            if (is_array($bot)) {
                return $bot;
            }
        }
        return null;
    }

    private static function requestTenantHint(): ?string
    {
        foreach ([
            $_POST['tenant'] ?? null,
            $_GET['tenant'] ?? null,
            $_SERVER['HTTP_X_FX_TENANT'] ?? null,
        ] as $value) {
            if (is_scalar($value) && ctype_digit(trim((string) $value)) && (int) $value > 0) {
                return (string) (int) $value;
            }
        }
        if (class_exists('FaoximaInput')) {
            try {
                $payload = FaoximaInput::payload();
                $value = is_array($payload) ? ($payload['tenant'] ?? null) : null;
                if (is_scalar($value) && ctype_digit(trim((string) $value)) && (int) $value > 0) {
                    return (string) (int) $value;
                }
            } catch (Throwable $e) {
            }
        }
        return null;
    }

    public static function finalizeReseller(int $userId, array $userRecord, int $resellerId): void
    {
        try {
            $existingToken = (string) ($userRecord['token'] ?? '');
            $token = $existingToken !== '' ? $existingToken : FaoximaAuth::issueToken($userId);
        } catch (Throwable $e) {
            FaoximaLogger::exception($e, 'Failed to issue token');
            self::sendVerifyError(500, 'Failed to generate session token');
        }
        FaoximaAuth::storeSessionTenant((string) $token, $userId, (int) $resellerId);
        FaoximaLogger::debug('Reseller admin verified', ['user_id' => $userId, 'reseller_id' => $resellerId]);
        self::emit(200, [
            'status' => true,
            'msg' => 'Reseller verified',
            'token' => $token,
            'reseller_id' => $resellerId,
            'is_reseller_admin' => true,
        ]);
    }

    private static function registerResellerUser(int $userId, array $userData, int $resellerId): ?array
    {
        global $pdo, $setting;

        if (!($pdo instanceof PDO)) {
            return null;
        }
        if (!is_array($setting)) {
            $setting = select('setting', '*', null, null, 'select');
        if (defined('FX_RESELLER_ID') && function_exists('rx_reseller_apply_setting_overrides')) { $setting = rx_reseller_apply_setting_overrides($setting); }
        }
        if (!is_array($setting)) {
            return null;
        }

        $username     = isset($userData['username']) ? (string) $userData['username'] : '';
        $date         = time();
        $valueverify  = (($setting['verifystart'] ?? '') !== 'onverify') ? 1 : 0;
        $randomString = bin2hex(random_bytes(6));
        $agent        = (string) $resellerId;

        try {
            $stmt = $pdo->prepare("INSERT IGNORE INTO user (id, step, limit_usertest, User_Status, number, Balance, pagenumber, username, agent, message_count, last_message_time, affiliates, affiliatescount, cardpayment, number_username, namecustom, register, verify, codeInvitation, pricediscount, maxbuyagent, joinchannel, score, status_cron, roll_Status, Processing_value, Processing_value_one, Processing_value_tow, Processing_value_four) VALUES (:from_id, 'none', :limit_usertest_all, 'Active', 'none', '0', '1', :username, :agent, '0', '0', '0', '0', :showcard, '100', 'none', :date, :verifycode, :codeInvitation, '0', '0', '0', '0', '1', :roll_status, :processing_value, :processing_value_one, :processing_value_tow, :processing_value_four)");
            $stmt->bindValue(':from_id', $userId);
            $stmt->bindValue(':limit_usertest_all', $setting['limit_usertest_all'] ?? null);
            $stmt->bindValue(':username', $username);
            $stmt->bindValue(':agent', $agent);
            $stmt->bindValue(':showcard', $setting['showcard'] ?? null);
            $stmt->bindValue(':date', $date);
            $stmt->bindValue(':verifycode', $valueverify);
            $stmt->bindValue(':codeInvitation', $randomString);
            $stmt->bindValue(':roll_status', '0');
            $stmt->bindValue(':processing_value', '0');
            $stmt->bindValue(':processing_value_one', 'none');
            $stmt->bindValue(':processing_value_tow', 'none');
            $stmt->bindValue(':processing_value_four', '0');
            $stmt->execute();
        } catch (Throwable $e) {
            FaoximaLogger::exception($e, 'Reseller admin insert failed');
            return null;
        }

        if (function_exists('clearSelectCache')) {
            clearSelectCache('user');
        }

        $userRecord = select('user', '*', 'id', $userId, 'select', ['cache' => false]);
        FaoximaLogger::debug('Registered reseller user for miniapp', ['tg_id' => $userId, 'reseller_id' => $resellerId]);
        return is_array($userRecord) ? $userRecord : null;
    }

    public static function finalizeForUser(int $userId, array $userData, array $userRecord, int $tenantId = 0): void
    {
        $gate = self::evaluateGates($userId, $userRecord);
        if ($gate !== null) {
            self::emit(200, array_merge(['status' => false, 'token' => null], $gate));
            return;
        }

        try {
            $existingToken = (string)($userRecord['token'] ?? '');
            $token = $existingToken !== '' ? $existingToken : FaoximaAuth::issueToken($userId);
        } catch (Throwable $e) {
            FaoximaLogger::exception($e, 'Failed to issue token');
            self::sendVerifyError(500, 'Failed to generate session token');
        }

        FaoximaAuth::storeSessionTenant((string) $token, $userId, (int) $tenantId);
        FaoximaLogger::debug('User verified', ['user_id' => $userId, 'tenant_id' => (int) $tenantId]);

        self::emit(200, [
            'status' => true,
            'msg'    => 'User verified',
            'token'  => $token,
        ]);
    }

    private static function evaluateGates(int $userId, array $userRecord): ?array
    {
        global $setting;

        if (!is_array($setting)) {
            $setting = select('setting', '*', null, null, 'select');
        if (defined('FX_RESELLER_ID') && function_exists('rx_reseller_apply_setting_overrides')) { $setting = rx_reseller_apply_setting_overrides($setting); }
        }
        if (!is_array($setting)) {
            $setting = [];
        }

        $GLOBALS['from_id'] = $userId;

        $adminIds = select('admin', 'id_admin', null, null, 'FETCH_COLUMN', ['cache' => false]);
        $isAdmin = is_array($adminIds) && in_array($userId, array_map('intval', $adminIds), true);

        if (!$isAdmin) {
            $channelsId = select('channels', 'link', null, null, 'FETCH_COLUMN', ['cache' => false]);
            if (is_array($channelsId) && count($channelsId) > 0 && function_exists('channel')) {
                $checkFailed = [];
                $missing = channel($channelsId, $checkFailed);
                if (is_array($missing) && count($missing) === 0 && !empty($checkFailed)) {
                    self::sendVerifyError(503, 'بررسی عضویت در کانال در حال حاضر با خطا مواجه شد؛ لطفاً چند لحظه دیگر دوباره تلاش کنید.');
                }
                if (is_array($missing) && count($missing) > 0) {
                    if (($userRecord['joinchannel'] ?? '') === 'active') {
                        update('user', 'joinchannel', '0', 'id', $userId);
                    }
                    $list = [];
                    foreach ($missing as $chan) {
                        $row = select('channels', '*', 'link', $chan, 'select', ['cache' => false]);
                        $title = is_array($row) && !empty($row['remark']) ? (string)$row['remark'] : (string)$chan;
                        $link  = is_array($row) && !empty($row['linkjoin']) ? (string)$row['linkjoin'] : ('https://t.me/' . ltrim((string)$chan, '@'));
                        $list[] = ['title' => $title, 'link' => $link];
                    }
                    return [
                        'gate'     => 'force_join',
                        'channels' => $list,
                        'msg'      => 'برای استفاده از مینی‌اپ، ابتدا در کانال‌های زیر عضو شوید.',
                    ];
                }
                if (($userRecord['joinchannel'] ?? '') !== 'active') {
                    update('user', 'joinchannel', 'active', 'id', $userId);
                    $userRecord['joinchannel'] = 'active';
                }
            }
        }

        $needPhone  = ((($setting['get_number'] ?? '') === 'onAuthenticationphone') || (($setting['iran_number'] ?? '') === 'onAuthenticationiran'))
            && (($userRecord['number'] ?? 'none') === 'none');
        $needVerify = (($setting['verifystart'] ?? '') === 'onverify')
            && (intval($userRecord['verify'] ?? 0) === 0)
            && !$isAdmin;

        if ($needPhone || $needVerify) {
            return [
                'gate' => 'phone_required',
                'iran' => (($setting['iran_number'] ?? '') === 'onAuthenticationiran'),
                'msg'  => 'برای ادامه، شمارهٔ موبایل خود را تأیید کنید.',
            ];
        }

        return null;
    }

    private static function autoRegisterUser(int $userId, array $userData): ?array
    {
        global $pdo, $setting;

        if (!($pdo instanceof PDO)) {
            FaoximaLogger::critical('Cannot auto-register miniapp user: database connection unavailable');
            return null;
        }

        if (!is_array($setting)) {
            $setting = select('setting', '*', null, null, 'select');
        if (defined('FX_RESELLER_ID') && function_exists('rx_reseller_apply_setting_overrides')) { $setting = rx_reseller_apply_setting_overrides($setting); }
        }
        if (!is_array($setting)) {
            FaoximaLogger::critical('Cannot auto-register miniapp user: settings unavailable');
            return null;
        }

        $username     = isset($userData['username']) ? (string)$userData['username'] : '';
        $date         = time();
        $valueverify  = (($setting['verifystart'] ?? '') !== 'onverify') ? 1 : 0;
        $randomString = bin2hex(random_bytes(6));
        $initialProcessingValue     = '0';
        $initialProcessingValueOne  = 'none';
        $initialProcessingValueTow  = 'none';
        $initialProcessingValueFour = '0';
        $initialRollStatus          = '0';

        try {
            $stmt = $pdo->prepare("INSERT IGNORE INTO user (id , step,limit_usertest,User_Status,number,Balance,pagenumber,username,agent,message_count,last_message_time,affiliates,affiliatescount,cardpayment,number_username,namecustom,register,verify,codeInvitation,pricediscount,maxbuyagent,joinchannel,score,status_cron,roll_Status,Processing_value,Processing_value_one,Processing_value_tow,Processing_value_four) VALUES (:from_id, 'none',:limit_usertest_all,'Active','none','0','1',:username,'f','0','0','0','0',:showcard,'100','none',:date,:verifycode,:codeInvitation,'0','0','0','0','1',:roll_status,:processing_value,:processing_value_one,:processing_value_tow,:processing_value_four)");
            $stmt->bindValue(':from_id', $userId);
            $stmt->bindValue(':limit_usertest_all', $setting['limit_usertest_all'] ?? null);
            $stmt->bindValue(':username', $username);
            $stmt->bindValue(':showcard', $setting['showcard'] ?? null);
            $stmt->bindValue(':date', $date);
            $stmt->bindValue(':verifycode', $valueverify);
            $stmt->bindValue(':codeInvitation', $randomString);
            $stmt->bindValue(':roll_status', $initialRollStatus);
            $stmt->bindValue(':processing_value', $initialProcessingValue);
            $stmt->bindValue(':processing_value_one', $initialProcessingValueOne);
            $stmt->bindValue(':processing_value_tow', $initialProcessingValueTow);
            $stmt->bindValue(':processing_value_four', $initialProcessingValueFour);
            $stmt->execute();
        } catch (Throwable $e) {
            FaoximaLogger::exception($e, 'Auto-registration insert failed');
            return null;
        }

        if (function_exists('clearSelectCache')) {
            clearSelectCache('user');
        }

        $userRecord = select('user', '*', 'id', $userId, 'select', ['cache' => false]);
        if (empty($userRecord) || !is_array($userRecord)) {
            return null;
        }

        FaoximaLogger::debug('Auto-registered new miniapp user', ['tg_id' => $userId]);
        return $userRecord;
    }

    private static function sendVerifyError(int $code, string $msg): void
    {
        self::emit($code, [
            'status' => false,
            'msg'    => $msg,
            'token'  => null,
        ]);
    }

    private static function emit(int $http, array $payload): void
    {
        if (function_exists('__verify_emit')) {
            __verify_emit($http, $payload);
            exit;
        }

        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        if (!headers_sent()) {
            http_response_code($http);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store, max-age=0');
        }
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

