<?php

declare(strict_types=1);

require_once __DIR__ . '/BaseHandler.php';

final class TicketsHandler extends BaseHandler
{
    public $mode = 'list';

    private const REACTION_EMOJI = ['👍', '❤️', '😂', '😮', '😢', '🙏'];

    public function handle(): void
    {
        switch ($this->mode) {
            case 'thread':       $this->handleThread(); break;
            case 'departments':  $this->handleDepartments(); break;
            case 'create':       $this->handleCreate(); break;
            case 'reply':        $this->handleReply(); break;
            case 'close':        $this->handleClose(); break;
            case 'media':        $this->handleMedia(); break;
            case 'react':        $this->handleReact(); break;
            default:             $this->handleList(); break;
        }
    }

    private function toJalaliTime(string $raw): string
    {
        if ($raw === '') return '';
        $ts = strtotime($raw);
        if ($ts === false) return $raw;
        if (!function_exists('jdate')) return $raw;
        return jdate('Y/m/d H:i', $ts, '', 'Asia/Tehran', 'en');
    }

    private function parseMedia($text): array
    {
        $t = (string)$text;
        $media = [];
        if (preg_match_all('/\[\[(photo|video):([^\]]+)\]\]/', $t, $mm, PREG_SET_ORDER)) {
            foreach ($mm as $m) {
                if (count($media) >= 5) break;
                $media[] = ['type' => $m[1], 'file_id' => $m[2]];
                $t = str_replace($m[0], '', $t);
            }
        }
        $t = preg_replace('/\[\[subj:[^\]]*\]\]/u', '', $t);
        return ['media' => $media, 'body' => trim((string)$t)];
    }

    private function parseSubject($text): string
    {
        if (preg_match('/\[\[subj:([^\]]*)\]\]/u', (string)$text, $m)) {
            return trim($m[1]);
        }
        return '';
    }

    private function uid(): string
    {
        return (string)($this->user['id'] ?? '');
    }

    private function handleList(): void
    {
        $this->requireMethod('GET');
        $uid = $this->uid();

        $limit = FaoximaInput::intRange($this->data, 'limit', 1, 50, 10);
        $page  = FaoximaInput::intMin($this->data, 'page', 1, 1);
        $offset = ($page - 1) * $limit;

        $totalItems = (int) FaoximaDb::fetchScalar(
            "SELECT COUNT(*) FROM (
                SELECT Tracking FROM support_message WHERE iduser = :u GROUP BY Tracking, name_departman
             ) t",
            [':u' => $uid]
        );
        $totalPages = $totalItems > 0 ? (int)ceil($totalItems / $limit) : 0;

        $rows = FaoximaDb::fetchAll(
            "SELECT Tracking, name_departman, MAX(id) last_id, MAX(time) last_time, MAX(status) status, COUNT(*) cnt
               FROM support_message WHERE iduser = :u
               GROUP BY Tracking, name_departman ORDER BY last_id DESC LIMIT :limit OFFSET :offset",
            [':u' => $uid, ':limit' => $limit, ':offset' => $offset]
        );

        $subjects = [];
        foreach (FaoximaDb::fetchAll(
            "SELECT s.Tracking, s.text FROM support_message s
               INNER JOIN (SELECT Tracking, MIN(id) mid FROM support_message WHERE iduser = :u GROUP BY Tracking) f
               ON s.id = f.mid",
            [':u' => $uid]
        ) as $fr) {
            $subjects[$fr['Tracking']] = $this->parseSubject($fr['text']);
        }

        $items = [];
        foreach ($rows as $r) {
            $st = (string)$r['status'];
            $items[] = [
                'tracking'   => (string)$r['Tracking'],
                'department' => (string)$r['name_departman'],
                'subject'    => $subjects[$r['Tracking']] ?? '',
                'status'     => $st,
                'open'       => $st !== 'close',
                'last_time'  => $this->toJalaliTime((string)$r['last_time']),
                'count'      => (int)$r['cnt'],
            ];
        }

        FaoximaResponse::ok([
            'items'       => $items,
            'total'       => $totalItems,
            'total_pages' => $totalPages,
            'page'        => $page,
            'limit'       => $limit,
        ]);
    }

    private function handleThread(): void
    {
        $this->requireMethod('GET');
        $uid = $this->uid();
        $T = FaoximaInput::string($this->data, 't');
        if ($T === '') {
            FaoximaResponse::badRequest('t is required');
        }

        $head = FaoximaDb::fetchOne(
            "SELECT * FROM support_message WHERE Tracking = :t AND iduser = :u ORDER BY id ASC LIMIT 1",
            [':t' => $T, ':u' => $uid]
        );
        if ($head === null) {
            FaoximaResponse::notFound('ticket not found');
        }

        $all = FaoximaDb::fetchAll("SELECT * FROM support_message WHERE Tracking = :t ORDER BY id ASC", [':t' => $T]);

        $ids = array_map(function ($m) { return (int)$m['id']; }, $all);
        $reactionsByMsg = $this->fetchReactions($ids);

        // Mark the counterparty's messages as seen by the current viewer (mini-app user).
        FaoximaDb::execute(
            "UPDATE support_message SET seen_by_user = 1 WHERE Tracking = :t AND result = 'admin' AND seen_by_user = 0",
            [':t' => $T]
        );

        $bodyById = [];
        foreach ($all as $m) {
            $bodyById[(int)$m['id']] = $this->parseMedia($m['text'])['body'];
        }

        $messages = [];
        $status = (string)$head['status'];
        foreach ($all as $m) {
            $info = $this->parseMedia($m['text']);
            $mediaList = [];
            foreach ($info['media'] as $mm) {
                $mediaList[] = ['type' => $mm['type']];
            }
            $mid = (int)$m['id'];
            $replyToId = isset($m['reply_to_id']) ? (int)$m['reply_to_id'] : 0;
            $messages[] = [
                'id'          => $mid,
                'sender'      => ($m['result'] === 'admin') ? 'admin' : 'user',
                'body'        => $info['body'],
                'media'       => $mediaList,
                'media_count' => count($mediaList),
                'time'        => $this->toJalaliTime((string)$m['time']),
                'seen'        => ($m['result'] === 'admin') ? true : ((int)($m['seen_by_admin'] ?? 0) === 1),
                'reply_to'    => $replyToId > 0 ? [
                    'id'   => $replyToId,
                    'body' => $bodyById[$replyToId] ?? '',
                ] : null,
                'reactions'   => $reactionsByMsg[$mid] ?? [],
            ];
            $status = (string)$m['status'];
        }

        FaoximaResponse::ok([
            'tracking'   => $T,
            'department' => (string)$head['name_departman'],
            'subject'    => $this->parseSubject($head['text']),
            'status'     => $status,
            'open'       => $status !== 'close',
            'messages'   => $messages,
        ]);
    }

    private function fetchReactions(array $messageIds): array
    {
        $messageIds = array_values(array_unique(array_filter($messageIds, function ($v) { return (int)$v > 0; })));
        if (count($messageIds) === 0) return [];
        $placeholders = [];
        $params = [];
        foreach ($messageIds as $i => $id) {
            $key = ":m$i";
            $placeholders[] = $key;
            $params[$key] = $id;
        }
        $rows = FaoximaDb::fetchAll(
            "SELECT message_id, actor, actor_id, emoji FROM support_message_reaction WHERE message_id IN (" . implode(',', $placeholders) . ")",
            $params
        );
        $out = [];
        foreach ($rows as $r) {
            $mid = (int)$r['message_id'];
            if (!isset($out[$mid])) $out[$mid] = [];
            $out[$mid][] = [
                'emoji' => (string)$r['emoji'],
                'actor' => (string)$r['actor'],
                'mine'  => ((string)$r['actor_id'] === $this->uid()),
            ];
        }
        return $out;
    }

    private function handleReact(): void
    {
        $this->requireMethod('POST');
        $uid = $this->uid();
        $messageId = FaoximaInput::int($this->data, 'message_id');
        $emoji = trim(FaoximaInput::string($this->data, 'emoji'));
        if ($messageId <= 0) {
            FaoximaResponse::badRequest('message_id is required');
        }
        if ($emoji !== '' && !in_array($emoji, self::REACTION_EMOJI, true)) {
            FaoximaResponse::badRequest('invalid emoji');
        }

        $msg = FaoximaDb::fetchOne("SELECT id, Tracking, result FROM support_message WHERE id = :id", [':id' => $messageId]);
        if ($msg === null) {
            FaoximaResponse::notFound('message not found');
        }
        $own = FaoximaDb::fetchOne("SELECT id FROM support_message WHERE Tracking = :t AND iduser = :u LIMIT 1", [':t' => $msg['Tracking'], ':u' => $uid]);
        if ($own === null) {
            FaoximaResponse::fail(403, 'forbidden');
        }
        if ((string)$msg['result'] !== 'admin') {
            FaoximaResponse::fail(403, 'نمی‌توانید به پیام خودتان واکنش بدهید');
        }

        if ($emoji === '') {
            FaoximaDb::execute("DELETE FROM support_message_reaction WHERE message_id = :m AND actor_id = :a", [':m' => $messageId, ':a' => $uid]);
        } else {
            FaoximaDb::execute(
                "INSERT INTO support_message_reaction (message_id, actor, actor_id, emoji, time)
                 VALUES (:m, 'user', :a, :e, :tm)
                 ON DUPLICATE KEY UPDATE emoji = VALUES(emoji), time = VALUES(time)",
                [':m' => $messageId, ':a' => $uid, ':e' => $emoji, ':tm' => date('Y/m/d H:i:s')]
            );
        }

        $reactions = $this->fetchReactions([$messageId]);
        FaoximaResponse::ok(['reactions' => $reactions[$messageId] ?? []]);
    }

    private function handleDepartments(): void
    {
        $this->requireMethod('GET');
        $rows = FaoximaDb::fetchAll("SELECT id, name_departman FROM departman ORDER BY id ASC", []);
        $items = [];
        foreach ($rows as $d) {
            $items[] = ['id' => (int)$d['id'], 'name' => (string)$d['name_departman']];
        }
        FaoximaResponse::ok(['items' => $items]);
    }

    private function handleCreate(): void
    {
        $this->requireMethod('POST');
        $uid = $this->uid();

        $depId = FaoximaInput::int($this->data, 'department_id');
        $subject = trim(FaoximaInput::string($this->data, 'subject'));
        $text = FaoximaInput::string($this->data, 'text');

        $dep = $depId > 0 ? select('departman', '*', 'id', $depId, 'select') : select('departman', '*', null, null, 'select');
        if (!is_array($dep)) {
            FaoximaResponse::badRequest('department invalid');
        }

        $uploads = $this->collectUploads();
        if ($text === '' && count($uploads) === 0) {
            FaoximaResponse::badRequest('text or media is required');
        }

        $idsupport = (string)($dep['idsupport'] ?? '');
        $mediaArr = [];
        $uploadTarget = '';
        if (count($uploads) > 0) {
            $up = $this->uploadMediaMulti($idsupport, $uploads);
            $mediaArr = $up['media'];
            $uploadTarget = $up['target'];
        }

        $T = bin2hex(random_bytes(4));
        $subjToken = $subject !== '' ? "[[subj:$subject]]\n" : '';
        $body = trim($subjToken . $text . $this->mediaTokens($mediaArr));
        $time = date('Y/m/d H:i:s');

        FaoximaDb::execute(
            "INSERT IGNORE INTO support_message (Tracking,idsupport,iduser,name_departman,text,result,time,status)
             VALUES (:t,:s,:u,:n,:x,'user',:tm,'Unseen')",
            [':t' => $T, ':s' => $idsupport, ':u' => $uid, ':n' => (string)$dep['name_departman'], ':x' => $body, ':tm' => $time]
        );

        $report = "🎫 تیکت جدید پشتیبانی (مینی‌اپ)\n\nکاربر : <a href=\"tg://user?id=$uid\">$uid</a>\nدپارتمان : " . (string)$dep['name_departman'] . "\nموضوع : " . ($subject !== '' ? $subject : '—') . "\nکد پیگیری : <code>$T</code>\nزمان : " . jdate('Y/m/d H:i:s') . "\n\nمتن : " . $text;
        $this->notifyAdmins($idsupport, $report, $mediaArr, $uploadTarget, $T);

        FaoximaResponse::ok(['tracking' => $T, 'message' => '✅ تیکت شما ثبت شد و پس از بررسی پاسخ داده می‌شود.']);
    }

    private function handleReply(): void
    {
        $this->requireMethod('POST');
        $uid = $this->uid();
        $T = FaoximaInput::string($this->data, 't');
        if ($T === '') {
            FaoximaResponse::badRequest('t is required');
        }

        $tk = FaoximaDb::fetchOne(
            "SELECT * FROM support_message WHERE Tracking = :t AND iduser = :u ORDER BY id ASC LIMIT 1",
            [':t' => $T, ':u' => $uid]
        );
        if ($tk === null) {
            FaoximaResponse::notFound('ticket not found');
        }

        $cur = FaoximaDb::fetchOne("SELECT status FROM support_message WHERE Tracking = :t ORDER BY id DESC LIMIT 1", [':t' => $T]);
        if (($cur['status'] ?? '') === 'close') {
            FaoximaResponse::fail(409, 'این تیکت بسته است؛ تا زمانی که پشتیبانی پاسخ ندهد یا آن را باز نکند نمی‌توانید پیام ارسال کنید.');
        }

        $text = FaoximaInput::string($this->data, 'text');
        $uploads = $this->collectUploads();
        if ($text === '' && count($uploads) === 0) {
            FaoximaResponse::badRequest('text or media is required');
        }

        $replyToId = FaoximaInput::int($this->data, 'reply_to_id');
        if ($replyToId > 0) {
            $replyTarget = FaoximaDb::fetchOne("SELECT id FROM support_message WHERE id = :id AND Tracking = :t", [':id' => $replyToId, ':t' => $T]);
            if ($replyTarget === null) $replyToId = 0;
        }

        $idsupport = (string)($tk['idsupport'] ?? '');
        $mediaArr = [];
        $uploadTarget = '';
        if (count($uploads) > 0) {
            $up = $this->uploadMediaMulti($idsupport, $uploads);
            $mediaArr = $up['media'];
            $uploadTarget = $up['target'];
        }

        $body = trim($text . $this->mediaTokens($mediaArr));
        $time = date('Y/m/d H:i:s');

        FaoximaDb::execute(
            "INSERT IGNORE INTO support_message (Tracking,idsupport,iduser,name_departman,text,result,time,status,reply_to_id)
             VALUES (:t,:s,:u,:n,:x,'user',:tm,'Customerresponse',:r)",
            [':t' => $T, ':s' => $idsupport, ':u' => $uid, ':n' => (string)$tk['name_departman'], ':x' => $body, ':tm' => $time, ':r' => $replyToId > 0 ? $replyToId : null]
        );
        update('support_message', 'status', 'Customerresponse', 'Tracking', $T);

        $report = "💬 پاسخ جدید کاربر در تیکت (مینی‌اپ)\n\nکاربر : <a href=\"tg://user?id=$uid\">$uid</a>\nدپارتمان : " . (string)$tk['name_departman'] . "\nکد پیگیری : <code>$T</code>\nزمان : " . jdate('Y/m/d H:i:s') . "\n\nمتن : " . $text;
        $this->notifyAdmins($idsupport, $report, $mediaArr, $uploadTarget, $T);

        FaoximaResponse::ok(['message' => '✅ پاسخ شما ثبت شد و پس از بررسی پاسخ داده می‌شود.']);
    }

    private function handleClose(): void
    {
        $this->requireMethod('POST');
        $uid = $this->uid();
        $T = FaoximaInput::string($this->data, 't');
        if ($T === '') {
            FaoximaResponse::badRequest('t is required');
        }
        $tk = FaoximaDb::fetchOne(
            "SELECT * FROM support_message WHERE Tracking = :t AND iduser = :u ORDER BY id ASC LIMIT 1",
            [':t' => $T, ':u' => $uid]
        );
        if ($tk === null) {
            FaoximaResponse::notFound('ticket not found');
        }
        update('support_message', 'status', 'close', 'Tracking', $T);
        $report = "🔒 کاربر <a href=\"tg://user?id=$uid\">$uid</a> تیکت <code>$T</code> را از مینی‌اپ بست.";
        $this->notifyAdmins((string)($tk['idsupport'] ?? ''), $report, [], '');
        FaoximaResponse::ok(['message' => '🔒 تیکت بسته شد.']);
    }

    private function handleMedia(): void
    {
        $uid = $this->uid();
        $id = FaoximaInput::int($this->data, 'id');
        if ($id <= 0) {
            http_response_code(404);
            exit;
        }
        $row = FaoximaDb::fetchOne("SELECT Tracking, text FROM support_message WHERE id = :id LIMIT 1", [':id' => $id]);
        if ($row === null) {
            http_response_code(404);
            exit;
        }
        $own = FaoximaDb::fetchOne("SELECT id FROM support_message WHERE Tracking = :t AND iduser = :u LIMIT 1", [':t' => $row['Tracking'], ':u' => $uid]);
        if ($own === null) {
            http_response_code(403);
            exit;
        }
        $info = $this->parseMedia($row['text']);
        $idx = FaoximaInput::int($this->data, 'i');
        if ($idx < 0) $idx = 0;
        $item = $info['media'][$idx] ?? null;
        if ($item === null) {
            http_response_code(404);
            exit;
        }
        global $APIKEY;
        $f = telegram('getFile', ['file_id' => $item['file_id']]);
        $path = $f['result']['file_path'] ?? '';
        if ($path === '') {
            http_response_code(404);
            exit;
        }
        $bin = @file_get_contents("https://api.telegram.org/file/bot{$APIKEY}/{$path}");
        if ($bin === false) {
            http_response_code(502);
            exit;
        }
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        $ext = strtolower((string)pathinfo($path, PATHINFO_EXTENSION));
        $ct = $item['type'] === 'video' ? 'video/mp4' : ($ext === 'png' ? 'image/png' : ($ext === 'gif' ? 'image/gif' : 'image/jpeg'));
        header('Content-Type: ' . $ct);
        header('Cache-Control: private, max-age=3600');
        echo $bin;
        exit;
    }

    private function mediaTokens(array $mediaArr): string
    {
        $out = '';
        foreach ($mediaArr as $m) {
            $out .= "\n[[{$m['type']}:{$m['file_id']}]]";
        }
        return $out;
    }

    private function collectUploads(): array
    {
        if (!isset($_FILES['media'])) return [];
        $F = $_FILES['media'];
        $list = [];
        if (is_array($F['tmp_name'] ?? null)) {
            $n = count($F['tmp_name']);
            for ($i = 0; $i < $n && count($list) < 5; $i++) {
                if ((int)($F['error'][$i] ?? 1) !== 0) continue;
                $tmp = (string)($F['tmp_name'][$i] ?? '');
                if ($tmp === '' || !is_uploaded_file($tmp)) continue;
                $mime = function_exists('mime_content_type') ? (string)(mime_content_type($tmp) ?: '') : (string)($F['type'][$i] ?? '');
                $list[] = ['tmp' => $tmp, 'mime' => $mime, 'name' => (string)($F['name'][$i] ?? 'file'), 'video' => stripos($mime, 'video') === 0];
            }
        } else {
            if ((int)($F['error'] ?? 1) === 0 && is_uploaded_file((string)($F['tmp_name'] ?? ''))) {
                $tmp = (string)$F['tmp_name'];
                $mime = function_exists('mime_content_type') ? (string)(mime_content_type($tmp) ?: '') : (string)($F['type'] ?? '');
                $list[] = ['tmp' => $tmp, 'mime' => $mime, 'name' => (string)($F['name'] ?? 'file'), 'video' => stripos($mime, 'video') === 0];
            }
        }
        return $list;
    }

    /**
     * Resolve notification recipients for the active authenticated tenant.
     * Main bot (tenant 0): main administrators only. Reseller tenant: owner and
     * delegated admins of that exact bot only.
     *
     * @return array{tenant:int,recipients:string[],bot_token:?string}
     */
    private function tenantNotificationContext(string $idsupport = ''): array
    {
        $tenant = function_exists('fx_tenant_agent_id') ? max(0, (int) fx_tenant_agent_id()) : 0;
        $recipients = [];
        $botToken = null;

        $add = static function ($id) use (&$recipients): void {
            $id = trim((string) $id);
            if ($id !== '' && ctype_digit($id) && (int) $id > 0) {
                $recipients[$id] = true;
            }
        };

        if ($tenant > 0) {
            $row = FaoximaDb::fetchOne(
                'SELECT id_user, bot_token, admin_ids FROM botsaz WHERE id_user = :id LIMIT 1',
                [':id' => (string) $tenant]
            );
            if (is_array($row)) {
                $botToken = trim((string) ($row['bot_token'] ?? '')) ?: null;
                $add($row['id_user'] ?? $tenant);
                $adminIds = json_decode((string) ($row['admin_ids'] ?? '[]'), true);
                if (is_array($adminIds)) {
                    foreach ($adminIds as $aid) $add($aid);
                }
            }
        } else {
            $add($GLOBALS['adminnumber'] ?? '');
            // Main admins only: exclude IDs that are owners of reseller bots.
            $rows = FaoximaDb::fetchAll(
                'SELECT a.id_admin FROM admin a LEFT JOIN botsaz b ON b.id_user = a.id_admin WHERE b.id_user IS NULL'
            );
            foreach ($rows as $row) $add($row['id_admin'] ?? '');
            if ($idsupport !== '') $add($idsupport);
        }

        return [
            'tenant' => $tenant,
            'recipients' => array_keys($recipients),
            'bot_token' => $botToken,
        ];
    }

    private function uploadMediaMulti(string $preferred, array $uploads): array
    {
        $ctx = $this->tenantNotificationContext($preferred);
        $token = $ctx['bot_token'];
        $target = (string) ($ctx['recipients'][0] ?? '');
        if ($target === '') {
            $target = $this->uid();
        }
        $media = [];
        if (count($uploads) === 1) {
            $u = $uploads[0];
            $cf = new CURLFile($u['tmp'], $u['mime'] !== '' ? $u['mime'] : ($u['video'] ? 'video/mp4' : 'image/jpeg'), $u['name']);
            if ($u['video']) {
                $res = telegram('sendVideo', ['chat_id' => $target, 'video' => $cf], $token);
                $fid = $res['result']['video']['file_id'] ?? '';
                if ($fid !== '') $media[] = ['type' => 'video', 'file_id' => (string)$fid];
            } else {
                $res = telegram('sendPhoto', ['chat_id' => $target, 'photo' => $cf], $token);
                $ph = $res['result']['photo'] ?? [];
                $fid = (is_array($ph) && $ph) ? (end($ph)['file_id'] ?? '') : '';
                if ($fid !== '') $media[] = ['type' => 'photo', 'file_id' => (string)$fid];
            }
        } elseif (count($uploads) >= 2) {
            $arr = [];
            $payload = [];
            foreach ($uploads as $i => $u) {
                $key = 'tkf' . $i;
                $arr[] = ['type' => $u['video'] ? 'video' : 'photo', 'media' => 'attach://' . $key];
                $payload[$key] = new CURLFile($u['tmp'], $u['mime'] !== '' ? $u['mime'] : ($u['video'] ? 'video/mp4' : 'image/jpeg'), $u['name']);
            }
            $res = telegram('sendMediaGroup', array_merge(['chat_id' => $target, 'media' => $arr], $payload), $token);
            foreach (($res['result'] ?? []) as $r) {
                if (isset($r['photo']) && is_array($r['photo'])) {
                    $fid = end($r['photo'])['file_id'] ?? '';
                    if ($fid !== '') $media[] = ['type' => 'photo', 'file_id' => (string)$fid];
                } elseif (isset($r['video']['file_id'])) {
                    $media[] = ['type' => 'video', 'file_id' => (string)$r['video']['file_id']];
                }
            }
        }
        return ['media' => $media, 'target' => $target];
    }

    private function notifyAdmins(string $idsupport, string $text, array $mediaArr, string $uploadTarget, string $tracking = ''): void
    {
        $ctx = $this->tenantNotificationContext($idsupport);
        $recipients = $ctx['recipients'];
        $token = $ctx['bot_token'];
        $tenant = (int) $ctx['tenant'];
        if (empty($recipients)) {
            FaoximaLogger::warn('Ticket notification skipped: no tenant recipients', [
                'tenant' => $tenant,
                'tracking' => $tracking,
            ]);
            return;
        }

        $topicRow = select('topicid', 'idreport', 'report', 'otherservice', 'select');
        $topic = is_array($topicRow) ? (string)($topicRow['idreport'] ?? '') : '';
        $miniappOnly = (string)($this->setting['miniapp_ticket_mode'] ?? '0') === '1';
        if ($miniappOnly) {
            $text .= "\n\n⚠️ پاسخ به این تیکت فقط از طریق پنل وب مدیریت امکان‌پذیر است.";
        }
        $replyKb = ($tracking !== '' && !$miniappOnly) ? json_encode(['inline_keyboard' => [[['text' => '💬 پاسخ به تیکت', 'callback_data' => 'ticketadminreply_' . $tracking]]]], JSON_UNESCAPED_UNICODE) : '';

        $sendAlbum = function (string $chat, ?string $topicId) use ($mediaArr, $token) {
            if (count($mediaArr) === 0) return;
            if (count($mediaArr) === 1) {
                $m = $mediaArr[0];
                $p = ['chat_id' => $chat, $m['type'] => $m['file_id']];
                if ($topicId !== null) $p['message_thread_id'] = $topicId;
                telegram($m['type'] === 'video' ? 'sendvideo' : 'sendphoto', $p, $token);
            } else {
                $arr = [];
                foreach ($mediaArr as $m) {
                    $arr[] = ['type' => $m['type'], 'media' => $m['file_id']];
                }
                $p = ['chat_id' => $chat, 'media' => $arr];
                if ($topicId !== null) $p['message_thread_id'] = $topicId;
                telegram('sendMediaGroup', $p, $token);
            }
        };

        $deliver = function (string $chat, bool $useTopic, bool $withButton) use ($text, $replyKb, $topic, $uploadTarget, $mediaArr, $sendAlbum, $token) {
            $tid = ($useTopic && $topic !== '') ? $topic : null;
            $p = ['chat_id' => $chat, 'text' => $text, 'parse_mode' => 'HTML'];
            if ($tid !== null) $p['message_thread_id'] = $tid;
            if ($withButton && $replyKb !== '') $p['reply_markup'] = $replyKb;
            telegram('sendmessage', $p, $token);
            if (count($mediaArr) > 0 && $chat !== $uploadTarget) {
                $sendAlbum($chat, $tid);
            }
        };

        foreach ($recipients as $chat) {
            $deliver((string) $chat, false, true);
        }
    }
}
