<?php
// FX-RESELLER-PANEL: manage the reseller's OWN marzban panels (tenant-scoped
// writes via the shared fx_res_svc_* core — never another tenant's panels).

require_once __DIR__ . '/lib/auth.php';
$ctx = fx_res_panel_require();
global $pdo;
$rid = $ctx['rid'];
$bot = fx_res_panel_bot($pdo, $rid);
$botName = (string) ($bot['username'] ?? '');

fx_csrf_guard();

if (isset($_POST['action']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) $_POST['action'];
    $panelId = (int) ($_POST['panel_id'] ?? 0);
    $value = trim((string) ($_POST['value'] ?? ''));
    $panel = fx_res_svc_get_panel(null, $rid, $panelId);
    if ($panel === null) {
        fx_res_panel_flash('پنل مورد نظر یافت نشد یا به این نمایندگی تعلق ندارد.');
    } else {
        $name = htmlspecialchars((string) ($panel['name_panel'] ?? ''), ENT_QUOTES, 'UTF-8');
        switch ($action) {
            case 'set_password':
                if ($value === '' || mb_strlen($value) < 6) {
                    fx_res_panel_flash("پنل {$name}: رمز باید حداقل ۶ کاراکتر باشد.");
                } elseif (fx_res_svc_set_password(null, $rid, $panelId, $value)) {
                    fx_res_panel_flash("✅ پنل {$name}: رمز عبور تغییر کرد.");
                } else {
                    fx_res_panel_flash("⚠️ پنل {$name}: تغییر رمز ممکن نشد.");
                }
                break;
            case 'set_username':
                if ($value === '' || !preg_match('/^[a-zA-Z0-9_.-]{3,32}$/', $value)) {
                    fx_res_panel_flash("پنل {$name}: نام کاربری معتبر نیست (۳ تا ۳۲ کاراکتر لاتین).");
                } elseif (fx_res_svc_set_username(null, $rid, $panelId, $value)) {
                    fx_res_panel_flash("✅ پنل {$name}: نام کاربری تغییر کرد.");
                } else {
                    fx_res_panel_flash("⚠️ پنل {$name}: تغییر نام کاربری ممکن نشد.");
                }
                break;
            case 'set_url':
                if ($value === '') {
                    fx_res_panel_flash("پنل {$name}: آدرس خالی است.");
                } elseif (fx_res_svc_set_url(null, $rid, $panelId, fx_res_svc_normalize_url($value))) {
                    fx_res_panel_flash("✅ پنل {$name}: آدرس پنل تغییر کرد.");
                } else {
                    fx_res_panel_flash("⚠️ پنل {$name}: آدرس معتبر نیست یا ذخیره نشد.");
                }
                break;
            case 'set_sublink':
                if ($value === '') {
                    fx_res_panel_flash("پنل {$name}: دامنه لینک ساب خالی است.");
                } elseif (fx_res_svc_set_sublink(null, $rid, $panelId, fx_res_svc_normalize_url($value))) {
                    fx_res_panel_flash("✅ پنل {$name}: دامنه لینک ساب تغییر کرد.");
                } else {
                    fx_res_panel_flash("⚠️ پنل {$name}: ذخیره لینک ساب ممکن نشد.");
                }
                break;
            case 'toggle_status':
                $new = fx_res_svc_toggle(null, $rid, $panelId, 'status');
                fx_res_panel_flash($new === null ? "⚠️ پنل {$name}: تغییر وضعیت ممکن نشد." : "✅ پنل {$name}: سرویس «" . ($new === 'active' ? 'روشن' : 'خاموش') . "» شد.");
                break;
            case 'toggle_extend':
                $new = fx_res_svc_toggle(null, $rid, $panelId, 'status_extend');
                fx_res_panel_flash($new === null ? "⚠️ پنل {$name}: تغییر وضعیت ممکن نشد." : "✅ پنل {$name}: فروش/تمدید «" . ($new === 'on_extend' ? 'روشن' : 'خاموش') . "» شد.");
                break;
            default:
                fx_res_panel_flash('عملیات نامعتبر است.');
        }
    }
    header('Location: services.php' . (isset($_POST['back_panel']) ? '?panel=' . (int) $_POST['back_panel'] : ''), true, 302);
    exit;
}

$panels = fx_res_svc_list_panels(null, $rid, 50);
$focusId = (int) ($_GET['panel'] ?? 0);

fx_res_panel_head('پنل‌ها و سرویس‌ها');
fx_res_panel_nav('services', $botName);
?>
<div class="rp-h">🛠 مدیریت پنل‌ها و سرویس‌ها</div>

<?php if (empty($panels)): ?>
<div class="rp-card"><div class="rp-mut">پنلی برای این نمایندگی ثبت نشده است.</div></div>
<?php endif; ?>

<?php foreach ($panels as $p):
    $pid = (int) ($p['id'] ?? 0);
    $pname = (string) ($p['name_panel'] ?? '');
    $purl = (string) ($p['url_panel'] ?? '');
    $puser = (string) ($p['username_panel'] ?? '');
    $psub = (string) ($p['linksubx'] ?? '');
    $pstatus = (string) ($p['status'] ?? '');
    $pext = (string) ($p['status_extend'] ?? '');
    $stOn = ($pstatus === 'active');
    $extOn = ($pext === 'on_extend');
    $isFocus = ($focusId === 0) || ($focusId === $pid);
    if (!$isFocus) {
        continue;
    }
?>
<div class="rp-card">
    <div class="rp-h">
        <?= htmlspecialchars($pname, ENT_QUOTES, 'UTF-8') ?>
        <span class="rp-badge <?= $stOn ? 'on' : 'off' ?>"><?= $stOn ? 'سرویس روشن' : 'سرویس خاموش' ?></span>
        <span class="rp-badge <?= $extOn ? 'on' : 'off' ?>"><?= $extOn ? 'فروش/تمدید روشن' : 'فروش/تمدید خاموش' ?></span>
    </div>

    <div class="rp-form">
        <form method="post" action="services.php" class="full">
            <?= fx_csrf_field() ?>
            <input type="hidden" name="panel_id" value="<?= $pid ?>">
            <input type="hidden" name="back_panel" value="<?= $pid ?>">
            <label>🔐 تغییر رمز عبور پنل</label>
            <div style="display:flex;gap:8px">
                <input type="password" name="value" placeholder="رمز جدید (حداقل ۶ کاراکتر)" required>
                <button type="submit" class="rp-btn" name="action" value="set_password">ذخیره</button>
            </div>
        </form>
        <form method="post" action="services.php">
            <?= fx_csrf_field() ?>
            <input type="hidden" name="panel_id" value="<?= $pid ?>">
            <input type="hidden" name="back_panel" value="<?= $pid ?>">
            <label>👤 تغییر نام کاربری پنل <span class="rp-mut">(الان: <?= $puser !== '' ? htmlspecialchars($puser, ENT_QUOTES, 'UTF-8') : '—' ?>)</span></label>
            <div style="display:flex;gap:8px">
                <input type="text" name="value" placeholder="new-username" required pattern="[a-zA-Z0-9_.\-]{3,32}">
                <button type="submit" class="rp-btn" name="action" value="set_username">ذخیره</button>
            </div>
        </form>
        <form method="post" action="services.php">
            <?= fx_csrf_field() ?>
            <input type="hidden" name="panel_id" value="<?= $pid ?>">
            <input type="hidden" name="back_panel" value="<?= $pid ?>">
            <label>🔗 آدرس/دامنه پنل <span class="rp-mut">(الان: <?= $purl !== '' ? htmlspecialchars($purl, ENT_QUOTES, 'UTF-8') : '—' ?>)</span></label>
            <div style="display:flex;gap:8px">
                <input type="text" name="value" placeholder="panel.example.com" required>
                <button type="submit" class="rp-btn" name="action" value="set_url">ذخیره</button>
            </div>
        </form>
        <form method="post" action="services.php">
            <?= fx_csrf_field() ?>
            <input type="hidden" name="panel_id" value="<?= $pid ?>">
            <input type="hidden" name="back_panel" value="<?= $pid ?>">
            <label>🔗 دامنه لینک ساب <span class="rp-mut">(الان: <?= $psub !== '' ? htmlspecialchars($psub, ENT_QUOTES, 'UTF-8') : '—' ?>)</span></label>
            <div style="display:flex;gap:8px">
                <input type="text" name="value" placeholder="sub.example.com" required>
                <button type="submit" class="rp-btn" name="action" value="set_sublink">ذخیره</button>
            </div>
        </form>
        <form method="post" action="services.php">
            <?= fx_csrf_field() ?>
            <input type="hidden" name="panel_id" value="<?= $pid ?>">
            <input type="hidden" name="back_panel" value="<?= $pid ?>">
            <label>🖥 وضعیت سرویس</label>
            <button type="submit" class="rp-btn <?= $stOn ? 'danger' : '' ?>" name="action" value="toggle_status"><?= $stOn ? 'خاموش کردن سرویس' : 'روشن کردن سرویس' ?></button>
        </form>
        <form method="post" action="services.php">
            <?= fx_csrf_field() ?>
            <input type="hidden" name="panel_id" value="<?= $pid ?>">
            <input type="hidden" name="back_panel" value="<?= $pid ?>">
            <label>💰 وضعیت فروش/تمدید</label>
            <button type="submit" class="rp-btn <?= $extOn ? 'danger' : '' ?>" name="action" value="toggle_extend"><?= $extOn ? 'قطع فروش/تمدید' : 'فعال‌سازی فروش/تمدید' ?></button>
        </form>
        <div class="full rp-mut">تغییر رمز/نام کاربری/آدرس باعث باطل‌شدن نشست‌های فعلی پنل (بازنشانی datelogin) می‌شود.</div>
    </div>
</div>
<?php endforeach; ?>
<?php fx_res_panel_foot(); ?>
