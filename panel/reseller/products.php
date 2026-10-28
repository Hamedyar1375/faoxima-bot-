<?php
// FX-RESELLER-PANEL: reseller's own products (tenant-scoped, read-only).

require_once __DIR__ . '/lib/auth.php';
$ctx = fx_res_panel_require();
global $pdo;
$rid = $ctx['rid'];
$bot = fx_res_panel_bot($pdo, $rid);
$botName = (string) ($bot['username'] ?? '');

[$clause, $params] = fx_res_svc_scope_clause($rid);
$q = $pdo->prepare("SELECT * FROM product WHERE {$clause} ORDER BY position, id");
foreach ($params as $k => $v) {
    $q->bindValue($k, $v, PDO::PARAM_INT);
}
$q->execute();
$products = $q->fetchAll(PDO::FETCH_ASSOC);

$sumPrice = 0;
foreach ($products as $p) {
    $sumPrice += (int) ($p['price_product'] ?? 0);
}

fx_res_panel_head('محصولات');
fx_res_panel_nav('products', $botName);
?>
<div class="rp-h">📦 محصولات نمایندگی</div>

<div class="rp-grid">
    <div class="rp-stat"><div class="l">تعداد محصولات</div><div class="v"><?= number_format(count($products)) ?></div></div>
    <div class="rp-stat"><div class="l">مجموع قیمت‌ها</div><div class="v"><?= number_format($sumPrice) ?></div></div>
</div>

<div class="rp-card">
    <?php if (empty($products)): ?>
        <div class="rp-mut">محصولی برای این نمایندگی ثبت نشده است.</div>
    <?php else: ?>
    <table class="rp-tbl">
        <thead>
            <tr><th>نام محصول</th><th>مدت</th><th>حجم</th><th>قیمت</th><th>قیمت عمده</th><th>نمایش در پنل</th><th>ترتیب</th></tr>
        </thead>
        <tbody>
        <?php foreach ($products as $p): ?>
            <tr>
                <td><b><?= htmlspecialchars((string) ($p['name_product'] ?? ''), ENT_QUOTES, 'UTF-8') ?></b></td>
                <td><?= (int) ($p['Service_time'] ?? 0) ?> روز</td>
                <td><?= (int) ($p['Volume_constraint'] ?? 0) ?> GB</td>
                <td><?= number_format((int) ($p['price_product'] ?? 0)) ?> T</td>
                <td><?= number_format((int) ($p['fx_wholesale_price'] ?? 0)) ?> T</td>
                <td>
                    <?php $hide = trim((string) ($p['hide_panel'] ?? '')) === '1'; ?>
                    <span class="rp-badge <?= $hide ? 'off' : 'on' ?>"><?= $hide ? 'مخفی' : 'قابل مشاهده' ?></span>
                </td>
                <td class="rp-mut"><?= (int) ($p['position'] ?? 0) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
    <div class="rp-mut" style="margin-top:12px">محصولات اختصاصی این نمایندگی (مربوط به آژنت شما) در اینجا نمایش داده می‌شوند. برای افزودن یا ویرایش از ربات استفاده کنید.</div>
</div>
<?php fx_res_panel_foot(); ?>
