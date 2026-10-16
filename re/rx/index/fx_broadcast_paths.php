<?php
// === FX-BROADCAST-QUEUE: per-tenant broadcast queue paths (live require) ===
// Main bot  => cronbot/info,        cronbot/users.txt,        cronbot/users.json
// Reseller  => cronbot/info_r<rid>, cronbot/users_r<rid>.txt, cronbot/users_r<rid>.json
// The worker (cronbot/sendmessage.php) picks exactly one queue per tick and
// sends with that queue's own bot token, so tenant broadcasts never collide
// with the main queue and never go out from the wrong bot.
if (!defined('FX_BROADCAST_PATHS_LOADED')) {
    define('FX_BROADCAST_PATHS_LOADED', true);

    if (!function_exists('fx_broadcast_files')) {
        function fx_broadcast_files(string $dir, ?int $rid = null): array
        {
            if ($rid === null) {
                $rid = defined('FX_RESELLER_ID') ? (int) FX_RESELLER_ID : 0;
            }
            $suffix = $rid > 0 ? '_r' . $rid : '';
            return [
                'rid'    => $rid,
                'suffix' => $suffix,
                'info'   => $dir . '/info' . $suffix,
                'users'  => $dir . '/users' . $suffix . '.txt',
                'json'   => $dir . '/users' . $suffix . '.json',
            ];
        }
    }

    if (!function_exists('fx_broadcast_rid_from_info')) {
        function fx_broadcast_rid_from_info(string $infoFile): int
        {
            if (preg_match('/^info_r([0-9]+)$/', basename($infoFile), $m)) {
                return (int) $m[1];
            }
            return 0;
        }
    }

    if (!function_exists('fx_broadcast_count_lines')) {
        function fx_broadcast_count_lines(string $path): int
        {
            if (!is_file($path)) {
                return 0;
            }
            $fh = @fopen($path, 'r');
            if (!$fh) {
                return 0;
            }
            $n = 0;
            while (!feof($fh)) {
                $chunk = fread($fh, 65536);
                if ($chunk === false) {
                    break;
                }
                $n += substr_count($chunk, "\n");
            }
            fclose($fh);
            return $n;
        }
    }

    if (!function_exists('fx_broadcast_has_work')) {
        function fx_broadcast_has_work(array $files): bool
        {
            if (is_file($files['info'])) {
                $info = json_decode((string) @file_get_contents($files['info']), true);
                if (is_array($info) && is_string($info['status'] ?? null) && strpos($info['status'], 'paused_') === 0) {
                    return false;
                }
            }
            if (fx_broadcast_count_lines($files['users']) > 0) {
                return true;
            }
            foreach (glob($files['users'] . '.*.inflight') ?: [] as $inf) {
                if (fx_broadcast_count_lines($inf) > 0) {
                    return true;
                }
            }
            return false;
        }
    }

    if (!function_exists('fx_broadcast_pick')) {
        // One queue per worker tick: prefer the first queue with pending work
        // (main queue first, then tenants by rid asc), otherwise the first
        // queue that only needs its finishing/report path.
        function fx_broadcast_pick(string $baseDir): ?array
        {
            $candidates = [];
            if (is_file($baseDir . '/info')) {
                $candidates[] = fx_broadcast_files($baseDir, 0);
            }
            foreach (glob($baseDir . '/info_r*') ?: [] as $f) {
                $rid = fx_broadcast_rid_from_info($f);
                if ($rid > 0) {
                    $candidates[] = fx_broadcast_files($baseDir, $rid);
                }
            }
            if (empty($candidates)) {
                return null;
            }
            usort($candidates, static function (array $a, array $b): int {
                return $a['rid'] <=> $b['rid'];
            });
            $finishable = null;
            foreach ($candidates as $c) {
                if (fx_broadcast_has_work($c)) {
                    return $c;
                }
                if ($finishable === null) {
                    $finishable = $c;
                }
            }
            return $finishable;
        }
    }

    if (!function_exists('fx_broadcast_rid_token')) {
        function fx_broadcast_rid_token(int $rid): ?string
        {
            if ($rid <= 0) {
                return null;
            }
            if (defined('FX_RESELLER_ID') && (int) FX_RESELLER_ID === $rid
                && defined('FX_RESELLER_TOKEN') && FX_RESELLER_TOKEN !== '') {
                return FX_RESELLER_TOKEN;
            }
            global $pdo;
            try {
                $stmt = $pdo->prepare("SELECT bot_token FROM botsaz WHERE id_user = :rid LIMIT 1");
                $stmt->execute([':rid' => (string) $rid]);
                $t = trim((string) $stmt->fetchColumn());
                return $t !== '' ? $t : null;
            } catch (\Throwable $e) {
                return null;
            }
        }
    }
}
