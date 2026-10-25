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
// FX-RESELLER (2026-10-16): per-reseller product manager (retail override + visibility).
require_once __DIR__ . '/../re/rx/index/fx_retail_override.php';
require_once __DIR__ . '/../re/rx/index/fx_res_visibility.php';
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
                $domain = $GLOBALS['domainhosts'] ?? 'faoxima.downloadbazi.fun';
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
            $wholesale = ($pid > 0 && (int)$uid > 0) ? fx_reseller_product_wholesale_value($pid) : null;
            if ($wholesale === null) {
                $fxFlash = '❌ محصول یا نماینده نامعتبر است.';
            } elseif ($wholesale <= 0) {
                $fxFlash = "❌ محصول {$pid} قیمت عمده معتبر ندارد؛ ابتدا قیمت عمده بزرگ‌تر از صفر را ثبت کنید.";
            } else {
                $fxFlash = fx_reseller_assign_product_agent($pid, (string)$uid)
                    ? "✅ محصول {$pid} به نماینده {$uid} اختصاص یافت."
                    : '❌ خطا در اختصاص محصول.';
            }
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
        } elseif ($action === 'whprice') {
            $pid = (int)($_POST['product_id'] ?? 0);
            $amount = (int)($_POST['amount'] ?? 0);
            if ($pid <= 0) {
                $fxFlash = '❌ محصول نامعتبر است.';
            } elseif ($amount < 0) {
                $fxFlash = '❌ مبلغ نامعتبر است.';
            } else {
                $st = $pdo->prepare("UPDATE product SET fx_wholesale_price = :w WHERE id = :pid");
                $st->execute([':w' => ($amount > 0 ? $amount : null), ':pid' => $pid]);
                $fxFlash = ($amount > 0)
                    ? "✅ قیمت عمده‌فروشی محصول {$pid} روی " . number_format($amount) . " تومان تنظیم شد."
                    : "⚠️ قیمت عمده‌فروشی محصول {$pid} پاک شد؛ فروش این محصول تا ثبت مجدد مسدود است.";
            }
        } elseif ($action === 'retail') {
            $pid = (int)($_POST['product_id'] ?? 0);
            $amount = (int)($_POST['amount'] ?? 0);
            if ($pid <= 0) {
                $fxFlash = '❌ محصول نامعتبر است.';
            } elseif ($amount > 0) {
                $fxFlash = fx_set_retail_price($pid, $uid, $amount)
                    ? "✅ قیمت فروش اختصاصی نماینده {$uid} برای محصول {$pid} روی " . number_format($amount) . " تومان تنظیم شد."
                    : '❌ خطا در تنظیم قیمت فروش.';
            } else {
                $fxFlash = fx_delete_retail_price($pid, $uid)
                    ? "✅ قیمت اختصاصی نماینده برای محصول {$pid} حذف شد (برگشت به قیمت پایه)."
                    : '❌ خطا در حذف قیمت اختصاصی.';
            }
        } elseif ($action === 'vis') {
            $pid = (int)($_POST['product_id'] ?? 0);
            $hidden = ((string)($_POST['hidden'] ?? '0') === '1');
            if ($pid <= 0) {
                $fxFlash = '❌ محصول نامعتبر است.';
            } elseif (fx_res_visibility_set($pid, $uid, $hidden)) {
                $fxFlash = $hidden
                    ? "🚫 محصول {$pid} برای مشتریان نماینده {$uid} پنهان شد."
                    : "👁 محصول {$pid} دوباره برای مشتریان نماینده {$uid} نمایش داده می‌شود.";
            } else {
                $fxFlash = '❌ خطا در تغییر وضعیت نمایش.';
            }
        } elseif ($action === 'release') {
            $pid = (int)($_POST['product_id'] ?? 0);
            if ($pid <= 0) {
                $fxFlash = '❌ محصول نامعتبر است.';
            } else {
                $st = $pdo->prepare("SELECT agent FROM product WHERE id = :pid LIMIT 1");
                $st->execute([':pid' => $pid]);
                $curAg = (string)($st->fetchColumn() ?? '');
                $parts = array_values(array_filter(
                    array_map('trim', explode(',', str_replace(' ', '', $curAg))),
                    static function ($x) use ($uid) { return $x !== '' && $x !== (string)$uid; }
                ));
                $newAg = $parts ? implode(',', $parts) : 'all';
                if ($newAg === $curAg) {
                    $fxFlash = 'ℹ️ این محصول اختصاصی این نماینده نیست.';
                } else {
                    $up = $pdo->prepare("UPDATE product SET agent = :a WHERE id = :pid");
                    $up->execute([':a' => $newAg, ':pid' => $pid]);
                    $fxFlash = "♻️ محصول {$pid} از نماینده {$uid} آزاد شد (agent: {$newAg}).";
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
    $fxRedir = 'reseller_bots.php?msg=' . rawurlencode($fxFlash);
    if (in_array($action, ['whprice', 'retail', 'vis', 'release'], true) && $uid > 0) {
        $fxRedir .= '&products=' . $uid;
    }
    header('Location: ' . $fxRedir, true, 302);
    exit;
}
if (isset($_GET['msg']) && $_GET['msg'] !== '') {
    $fxFlash = (string)$_GET['msg'];
}

// ---------- data ----------
$bots = fx_reseller_get_all();
$prods = $pdo->query("SELECT id, name_product, agent, fx_wholesale_price FROM product ORDER BY id DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
$panels = $pdo->query("SELECT id, name_panel, agent, username_panel, password_panel, api_key, pasarguard_auth_mode FROM marzban_panel ORDER BY id DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);

// FX-RESELLER (2026-10-16): per-reseller product counts + products manager data.
$fxAgentProdCounts = [];
$fxSharedProdCount = 0;
try {
    foreach ($pdo->query("SELECT agent, COUNT(*) AS c FROM product GROUP BY agent")->fetchAll(PDO::FETCH_ASSOC) as $ar) {
        $ag = str_replace(' ', '', (string)($ar['agent'] ?? ''));
        if ($ag === '') {
            $fxSharedProdCount += (int)$ar['c'];
            continue;
        }
        foreach (explode(',', $ag) as $oneAg) {
            if ($oneAg === '') continue;
            if (in_array($oneAg, ['all', 'allusers', 'f', 'n', 'n2'], true)) {
                $fxSharedProdCount += (int)$ar['c'];
            } else {
                $fxAgentProdCounts[$oneAg] = ($fxAgentProdCounts[$oneAg] ?? 0) + (int)$ar['c'];
            }
        }
    }
} catch (\Throwable $e) {}

$fxProdRid = isset($_GET['products']) ? (int)$_GET['products'] : 0;
$fxProdRows = [];
$fxProdHidden = [];
if ($fxProdRid > 0) {
    try {
        $fxProdScope = function_exists('fx_res_product_scope_sql')
            ? fx_res_product_scope_sql('p', ':rid2')
            : "(FIND_IN_SET(:rid2, REPLACE(p.agent, ' ', '')) OR p.agent IN ('all', 'allusers', 'f', 'n', 'n2') OR p.agent = '')";
        $st = $pdo->prepare(
            "SELECT p.id, p.name_product, p.agent, p.price_product AS base_price, p.fx_wholesale_price AS wholesale, r.retail_price AS retail " .
            "FROM product p LEFT JOIN fx_retail_prices r ON r.product_id = p.id AND r.reseller_id = :rid " .
            "WHERE {$fxProdScope} ORDER BY p.id ASC LIMIT 300"
        );
        $st->execute([':rid' => $fxProdRid, ':rid2' => (string)$fxProdRid]);
        $fxProdRows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) {}
    $fxProdHidden = fx_res_visibility_hidden_ids($fxProdRid);
}

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
                                        <?php $fxCnt = (int)($fxAgentProdCounts[(string)$rid] ?? 0) + $fxSharedProdCount; ?>
                                        <a class="btn btn-sm btn-soft-success" title="مدیریت محصولات این نماینده (قیمت عمده، قیمت فروش، نمایش، آزادسازی)" href="reseller_bots.php?products=<?php echo $rid; ?>"><?php echo icon('package', 'svg-icon'); ?> محصولات<?php echo $fxCnt > 0 ? ' (' . $fxCnt . ')' : ''; ?></a>
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
                        <option value="<?php echo (int)$p['id']; ?>"><?php echo htmlspecialchars((string)$p['name_product'], ENT_QUOTES, 'UTF-8'); ?> (id: <?php echo (int)$p['id']; ?> — agent: <?php echo htmlspecialchars((string)($p['agent'] ?: '—'), ENT_QUOTES, 'UTF-8'); ?> — <?php echo (int)($p['fx_wholesale_price'] ?? 0) > 0 ? 'عمده: ' . number_format((int)$p['fx_wholesale_price']) : '⛔ بدون قیمت عمده'; ?>)</option>
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

<?php if ($fxProdRid > 0): ?>
<!-- Modal: reseller products manager (FX-RESELLER 2026-10-16) -->
<div id="modal-products" class="modal-overlay" style="display:flex;overflow:auto;">
    <div class="modal-box" style="max-width:980px;width:96%;">
        <div class="modal-head">
            <span class="modal-head__title">🛍 محصولات نماینده <?php echo $fxProdRid; ?></span>
            <a class="modal-close" href="reseller_bots.php" style="text-decoration:none;">&times;</a>
        </div>
        <?php if ($fxFlash !== ''): ?>
            <div class="flash" style="padding:10px 12px;border-radius:10px;margin:0 0 12px;background:var(--accent-soft,#eef2ff);color:var(--text,#111);">
                <?php echo htmlspecialchars($fxFlash, ENT_QUOTES, 'UTF-8'); ?>
            </div>
        <?php endif; ?>
        <div class="table-wrap" style="max-height:65vh;overflow:auto;">
            <table class="display app-table" style="width:100%">
                <thead>
                    <tr>
                        <th>محصول</th>
                        <th>نوع</th>
                        <th>قیمت پایه</th>
                        <th>عمده‌فروشی (هزینه نماینده)</th>
                        <th>فروش نماینده (مشتری)</th>
                        <th>نمایش</th>
                        <th>آزادسازی</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$fxProdRows): ?>
                    <tr><td colspan="7" style="text-align:center;padding:24px;color:#888;">محصولی برای این نماینده یافت نشد. از دکمه «محصول» در فهرست، محصول اختصاص دهید.</td></tr>
                <?php endif; ?>
                <?php foreach ($fxProdRows as $pr):
                    $prPid = (int)$pr['id'];
                    $prBase = (int)($pr['base_price'] ?? 0);
                    $prWh = (int)($pr['wholesale'] ?? 0);
                    $prRet = (int)($pr['retail'] ?? 0);
                    $prExc = in_array((string)$fxProdRid, array_filter(array_map('trim', explode(',', (string)$pr['agent']))), true);
                    $prHid = in_array($prPid, $fxProdHidden, true);
                    $prCur = $prRet > 0 ? $prRet : $prBase;
                ?>
                    <tr>
                        <td data-label="محصول">
                            <div style="font-weight:600;"><?php echo htmlspecialchars((string)$pr['name_product'], ENT_QUOTES, 'UTF-8'); ?></div>
                            <div style="font-size:12px;color:#888;">ID: <?php echo $prPid; ?> — <?php echo $prWh > 0 ? 'سود تقریبی هر فروش: ' . number_format($prCur - $prWh) . ' تومان' : '⛔ بدون قیمت عمده — فروش مسدود'; ?></div>
                        </td>
                        <td data-label="نوع"><?php echo $prExc ? '<span style="color:#0a7">اختصاصی</span>' : '<span style="color:#888">مشترک</span>'; ?></td>
                        <td data-label="پایه" style="font-variant-numeric:tabular-nums;"><?php echo number_format($prBase); ?></td>
                        <td data-label="عمده">
                            <form method="POST" action="reseller_bots.php" style="display:flex;gap:6px;align-items:center;">
                                <?php echo fx_csrf_field(); ?>
                                <input type="hidden" name="action" value="whprice">
                                <input type="hidden" name="uid" value="<?php echo $fxProdRid; ?>">
                                <input type="hidden" name="product_id" value="<?php echo $prPid; ?>">
                                <input type="number" name="amount" class="form-control" style="width:110px;" min="0" step="1" value="<?php echo $prWh > 0 ? $prWh : ''; ?>" placeholder="مثلاً <?php echo $prBase; ?>">
                                <button type="submit" class="btn btn-sm btn-soft-info" title="ذخیره عمده (خالی/0 = پاک‌کردن؛ فروش مسدود می‌شود)">💾</button>
                            </form>
                            <?php if ($prWh <= 0): ?><div style="font-size:11px;color:#c00;margin-top:4px;">⛔ بدون قیمت عمده — فروش مسدود</div><?php endif; ?>
                        </td>
                        <td data-label="فروش">
                            <form method="POST" action="reseller_bots.php" style="display:flex;gap:6px;align-items:center;">
                                <?php echo fx_csrf_field(); ?>
                                <input type="hidden" name="action" value="retail">
                                <input type="hidden" name="uid" value="<?php echo $fxProdRid; ?>">
                                <input type="hidden" name="product_id" value="<?php echo $prPid; ?>">
                                <input type="number" name="amount" class="form-control" style="width:110px;" min="0" step="1" value="<?php echo $prRet > 0 ? $prRet : ''; ?>" placeholder="پایه: <?php echo $prBase; ?>">
                                <button type="submit" class="btn btn-sm btn-soft-info" title="ذخیره فروش نماینده (خالی/0 = حذف قیمت سفارشی)">💾</button>
                            </form>
                        </td>
                        <td data-label="نمایش">
                            <form method="POST" action="reseller_bots.php">
                                <?php echo fx_csrf_field(); ?>
                                <input type="hidden" name="action" value="vis">
                                <input type="hidden" name="uid" value="<?php echo $fxProdRid; ?>">
                                <input type="hidden" name="product_id" value="<?php echo $prPid; ?>">
                                <input type="hidden" name="hidden" value="<?php echo $prHid ? '0' : '1'; ?>">
                                <button type="submit" class="btn btn-sm <?php echo $prHid ? 'btn-soft-success' : 'btn-soft-warning'; ?>"><?php echo $prHid ? '👁 نمایش' : '🚫 پنهان'; ?></button>
                            </form>
                        </td>
                        <td data-label="آزادسازی">
                            <?php if ($prExc): ?>
                            <form method="POST" action="reseller_bots.php" onsubmit="return confirm('محصول <?php echo $prPid; ?> آزاد شود (به کاتالوگ مشترک برگردد)؟');">
                                <?php echo fx_csrf_field(); ?>
                                <input type="hidden" name="action" value="release">
                                <input type="hidden" name="uid" value="<?php echo $fxProdRid; ?>">
                                <input type="hidden" name="product_id" value="<?php echo $prPid; ?>">
                                <button type="submit" class="btn btn-sm btn-soft-danger">♻️ آزادسازی</button>
                            </form>
                            <?php else: ?><span style="color:#bbb;">—</span><?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p style="font-size:12px;color:#888;margin-top:10px;line-height:1.9;">
            💵 <strong>عمده‌فروشی</strong> = هزینه‌ای که با هر خرید مشتری، به حساب نماینده (بدهی/موجودی) ثبت می‌شود — باید بزرگ‌تر از صفر باشد؛ خالی/0 فروش محصول را مسدود می‌کند.<br>
            💰 <strong>فروش نماینده</strong> = قیمت اختصاصی همین نماینده برای مشتریان ربات خودش — خالی/0 یعنی قیمت پایه.<br>
            🚫 <strong>پنهان</strong> = محصول برای مشتریان این نماینده نمایش داده نمی‌شود (اثر روی بقیه ندارد). ♻️ <strong>آزادسازی</strong> = محصول از حالت اختصاصی خارج و به کاتالوگ مشترک برمی‌گردد.
        </p>
    </div>
</div>
<?php endif; ?>

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
