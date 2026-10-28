<?php
// FX-RESELLER-PANEL: pure tenant-resolution helpers (no session/db bootstrap).

function fx_res_panel_rid_for_username(PDO $pdo, string $username): ?int
{
    $username = trim($username);
    if ($username === '') {
        return null;
    }
    $stmt = $pdo->prepare("SELECT id_admin FROM admin WHERE username = :u LIMIT 1");
    $stmt->execute([':u' => $username]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return null;
    }
    $id = (int) ($row['id_admin'] ?? 0);
    if ($id <= 0) {
        return null;
    }
    $b = $pdo->prepare("SELECT id_user FROM botsaz WHERE id_user = :id LIMIT 1");
    $b->execute([':id' => $id]);
    return $b->fetch(PDO::FETCH_COLUMN) !== false ? $id : null;
}

function fx_res_panel_reseller_token(PDO $pdo, int $rid): string
{
    $stmt = $pdo->prepare("SELECT bot_token FROM botsaz WHERE id_user = :id LIMIT 1");
    $stmt->execute([':id' => $rid]);
    return (string) $stmt->fetchColumn();
}

function fx_res_panel_scopes(PDO $pdo, int $rid): array
{
    $rid = (int) $rid;
    $tokSql = "'" . addslashes(fx_res_panel_reseller_token($pdo, $rid)) . "'";
    $uScope = "(agent = '{$rid}' OR bottype = " . $tokSql . ")";
    $iScope = "EXISTS (SELECT 1 FROM user ufx WHERE ufx.id = invoice.id_user AND (ufx.agent = '{$rid}' OR ufx.bottype = " . $tokSql . "))";
    return ['u' => $uScope, 'i' => $iScope];
}
