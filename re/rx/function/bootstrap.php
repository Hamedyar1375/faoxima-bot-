<?php

if (!defined('APP_ROOT_PATH')) {
    define('APP_ROOT_PATH', REFACTORED_LEGACY_ROOT);
}


$composerAutoload = APP_ROOT_PATH . '/vendor/autoload.php';
if (is_readable($composerAutoload)) {
    require_once $composerAutoload;
    unset($composerAutoload);
} else {
    error_log('Composer autoloader not found. Optional dependencies may be unavailable.');
    unset($composerAutoload);
}
require_once APP_ROOT_PATH . '/config.php';
require_once __DIR__ . '/redis_client.php';

ini_set('error_log', APP_ROOT_PATH . '/error_log');

function getDatabaseConnection()
{
    static $cachedPdo = null;

    if ($cachedPdo instanceof PDO) {
        return $cachedPdo;
    }

    if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
        $cachedPdo = $GLOBALS['pdo'];
        return $cachedPdo;
    }

    $dsn = $GLOBALS['dsn'] ?? null;
    $username = $GLOBALS['usernamedb'] ?? null;
    $password = $GLOBALS['passworddb'] ?? null;
    $options = $GLOBALS['options'] ?? [];

    if (!is_string($dsn) || trim($dsn) === '') {
        // config.php already logs once-per-hour about empty credentials when the
        // installer is gone; logging here too just duplicates that signal. We
        // suppress this branch unless DB creds are filled (meaning the DSN
        // string itself was somehow corrupted at runtime — a real anomaly).
        $rxDbname = $GLOBALS['dbname'] ?? '';
        $rxUser   = $GLOBALS['usernamedb'] ?? '';
        if (is_string($rxDbname) && $rxDbname !== '' && is_string($rxUser) && $rxUser !== '') {
            $rxDsnMarker = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'rx_dsn_missing.flag';
            if (!is_file($rxDsnMarker) || (time() - (int) @filemtime($rxDsnMarker)) > 3600) {
                error_log('getDatabaseConnection: DSN is not configured (creds present — check config.php).');
                @touch($rxDsnMarker);
            }
        }
        return null;
    }

    try {
        $newPdo = function_exists('rx_connect_pdo')
            ? rx_connect_pdo($dsn, (string) $username, (string) $password, is_array($options) ? $options : [])
            : new PDO($dsn, (string) $username, (string) $password, is_array($options) ? $options : []);
        $GLOBALS['pdo'] = $newPdo;
        $cachedPdo = $newPdo;
        return $cachedPdo;
    } catch (PDOException $e) {
        error_log('getDatabaseConnection: Unable to create PDO instance. ' . $e->getMessage());
        return null;
    }
}

if (!defined('TRONADO_API_CONFIGURATION')) {
    $tronadoApiConfiguration = [
        'base_url' => 'https://bot.tronado.cloud',
        'order_token_path' => '/api/v5/GetOrderToken',
        'price_toman_path' => '/Tron/GetPriceToToman',
        'get_status_path' => '/Order/GetStatus',
        'get_status_by_payment_id_path' => '/Order/GetStatusByPaymentID',
    ];

    define('TRONADO_API_CONFIGURATION', $tronadoApiConfiguration);
    unset($tronadoApiConfiguration);
}

if (!defined('TRONADO_ORDER_TOKEN_ENDPOINTS')) {
    $tronadoConfig = TRONADO_API_CONFIGURATION;
    $baseUrl = rtrim((string) ($tronadoConfig['base_url'] ?? ''), '/');
    $path = '/' . ltrim((string) ($tronadoConfig['order_token_path'] ?? ''), '/');

    define('TRONADO_ORDER_TOKEN_ENDPOINTS', $baseUrl !== '' ? [$baseUrl . $path] : []);
    unset($baseUrl, $path, $tronadoConfig);
}

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Label\Font\OpenSans;
use Endroid\QrCode\Label\LabelAlignment;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\PngWriter;


function isShellExecAvailable()
{
    static $isAvailable;

    if ($isAvailable !== null) {
        return $isAvailable;
    }

    if (!function_exists('shell_exec')) {
        $isAvailable = false;
        return $isAvailable;
    }

    $disabledFunctions = ini_get('disable_functions');
    if (!empty($disabledFunctions) && stripos($disabledFunctions, 'shell_exec') !== false) {
        $isAvailable = false;
        return $isAvailable;
    }

    $isAvailable = true;
    return $isAvailable;
}

if (!function_exists('safe_divide')) {


    function safe_divide($numerator, $denominator, $fallback = 0)
    {
        if (!is_numeric($numerator) || !is_numeric($denominator)) {
            return $fallback;
        }

        $denominator = (float) $denominator;
        if ($denominator == 0.0) {
            return $fallback;
        }

        $result = (float) $numerator / $denominator;

        if (!is_finite($result)) {
            return $fallback;
        }

        return $result;
    }
}


function generateReferralCode($length = 12)
{
    $length = max(1, (int) $length);
    $bytes = (int) ceil($length / 2);

    if (function_exists('random_bytes')) {
        try {
            $code = bin2hex(random_bytes($bytes));
            return substr($code, 0, $length);
        } catch (Exception $exception) {
            error_log('Falling back to pseudo-random referral code generator: ' . $exception->getMessage());
        } catch (Error $exception) {
            error_log('Falling back to pseudo-random referral code generator: ' . $exception->getMessage());
        }
    }

    $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $maxIndex = strlen($characters) - 1;
    $code = '';

    for ($i = 0; $i < $length; ++$i) {
        if (function_exists('random_int')) {
            try {
                $index = random_int(0, $maxIndex);
            } catch (Exception $exception) {
                error_log('random_int failed, using mt_rand fallback: ' . $exception->getMessage());
                $index = mt_rand(0, $maxIndex);
            } catch (Error $exception) {
                error_log('random_int failed, using mt_rand fallback: ' . $exception->getMessage());
                $index = mt_rand(0, $maxIndex);
            }
        } else {
            $index = mt_rand(0, $maxIndex);
        }

        $code .= $characters[$index];
    }

    return $code;
}


function ensureUserInvitationCode($userId, $currentCode = null, $length = 12)
{
    if (!is_scalar($userId) || (string) $userId === '') {
        return null;
    }

    $currentCode = is_string($currentCode) ? trim($currentCode) : '';
    if ($currentCode !== '') {
        return $currentCode;
    }

    $newCode = generateReferralCode($length);
    update('user', 'codeInvitation', $newCode, 'id', (string) $userId);

    return $newCode;
}

if (!function_exists('rxManualsaleExtIsFile')) {
    function rxManualsaleExtIsFile($fileExt)
    {
        $ext = strtolower(ltrim(trim((string) $fileExt), '.'));
        return $ext !== '' && $ext !== 'sub' && $ext !== 'text';
    }
}

if (!function_exists('rxShouldShowConnectionLink')) {
    function rxShouldShowConnectionLink($panel_info, $fileExt = null)
    {
        $type = is_array($panel_info) ? ($panel_info['type'] ?? '') : (string) $panel_info;
        if ($type === 'Manualsale') {
            return !rxManualsaleExtIsFile($fileExt);
        }
        $sublink = is_array($panel_info) ? ($panel_info['sublink'] ?? '') : '';
        return $sublink == "onsublink";
    }
}

if (!function_exists('rxResolveConnectionLink')) {
    function rxResolveConnectionLink($panel_info, $subscriptionLink, $fileExt = null)
    {
        $type = is_array($panel_info) ? ($panel_info['type'] ?? '') : (string) $panel_info;
        if ($type !== 'Manualsale') {
            return (string) $subscriptionLink;
        }
        if (rxManualsaleExtIsFile($fileExt)) {
            return '';
        }
        return (string) $subscriptionLink;
    }
}

if (!function_exists('applyConnectionPlaceholders')) {


    function applyConnectionPlaceholders($template, $subscriptionLink, $configList)
    {
        $trimmedSubscription = trim((string) $subscriptionLink);
        $trimmedConfigList = trim((string) $configList);

        $connectionSections = [];
        $configSection = '';
        $linksSection = '';

        if ($trimmedSubscription !== '') {
            $configSection = "🔗 لینک اتصال:\n\n<code>" . htmlspecialchars($trimmedSubscription, ENT_QUOTES, 'UTF-8') . "</code>";
            $connectionSections['config'] = $configSection;
        }

        $rxConfigListIsMultiLine = strpos($trimmedConfigList, "\n") !== false;
        if ($trimmedConfigList !== '') {
            $linksSection = $rxConfigListIsMultiLine ? "🔐 کانفیگ اشتراک :" : "🔐 کانفیگ اشتراک :\n\n<code>" . htmlspecialchars($trimmedConfigList, ENT_QUOTES, 'UTF-8') . "</code>";
            $connectionSections['links'] = $linksSection;
        }

        $connectionLinksBlock = implode("\n\n", array_values($connectionSections));
        if ($connectionLinksBlock !== '') {
            $connectionLinksBlock .= "\n";
        }

        $hasConnectionLinksPlaceholder = strpos($template, '{connection_links}') !== false;
        $hasConfigPlaceholder = strpos($template, '{config}') !== false;
        $hasLinksPlaceholder = strpos($template, '{links}') !== false;

        $placeholderLabels = [
            '{config}' => [
                '🔗 لینک اتصال:',
                '🔗 لینک اتصال :',
                'لینک اتصال:',
                'لینک اتصال :',
            ],
            '{links}' => [
                '🔐 کانفیگ اشتراک:',
                '🔐 کانفیگ اشتراک :',
                'لینک اشتراک:',
                'لینک اشتراک :',
            ],
        ];

        $replacePlaceholder = function ($templateValue, $placeholder, $replacement) use ($placeholderLabels) {
            $wrappedPlaceholder = "<code>{$placeholder}</code>";
            $labels = $placeholderLabels[$placeholder] ?? [];
            $placeholderPattern = '(?:' . preg_quote($placeholder, '/') . '|' . preg_quote($wrappedPlaceholder, '/') . ')';

            foreach ($labels as $label) {
                $labelPattern = preg_quote($label, '/');
                $pattern = '/(^|\R)[^\S\r\n]*' . $labelPattern . '[^\S\r\n]*(?:\r?\n)?[^\S\r\n]*' . $placeholderPattern . '/u';
                $updatedTemplate = preg_replace($pattern, '$1' . $replacement, $templateValue, 1, $count);
                if ($count > 0) {
                    return $updatedTemplate;
                }
            }

            if (strpos($templateValue, $wrappedPlaceholder) !== false) {
                return str_replace($wrappedPlaceholder, $replacement, $templateValue);
            }

            return str_replace($placeholder, $replacement, $templateValue);
        };

        if ($hasConnectionLinksPlaceholder) {
            if ($connectionLinksBlock === '') {
                $orphanLabels = [
                    'اطلاعات سرویس :',
                    'اطلاعات سرویس:',
                    '📌 اطلاعات سرویس :',
                    '📌 اطلاعات سرویس:',
                ];
                foreach ($orphanLabels as $orphanLabel) {
                    $orphanPattern = '/(^|\R)[^\S\r\n]*' . preg_quote($orphanLabel, '/') . '[^\S\r\n]*(?:\r?\n)?[^\S\r\n]*\{connection_links\}/u';
                    $template = preg_replace($orphanPattern, '$1{connection_links}', $template, 1, $orphanCount);
                    if ($orphanCount > 0) {
                        break;
                    }
                }
            }
            $template = str_replace('{connection_links}', $connectionLinksBlock, $template);

            if ($hasConfigPlaceholder) {
                $configReplacement = $configSection;
                if ($configReplacement !== '' && $linksSection !== '') {
                    $configReplacement .= "\n\n";
                }
                $template = $replacePlaceholder($template, '{config}', $configReplacement);
            }

            if ($hasLinksPlaceholder) {
                $template = $replacePlaceholder($template, '{links}', $linksSection);
            }
        } elseif ($hasConfigPlaceholder || $hasLinksPlaceholder) {
            if ($hasConfigPlaceholder && $hasLinksPlaceholder) {
                $configReplacement = $configSection;
                if ($configReplacement !== '' && $linksSection !== '') {
                    $configReplacement .= "\n\n";
                }

                $template = $replacePlaceholder($template, '{config}', $configReplacement);
                $template = $replacePlaceholder($template, '{links}', $linksSection);
            } elseif ($hasConfigPlaceholder) {
                $template = $replacePlaceholder($template, '{config}', $connectionLinksBlock);
            } else {
                $template = $replacePlaceholder($template, '{links}', $connectionLinksBlock);
            }
        }

        if (strpos($template, '{links2}') !== false) {
            $template = str_replace('{links2}', $trimmedSubscription, $template);
        }

        $template = preg_replace("/\n{3,}/u", "\n\n", $template);

        return $template;
    }
}

function getCrontabBinary()
{
    static $resolvedPath;

    if ($resolvedPath !== null) {
        return $resolvedPath ?: null;
    }

    $candidateDirectories = [
        '/usr/local/bin',
        '/usr/bin',
        '/bin',
        '/usr/sbin',
        '/sbin',
    ];

    $environmentPath = getenv('PATH');
    if ($environmentPath !== false && $environmentPath !== '') {
        foreach (explode(PATH_SEPARATOR, $environmentPath) as $pathDirectory) {
            $pathDirectory = trim($pathDirectory);
            if ($pathDirectory !== '' && !in_array($pathDirectory, $candidateDirectories, true)) {
                $candidateDirectories[] = $pathDirectory;
            }
        }
    }

    foreach ($candidateDirectories as $directory) {
        $executablePath = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'crontab';
        if (@is_file($executablePath) && @is_executable($executablePath)) {
            $resolvedPath = $executablePath;
            return $resolvedPath;
        }
    }

    if (isShellExecAvailable()) {
        $whichOutput = @shell_exec('command -v crontab 2>/dev/null');
        if (is_string($whichOutput)) {
            $whichOutput = trim($whichOutput);
            if ($whichOutput !== '' && @is_executable($whichOutput)) {
                $resolvedPath = $whichOutput;
                return $resolvedPath;
            }
        }
    }

    $resolvedPath = '';
    error_log('Unable to locate the crontab executable on this system.');

    return null;
}

function runShellCommand($command)
{
    if (!isShellExecAvailable()) {
        error_log('shell_exec is not available; unable to run command: ' . $command);
        return null;
    }

    if (getenv('PATH') === false || trim((string) getenv('PATH')) === '') {
        if (!rx_putenv_disabled()) {
            putenv('PATH=/usr/local/bin:/usr/bin:/bin');
        }
    }

    return shell_exec($command);
}

function deleteDirectory($directory)
{
    if (!file_exists($directory)) {
        return true;
    }

    if (!is_dir($directory)) {
        return @unlink($directory);
    }

    $items = scandir($directory);
    if ($items === false) {
        return false;
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $path = $directory . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            if (!deleteDirectory($path)) {
                return false;
            }
        } else {
            if (!@unlink($path)) {
                return false;
            }
        }
    }

    return @rmdir($directory);
}

function ensureTableUtf8mb4($table)
{
    global $pdo;

    try {
        $stmt = $pdo->prepare('SELECT TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $stmt->execute([$table]);
        $currentCollation = $stmt->fetchColumn();

        if ($currentCollation === false) {
            error_log("Failed to detect current collation for table {$table}");
            return false;
        }

        if (stripos((string) $currentCollation, 'utf8mb4') === 0) {
            return true;
        }

        $pdo->exec("ALTER TABLE `{$table}` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        return true;
    } catch (PDOException $e) {
        error_log('Failed to convert table to utf8mb4: ' . $e->getMessage());
        return false;
    }
}

function ensureCardNumberTableSupportsUnicode()
{
    global $connect;

    if (!isset($connect) || !($connect instanceof mysqli)) {
        return;
    }

    try {
        if (method_exists($connect, 'character_set_name') && $connect->character_set_name() !== 'utf8mb4') {
            if (!$connect->set_charset('utf8mb4')) {
                error_log('Failed to enforce utf8mb4 charset on mysqli connection: ' . $connect->error);
            }
        }

        if (!$connect->query("SET NAMES 'utf8mb4' COLLATE 'utf8mb4_unicode_ci'")) {
            error_log('Failed to execute SET NAMES utf8mb4 for card_number table: ' . $connect->error);
        }

        $createQuery = "CREATE TABLE IF NOT EXISTS card_number (" .
            "cardnumber varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci PRIMARY KEY," .
            "namecard varchar(1000) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL" .
            ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        if (!$connect->query($createQuery)) {
            error_log('Failed to create card_number table with utf8mb4 charset: ' . $connect->error);
        }

        ensureTableUtf8mb4('card_number');

        $columnInfo = $connect->query("SHOW FULL COLUMNS FROM card_number WHERE Field IN ('cardnumber', 'namecard')");
        if ($columnInfo instanceof mysqli_result) {
            while ($column = $columnInfo->fetch_assoc()) {
                $collation = $column['Collation'] ?? '';
                if (!is_string($collation) || stripos($collation, 'utf8mb4') === false) {
                    $field = $column['Field'];
                    $type = $field === 'cardnumber' ? 'varchar(500)' : 'varchar(1000)';
                    $alter = sprintf(
                        "ALTER TABLE card_number MODIFY %s %s CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci%s",
                        $field,
                        $type,
                        $field === 'cardnumber' ? ' PRIMARY KEY' : ' NOT NULL'
                    );
                    if (!$connect->query($alter)) {
                        error_log('Failed to update card_number column collation: ' . $connect->error);
                    }
                }
            }
            $columnInfo->free();
        } else {
            error_log('Unable to inspect card_number column collations: ' . $connect->error);
        }
    } catch (\Throwable $e) {
        error_log('Unexpected error while ensuring card_number utf8mb4 compatibility: ' . $e->getMessage());
    }
}

function normaliseUpdateValue($value)
{
    if (is_array($value) || is_object($value)) {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    return $value;
}

function fx_tenant_agent_id()
{
    static $agentId = null;
    if ($agentId === null) {
        $agentId = defined('FX_RESELLER_ID') ? max(0, (int) FX_RESELLER_ID) : 0;
    }

    if (array_key_exists('fx_tenant_agent_override', $GLOBALS)) {
        return max(0, (int) $GLOBALS['fx_tenant_agent_override']);
    }

    return $agentId;
}

/**
 * Set the tenant for the current request from a trusted, authenticated source.
 * Never populate this from a client-supplied header/query/body value.
 */
if (!function_exists('fx_set_tenant_agent_id')) {
    function fx_set_tenant_agent_id($agentId)
    {
        $GLOBALS['fx_tenant_agent_override'] = max(0, (int) $agentId);
        if (function_exists('clearSelectCache')) {
            clearSelectCache();
        }
    }
}

/**
 * Resolve a tenant only from the authenticated user's database row. The agent
 * must be a positive numeric id that exists in botsaz; otherwise the request
 * stays on the main/global tenant (0).
 */
if (!function_exists('rx_resolve_authenticated_tenant_agent_id')) {
    function rx_resolve_authenticated_tenant_agent_id(array $user)
    {
        $agent = $user['agent'] ?? null;
        if (!is_scalar($agent) || !ctype_digit(trim((string) $agent))) {
            return 0;
        }
        $agentId = (int) $agent;
        if ($agentId <= 0) {
            return 0;
        }
        $pdo = getDatabaseConnection();
        if (!($pdo instanceof PDO)) {
            return 0;
        }
        try {
            $stmt = $pdo->prepare('SELECT 1 FROM botsaz WHERE id_user = ? LIMIT 1');
            $stmt->execute([(string) $agentId]);
            return $stmt->fetchColumn() !== false ? $agentId : 0;
        } catch (Throwable $e) {
            error_log('rx_resolve_authenticated_tenant_agent_id: ' . $e->getMessage());
            return 0;
        }
    }
}

if (!function_exists('rx_main_admin_ids')) {
    /**
     * Main-bot administrators only. Reseller owners are stored in `admin` too
     * for legacy reasons, so exclude botsaz.id_user entries; the configured
     * central admin is always retained.
     *
     * @return string[]
     */
    function rx_main_admin_ids(): array
    {
        $ids = [];
        try {
            $rows = select('admin', 'id_admin', null, null, 'FETCH_COLUMN', ['cache' => false]);
            if (is_array($rows)) {
                foreach ($rows as $id) {
                    $id = trim((string) $id);
                    if ($id !== '' && ctype_digit($id) && (int) $id > 0) {
                        $ids[$id] = true;
                    }
                }
            }
            $resellerRows = select('botsaz', 'id_user', null, null, 'FETCH_COLUMN', ['cache' => false]);
            if (is_array($resellerRows)) {
                foreach ($resellerRows as $rid) {
                    $rid = trim((string) $rid);
                    if ($rid !== '') {
                        unset($ids[$rid]);
                    }
                }
            }
        } catch (Throwable $e) {
            error_log('rx_main_admin_ids: ' . $e->getMessage());
        }

        $central = trim((string) ($GLOBALS['adminnumber'] ?? ''));
        if ($central !== '' && ctype_digit($central) && (int) $central > 0) {
            $ids[$central] = true;
        }

        return array_map('strval', array_keys($ids));
    }
}

if (!function_exists('rx_is_main_admin')) {
    function rx_is_main_admin($userId): bool
    {
        $userId = trim((string) $userId);
        return $userId !== '' && in_array($userId, rx_main_admin_ids(), true);
    }
}

/**
 * Main-only surfaces for reseller administrators.
 *
 * Gateway/card/channel/price management remains available per tenant, but
 * categories, base product CRUD, technical settings, webhook and backup are
 * owned by the main bot. This helper is also used by callbacks so a forged
 * callback cannot bypass the hidden menu buttons.
 */
if (!function_exists('rx_current_bot_context')) {
    /**
     * Single source of truth for the current bot context. No client-supplied
     * tenant is trusted here; FX_RESELLER_ID comes from the authenticated
     * webhook wrapper, and fx_tenant_agent_id() is set only after API auth.
     */
    function rx_current_bot_context(): array
    {
        $tenant = defined('FX_RESELLER_ID')
            ? (int) FX_RESELLER_ID
            : (function_exists('fx_tenant_agent_id') ? (int) fx_tenant_agent_id() : 0);
        return [
            'bot_id' => $tenant > 0 ? (int) $tenant : 0,
            'type' => $tenant > 0 ? 'reseller' : 'main',
            'tenant_id' => $tenant > 0 ? (int) $tenant : 0,
            'token' => defined('FX_RESELLER_TOKEN') ? (string) FX_RESELLER_TOKEN : '',
        ];
    }
}

if (!function_exists('rx_admin_invoice_allowed')) {
    /** Server-side invoice ownership check for admin financial actions. */
    function rx_admin_invoice_allowed($invoice): bool
    {
        if (!is_array($invoice)) return false;
        return rx_admin_target_user_allowed($invoice['id_user'] ?? '');
    }
}

if (!function_exists('rx_guard_bot_context_switch')) {
    /**
     * Shared user rows must not carry an in-progress step from another bot.
     * When the same Telegram account switches between main/reseller bots we
     * reset only transient conversation state before handling the update.
     */
    function rx_guard_bot_context_switch($userId, $user = null, $pdo = null)
    {
        if (!is_array($user)) return $user;
        $userId = trim((string)$userId);
        if ($userId === '' || !ctype_digit($userId)) return $user;
        if (!($pdo instanceof PDO)) {
            $pdo = function_exists('getDatabaseConnection') ? getDatabaseConnection() : null;
        }
        if (!($pdo instanceof PDO)) return $user;
        $ctx = rx_current_bot_context();
        $botId = (string)(int)$ctx['bot_id'];
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS user_bot_context (
                user_id varchar(64) NOT NULL PRIMARY KEY,
                bot_id varchar(64) NOT NULL DEFAULT '0',
                updated_at int unsigned NOT NULL DEFAULT 0
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $stmt = $pdo->prepare('SELECT bot_id FROM user_bot_context WHERE user_id = ? LIMIT 1');
            $stmt->execute([$userId]);
            $previous = $stmt->fetchColumn();
            $switched = ($previous !== false && (string)$previous !== $botId);
            if ($switched) {
                $pdo->prepare("UPDATE user SET step = 'home', Processing_value = '0', Processing_value_one = '0', Processing_value_tow = '0', Processing_value_four = '0', Processing_value_price = '0' WHERE id = ?")->execute([$userId]);
                if (function_exists('clearSelectCache')) clearSelectCache('user');
                if (function_exists('rx_log_event')) {
                    rx_log_event('BOT_CONTEXT_STATE_RESET', 'Transient state reset after bot switch', [
                        'user_id' => $userId, 'from_bot' => (string)$previous, 'to_bot' => $botId,
                    ]);
                }
            }
            $up = $pdo->prepare('INSERT INTO user_bot_context (user_id, bot_id, updated_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE bot_id = VALUES(bot_id), updated_at = VALUES(updated_at)');
            $up->execute([$userId, $botId, time()]);
            if ($switched) {
                $fresh = select('user', '*', 'id', $userId, 'select', ['cache' => false]);
                if (is_array($fresh)) return $fresh;
            }
        } catch (Throwable $e) {
            error_log('[rx_guard_bot_context_switch] ' . $e->getMessage());
        }
        return $user;
    }
}

if (!function_exists('rx_admin_target_user_allowed')) {
    /** Reseller admins may only credit/debit users inside their own tenant. */
    function rx_admin_target_user_allowed($targetUserId): bool
    {
        $ctx = rx_current_bot_context();
        if ($ctx['type'] !== 'reseller') return true;
        $targetUserId = trim((string) $targetUserId);
        if ($targetUserId === '' || !ctype_digit($targetUserId)) return false;
        if ((int) $targetUserId === (int) $ctx['tenant_id']) return true;
        $row = function_exists('select') ? select('user', 'agent', 'id', $targetUserId, 'select', ['cache' => false]) : null;
        return is_array($row) && (string) ($row['agent'] ?? '') === (string) $ctx['tenant_id'];
    }
}

if (!function_exists('rx_reseller_scoped_setting_keys')) {
    /** Settings that are safe and useful to customize per reseller tenant. */
    function rx_reseller_scoped_setting_keys(): array
    {
        return [
            'forced_miniapp_mode', 'inlinebtnmain', 'auto_remove_reply_keyboard',
            'get_number', 'iran_number', 'verifystart', 'verifybucodeuser',
            'auth_scope', 'card_verify_status', 'card_verify_scope',
            'timeauto_not_verify',
        ];
    }
}

if (!function_exists('rx_reseller_apply_setting_overrides')) {
    /** Load per-tenant overrides; initialize them once from the current global settings. */
    function rx_reseller_apply_setting_overrides(array $setting): array
    {
        if (!defined('FX_RESELLER_ID') || (int)FX_RESELLER_ID <= 0 || !function_exists('select')) {
            return $setting;
        }
        $rid = (int)FX_RESELLER_ID;
        try {
            $row = select('botsaz', 'setting', 'id_user', $rid, 'select', ['cache' => false]);
            $json = is_array($row) ? json_decode((string)($row['setting'] ?? ''), true) : null;
            if (!is_array($json)) $json = [];
            $keys = rx_reseller_scoped_setting_keys();
            $changed = false;
            if (empty($json['_tenant_settings_v1'])) {
                foreach ($keys as $k) {
                    if (!array_key_exists($k, $json)) {
                        $json[$k] = $setting[$k] ?? null;
                    }
                }
                $json['_tenant_settings_v1'] = 1;
                $changed = true;
            }
            foreach ($keys as $k) {
                if (array_key_exists($k, $json) && $json[$k] !== null) {
                    $setting[$k] = $json[$k];
                }
            }
            if ($changed) {
                update('botsaz', 'setting', json_encode($json, JSON_UNESCAPED_UNICODE), 'id_user', $rid);
            }
        } catch (Throwable $e) {
            error_log('[rx_reseller_apply_setting_overrides] ' . $e->getMessage());
        }
        return $setting;
    }
}

if (!function_exists('rx_reseller_store_setting')) {
    /** Persist one scoped setting for the current reseller. Returns true when handled. */
    function rx_reseller_store_setting(string $key, $value): bool
    {
        if (!defined('FX_RESELLER_ID') || (int)FX_RESELLER_ID <= 0) return false;
        if (!in_array($key, rx_reseller_scoped_setting_keys(), true)) return false;
        if (!function_exists('fx_res_setting_patch')) return false;
        return fx_res_setting_patch((int)FX_RESELLER_ID, [$key => (string)$value]);
    }
}

function fx_reseller_main_only_hit($text, $datain, $step = '')
{
    static $texts = [
        "🏬 تنظیمات فروشگاه",
        "🗂 مدیریت دسته‌بندی",
        "🛒 افزودن دسته‌بندی",
        "❌ حذف دسته بندی",
        "✏️ ویرایش دسته بندی",
        "🛍 افزودن محصول",
        "❌ حذف محصول",
        "✏️ ویرایش محصول",
        "⬆️ افزایش قیمت",
        "⬇️ کاهش گروهی قیمت",
        "🎁 ساخت کد هدیه",
        "❌ حذف کد هدیه",
        "🎁 ساخت کد تخفیف",
        "❌ حذف کد تخفیف",
        "⚙️ تنظیمات فنی و ربات",
        "📝 تنظیم متن ربات",
        "⚙️ وضعیت قابلیت ها",
        "⚙️ وضعیت قابلیت ها پنل",
        "🗑 بهینه سازی ربات",
        "✅ پنل تحت وب",
        "📷 تنظیمات کیو آر کد",
        "🖼 پس‌زمینه کیوآرکد",
        "🔄 وضعیت کیوآرکد",
        "🔗 وبهوک ربات‌های نماینده",
        "💰 عضویت نمایندگی",
        "➕ محدودیت تست برای همه",
        "📦 بکاپ‌گیری",
    ];
    static $exact = [
        'admin_shop', 'admin_settings', 'set_text', 'set_features', 'set_optimize',
        'set_webpanel', 'set_qrsettings', 'set_qrbg', 'set_qr_toggle', 'set_webhook',
        'set_agentprice', 'set_testlimit', 'nm_manager_menu', 'systemsms',
        'balanceaddall', 'voloume_or_day_all', 'startgift',
        'shop_category', 'cat_add', 'cat_del', 'cat_edit',
        'shopitem_add', 'shopitem_del', 'shopitem_edit', 'shopitem_priceinc', 'shopitem_pricedec',
        'shop_giftadd', 'shop_giftdel', 'shop_discountadd', 'shop_discountdel',
        'shop_status', 'shop_minbulk', 'shop_renewcb',
        'randomwallet_status', 'btn_status_category', 'infocard_status', 'changeloclimit',
    ];
    static $prefixes = [
        'productedit_', 'typeagenteditproduct_', 'locationedit_',
        'getremarkcategory', 'removecategory', 'editcategory_name', 'get_name_new_category',
        'backup', 'set_qr', 'editstsuts', 'randomwallet', 'infocard_', 'premium_emoji_',
        'btnstaut', 'btn_status', 'changeloc', 'limitfree', 'limitchange', 'reasetchangeloc',
        'hidepanel_', 'removehide_', 'getpanelhide', 'getremovehide',
        'setvolumesrc_', 'settimepricesrc_', 'typegift_',
    ];
    static $steps = [
        'get_limit', 'get_agent', 'get_location', 'getcategory', 'get_time', 'get_price',
        'gettimereset', 'getnote', 'endstep', 'selectloc', 'remove-product',
        'getremarkcategory', 'removecategory', 'editcategory_name', 'get_name_new_category',
        'getpricef', 'getpricnn', 'getpricnn2', 'getpriceftime', 'getpricnntime', 'getpricnn2time',
        'limitchangeall', 'limitfreechangefree', 'reasetchangeloc', 'get_randomwallet_slot',
        'getlimitchangenewbyuser', 'getpanelhidebotsaz', 'getremovehidepanel',
        'getpanelgift', 'getvaluegift', 'gettextgift', 'getlistpanel',
    ];

    if (is_string($text) && in_array($text, $texts, true)) {
        return true;
    }
    if (is_string($datain) && $datain !== '') {
        // Reseller-scoped settings that are intentionally safe to toggle from
        // the reseller admin panel (stored in botsaz.setting, not main config).
        if (preg_match('/^editstsuts-(forced_miniapp|get_number|Authenticationiran|verifystart|verifybyuser|authscope|cardverify|inlinebtnmain|auto_remove_kb)-/', $datain)) {
            return false;
        }
        if (in_array($datain, $exact, true)) {
            return true;
        }
        foreach ($prefixes as $prefix) {
            if (strpos($datain, $prefix) === 0) {
                return true;
            }
        }
    }
    $step = (string) $step;
    return $step !== '' && in_array($step, $steps, true);
}

function fx_tenant_scope_tables()
{
    return [
        'PaySetting' => true,
        'card_number' => true,
        'channels' => true,
    ];
}

function fx_tenant_scope_for_table($table)
{
    static $cache = [];

    if (!isset(fx_tenant_scope_tables()[$table])) {
        return null;
    }

    $tenantAgent = fx_tenant_agent_id();
    $cacheKey = $table . '|' . $tenantAgent;
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }

    $pdo = getDatabaseConnection();
    if (!($pdo instanceof PDO)) {
        $cache[$cacheKey] = null;
        return null;
    }

    $safeTable = preg_replace('/[^A-Za-z0-9_]/', '', (string) $table);
    if ($safeTable === '') {
        $cache[$cacheKey] = null;
        return null;
    }

    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM `{$safeTable}` LIKE 'agent'");
        $hasAgent = $stmt !== false && $stmt->fetch(PDO::FETCH_ASSOC) !== false;
        $cache[$cacheKey] = $hasAgent ? $tenantAgent : null;
    } catch (Throwable $e) {
        $cache[$cacheKey] = null;
    }

    return $cache[$cacheKey];
}

function fx_tenant_sql_agent($alias = '')
{
    $column = $alias !== '' ? '`' . preg_replace('/[^A-Za-z0-9_]/', '', (string) $alias) . '`.agent' : 'agent';
    return $column . ' = ' . (int) fx_tenant_agent_id();
}

function fx_tenant_row_owned($table, $idColumn, $idValue)
{
    $agentId = fx_tenant_scope_for_table($table);
    if ($agentId === null) {
        return true;
    }

    $pdo = getDatabaseConnection();
    if (!($pdo instanceof PDO)) {
        return false;
    }

    $safeTable = preg_replace('/[^A-Za-z0-9_]/', '', (string) $table);
    $safeId = preg_replace('/[^A-Za-z0-9_]/', '', (string) $idColumn);
    if ($safeTable === '' || $safeId === '') {
        return false;
    }

    try {
        $stmt = $pdo->prepare("SELECT 1 FROM `{$safeTable}` WHERE `{$safeId}` = ? AND agent = ? LIMIT 1");
        $stmt->execute([$idValue, (int) $agentId]);
        return $stmt->fetchColumn() !== false;
    } catch (Throwable $e) {
        return false;
    }
}

function fx_tenant_scoped_update($table, $field, $newValue, $whereField, $whereValue, $agentId)
{
    global $pdo;
    if (!($pdo instanceof PDO)) {
        return 0;
    }

    $safeTable = preg_replace('/[^A-Za-z0-9_]/', '', (string) $table);
    $safeField = preg_replace('/[^A-Za-z0-9_]/', '', (string) $field);
    if ($safeTable === '' || $safeField === '') {
        return 0;
    }

    $value = normaliseUpdateValue($newValue);
    $whereValueToStore = $whereField !== null ? normaliseUpdateValue($whereValue) : null;
    $rows = 0;

    if ($whereField !== null) {
        $safeWhere = preg_replace('/[^A-Za-z0-9_]/', '', (string) $whereField);
        if ($safeWhere === '') {
            return 0;
        }
        if ($whereValueToStore === null) {
            $stmt = $pdo->prepare("UPDATE `{$safeTable}` SET `{$safeField}` = ? WHERE `{$safeWhere}` IS NULL AND agent = ?");
            $stmt->execute([$value, (int) $agentId]);
        } else {
            $stmt = $pdo->prepare("UPDATE `{$safeTable}` SET `{$safeField}` = ? WHERE `{$safeWhere}` = ? AND agent = ?");
            $stmt->execute([$value, $whereValueToStore, (int) $agentId]);
        }
        $rows = $stmt->rowCount();

        if ($rows === 0) {
            if ($whereValueToStore === null) {
                $existsStmt = $pdo->prepare("SELECT 1 FROM `{$safeTable}` WHERE `{$safeWhere}` IS NULL AND agent = ? LIMIT 1");
                $existsStmt->execute([(int) $agentId]);
            } else {
                $existsStmt = $pdo->prepare("SELECT 1 FROM `{$safeTable}` WHERE `{$safeWhere}` = ? AND agent = ? LIMIT 1");
                $existsStmt->execute([$whereValueToStore, (int) $agentId]);
            }

            if ($existsStmt->fetchColumn() === false) {
                $columns = [$safeField];
                $values = [$value];
                if ($safeWhere !== $safeField) {
                    $columns[] = $safeWhere;
                    $values[] = $whereValueToStore;
                }
                $columns[] = 'agent';
                $values[] = (int) $agentId;
                $columnList = implode(', ', array_map(static function ($column) {
                    return "`{$column}`";
                }, $columns));
                $placeholders = implode(', ', array_fill(0, count($columns), '?'));
                try {
                    $insertStmt = $pdo->prepare("INSERT INTO `{$safeTable}` ({$columnList}) VALUES ({$placeholders}) ON DUPLICATE KEY UPDATE `{$safeField}` = VALUES(`{$safeField}`)");
                    $insertStmt->execute($values);
                    $rows = $insertStmt->rowCount();
                } catch (PDOException $insertException) {
                    error_log('fx_tenant_scoped_update insert failed: ' . $insertException->getMessage());
                }
            }
        }
    } else {
        $stmt = $pdo->prepare("UPDATE `{$safeTable}` SET `{$safeField}` = ? WHERE agent = ?");
        $stmt->execute([$value, (int) $agentId]);
        $rows = $stmt->rowCount();
    }

    if ($whereField !== null && $whereValueToStore !== null && function_exists('clearSelectCacheRow')) {
        clearSelectCacheRow($table, $whereField, $whereValueToStore);
    } else {
        clearSelectCache($table);
    }

    return $rows;
}

function copyDirectoryContents($source, $destination)
{
    if (!is_dir($source)) {
        return false;
    }

    if (!is_dir($destination) && !mkdir($destination, 0777, true) && !is_dir($destination)) {
        return false;
    }

    $items = scandir($source);
    if ($items === false) {
        return false;
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $sourcePath = $source . DIRECTORY_SEPARATOR . $item;
        $destinationPath = $destination . DIRECTORY_SEPARATOR . $item;

        if (is_dir($sourcePath)) {
            if (!copyDirectoryContents($sourcePath, $destinationPath)) {
                return false;
            }
        } else {
            if (!@copy($sourcePath, $destinationPath)) {
                return false;
            }
        }
    }

    return true;
}


function step($step, $from_id)
{
    global $pdo;
    try {
        if (function_exists('rxWizardStepFlow') && function_exists('rxStepStackRead')) {
            $rxRow = select("user", "step", "id", $from_id, "select", ['cache' => false]);
            $rxOld = (is_array($rxRow) && isset($rxRow['step'])) ? (string) $rxRow['step'] : '';
            $rxFlowNew = rxWizardStepFlow((string) $step);
            $rxFlowOld = rxWizardStepFlow($rxOld);
            if ($rxFlowNew !== null || $rxFlowOld !== null) {
                $rxStack = rxStepStackRead($from_id);
                $rxChanged = false;
                if ($rxFlowNew === null) {
                    if (!empty($rxStack)) { $rxStack = []; $rxChanged = true; }
                } elseif ($rxFlowNew !== $rxFlowOld) {
                    if (!empty($rxStack)) { $rxStack = []; $rxChanged = true; }
                } elseif ($rxOld !== (string) $step) {
                    $rxStack[] = $rxOld;
                    $rxChanged = true;
                }
                if ($rxChanged) {
                    update("user", "step_stack", json_encode(array_values($rxStack), JSON_UNESCAPED_UNICODE), "id", $from_id);
                }
            }
        }
    } catch (\Throwable $rxStackError) {
    }
    $rxNavFromStep = null;
    if (class_exists('logNavigation')) {
        if (isset($rxOld)) {
            $rxNavFromStep = $rxOld;
        } else {
            $rxNavRow = select("user", "step", "id", $from_id, "select", ['cache' => false]);
            if (is_array($rxNavRow) && isset($rxNavRow['step'])) {
                $rxNavFromStep = $rxNavRow['step'];
            } elseif (is_scalar($rxNavRow)) {
                $rxNavFromStep = $rxNavRow;
            }
        }
    }
    $stmt = $pdo->prepare('UPDATE user SET step = ? WHERE id = ?');
    $stmt->execute([$step, $from_id]);
    clearSelectCache('user');
    if (function_exists('rx_redis_is_active') && rx_redis_is_active()) {
        rx_redis_set('faoxima:step:' . $from_id, (string) $step, 3600);
    }
    if (class_exists('logNavigation')) {
        logNavigation::transition($from_id, $rxNavFromStep, $step);
    }
}
function determineColumnTypeFromValue($value)
{
    if (is_bool($value)) {
        return 'TINYINT(1)';
    }

    if (is_int($value)) {
        return 'INT(11)';
    }

    if (is_float($value)) {
        return 'DOUBLE';
    }

    if ($value === null) {
        return 'VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
    }

    if (is_string($value)) {
        if (function_exists('mb_strlen')) {
            $length = mb_strlen($value, 'UTF-8');
        } else {
            $length = strlen($value);
        }

        if ($length <= 191) {
            return 'VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
        }

        if ($length <= 500) {
            return 'VARCHAR(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
        }

        return 'TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
    }

    return 'TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
}
function ensureColumnExistsForUpdate($tableName, $fieldName, $valueSample = null)
{
    global $pdo;

    static $checkedColumns = [];

    $cacheKey = $tableName . '.' . $fieldName;
    if (isset($checkedColumns[$cacheKey])) {
        return;
    }

    try {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?');
        $stmt->execute([$tableName, $fieldName]);
        if ((int) $stmt->fetchColumn() > 0) {
            $checkedColumns[$cacheKey] = true;
            return;
        }

        $datatype = determineColumnTypeFromValue($valueSample);

        $defaultValue = null;
        if (is_bool($valueSample)) {
            $defaultValue = $valueSample ? '1' : '0';
        } elseif (is_scalar($valueSample) && $valueSample !== null) {
            $defaultValue = (string) $valueSample;
        }

        addFieldToTable($tableName, $fieldName, $defaultValue, $datatype);
        $checkedColumns[$cacheKey] = true;
    } catch (PDOException $e) {
        error_log('Failed to ensure column exists: ' . $e->getMessage());
        $checkedColumns[$cacheKey] = true;
    }
}
function update($table, $field, $newValue, $whereField = null, $whereValue = null)
{
    global $pdo, $user;

    $fxTenantAgent = fx_tenant_scope_for_table($table);
    if ($fxTenantAgent !== null) {
        return fx_tenant_scoped_update($table, $field, $newValue, $whereField, $whereValue, $fxTenantAgent);
    }

    $valueToStore = normaliseUpdateValue($newValue);
    $whereValueToStore = $whereField !== null ? normaliseUpdateValue($whereValue) : null;

    $executeUpdate = function ($value) use ($pdo, $table, $field, $whereField, $whereValueToStore) {
        if ($whereField !== null) {
            $stmt = $pdo->prepare("UPDATE $table SET $field = ? WHERE $whereField = ?");
            $stmt->execute([$value, $whereValueToStore]);
        } else {
            $stmt = $pdo->prepare("UPDATE $table SET $field = ?");
            $stmt->execute([$value]);
        }

        return isset($stmt) ? $stmt->rowCount() : 0;
    };

    $affectedRows = 0;

    try {
        $affectedRows = $executeUpdate($valueToStore);
    } catch (PDOException $e) {
        $rxMissingColumn = (string) $e->getCode() === '42S22'
            || (int) ($e->errorInfo[1] ?? 0) === 1054
            || stripos($e->getMessage(), 'Unknown column') !== false;
        if ($rxMissingColumn) {
            ensureColumnExistsForUpdate($table, $field, $valueToStore);
            if ($whereField !== null) {
                ensureColumnExistsForUpdate($table, $whereField, $whereValueToStore);
            }
            $affectedRows = $executeUpdate($valueToStore);
        } elseif (strpos($e->getMessage(), 'Incorrect string value') !== false) {
            $tableConverted = ensureTableUtf8mb4($table);
            if ($tableConverted) {
                try {
                    $affectedRows = $executeUpdate($valueToStore);
                } catch (PDOException $retryException) {
                    error_log('Retry after charset conversion failed: ' . $retryException->getMessage());
                    throw $retryException;
                }
            } else {
                $fallbackValue = is_string($valueToStore) ? @iconv('UTF-8', 'UTF-8//IGNORE', $valueToStore) : $valueToStore;
                if ($fallbackValue === false) {
                    $fallbackValue = '';
                }
                $affectedRows = $executeUpdate($fallbackValue);
            }
        } else {
            throw $e;
        }
    }

    if ($whereField !== null && $affectedRows === 0) {
        if ($whereValueToStore === null) {
            $existsStmt = $pdo->prepare("SELECT 1 FROM $table WHERE $whereField IS NULL LIMIT 1");
            $existsStmt->execute();
        } else {
            $existsStmt = $pdo->prepare("SELECT 1 FROM $table WHERE $whereField = ? LIMIT 1");
            $existsStmt->execute([$whereValueToStore]);
        }

        $rowExists = $existsStmt->fetchColumn();

        if ($rowExists === false) {
            $columns = [$field];
            $values = [$valueToStore];

            if ($field !== $whereField) {
                $columns[] = $whereField;
                $values[] = $whereValueToStore;
            }

            $placeholders = implode(', ', array_fill(0, count($columns), '?'));
            $columnList = implode(', ', array_map(function ($column) {
                return "`$column`";
            }, $columns));

            try {
                $insertStmt = $pdo->prepare("INSERT INTO $table ($columnList) VALUES ($placeholders)");
                $insertStmt->execute($values);
            } catch (PDOException $insertException) {
                error_log('Failed to insert missing row during update fallback: ' . $insertException->getMessage());
            }
        }
    }


    if ($table === 'invoice' && strtolower($field) === 'status' && $valueToStore === 'active'
        && $whereField !== null && strtolower($field) !== strtolower($whereField)) {
        $clearStmt = $pdo->prepare("UPDATE invoice SET invalidated_at = NULL WHERE $whereField = ?");
        $clearStmt->execute([$whereValueToStore]);
    }

    if (defined('RX_UPDATE_LOG_ENABLED') && RX_UPDATE_LOG_ENABLED === true) {
        static $rx_update_log_skip = [
            'message_count'     => true,
            'last_message_time' => true,
            'step'              => true,
            'pagenumber'        => true,
            'Processing_value'  => true,
        ];
        if (!isset($rx_update_log_skip[$field])) {
            $date = date("Y-m-d H:i:s");
            if (!isset($user['step'])) {
                $user['step'] = '';
            }
            $logValue = is_scalar($valueToStore)
                ? $valueToStore
                : json_encode($valueToStore, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $logLine = "\n{$table}_{$field}_{$logValue}_{$whereField}_{$whereValue}_{$user['step']}_$date";
            $logPath = defined('RX_UPDATE_LOG_PATH') ? RX_UPDATE_LOG_PATH : 'log.txt';
            @file_put_contents($logPath, $logLine, FILE_APPEND | LOCK_EX);
        }
    }


    if ($table === 'marzban_panel' || $table === 'textbot') {
        clearSelectCache($table);
        if (function_exists('faoxima_bust_bot_selectcache')) {
            faoxima_bust_bot_selectcache($table);
        }
    } elseif ($whereField !== null && $whereValueToStore !== null
        && function_exists('clearSelectCacheRow')) {
        clearSelectCacheRow($table, $whereField, $whereValueToStore);
    } else {
        clearSelectCache($table);
    }
}
function &getSelectCacheStore()
{
    static $store = [
        'results' => [],
        'tableIndex' => [],
        'rowIndex' => [],
    ];

    return $store;
}

function rx_select_cache_hot_tables()
{
    return [
        'user'               => true,
        'invoice'            => true,
        'Payment_report'     => true,
        'reagent_report'     => true,
        'cron_runtime_state' => true,
    ];
}

function rx_select_cache_redis_ttl()
{
    return 300;
}

function clearSelectCache($table = null)
{
    $store =& getSelectCacheStore();

    if ($table === null) {
        $store['results'] = [];
        $store['tableIndex'] = [];
        $store['rowIndex'] = [];
        if (function_exists('rx_redis_is_active') && rx_redis_is_active()) {
            rx_redis_clear_selectcache_table(null);
        }
        return;
    }

    if (function_exists('rx_redis_is_active') && rx_redis_is_active()) {
        rx_redis_clear_selectcache_table($table);
    }

    if (!isset($store['tableIndex'][$table])) {
        return;
    }

    foreach (array_keys($store['tableIndex'][$table]) as $cacheKey) {
        unset($store['results'][$cacheKey]);
    }

    unset($store['tableIndex'][$table]);


    if (isset($store['rowIndex']) && is_array($store['rowIndex'])) {
        foreach (array_keys($store['rowIndex']) as $rowKey) {
            if (strpos($rowKey, $table . '|') === 0) {
                unset($store['rowIndex'][$rowKey]);
            }
        }
    }
}

function rx_redis_clear_selectcache_table($table)
{
    if ($table === null) {
        if (function_exists('rx_redis_scan_delete')) {
            rx_redis_scan_delete('faoxima:selectcache:*');
        }
        return;
    }

    $indexKey = 'faoxima:selectcache:tableindex:' . $table;
    $client = getRedisConnection();
    if ($client === null) {
        return;
    }
    try {
        $members = $client->sMembers(rx_redis_key($indexKey));
        if (is_array($members) && !empty($members)) {
            rx_redis_del($members);
        }
        rx_redis_del($indexKey);
    } catch (\Throwable $e) {
        rx_redis_mark_unavailable('exception');
    }
}

function rx_redis_clear_selectcache_row($table, $whereField, $whereValue)
{
    $client = getRedisConnection();
    if ($client === null) {
        return;
    }
    $rowIdx = $table . '|' . (string) $whereField . '|' . (string) $whereValue;
    $allRowsIdx = $table . '||';
    foreach ([$rowIdx, $allRowsIdx] as $idx) {
        $indexKey = 'faoxima:selectcache:rowindex:' . $idx;
        try {
            $members = $client->sMembers(rx_redis_key($indexKey));
            if (is_array($members) && !empty($members)) {
                rx_redis_del($members);
            }
            rx_redis_del($indexKey);
        } catch (\Throwable $e) {
            rx_redis_mark_unavailable('exception');
        }
    }
}

function select($table, $field, $whereField = null, $whereValue = null, $type = "select", $options = [])
{
    $pdo = getDatabaseConnection();

    if (!($pdo instanceof PDO)) {
        $rxDbMarker = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'rx_db_unavailable.flag';
        if (!is_file($rxDbMarker) || (time() - (int) @filemtime($rxDbMarker)) > 3600) {
            error_log('select: Database connection is unavailable.');
            @touch($rxDbMarker);
        }

        switch ($type) {
            case 'count':
                return 0;
            case 'FETCH_COLUMN':
            case 'fetchAll':
                return [];
            default:
                return null;
        }
    }

    $useCache = true;
    if (is_array($options) && array_key_exists('cache', $options)) {
        $useCache = (bool) $options['cache'];
    }

    $fxTenantAgent = fx_tenant_scope_for_table($table);

    $cacheKey = null;
    if ($useCache) {
        $cacheKey = hash('sha256', json_encode([
            $table,
            $field,
            $whereField,
            $whereValue,
            $type,
            'tenant_agent' => $fxTenantAgent,
        ], JSON_UNESCAPED_UNICODE));

        $store =& getSelectCacheStore();
        if (isset($store['results'][$cacheKey])) {
            return $store['results'][$cacheKey];
        }

        if (!isset(rx_select_cache_hot_tables()[$table]) && function_exists('rx_redis_is_active') && rx_redis_is_active()) {
            $rxRedisCached = rx_redis_get('faoxima:selectcache:' . $cacheKey);
            if ($rxRedisCached !== null) {
                $rxRedisDecoded = json_decode($rxRedisCached, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $store['results'][$cacheKey] = $rxRedisDecoded;
                    if (!isset($store['tableIndex'][$table])) {
                        $store['tableIndex'][$table] = [];
                    }
                    $store['tableIndex'][$table][$cacheKey] = true;
                    $rxRedisHitRowIdx = $table . '|' . (string) $whereField . '|' . (string) $whereValue;
                    if (!isset($store['rowIndex'][$rxRedisHitRowIdx])) {
                        $store['rowIndex'][$rxRedisHitRowIdx] = [];
                    }
                    $store['rowIndex'][$rxRedisHitRowIdx][$cacheKey] = true;
                    return $rxRedisDecoded;
                }
            }
        }
    }

    $query = "SELECT $field FROM $table";

    if ($whereField !== null) {
        $query .= " WHERE $whereField = :whereValue";
    }
    if ($fxTenantAgent !== null) {
        $query .= ($whereField !== null ? " AND" : " WHERE") . " agent = :fxTenantAgent";
    }

    try {
        $stmt = $pdo->prepare($query);
        if ($whereField !== null) {
            $stmt->bindParam(':whereValue', $whereValue, PDO::PARAM_STR);
        }
        if ($fxTenantAgent !== null) {
            $stmt->bindValue(':fxTenantAgent', (int) $fxTenantAgent, PDO::PARAM_INT);
        }

        $stmt->execute();
        if ($type == "count") {
            $result = $stmt->rowCount();
        } elseif ($type == "FETCH_COLUMN") {
            $results = $stmt->fetchAll(PDO::FETCH_COLUMN);
            if ($table === 'admin' && $field === 'id_admin') {
                global $adminnumber;
                if (!is_array($results)) {
                    $results = [];
                }

                $results = array_values(array_unique(array_filter($results, function ($value) {
                    return $value !== null && $value !== '';
                })));

                if (empty($results) && isset($adminnumber) && $adminnumber !== '') {
                    $results[] = (string) $adminnumber;
                }
            }
            $result = $results;
        } elseif ($type == "fetchAll") {
            $result = $stmt->fetchAll();
        } else {
            $fetched = $stmt->fetch(PDO::FETCH_ASSOC);
            $result = $fetched === false ? null : $fetched;
        }
    } catch (PDOException $e) {


        error_log('select() PDOException on table=' . $table . ': ' . $e->getMessage());
        switch ($type) {
            case 'count':
                return 0;
            case 'FETCH_COLUMN':
            case 'fetchAll':
                return [];
            default:
                return null;
        }
    }

    if ($useCache && $cacheKey !== null) {

        $skipCache = false;
        if (isset(rx_select_cache_hot_tables()[$table])) {
            $skipCache = true;
        }
        if (!$skipCache && is_array($result) && count($result) > 1000) {
            $skipCache = true;
        }
        if (!$skipCache) {
            $store =& getSelectCacheStore();


            $rxCacheMaxEntries = 500;
            if (count($store['results']) >= $rxCacheMaxEntries) {
                $oldestKey = array_key_first($store['results']);
                if ($oldestKey !== null) {
                    unset($store['results'][$oldestKey]);
                    foreach ($store['tableIndex'] as $tIdx => &$keys) {
                        if (isset($keys[$oldestKey])) {
                            unset($keys[$oldestKey]);
                        }
                    }
                    unset($keys);
                }
            }
            $store['results'][$cacheKey] = $result;
            if (!isset($store['tableIndex'][$table])) {
                $store['tableIndex'][$table] = [];
            }
            $store['tableIndex'][$table][$cacheKey] = true;

            $rxRowIdx = $table . '|' . (string) $whereField . '|' . (string) $whereValue;
            if (!isset($store['rowIndex'][$rxRowIdx])) {
                $store['rowIndex'][$rxRowIdx] = [];
            }
            $store['rowIndex'][$rxRowIdx][$cacheKey] = true;

            if (function_exists('rx_redis_is_active') && rx_redis_is_active()) {
                $rxRedisEncoded = json_encode($result, JSON_UNESCAPED_UNICODE);
                if ($rxRedisEncoded !== false) {
                    $rxSelectCacheTtl = rx_select_cache_redis_ttl();
                    rx_redis_set('faoxima:selectcache:' . $cacheKey, $rxRedisEncoded, $rxSelectCacheTtl);
                    rx_redis_sadd_index('faoxima:selectcache:tableindex:' . $table, 'faoxima:selectcache:' . $cacheKey, $rxSelectCacheTtl);
                    rx_redis_sadd_index('faoxima:selectcache:rowindex:' . $rxRowIdx, 'faoxima:selectcache:' . $cacheKey, $rxSelectCacheTtl);
                }
            }
        }
    }

    return $result;
}


if (!function_exists('clearSelectCacheRow')) {
    function clearSelectCacheRow($table, $whereField, $whereValue)
    {
        if (function_exists('rx_redis_is_active') && rx_redis_is_active()) {
            rx_redis_clear_selectcache_row($table, $whereField, $whereValue);
        }

        $store =& getSelectCacheStore();
        if (!isset($store['tableIndex'][$table])) {
            return;
        }


        $rowIdx = $table . '|' . (string) $whereField . '|' . (string) $whereValue;
        if (isset($store['rowIndex'][$rowIdx]) && is_array($store['rowIndex'][$rowIdx])) {
            foreach (array_keys($store['rowIndex'][$rowIdx]) as $cacheKey) {
                unset($store['results'][$cacheKey]);
                unset($store['tableIndex'][$table][$cacheKey]);
            }
            unset($store['rowIndex'][$rowIdx]);
        }


        $allRowsIdx = $table . '||';
        if (isset($store['rowIndex'][$allRowsIdx]) && is_array($store['rowIndex'][$allRowsIdx])) {
            foreach (array_keys($store['rowIndex'][$allRowsIdx]) as $cacheKey) {
                unset($store['results'][$cacheKey]);
                unset($store['tableIndex'][$table][$cacheKey]);
            }
            unset($store['rowIndex'][$allRowsIdx]);
        }
    }
}

function getPaySettingValue($name, $default = null)
{
    $result = select("PaySetting", "ValuePay", "NamePay", $name, "select");
    if (!is_array($result) || !array_key_exists('ValuePay', $result)) {
        return $default;
    }

    return $result['ValuePay'];
}

if (!defined('RX_DEFAULT_MIN_DEPOSIT')) {
    define('RX_DEFAULT_MIN_DEPOSIT', 10000);
}
if (!defined('RX_DEFAULT_MAX_DEPOSIT')) {
    define('RX_DEFAULT_MAX_DEPOSIT', 50000000);
}

/**
 * Fetch a PaySetting row value scoped to the current tenant (agent column).
 * PaySetting is keyed by (agent, NamePay); the legacy unfiltered select() can
 * return the wrong tenant's row. Falls back to the main (agent = 0) row when
 * the tenant has no row of its own, then null when nothing exists at all.
 */
if (!function_exists('rx_fetchTenantPaySetting')) {
    function rx_fetchTenantPaySetting($name)
    {
        $agents = [fx_tenant_agent_id()];
        if ($agents[0] !== 0) {
            $agents[] = 0;
        }
        $pdo = getDatabaseConnection();
        if (!($pdo instanceof PDO)) {
            $legacyRow = select("PaySetting", "ValuePay", "NamePay", $name, "select");
            return is_array($legacyRow) ? (string) ($legacyRow['ValuePay'] ?? '') : null;
        }
        foreach ($agents as $agentId) {
            try {
                $stmt = $pdo->prepare('SELECT ValuePay FROM PaySetting WHERE agent = :agent AND NamePay = :name LIMIT 1');
                $stmt->execute([':agent' => $agentId, ':name' => (string) $name]);
                $value = $stmt->fetchColumn();
                if ($value !== false && $value !== null && trim((string) $value) !== '') {
                    return (string) $value;
                }
            } catch (PDOException $e) {
                error_log('rx_fetchTenantPaySetting: ' . $e->getMessage());
            }
        }
        return null;
    }
}

/**
 * Write a PaySetting row scoped to the current tenant (agent column).
 * Creates the tenant row when it does not exist yet.
 */
if (!function_exists('rx_writeTenantPaySetting')) {
    function rx_writeTenantPaySetting($name, $value)
    {
        try {
            $pdo = getDatabaseConnection();
            if (!($pdo instanceof PDO)) {
                update("PaySetting", "ValuePay", $value, "NamePay", $name);
                return true;
            }
            $stmt = $pdo->prepare('UPDATE PaySetting SET ValuePay = :value WHERE agent = :agent AND NamePay = :name');
            $stmt->execute([':value' => is_array($value) || is_object($value) ? json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : (string) $value, ':agent' => fx_tenant_agent_id(), ':name' => (string) $name]);
            if ($stmt->rowCount() > 0) {
                clearSelectCache('PaySetting');
                return true;
            }
            $insert = $pdo->prepare('INSERT IGNORE INTO PaySetting (agent, NamePay, ValuePay) VALUES (:agent, :name, :value)');
            $insert->execute([':agent' => fx_tenant_agent_id(), ':name' => (string) $name, ':value' => is_array($value) || is_object($value) ? json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : (string) $value]);
            clearSelectCache('PaySetting');
            return true;
        } catch (PDOException $e) {
            error_log('rx_writeTenantPaySetting: ' . $e->getMessage());
            return false;
        }
    }
}

/**
 * Resolve an amount limit that may be stored as:
 *  - a JSON map keyed by user agent/group ("n", "n2", "f", tenant ids)
 *  - a legacy plain scalar seed (e.g. "20000")
 * Returns a non-negative int, falling back to $fallback when the stored
 * value is missing, zero, or not usable for the given agent.
 */
if (!function_exists('rx_resolveAgentAmountLimit')) {
    function rx_resolveAgentAmountLimit($rawValue, $agent, $fallback)
    {
        $fallback = max(0, (int) $fallback);
        $raw = is_array($rawValue) ? (string) ($rawValue['ValuePay'] ?? '') : (string) $rawValue;
        $raw = trim($raw);
        if ($raw === '') {
            return $fallback;
        }
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $candidates = [(string) $agent];
            if (!in_array($candidates[0], ['n', 'n2', 'f'], true)) {
                $candidates = array_merge($candidates, ['n', 'n2', 'f']);
            }
            foreach ($candidates as $candidate) {
                if (isset($decoded[$candidate]) && is_numeric($decoded[$candidate])) {
                    $candidateValue = max(0, (int) $decoded[$candidate]);
                    return $candidateValue > 0 ? $candidateValue : $fallback;
                }
            }
            foreach ($decoded as $candidateValue) {
                if (is_numeric($candidateValue)) {
                    $candidateValue = max(0, (int) $candidateValue);
                    return $candidateValue > 0 ? $candidateValue : $fallback;
                }
            }
            return $fallback;
        }
        if (is_numeric($raw)) {
            $scalarValue = max(0, (int) $raw);
            return $scalarValue > 0 ? $scalarValue : $fallback;
        }
        return $fallback;
    }
}

/**
 * Explicit per-payment-method max keys used by the wallet charge entry step.
 * Keeping this list conservative avoids turning missing method settings into
 * RX_DEFAULT_MAX_DEPOSIT via a generic fallback.
 */
if (!function_exists('rx_depositMethodMaxLimitNames')) {
    function rx_depositMethodMaxLimitNames()
    {
        return [
            'maxbalancecart',
            'maxbalancezarinpal',
            'maxbalanceplisio',
            'maxbalancenowpayment',
            'maxbalancedigitaltron',
            'maxbalanceiranpay',
            'maxbalanceiranpay2',
            'maxbalancetonpay',
            'maxbalancecubepay',
            'maxbalanceblupal',
            'maxbalancevariza',
            'maxbalanceabangateway',
            'maxbalanceatlaspay',
            'maxbalancepaynotverify',
            'maxbalanceperfect',
            'maxbalancestar',
            'maxbalancetetrapay',
        ];
    }
}

/**
 * Highest explicitly configured per-payment-method cap for the current tenant.
 * Missing/empty method settings are ignored so the general fallback is not
 * silently raised to RX_DEFAULT_MAX_DEPOSIT.
 */
if (!function_exists('rx_configuredDepositMethodMaxLimit')) {
    function rx_configuredDepositMethodMaxLimit()
    {
        $max = 0;
        foreach (rx_depositMethodMaxLimitNames() as $name) {
            $raw = rx_fetchTenantPaySetting($name);
            if ($raw === null || trim((string) $raw) === '') {
                continue;
            }
            $value = rx_resolveAgentAmountLimit($raw, '', 0);
            if ($value > $max) {
                $max = $value;
            }
        }
        return $max;
    }
}

/**
 * Wallet deposit limits for the given user agent, always non-zero.
 * The max is the larger of maxbalance and the explicitly configured
 * per-method caps, so the generic wallet entry does not block a legitimately
 * higher card-to-card cap. Per-method server validation remains authoritative.
 *
 * @return int[] [min, max]
 */
if (!function_exists('rx_getDepositLimits')) {
    function rx_getDepositLimits($agent)
    {
        $min = rx_resolveAgentAmountLimit(rx_fetchTenantPaySetting('minbalance'), $agent, RX_DEFAULT_MIN_DEPOSIT);
        $max = rx_resolveAgentAmountLimit(rx_fetchTenantPaySetting('maxbalance'), $agent, RX_DEFAULT_MAX_DEPOSIT);
        $methodMax = rx_configuredDepositMethodMaxLimit();
        if ($methodMax > $max) {
            $max = $methodMax;
        }
        if ($max < $min) {
            $max = $min;
        }
        return [$min, $max];
    }
}

if (!function_exists('rx_depositLimitAllowsAmount')) {
    function rx_depositLimitAllowsAmount($amount, $agent)
    {
        if (!is_scalar($amount) || !is_numeric($amount)) {
            return false;
        }
        [$min, $max] = rx_getDepositLimits($agent);
        $amount = (int) $amount;
        return $amount >= $min && $amount <= $max;
    }
}

if (!function_exists('rx_depositLimitValue')) {
    function rx_depositLimitValue($which, $agent)
    {
        [$min, $max] = rx_getDepositLimits($agent);
        return strtolower((string) $which) === 'max' ? $max : $min;
    }
}

/**
 * Per-payment-method deposit limit (minbalancecart, maxbalanceplisio, ...).
 * Always resolves to a non-negative int; falls back to safe defaults when the
 * stored value is missing, empty or zero.
 */
if (!function_exists('rx_gatewayDepositLimit')) {
    function rx_gatewayDepositLimit($name)
    {
        $name = (string) $name;
        $fallback = str_starts_with($name, 'minbalance') ? RX_DEFAULT_MIN_DEPOSIT : RX_DEFAULT_MAX_DEPOSIT;
        return rx_resolveAgentAmountLimit(rx_fetchTenantPaySetting($name), '', $fallback);
    }
}


function userExists($userId)
{
    static $existenceCache = [];

    if (is_array($userId) || is_object($userId)) {
        return false;
    }

    $normalizedId = trim((string) $userId);
    if ($normalizedId === '' || !ctype_digit($normalizedId)) {
        return false;
    }

    if (array_key_exists($normalizedId, $existenceCache)) {
        return $existenceCache[$normalizedId];
    }

    $pdo = getDatabaseConnection();
    if (!($pdo instanceof PDO)) {
        $rxDbMarker = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'rx_db_unavailable.flag';
        if (!is_file($rxDbMarker) || (time() - (int) @filemtime($rxDbMarker)) > 3600) {
            error_log('userExists: Database connection is unavailable.');
            @touch($rxDbMarker);
        }
        return false;
    }

    try {
        $stmt = $pdo->prepare('SELECT 1 FROM user WHERE id = :user_id LIMIT 1');
        $stmt->bindParam(':user_id', $normalizedId, PDO::PARAM_STR);
        $stmt->execute();
        $exists = $stmt->fetchColumn() !== false;
    } catch (PDOException $exception) {
        error_log('userExists: ' . $exception->getMessage());
        $exists = false;
    }

    $existenceCache[$normalizedId] = $exists;

    return $exists;
}

function formatPaymentReportNote($rawNote)
{
    if ($rawNote === null) {
        return '';
    }

    if (is_array($rawNote)) {
        return json_encode($rawNote, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    if (!is_scalar($rawNote)) {
        return '';
    }

    $rawNote = trim((string) $rawNote);
    if ($rawNote === '') {
        return '';
    }

    $decoded = json_decode($rawNote, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
        if (($decoded['gateway'] ?? '') === 'zarinpay') {
            $lines = ['زرین‌پی'];
            $fieldMap = [
                'payment_id' => 'شناسه پرداخت',
                'reference_id' => 'شماره پیگیری',
                'authority' => 'کد اعتبار',
                'order_id' => 'کد سفارش',
                'code' => 'کد تأیید',
            ];

            foreach ($fieldMap as $key => $label) {
                $value = $decoded[$key] ?? null;
                if ($value !== null && $value !== '') {
                    $lines[] = sprintf('%s: %s', $label, $value);
                }
            }

            if (!empty($decoded['amount'])) {
                $lines[] = 'مبلغ تراکنش (ریال): ' . number_format((int) $decoded['amount']);
            }

            if (!empty($decoded['card_pan'])) {
                $lines[] = 'کارت پرداخت‌کننده: ' . $decoded['card_pan'];
            }

            if (!empty($decoded['paid_at'])) {
                $lines[] = 'زمان پرداخت: ' . $decoded['paid_at'];
            }

            return implode("\n", array_filter($lines));
        }

        return json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    return $rawNote;
}
function rxFormatToman($amount)
{
    if ($amount === null) {
        return '0';
    }
    if (is_bool($amount)) {
        return $amount ? '1' : '0';
    }
    if (!is_scalar($amount)) {
        return '0';
    }
    if (is_string($amount)) {
        $amount = trim($amount);
        if ($amount === '' || !is_numeric($amount)) {
            return '0';
        }
    }
    return number_format((float) $amount, 0, '.', ',');
}
function generateUUID()
{
    $data = openssl_random_pseudo_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);

    $uuid = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));

    return $uuid;
}

function rxTableValueExists($table, $field, $value)
{
    static $allowed = [
        'invoice'      => ['id_invoice' => true, 'username' => true],
        'Discount'     => ['code' => true],
        'DiscountSell' => ['codeDiscount' => true],
    ];
    if (!isset($allowed[$table][$field]) || is_array($value) || is_object($value)) {
        return false;
    }
    $pdo = getDatabaseConnection();
    if (!($pdo instanceof PDO)) {
        return false;
    }
    try {
        $stmt = $pdo->prepare("SELECT 1 FROM `{$table}` WHERE `{$field}` = BINARY :value LIMIT 1");
        $stmt->execute([':value' => (string) $value]);
        return $stmt->fetchColumn() !== false;
    } catch (Throwable $e) {
        error_log('rxTableValueExists: ' . $e->getMessage());
        return false;
    }
}
