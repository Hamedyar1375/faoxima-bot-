<?php
// FX-RESELLER-PANEL: login — restricted to accounts whose id_admin is a
// reseller owner (botsaz.id_user). Main admin accounts are rejected.

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

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../function.php';
require_once __DIR__ . '/lib/rid.php';
require_once __DIR__ . '/../lib/icons.php';

$user_ip = $_SERVER['REMOTE_ADDR'] ?? '';
$texterrr = '';
$ip_denied = false;

if (isset($_SESSION['reseller'])) {
    header('Location: index.php', true, 302);
    exit;
}

if (isset($_POST['login'])) {
    $username = isset($_POST['username']) ? trim((string) $_POST['username']) : '';
    $password = isset($_POST['password']) ? (string) $_POST['password'] : '';

    if ($username !== '' && $password !== '') {
        $query = $pdo->prepare("SELECT * FROM admin WHERE username = :username LIMIT 1");
        $query->bindValue(':username', $username, PDO::PARAM_STR);
        $query->execute();
        $result = $query->fetch(PDO::FETCH_ASSOC);

        $passwordOk = false;
        if ($result) {
            $storedHash = (string) ($result["password_hash"] ?? '');
            if ($storedHash !== '' && password_verify($password, $storedHash)) {
                $passwordOk = true;
                if (password_needs_rehash($storedHash, PASSWORD_DEFAULT)) {
                    $rehash = $pdo->prepare("UPDATE admin SET password_hash = :h WHERE id_admin = :id");
                    $rehash->execute([':h' => password_hash($password, PASSWORD_DEFAULT), ':id' => $result['id_admin']]);
                }
            } elseif ($storedHash === '' && (string) $password === (string) ($result["password"] ?? '') && $password !== '') {
                $passwordOk = true;
                $migrate = $pdo->prepare("UPDATE admin SET password_hash = :h WHERE id_admin = :id");
                $migrate->execute([':h' => password_hash($password, PASSWORD_DEFAULT), ':id' => $result['id_admin']]);
            }
        }

        $adminIpOk = true;
        if ($result) {
            $rawAdminIp = $result['iplogin'] ?? null;
            if ($rawAdminIp !== null && $rawAdminIp !== '') {
                $adminIpDecoded = json_decode((string) $rawAdminIp, true);
                if (is_array($adminIpDecoded)) {
                    if (in_array('*', $adminIpDecoded, true) || in_array('all', $adminIpDecoded, true) || in_array('unlimited', $adminIpDecoded, true)) {
                        $adminIpOk = true;
                    } else {
                        $adminIpOk = in_array($user_ip, $adminIpDecoded, true);
                    }
                } elseif ($rawAdminIp === '*' || $rawAdminIp === 'all' || $rawAdminIp === 'unlimited') {
                    $adminIpOk = true;
                } elseif (filter_var($rawAdminIp, FILTER_VALIDATE_IP)) {
                    $adminIpOk = ($rawAdminIp === $user_ip);
                }
            }
        }

        if (!$result) {
            $texterrr = 'نام کاربری یا رمزعبور وارد شده اشتباه است!';
        } elseif (!$passwordOk) {
            $texterrr = 'رمز صحیح نمی باشد';
        } elseif (!$adminIpOk) {
            http_response_code(403);
            $ip_denied = true;
        } elseif ((fx_res_panel_rid_for_username($pdo, (string) $result['username'] ?? '')) === null) {
            $texterrr = 'این حساب کاربری برای پنل نمایندگی مجاز نیست.';
        } else {
            session_regenerate_id(true);
            $_SESSION['reseller'] = (string) $result['username'];
            session_write_close();
            header('Location: index.php', true, 302);
            exit;
        }
    } else {
        $texterrr = 'نام کاربری یا رمز عبور خالی است.';
    }
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ورود | پنل نمایندگی فاکسیما</title>
<style>
:root{--bg:#0b0e14;--card:#121722;--card2:#0f141e;--line:#1e2634;--txt:#e8edf5;--mut:#8b98ab;--acc:#3b82f6;--acc2:#60a5fa;}
*{box-sizing:border-box;margin:0;padding:0}
body{background:var(--bg);color:var(--txt);font-family:Vazirmatn,Tahoma,'Segoe UI',sans-serif;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:16px}
.rp-login{width:100%;max-width:380px;background:var(--card);border:1px solid var(--line);border-radius:16px;padding:28px 24px}
.rp-login h1{font-size:18px;margin-bottom:4px}
.rp-login p{color:var(--mut);font-size:13px;margin-bottom:18px}
.rp-login label{display:block;color:var(--mut);font-size:12px;margin:12px 0 4px}
.rp-login input{width:100%;background:var(--card2);border:1px solid var(--line);color:var(--txt);border-radius:9px;padding:10px 12px;font-size:14px;outline:none}
.rp-login input:focus{border-color:var(--acc)}
.rp-btn{display:block;width:100%;margin-top:18px;background:var(--acc);color:#fff;border:none;border-radius:9px;padding:11px;font-size:14px;font-weight:700;cursor:pointer;font-family:inherit}
.rp-btn:hover{filter:brightness(1.1)}
.rp-err{background:rgba(239,68,68,.12);border:1px solid rgba(239,68,68,.4);color:#fca5a5;border-radius:9px;padding:8px 12px;font-size:13px;margin-bottom:10px}
.rp-foot{margin-top:14px;text-align:center;color:var(--mut);font-size:12px}
</style>
</head>
<body>
<div class="rp-login">
    <h1>🛰 پنل نمایندگی فاکسیما</h1>
    <p>ورود مخصوص مدیران ربات نمایندگی</p>
    <?php if ($texterrr !== ''): ?><div class="rp-err"><?= htmlspecialchars($texterrr, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
    <?php if ($ip_denied): ?><div class="rp-err">⛔ دسترسی از این IP مجاز نیست.</div><?php endif; ?>
    <form method="post" action="login.php">
        <label for="username">نام کاربری</label>
        <input type="text" id="username" name="username" value="<?= htmlspecialchars($_POST['username'] ?? '', ENT_QUOTES, 'UTF-8') ?>" autocomplete="username" required>
        <label for="password">رمز عبور</label>
        <input type="password" id="password" name="password" autocomplete="current-password" required>
        <button type="submit" class="rp-btn" name="login" value="1">ورود به پنل</button>
    </form>
    <div class="rp-foot">فاکسیما — نسخه نمایندگی</div>
</div>
</body>
</html>
