<?php
// FX-RESELLER-PANEL: reseller's own users (tenant-scoped, read-only).

require_once __DIR__ . '/lib/auth.php';
$ctx = fx_res_panel_require();
global $pdo;
$rid = $ctx['rid'];
$bot = fx_res_panel_bot($pdo, $rid);
$botName = (string) ($bot['username'] ?? '');

$scopes = fx_res_panel_scopes($pdo, $rid);
$uScope = $scopes['u'];

$qraw = (string) ($_GET['q'] ?? '');
$q = trim($qraw);
$ffilter = (string) ($_GET['f'] ?? '');
if (!in_array($ffilter, ['active', 'block'], true)) {
    $ffilter = '';
}

$where = "1=1 AND {$uScope}";
$bp = [];
if ($q !== '') {
    $where .= " AND (id LIKE :q1 OR username LIKE :q2 OR namecustom LIKE :q3 OR number LIKE :q4 OR number_username LIKE :q5)";
    $like = '%' . $q . '%';
    $bp = [':q1' => $like, ':q2' => $like, ':q3' => $like, ':q4' => $like, ':q5' => $like];
}
if ($ffilter === 'active') {
    $where .= " AND (User_Status IS NULL OR LOWER(User_Status) <> 'block')";
} elseif ($ffilter === 'block') {
    $where .= " AND LOWER(User_Status) = 'block'";
}

$perPage = 25;
$cs = $pdo->prepare("SELECT COUNT(*) FROM user WHERE {$where}");
foreach ($bp as $k => $v) {
    $cs->bindValue($k, $v, PDO::PARAM_STR);
}
$cs->execute();
$total = (int) $cs->fetchColumn();
$pages = max(1, (int) ceil($total / $perPage));
$page = (int) ($_GET['p'] ?? 1);
if ($page < 1) {
    $page = 1;
}
if ($page > $pages) {
    $page = $pages;
}
$offset = ($page - 1) * $perPage;

$qs = function (array $over = []) use ($q, $ffilter, $page): string {
    $g = ['q' => $q !== '' ? $q : null, 'f' => $ffilter !== '' ? $ffilter : null, 'p' => $page > 1 ? $page : null];
    foreach ($over as $k => $v) {
        $g[$k] = $v;
    }
    $pairs = [];
    foreach ($g as $k => $v) {
        if ($v !== null && $v !== '') {
            $pairs[] = $k . '=' . urlencode((string) $v);
        }
    }
    return $pairs ? '?' . implode('&', $pairs) : '';
};

$q2 = $pdo->prepare("SELECT * FROM user WHERE {$where} ORDER BY id DESC LIMIT {$perPage} OFFSET {$offset}");
foreach ($bp as $k => $v) {
    $q2->bindValue($k, $v, PDO::PARAM_STR);
}
$q2->execute();
$users = $q2->fetchAll(PDO::FETCH_ASSOC);

$sumBalance = 0;
foreach ($users as $u) {
    $sumBalance += (int) ($u['Balance'] ?? 0);
}

fx_res_panel_head('کاربران');
fx_res_panel_nav('users', $botName);
?>
<div class="rp-h">👥 کاربران نمایندگی</div>

<div class="rp-card">
    <form method="get" action="users.php" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
        <input type="text" name="q" value="<?= htmlspecialchars($q, ENT_QUOTES, 'UTF-8') ?>" placeholder="جستجو: شناسه، نام، شماره..." style="flex:1;min-width:200px;background:var(--card2);border:1px solid var(--line);color:var(--txt);border-radius:9px;padding:9px 12px;font-size:13px;outline:none">
        <select name="f" style="background:var(--card2);border:1px solid var(--line);color:var(--txt);border-radius:9px;padding:9px 12px;font-size:13px">
            <option value="">همه وضعیت‌ها</option>
            <option value="active" <?= $ffilter === 'active' ? 'selected' : '' ?>>فعال</option>
            <option value="block" <?= $ffilter === 'block' ? 'selected' : '' ?>>مسدود</option>
        </select>
        <button type="submit" class="rp-btn">فیلتر</button>
    </form>
    <div class="rp-mut" style="margin-top:8px"><?= number_format($total) ?> کاربر ثبت‌شده در این نمایندگی</div>
</div>

<div class="rp-card">
    <?php if (empty($users)): ?>
        <div class="rp-mut">کاربری یافت نشد.</div>
    <?php else: ?>
    <table class="rp-tbl">
        <thead>
            <tr><th>شناسه</th><th>نام</th><th>شماره تماس</th><th>موجودی</th><th>وضعیت</th><th>تاریخ عضویت</th></tr>
        </thead>
        <tbody>
        <?php foreach ($users as $u):
            $un = trim((string) ($u['namecustom'] ?? ''));
            $un = $un !== '' ? $un : (trim((string) ($u['username'] ?? '')) !== '' ? (string) $u['username'] : '—');
            $num = trim((string) ($u['number'] ?? ''));
            $num = $num !== '' ? $num : (trim((string) ($u['number_username'] ?? '')) !== '' ? (string) $u['number_username'] : '—');
            $st = strtolower((string) ($u['User_Status'] ?? ''));
            $blocked = $st === 'block';
            $reg = trim((string) ($u['register'] ?? ''));
            $regOut = ($reg !== '' && $reg !== 'none') ? (function_exists('jdate') ? jdate('Y/m/d', strtotime($reg)) : $reg) : '—';
        ?>
            <tr>
                <td><?= (int) ($u['id'] ?? 0) ?></td>
                <td><?= htmlspecialchars($un, ENT_QUOTES, 'UTF-8') ?></td>
                <td dir="ltr"><?= htmlspecialchars($num, ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= number_format((int) ($u['Balance'] ?? 0)) ?> T</td>
                <td><span class="rp-badge <?= $blocked ? 'off' : 'on' ?>"><?= $blocked ? 'مسدود' : 'فعال' ?></span></td>
                <td class="rp-mut"><?= htmlspecialchars($regOut, ENT_QUOTES, 'UTF-8') ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<?php if ($pages > 1): ?>
<div class="rp-card" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
    <span class="rp-mut">صفحه <?= $page ?> از <?= $pages ?></span>
    <?php if ($page > 1): ?><a class="rp-btn ghost" href="users.php<?= $qs(['p' => $page - 1]) ?>">قبلی</a><?php endif; ?>
    <?php if ($page < $pages): ?><a class="rp-btn ghost" href="users.php<?= $qs(['p' => $page + 1]) ?>">بعدی</a><?php endif; ?>
</div>
<?php endif; ?>
<?php fx_res_panel_foot(); ?>
