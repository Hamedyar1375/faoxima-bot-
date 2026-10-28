<?php
// FX-RESELLER-PANEL: logout — destroy the reseller-panel session only.

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('faoxima_reseller_panel');
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.cookie_httponly', '1');
    if ((!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') {
        ini_set('session.cookie_secure', '1');
    }
    session_start();
}
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    @setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
}
@session_destroy();
header('Location: login.php', true, 302);
exit;
