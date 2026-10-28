<?php
// FX-RESELLER-PANEL: dedicated reseller web panel — auth/session bootstrap.
// Isolated from the main admin panel: separate session name, and the login is
// restricted to admin-table accounts whose id_admin IS a reseller (botsaz.id_user).
// Every page includes this file first; fx_res_panel_require() resolves the
// tenant rid and bounces unauthenticated visitors to login.php.

if (!defined('FAOXIMA_SKIP_BOTAPI_ROUTER')) {
    define('FAOXIMA_SKIP_BOTAPI_ROUTER', true);
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('faoxima_reseller_panel');
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.cookie_httponly', '1');
    if ((!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') {
        ini_set('session.cookie_secure', '1');
    }
    session_start();
}

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../function.php';
require_once __DIR__ . '/../../../jdf.php';
require_once __DIR__ . '/../../../re/rx/function/statistics_helpers.php';
require_once __DIR__ . '/../../../re/rx/function/fx_reseller_service_mgmt.php';
require_once __DIR__ . '/../../../re/rx/function/fx_reseller_stats.php';
require_once __DIR__ . '/rid.php';
require_once __DIR__ . '/../../lib/icons.php';
require_once __DIR__ . '/../../../panel/lib/csrf.php';

function fx_res_panel_require(): array
{
    global $pdo;
    $username = (isset($_SESSION['reseller']) && is_string($_SESSION['reseller'])) ? trim($_SESSION['reseller']) : '';
    $rid = ($username !== '' && ($pdo instanceof PDO)) ? fx_res_panel_rid_for_username($pdo, $username) : null;
    if ($rid === null) {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            @setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        @session_destroy();
        header('Location: login.php', true, 302);
        exit;
    }
    return ['username' => $username, 'rid' => $rid];
}

function fx_res_panel_bot(PDO $pdo, int $rid): ?array
{
    $stmt = $pdo->prepare("SELECT id_user, username, bot_token FROM botsaz WHERE id_user = :id LIMIT 1");
    $stmt->execute([':id' => $rid]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function fx_res_panel_origin(): string
{
    $host = rtrim((string) preg_replace('#^https?://#i', '', trim((string) ($GLOBALS['domainhosts'] ?? ''))), '/');
    return ($host !== '') ? 'https://' . $host : '';
}

function fx_res_panel_flash(?string $msg = null): string
{
    if ($msg !== null) {
        $_SESSION['fx_rp_flash'] = $msg;
        return '';
    }
    $m = (string) ($_SESSION['fx_rp_flash'] ?? '');
    unset($_SESSION['fx_rp_flash']);
    return $m;
}

function fx_res_panel_head(string $title): void
{
    ?><!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?> | پنل نمایندگی فاکسیما</title>
<style>
:root{--bg:#0b0e14;--card:#121722;--card2:#0f141e;--line:#1e2634;--txt:#e8edf5;--mut:#8b98ab;--acc:#3b82f6;--acc2:#60a5fa;--ok:#22c55e;--warn:#f59e0b;--bad:#ef4444;}
*{box-sizing:border-box;margin:0;padding:0}
body{background:var(--bg);color:var(--txt);font-family:Vazirmatn,Tahoma,'Segoe UI',sans-serif;font-size:14px;line-height:1.7;min-height:100vh}
a{color:var(--acc2);text-decoration:none}
a:hover{text-decoration:underline}
.rp-wrap{max-width:1100px;margin:0 auto;padding:18px 16px 60px}
.rp-top{display:flex;align-items:center;gap:12px;padding:10px 4px 18px;border-bottom:1px solid var(--line);margin-bottom:20px;flex-wrap:wrap}
.rp-logo{font-size:18px;font-weight:800;color:var(--acc2)}
.rp-nav{display:flex;gap:6px;flex-wrap:wrap;margin-left:auto}
.rp-nav a{padding:7px 14px;border-radius:9px;color:var(--mut);font-weight:600}
.rp-nav a:hover{color:var(--txt);background:var(--card);text-decoration:none}
.rp-nav a.on{background:var(--acc);color:#fff}
.rp-card{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:18px;margin-bottom:16px}
.rp-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:12px;margin-bottom:16px}
.rp-stat{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:14px 16px}
.rp-stat .l{color:var(--mut);font-size:12px;margin-bottom:4px}
.rp-stat .v{font-size:20px;font-weight:800}
.rp-h{font-size:16px;font-weight:800;margin-bottom:12px;display:flex;align-items:center;gap:8px}
table.rp-tbl{width:100%;border-collapse:collapse}
.rp-tbl th{color:var(--mut);font-size:12px;text-align:right;padding:8px 10px;border-bottom:1px solid var(--line);white-space:nowrap}
.rp-tbl td{padding:9px 10px;border-bottom:1px solid var(--line);vertical-align:middle}
.rp-tbl tr:last-child td{border-bottom:none}
.rp-badge{display:inline-block;padding:2px 10px;border-radius:999px;font-size:12px;font-weight:700}
.rp-badge.on{background:rgba(34,197,94,.15);color:var(--ok)}
.rp-badge.off{background:rgba(239,68,68,.15);color:var(--bad)}
.rp-badge.mut{background:rgba(139,152,171,.15);color:var(--mut)}
.rp-form{display:grid;grid-template-columns:1fr 1fr;gap:10px;align-items:end}
.rp-form .full{grid-column:1/-1}
.rp-form label{display:block;color:var(--mut);font-size:12px;margin-bottom:4px}
.rp-form input[type=text],.rp-form input[type=password]{width:100%;background:var(--card2);border:1px solid var(--line);color:var(--txt);border-radius:9px;padding:9px 12px;font-size:13px;outline:none}
.rp-form input:focus{border-color:var(--acc)}
.rp-btn{display:inline-block;background:var(--acc);color:#fff;border:none;border-radius:9px;padding:9px 18px;font-size:13px;font-weight:700;cursor:pointer;font-family:inherit}
.rp-btn:hover{filter:brightness(1.1);text-decoration:none}
.rp-btn.ghost{background:transparent;border:1px solid var(--line);color:var(--txt)}
.rp-btn.danger{background:rgba(239,68,68,.9)}
.rp-flash{background:rgba(59,130,246,.12);border:1px solid rgba(59,130,246,.35);color:var(--acc2);border-radius:10px;padding:10px 14px;margin-bottom:14px;font-size:13px}
.rp-mut{color:var(--mut);font-size:12px}
.rp-login{max-width:380px;margin:9vh auto;background:var(--card);border:1px solid var(--line);border-radius:16px;padding:28px 24px}
.rp-login h1{font-size:18px;margin-bottom:4px}
.rp-login p{color:var(--mut);font-size:13px;margin-bottom:18px}
.rp-login label{display:block;color:var(--mut);font-size:12px;margin:12px 0 4px}
.rp-login input{width:100%;background:var(--card2);border:1px solid var(--line);color:var(--txt);border-radius:9px;padding:10px 12px;font-size:14px;outline:none}
.rp-login input:focus{border-color:var(--acc)}
.rp-login .rp-btn{width:100%;margin-top:18px;padding:11px}
.rp-err{background:rgba(239,68,68,.12);border:1px solid rgba(239,68,68,.4);color:#fca5a5;border-radius:9px;padding:8px 12px;font-size:13px;margin-bottom:10px}
@media (max-width:640px){.rp-form{grid-template-columns:1fr}.rp-tbl{display:block;overflow-x:auto}}
</style>
</head>
<body>
<div class="rp-wrap">
<?php
}

function fx_res_panel_nav(string $active, string $botName = ''): void
{
    $items = [
        'index' => ['index.php', 'داشبورد'],
        'products' => ['products.php', 'محصولات'],
        'users' => ['users.php', 'کاربران'],
        'services' => ['services.php', 'پنل‌ها و سرویس‌ها'],
    ];
    $origin = fx_res_panel_origin();
    $appUrl = $origin !== '' ? $origin . '/app/' : '#';
    ?><div class="rp-top">
    <span class="rp-logo">🛰 پنل نمایندگی فاکسیما<?= $botName !== '' ? ' — ' . htmlspecialchars($botName, ENT_QUOTES, 'UTF-8') : '' ?></span>
    <nav class="rp-nav">
        <?php foreach ($items as $k => $it): ?>
        <a class="<?= $active === $k ? 'on' : '' ?>" href="<?= $it[0] ?>"><?= $it[1] ?></a>
        <?php endforeach; ?>
        <a href="<?= htmlspecialchars($appUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">مینی‌اپ ↗</a>
        <a href="logout.php">خروج</a>
    </nav>
</div>
<?php if (($f = fx_res_panel_flash()) !== ''): ?><div class="rp-flash"><?= htmlspecialchars($f, ENT_QUOTES, 'UTF-8') ?></div><?php endif;
}

function fx_res_panel_foot(): void
{
    ?></div>
</body>
</html><?php
}
