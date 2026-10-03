<?php
date_default_timezone_set('Asia/Tehran');
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.cookie_httponly', '1');
session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../jdf.php';
require_once __DIR__ . '/../botapi.php';
require_once __DIR__ . '/../function.php';
// Reseller-management helpers (create/delete/balance/assign) live in the bot
// index-module helper. Load it in the panel web context too (function defs only,
// no side effects, guarded by require_once so no redeclare).
require_once __DIR__ . '/../re/rx/index/fx_reseller_mgmt.php';
require_once __DIR__ . '/lib/icons.php';
require_once __DIR__ . '/lib/csrf.php';

if (!isset($_SESSION["user"])) {
    header('Location: login.php');
    exit;
}

$query = $pdo->prepare("SELECT * FROM admin WHERE username=:username");
$query->bindParam("username", $_SESSION["user"], PDO::PARAM_STR);
$query->execute();
$adminRow = $query->fetch(PDO::FETCH_ASSOC);
if (!$adminRow) {
    header('Location: login.php');
    exit;
}

fx_csrf_guard();

// ---------- POST actions (POST-Redirect-GET) ----------
$fxFlash = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $uid = (int)($_POST['uid'] ?? 0);
    try {
        if ($action === 'create') {
            $token = trim((string)($_POST['token'] ?? ''));
            $res = fx_reseller_create_bot($uid, $token);
            $fxFlash = $res[1];
        } elseif ($action === 'charge') {
            $amount = (int)($_POST['amount'] ?? 0);
            if ($amount > 0 && $uid > 0 && fx_reseller_db_credit($uid, $amount, 'web_credit')) {
                $fxFlash = "✅ مبلغ {$amount} تومان به نماینده {$uid} شارژ شد.";
                @file_get_contents("https://api.telegram.org/bot{$ApiToken}/sendMessage?chat_id={$uid}&text=" . urlencode("💎 مبلغ {$amount} تومان از طریق وب‌پنل مدیریت به موجودی شما اضافه شد."));
            } else {
                $fxFlash = '❌ خطا در شارژ (مبلغ باید بزرگتر از صفر باشد).';
            }
        } elseif ($action === 'debit') {
            $amount = (int)($_POST['amount'] ?? 0);
            if ($amount > 0 && $uid > 0 && fx_reseller_db_debit($uid, $amount, 'web_debit')) {
                $fxFlash = "✅ مبلغ {$amount} تومان از نماینده {$uid} کسر شد.";
                @file_get_contents("https://api.telegram.org/bot{$ApiToken}/sendMessage?chat_id={$uid}&text=" . urlencode("⚠️ مبلغ {$amount} تومان از طریق وب‌پنل مدیریت از موجودی شما کسر شد."));
            } else {
                $fxFlash = '❌ موجودی کافی نیست یا خطا رخ داد.';
            }
        } elseif ($action === 'wh') {
            $bot = select("botsaz", "*", "id_user", $uid, "select", ['cache' => false]);
            if (!$bot) {
                $fxFlash = '❌ رباتی برای این نماینده وجود ندارد.';
            } else {
                $domain = $GLOBALS['domainhosts'] ?? 'faoxima.downloadbazi.fun/faoxima';
                $secret = hash('sha256', $bot['bot_token'] . '_faoxima_webhook_secret');
                $wh = telegram('setWebhook', [
                    'url' => "https://{$domain}/vpnbot/{$uid}{$bot['username']}/index.php",
                    'secret_token' => $secret,
                    'allowed_updates' => ['message', 'callback_query', 'edited_message'],
                ], $bot['bot_token']);
                $fxFlash = ((is_array($wh) && !empty($wh['ok'])) ? '✅ وبهوک ربات نماینده ریست شد.' : '❌ ست وبهوک ناموفق بود.');
            }
        } elseif ($action === 'prod') {
            $pid = (int)($_POST['product_id'] ?? 0);
            $fxFlash = ($pid > 0 && fx_reseller_assign_product_agent($pid, (string)$uid))
                ? "✅ محصول {$pid} به نماینده {$uid} اختصاص یافت."
                : '❌ خطا در اختصاص محصول.';
        } elseif ($action === 'panel') {
            $panId = (int)($_POST['panel_id'] ?? 0);
            $fxFlash = ($panId > 0 && fx_reseller_assign_panel_agent($panId, (string)$uid))
                ? "✅ پنل {$panId} به نماینده {$uid} اختصاص یافت."
                : '❌ خطا در اختصاص پنل.';
        } elseif ($action === 'pasargad') {
            $panId = (int)($_POST['panel_id'] ?? 0);
            $pgMode = ((string)($_POST['mode'] ?? '') === 'api_key') ? 'api_key' : 'password';
            $pgUname = trim((string)($_POST['username'] ?? ''));
            $pgPass = (string)($_POST['password'] ?? '');
            $pgKey = trim((string)($_POST['api_key'] ?? ''));
            $pgStmt = $pdo->prepare("SELECT id, name_panel FROM marzban_panel WHERE id = :id");
            $pgStmt->execute([':id' => $panId]);
            $pgRow = $pgStmt->fetch(PDO::FETCH_ASSOC);
            if (!$pgRow) {
                $fxFlash = '❌ پنل انتخاب‌شده وجود ندارد.';
            } elseif ($pgMode === 'password') {
                if ($pgUname === '' || $pgPass === '') {
                    $fxFlash = '❌ نام کاربری و گذرواژه پنل را وارد کنید.';
                } elseif (fx_reseller_set_pasargad_creds($panId, $pgUname, $pgPass)) {
                    $fxFlash = "✅ اعتبار پاسارگاد پنل «{$pgRow['name_panel']}» (نام کاربری/گذرواژه) تنظیم شد.";
                } else {
                    $fxFlash = '❌ خطا در تنظیم اعتبار.';
                }
            } else {
                if ($pgKey === '') {
                    $fxFlash = '❌ API Key را وارد کنید.';
                } else {
                    $pgStmt2 = $pdo->prepare("UPDATE marzban_panel SET api_key = :k, pasarguard_auth_mode = 'api_key' WHERE id = :id");
                    $pgStmt2->execute([':k' => $pgKey, ':id' => $panId]);
                    $fxFlash = ($pgStmt2->rowCount() > 0)
                        ? "✅ API Key پنل پاسارگاد «{$pgRow['name_panel']}» تنظیم شد."
                        : '❌ خطا در تنظیم API Key.';
                }
            }
        } elseif ($action === 'del') {
            $res = fx_reseller_delete_bot($uid);
            $fxFlash = $res[1];
        }
    } catch (\Throwable $e) {
        $fxFlash = '❌ خطای غیرمنتظره: ' . $e->getMessage();
        error_log('[panel/reseller_bots] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    }
    header('Location: reseller_bots.php?msg=' . rawurlencode($fxFlash), true, 302);
    exit;
}
if (isset($_GET['msg']) && $_GET['msg'] !== '') {
    $fxFlash = (string)$_GET['msg'];
}

// ---------- data ----------
$bots = fx_reseller_get_all();
$prods = $pdo->query("SELECT id, name_product, agent FROM product ORDER BY id DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
$panels = $pdo->query("SELECT id, name_panel, agent, username_panel, password_panel, api_key, pasarguard_auth_mode FROM marzban_panel ORDER BY id DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);

$fxMaskToken = static function ($t): string {
    $t = (string)$t;
    return (strlen($t) > 6) ? '••••' . substr($t, -4) : '••••';
};
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>ربات‌های نمایندگی | ربات فاکسیما</title>
    <link rel="stylesheet" href="css/theme.css?v=flat50">
    <script src="js/theme.js?v=flat50" defer></script>
</head>
<body>
<section id="container">
    <?php include("header.php"); ?>
    <section id="main-content">
        <div class="wrapper fx-page-list">
            <div class="page-head">
                <div>
                    <div class="page-head__title">
                        <svg class="svg-icon svg-lg" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><circle cx="9" cy="7" r="4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M23 21v-2a4 4 0 0 0-3-3.87" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M16 3.13a4 4 0 0 1 0 7.75" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        ربات‌های نمایندگی
                    </div>
                    <div class="page-head__sub">مدیریت ربات‌های نماینده (ساخت، شارژ، اختصاص محصول/پنل، وبهوک)</div>
                </div>
                <div class="chip-row">
                    <button onclick="openModal('modal-create')" class="btn btn-primary btn-sm">
                        <?php echo icon('plus', 'svg-icon'); ?> ساخت ربات نماینده
                    </button>
                </div>
            </div>

            <?php if ($fxFlash !== ''): ?>
                <div class="flash" style="padding:12px 14px;border-radius:10px;margin-bottom:14px;background:var(--accent-soft,#eef2ff);color:var(--text,#111);">
                    <?php echo htmlspecialchars($fxFlash, ENT_QUOTES, 'UTF-8'); ?>
                </div>
            <?php endif; ?>

            <div class="card">
                <div class="table-wrap">
                    <table class="display app-table" style="width:100%">
                        <thead>
                            <tr>
                                <th>نماینده</th>
                                <th>ربات</th>
                                <th>موجودی (تومان)</th>
                                <th>نوع</th>
                                <th>تاریخ ساخت</th>
                                <th>عملیات</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (!$bots): ?>
                            <tr><td colspan="6" style="text-align:center;padding:24px;color:#888;">هنوز ربات نماینده‌ای ساخته نشده است.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($bots as $r): $rid = (int)$r['id_user']; ?>
                            <tr>
                                <td data-label="نماینده">
                                    <div style="font-weight:600;"><?php echo htmlspecialchars((string)($r['u_username'] ?? ('ID: ' . $rid)), ENT_QUOTES, 'UTF-8'); ?></div>
                                    <div style="font-size:12px;color:#888;">ID: <?php echo $rid; ?></div>
                                </td>
                                <td data-label="ربات">
                                    <div style="font-weight:600;">@<?php echo htmlspecialchars((string)$r['username'], ENT_QUOTES, 'UTF-8'); ?></div>
                                    <div style="font-size:12px;color:#888;direction:ltr;text-align:right;"><?php echo htmlspecialchars($fxMaskToken($r['bot_token']), ENT_QUOTES, 'UTF-8'); ?></div>
                                </td>
                                <td data-label="موجودی" style="font-variant-numeric:tabular-nums;"><?php echo number_format((int)($r['Balance'] ?? 0)); ?></td>
                                <td data-label="نوع"><?php echo htmlspecialchars((string)($r['agent'] ?? 'f'), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td data-label="تاریخ ساخت"><?php echo htmlspecialchars((string)($r['time'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td data-label="عملیات" class="cell-actions">
                                    <div class="cell-actions__group">
                                        <button type="button" class="btn btn-sm btn-soft-info" title="شارژ موجودی" onclick="fxOpenAmount('charge', <?php echo $rid; ?>)"><?php echo icon('plus', 'svg-icon'); ?> شارژ</button>
                                        <button type="button" class="btn btn-sm btn-soft-warning" title="کسر موجودی" onclick="fxOpenAmount('debit', <?php echo $rid; ?>)"><?php echo icon('arrow-down', 'svg-icon'); ?> کسر</button>
                                        <button type="button" class="btn btn-sm btn-soft-info" title="اختصاص محصول" onclick="fxOpenAssign('prod', <?php echo $rid; ?>)"><?php echo icon('package', 'svg-icon'); ?> محصول</button>
                                        <button type="button" class="btn btn-sm btn-soft-info" title="اختصاص پنل" onclick="fxOpenAssign('panel', <?php echo $rid; ?>)"><?php echo icon('grid', 'svg-icon'); ?> پنل</button>
                                        <button type="button" class="btn btn-sm btn-soft-warning" title="اعتبار پنل پاسارگاد" onclick="fxOpenPasargad(<?php echo $rid; ?>)"><?php echo icon('key', 'svg-icon'); ?> پاسارگاد</button>
                                        <form method="POST" action="reseller_bots.php" style="display:inline;" onsubmit="return confirm('وبهوک ربات نماینده ریست شود؟');">
                                            <?php echo fx_csrf_field(); ?>
                                            <input type="hidden" name="action" value="wh">
                                            <input type="hidden" name="uid" value="<?php echo $rid; ?>">
                                            <button type="submit" class="btn btn-sm btn-soft-info" title="ریست وبهوک"><?php echo icon('refresh', 'svg-icon'); ?> وبهوک</button>
                                        </form>
                                        <form method="POST" action="reseller_bots.php" style="display:inline;" onsubmit="return confirm('آیا از حذف ربات نماینده مطمئن هستید؟ دایرکتوری و رکورد ربات حذف می‌شود.');">
                                            <?php echo fx_csrf_field(); ?>
                                            <input type="hidden" name="action" value="del">
                                            <input type="hidden" name="uid" value="<?php echo $rid; ?>">
                                            <button type="submit" class="btn btn-sm btn-soft-danger" title="حذف ربات"><?php echo icon('trash', 'svg-icon'); ?></button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            <?php $fxAgentPanels = array_values(array_filter($panels, static function ($pp) use ($rid) { return ((string)$pp['agent']) === (string)$rid; })); if ($fxAgentPanels): ?>
                            <tr>
                                <td colspan="6" style="background:var(--surface-2,#f6f7f9);font-size:12px;color:#555;padding:8px 14px;border-top:1px dashed var(--border,#e2e5ea);">
                                    <strong>📡 پنل‌های اختصاص‌یافته:</strong>
                                    <?php foreach ($fxAgentPanels as $pp):
                                        $ppMode = ((string)($pp['pasarguard_auth_mode'] ?? 'api_key') === 'password') ? 'password' : 'api_key';
                                        $ppHasU = ($pp['username_panel'] !== '' && $pp['username_panel'] !== null);
                                        $ppHasP = ($pp['password_panel'] !== '' && $pp['password_panel'] !== null);
                                        $ppHasK = ($pp['api_key'] !== '' && $pp['api_key'] !== null);
                                        $ppCred = ($ppMode === 'password')
                                            ? ('user: ' . ($ppHasU ? $pp['username_panel'] : '—') . ' / pass: ' . ($ppHasP ? '●' : '—'))
                                            : ('api_key: ' . ($ppHasK ? '●' : '—'));
                                    ?>
                                        <span style="display:inline-block;margin:2px 12px 2px 0;">
                                            <?php echo htmlspecialchars((string)$pp['name_panel'], ENT_QUOTES, 'UTF-8'); ?>
                                            <span style="color:#888;direction:ltr;display:inline-block;">(<?php echo htmlspecialchars($ppCred, ENT_QUOTES, 'UTF-8'); ?>)</span>
                                        </span>
                                    <?php endforeach; ?>
                                </td>
                            </tr>
                            <?php endif; ?>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </section>
</section>

<!-- Modal: create bot -->
<div id="modal-create" class="modal-overlay">
    <div class="modal-box" style="max-width:460px;">
        <div class="modal-head">
            <span class="modal-head__title">ساخت ربات نماینده</span>
            <button class="modal-close" onclick="closeModal('modal-create')">&times;</button>
        </div>
        <form action="reseller_bots.php" method="POST">
            <?php echo fx_csrf_field(); ?>
            <input type="hidden" name="action" value="create">
            <div class="form-group">
                <label class="form-label">آیدی نماینده (عدد)</label>
                <input type="number" name="uid" class="form-control" placeholder="مثلاً 6327424512" required>
            </div>
            <div class="form-group">
                <label class="form-label">توکن ربات جدید (از BotFather)</label>
                <input type="text" name="token" class="form-control" dir="ltr" placeholder="123456789:AAxxx" required>
            </div>
            <button type="submit" class="btn btn-primary btn-block" onclick="return confirm('با این توکن ربات نماینده ساخته و وبهوک آن ست می‌شود. ادامه؟');">ساخت ربات</button>
        </form>
    </div>
</div>

<!-- Modal: charge / debit -->
<div id="modal-amount" class="modal-overlay">
    <div class="modal-box" style="max-width:420px;">
        <div class="modal-head">
            <span class="modal-head__title" id="amount-title">شارژ موجودی</span>
            <button class="modal-close" onclick="closeModal('modal-amount')">&times;</button>
        </div>
        <form action="reseller_bots.php" method="POST">
            <?php echo fx_csrf_field(); ?>
            <input type="hidden" name="action" id="amount-action" value="charge">
            <input type="hidden" name="uid" id="amount-uid" value="">
            <div class="form-group">
                <label class="form-label">مبلغ (تومان)</label>
                <input type="number" name="amount" class="form-control" min="1" step="1" required>
            </div>
            <button type="submit" class="btn btn-primary btn-block">تأیید</button>
        </form>
    </div>
</div>

<!-- Modal: assign product -->
<div id="modal-prod" class="modal-overlay">
    <div class="modal-box" style="max-width:460px;">
        <div class="modal-head">
            <span class="modal-head__title">اختصاص محصول به نماینده</span>
            <button class="modal-close" onclick="closeModal('modal-prod')">&times;</button>
        </div>
        <form action="reseller_bots.php" method="POST">
            <?php echo fx_csrf_field(); ?>
            <input type="hidden" name="action" value="prod">
            <input type="hidden" name="uid" id="prod-uid" value="">
            <div class="form-group">
                <label class="form-label">محصول</label>
                <select name="product_id" class="form-control" required>
                    <option value="">— انتخاب محصول —</option>
                    <?php foreach ($prods as $p): ?>
                        <option value="<?php echo (int)$p['id']; ?>"><?php echo htmlspecialchars((string)$p['name_product'], ENT_QUOTES, 'UTF-8'); ?> (id: <?php echo (int)$p['id']; ?> — agent: <?php echo htmlspecialchars((string)($p['agent'] ?: '—'), ENT_QUOTES, 'UTF-8'); ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn btn-primary btn-block">اختصاص</button>
        </form>
    </div>
</div>

<!-- Modal: assign panel -->
<div id="modal-panel" class="modal-overlay">
    <div class="modal-box" style="max-width:460px;">
        <div class="modal-head">
            <span class="modal-head__title">اختصاص پنل به نماینده</span>
            <button class="modal-close" onclick="closeModal('modal-panel')">&times;</button>
        </div>
        <form action="reseller_bots.php" method="POST">
            <?php echo fx_csrf_field(); ?>
            <input type="hidden" name="action" value="panel">
            <input type="hidden" name="uid" id="panel-uid" value="">
            <div class="form-group">
                <label class="form-label">پنل</label>
                <select name="panel_id" class="form-control" required>
                    <option value="">— انتخاب پنل —</option>
                    <?php foreach ($panels as $p): ?>
                        <option value="<?php echo (int)$p['id']; ?>"><?php echo htmlspecialchars((string)$p['name_panel'], ENT_QUOTES, 'UTF-8'); ?> (id: <?php echo (int)$p['id']; ?> — agent: <?php echo htmlspecialchars((string)($p['agent'] ?: '—'), ENT_QUOTES, 'UTF-8'); ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn btn-primary btn-block">اختصاص</button>
        </form>
    </div>
</div>

<!-- Modal: Pasargad panel credentials -->
<div id="modal-pasargad" class="modal-overlay">
    <div class="modal-box" style="max-width:480px;">
        <div class="modal-head">
            <span class="modal-head__title"><?php echo icon('key', 'svg-icon'); ?> اعتبار پنل پاسارگاد</span>
            <button class="modal-close" onclick="closeModal('modal-pasargad')">&times;</button>
        </div>
        <form action="reseller_bots.php" method="POST">
            <?php echo fx_csrf_field(); ?>
            <input type="hidden" name="action" value="pasargad">
            <input type="hidden" name="uid" id="pg-uid" value="">
            <div class="form-group">
                <label class="form-label">نماینده</label>
                <input type="text" class="form-control" id="pg-uid-label" readonly value="—">
            </div>
            <div class="form-group">
                <label class="form-label">پنل پاسارگاد</label>
                <select name="panel_id" class="form-control" required>
                    <option value="">— انتخاب پنل —</option>
                    <?php foreach ($panels as $p): ?>
                        <option value="<?php echo (int)$p['id']; ?>"><?php echo htmlspecialchars((string)$p['name_panel'], ENT_QUOTES, 'UTF-8'); ?> (id: <?php echo (int)$p['id']; ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">نوع احراز هویت</label>
                <select name="mode" class="form-control" id="pg-mode" onchange="fxPgToggle()" required>
                    <option value="password">نام کاربری / گذرواژه</option>
                    <option value="api_key">API Key</option>
                </select>
            </div>
            <div class="form-group" id="pg-f-user">
                <label class="form-label">نام کاربری</label>
                <input type="text" name="username" class="form-control" dir="ltr" placeholder="admin" autocomplete="off">
            </div>
            <div class="form-group" id="pg-f-pass">
                <label class="form-label">گذرواژه</label>
                <input type="password" name="password" class="form-control" dir="ltr" placeholder="••••••••" autocomplete="new-password">
            </div>
            <div class="form-group" id="pg-f-key" style="display:none;">
                <label class="form-label">API Key</label>
                <input type="text" name="api_key" class="form-control" dir="ltr" placeholder="api-key" autocomplete="off">
            </div>
            <button type="submit" class="btn btn-primary btn-block">ذخیره اعتبار</button>
        </form>
    </div>
</div>

<script>
function fxOpenAmount(kind, uid) {
    document.getElementById('amount-action').value = kind;
    document.getElementById('amount-uid').value = uid;
    document.getElementById('amount-title').textContent = (kind === 'charge') ? 'شارژ موجودی نماینده ' + uid : 'کسر موجودی نماینده ' + uid;
    openModal('modal-amount');
}
function fxOpenAssign(kind, uid) {
    document.getElementById(kind + '-uid').value = uid;
    openModal('modal-' + kind);
}
function fxOpenPasargad(uid) {
    document.getElementById('pg-uid').value = uid;
    document.getElementById('pg-uid-label').value = uid;
    document.getElementById('pg-mode').value = 'password';
    document.getElementById('pg-f-user').style.display = '';
    document.getElementById('pg-f-pass').style.display = '';
    document.getElementById('pg-f-key').style.display = 'none';
    openModal('modal-pasargad');
}
function fxPgToggle() {
    var isKey = document.getElementById('pg-mode').value === 'api_key';
    document.getElementById('pg-f-user').style.display = isKey ? 'none' : '';
    document.getElementById('pg-f-pass').style.display = isKey ? 'none' : '';
    document.getElementById('pg-f-key').style.display = isKey ? '' : 'none';
}
</script>
</body>
</html>
