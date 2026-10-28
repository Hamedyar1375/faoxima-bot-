<?php
// FX-RESELLER-PANEL: dashboard — tenant-scoped stats + panel status overview.

require_once __DIR__ . '/lib/auth.php';
$ctx = fx_res_panel_require();
global $pdo;
$rid = $ctx['rid'];

$scopes = fx_res_panel_scopes($pdo, $rid);
$uScope = $scopes['u'];
$iScope = $scopes['i'];
$bot = fx_res_panel_bot($pdo, $rid);
$botName = (string) ($bot['username'] ?? '');

$stat = function (string $sql, array $params = []) use ($pdo): array {
    $s = $pdo->prepare($sql);
    $s->execute($params);
    $r = $s->fetch(PDO::FETCH_ASSOC);
    return is_array($r) ? $r : [];
};

$usersTotal = (int) ($stat("SELECT COUNT(*) AS c FROM user WHERE {$uScope}")['c'] ?? 0);
$usersActive = (int) ($stat("SELECT COUNT(*) AS c FROM user WHERE {$uScope} AND (User_Status IS NULL OR LOWER(User_Status) <> 'block')")['c'] ?? 0);
$balanceSum = (int) ($stat("SELECT COALESCE(SUM(Balance),0) AS s FROM user WHERE {$uScope}")['s'] ?? 0);
$sales = $stat("SELECT COUNT(*) AS c, COALESCE(SUM(price_product),0) AS s FROM invoice WHERE {$iScope}");
$activeServices = (int) ($stat("SELECT COUNT(*) AS c FROM invoice WHERE " . rx_stats_operational_service_predicate() . " AND {$iScope}")['c'] ?? 0);
$buyers = (int) ($stat("SELECT COUNT(DISTINCT id_user) AS c FROM invoice WHERE {$iScope}")['c'] ?? 0);
$dayStart = date('Y-m-d 00:00:00');
$today = $stat("SELECT COALESCE(SUM(price_product),0) AS s FROM invoice WHERE time_sell >= :d AND {$iScope}", [':d' => $dayStart]);

$panels = fx_res_svc_list_panels(null, $rid, 50);

fx_res_panel_head('داشبورد');
fx_res_panel_nav('index', $botName);
?>
<div class="rp-h">📊 وضعیت نمایندگی
    <?php if ($botName !== ''): ?><span class="rp-mut">@<?= htmlspecialchars($botName, ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
</div>

<div class="rp-grid">
    <div class="rp-stat"><div class="l">کل کاربران</div><div class="v"><?= number_format($usersTotal) ?></div></div>
    <div class="rp-stat"><div class="l">کاربران فعال</div><div class="v"><?= number_format($usersActive) ?></div></div>
    <div class="rp-stat"><div class="l">مجموع موجودی</div><div class="v"><?= number_format($balanceSum) ?> T</div></div>
    <div class="rp-stat"><div class="l">فروش کل</div><div class="v"><?= number_format((int) ($sales['s'] ?? 0)) ?></div></div>
    <div class="rp-stat"><div class="l">تعداد فاکتور</div><div class="v"><?= number_format((int) ($sales['c'] ?? 0)) ?></div></div>
    <div class="rp-stat"><div class="l">سرویس‌های فعال</div><div class="v"><?= number_format($activeServices) ?></div></div>
    <div class="rp-stat"><div class="l">خریداران</div><div class="v"><?= number_format($buyers) ?></div></div>
    <div class="rp-stat"><div class="l">فروش امروز</div><div class="v"><?= number_format((int) ($today['s'] ?? 0)) ?></div></div>
</div>

<div class="rp-card">
    <div class="rp-h">🖥 پنل‌ها و سرویس‌های من</div>
    <?php if (empty($panels)): ?>
        <div class="rp-mut">هیچ پنلی برای این نمایندگی ثبت نشده است.</div>
    <?php else: ?>
    <table class="rp-tbl">
        <thead>
            <tr><th>نام پنل</th><th>آدرس</th><th>نام کاربری</th><th>سرویس</th><th>فروش/تمدید</th><th></th></tr>
        </thead>
        <tbody>
        <?php foreach ($panels as $p):
            $pname = htmlspecialchars((string) ($p['name_panel'] ?? ''), ENT_QUOTES, 'UTF-8');
            $purl = (string) ($p['url_panel'] ?? '');
            $puser = (string) ($p['username_panel'] ?? '');
            $pstatus = (string) ($p['status'] ?? '');
            $pext = (string) ($p['status_extend'] ?? '');
            $stOn = ($pstatus === 'active');
            $extOn = ($pext === 'on_extend');
        ?>
            <tr>
                <td><b><?= $pname ?></b></td>
                <td><?= $purl !== '' ? '<a href="' . htmlspecialchars($purl, ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener">' . htmlspecialchars($purl, ENT_QUOTES, 'UTF-8') . '</a>' : '—' ?></td>
                <td><?= $puser !== '' ? htmlspecialchars($puser, ENT_QUOTES, 'UTF-8') : '—' ?></td>
                <td><span class="rp-badge <?= $stOn ? 'on' : 'off' ?>"><?= $stOn ? 'روشن' : 'خاموش' ?></span></td>
                <td><span class="rp-badge <?= $extOn ? 'on' : 'off' ?>"><?= $extOn ? 'روشن' : 'خاموش' ?></span></td>
                <td><a class="rp-btn ghost" href="services.php?panel=<?= (int) ($p['id'] ?? 0) ?>">مدیریت</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<div class="rp-card">
    <div class="rp-h">⚡ دسترسی سریع</div>
    <?php $origin = fx_res_panel_origin(); ?>
    <div style="display:flex;gap:10px;flex-wrap:wrap">
        <?php if ($origin !== ''): ?>
        <a class="rp-btn" href="<?= htmlspecialchars($origin . '/app/', ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">🚀 مینی‌اپ مدیریت</a>
        <?php endif; ?>
        <a class="rp-btn ghost" href="services.php">🛠 مدیریت پنل‌ها</a>
        <a class="rp-btn ghost" href="users.php">👥 کاربران</a>
        <a class="rp-btn ghost" href="products.php">📦 محصولات</a>
    </div>
    <div class="rp-mut" style="margin-top:10px">همه داده‌های این پنل فقط مربوط به نمایندگی شماست. مدیریت محصولات از طریق منوی «محصولات من» در ربات انجام می‌شود.</div>
</div>
<?php fx_res_panel_foot(); ?>
