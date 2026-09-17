<?php

declare(strict_types=1);

namespace FileRise\Http\Controllers;

use FileRise\Domain\LinkModel;
use ZipStream\ZipStream;

final class LinkController
{
    private static function json(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    }

    private static function input(): array
    {
        $decoded = json_decode((string)file_get_contents('php://input'), true);
        return is_array($decoded) ? $decoded : [];
    }

    private static function requirePost(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            self::json(['error' => 'Method not allowed.'], 405);
            exit;
        }
    }

    private static function cleanQuery(string $key): string
    {
        $value = $_GET[$key] ?? '';
        if (is_array($value)) {
            return '';
        }
        return trim((string)preg_replace('/[\x00-\x1F\x7F]/', '', (string)$value));
    }

    public function listLinks(): void
    {
        AdminController::requireAdmin();
        self::json(LinkModel::listActive());
    }

    public function browse(): void
    {
        AdminController::requireAdmin();
        $result = LinkModel::browse(self::cleanQuery('path'));
        self::json($result, isset($result['error']) ? 404 : 200);
    }

    public function createUpload(): void
    {
        self::requirePost();
        AdminController::requireAdmin();
        AdminController::requireCsrf();
        $result = LinkModel::createUpload(self::input(), (string)($_SESSION['username'] ?? 'admin'));
        self::json($result, isset($result['error']) ? 400 : 201);
    }

    public function createShare(): void
    {
        self::requirePost();
        AdminController::requireAdmin();
        AdminController::requireCsrf();
        $result = LinkModel::createShare(self::input(), (string)($_SESSION['username'] ?? 'admin'));
        self::json($result, isset($result['error']) ? 400 : 201);
    }

    public function update(): void
    {
        self::requirePost();
        AdminController::requireAdmin();
        AdminController::requireCsrf();
        $input = self::input();
        $result = LinkModel::update(
            trim((string)($input['type'] ?? '')),
            trim((string)($input['id'] ?? '')),
            $input
        );
        self::json($result, isset($result['error']) ? 400 : 200);
    }

    public function revoke(): void
    {
        self::requirePost();
        AdminController::requireAdmin();
        AdminController::requireCsrf();
        $input = self::input();
        $result = LinkModel::revoke(
            trim((string)($input['type'] ?? '')),
            trim((string)($input['id'] ?? ''))
        );
        self::json($result, isset($result['error']) ? 404 : 200);
    }

    private static function publicUrl(string $code, array $query = []): string
    {
        $url = '/' . rawurlencode($code);
        if ($query !== []) {
            $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }
        return fr_with_base_path($url);
    }

    private static function unavailable(): void
    {
        http_response_code(404);
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Robots-Tag: noindex, nofollow');
        ?>
        <!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
        <title>Link unavailable · Phaise Drop</title>
        <link rel="stylesheet" href="<?php echo htmlspecialchars(fr_with_base_path('/css/phaise-public.css?v={{APP_QVER}}'), ENT_QUOTES, 'UTF-8'); ?>">
        </head><body class="pd-public"><main class="pd-public-shell"><section class="pd-public-card pd-public-empty">
        <div class="pd-public-mark" aria-hidden="true">P</div><p class="pd-eyebrow">Phaise Drop</p><h1>Link unavailable</h1>
        <p>This link is invalid, closed, or expired. Ask the sender for a new one.</p></section></main></body></html>
        <?php
    }

    public function open(): void
    {
        $code = strtolower(self::cleanQuery('code'));
        $found = LinkModel::findOutboundByCode($code);
        if ($found === null) {
            // Upload requests and legacy four-letter drops keep the mature,
            // resumable FolderController path.
            $_GET['token'] = $code;
            (new FolderController())->shareFolder();
            return;
        }
        $token = $found['token'];
        $record = $found['record'];
        if (!LinkModel::isUnlocked($token, $record)) {
            $this->renderPin($code, $record);
            return;
        }

        $itemId = self::cleanQuery('item');
        $path = self::cleanQuery('path');
        $listing = LinkModel::publicListing($record, $itemId !== '' ? $itemId : null, $path);
        if (isset($listing['error'])) {
            self::unavailable();
            return;
        }
        $this->renderShare($code, $record, $listing);
    }

    private function renderPin(string $code, array $record): void
    {
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Robots-Tag: noindex, nofollow');
        $title = htmlspecialchars((string)($record['title'] ?? 'Protected share'), ENT_QUOTES, 'UTF-8');
        $csrf = htmlspecialchars((string)($_SESSION['csrf_token'] ?? ''), ENT_QUOTES, 'UTF-8');
        ?>
        <!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
        <title><?php echo $title; ?> · Phaise Drop</title>
        <link rel="stylesheet" href="<?php echo htmlspecialchars(fr_with_base_path('/css/phaise-public.css?v={{APP_QVER}}'), ENT_QUOTES, 'UTF-8'); ?>">
        </head><body class="pd-public"><main class="pd-public-shell"><section class="pd-public-card pd-pin-card">
        <div class="pd-public-mark" aria-hidden="true">P</div><p class="pd-eyebrow">Protected share</p>
        <h1><?php echo $title; ?></h1><p>Enter the PIN supplied by the sender to view these files.</p>
        <form class="pd-pin-form" method="post" action="<?php echo htmlspecialchars(fr_with_base_path('/api/links/unlock.php'), ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="code" value="<?php echo htmlspecialchars($code, ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="csrfToken" value="<?php echo $csrf; ?>">
        <label for="sharePin">PIN</label><input id="sharePin" name="pin" inputmode="numeric" pattern="[0-9]{4,12}" minlength="4" maxlength="12" autocomplete="one-time-code" required autofocus>
        <button type="submit">Unlock files</button></form></section></main></body></html>
        <?php
    }

    public function unlock(): void
    {
        self::requirePost();
        $csrf = trim((string)($_POST['csrfToken'] ?? ''));
        if ($csrf === '' || empty($_SESSION['csrf_token']) || !hash_equals((string)$_SESSION['csrf_token'], $csrf)) {
            self::unavailable();
            return;
        }
        $code = strtolower(trim((string)($_POST['code'] ?? '')));
        $pin = trim((string)($_POST['pin'] ?? ''));
        $found = LinkModel::findOutboundByCode($code);
        $limited = $found !== null && !self::consumePinAttempt((string)$found['token']);
        if ($found === null || $limited || !LinkModel::verifyPin($found['token'], $found['record'], $pin)) {
            http_response_code($limited ? 429 : 403);
            if ($limited) {
                header('Retry-After: 900');
            }
            header('Content-Type: text/html; charset=utf-8');
            header('Cache-Control: no-store');
            echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
                . '<link rel="stylesheet" href="' . htmlspecialchars(fr_with_base_path('/css/phaise-public.css?v={{APP_QVER}}'), ENT_QUOTES, 'UTF-8') . '">'
                . '<body class="pd-public"><main class="pd-public-shell"><section class="pd-public-card pd-public-empty"><h1>'
                . ($limited ? 'Too many attempts' : 'Incorrect PIN') . '</h1><p>'
                . ($limited ? 'Please wait 15 minutes before trying this PIN again.' : 'Check the PIN and try again.')
                . '</p><a class="pd-public-button" href="' . htmlspecialchars(self::publicUrl($code), ENT_QUOTES, 'UTF-8') . '">Try again</a></section></main></body>';
            return;
        }
        self::clearPinAttempts((string)$found['token']);
        header('Location: ' . self::publicUrl($code), true, 303);
    }

    private static function pinAttemptPath(): string
    {
        return rtrim((string)META_DIR, '/\\') . DIRECTORY_SEPARATOR . 'link_pin_attempts.json';
    }

    private static function clientIp(): string
    {
        foreach (['HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $key) {
            $candidate = trim((string)($_SERVER[$key] ?? ''));
            if (filter_var($candidate, FILTER_VALIDATE_IP)) {
                return $candidate;
            }
        }
        return '0.0.0.0';
    }

    /** Allow at most five submissions per link and client in 15 minutes. */
    private static function consumePinAttempt(string $token): bool
    {
        $path = self::pinAttemptPath();
        @mkdir(dirname($path), 0775, true);
        $handle = @fopen($path, 'c+');
        if ($handle === false || !@flock($handle, LOCK_EX)) {
            if (is_resource($handle)) {
                @fclose($handle);
            }
            return false;
        }
        try {
            rewind($handle);
            $decoded = json_decode((string)stream_get_contents($handle), true);
            $state = is_array($decoded) ? $decoded : [];
            $now = time();
            foreach ($state as $key => $bucket) {
                if (!is_array($bucket) || (int)($bucket['startedAt'] ?? 0) < $now - 900) {
                    unset($state[$key]);
                }
            }
            $key = hash('sha256', $token . '|' . self::clientIp());
            $bucket = is_array($state[$key] ?? null) ? $state[$key] : ['startedAt' => $now, 'attempts' => 0];
            if ((int)$bucket['attempts'] >= 5) {
                return false;
            }
            $bucket['attempts'] = (int)$bucket['attempts'] + 1;
            $state[$key] = $bucket;
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, (string)json_encode($state, JSON_PRETTY_PRINT));
            fflush($handle);
            return true;
        } finally {
            @flock($handle, LOCK_UN);
            @fclose($handle);
        }
    }

    private static function clearPinAttempts(string $token): void
    {
        $path = self::pinAttemptPath();
        $handle = @fopen($path, 'c+');
        if ($handle === false || !@flock($handle, LOCK_EX)) {
            if (is_resource($handle)) {
                @fclose($handle);
            }
            return;
        }
        try {
            rewind($handle);
            $decoded = json_decode((string)stream_get_contents($handle), true);
            $state = is_array($decoded) ? $decoded : [];
            unset($state[hash('sha256', $token . '|' . self::clientIp())]);
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, (string)json_encode($state, JSON_PRETTY_PRINT));
            fflush($handle);
        } finally {
            @flock($handle, LOCK_UN);
            @fclose($handle);
        }
    }

    private function renderShare(string $code, array $record, array $listing): void
    {
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Robots-Tag: noindex, nofollow');
        $title = htmlspecialchars((string)($record['title'] ?? 'Shared files'), ENT_QUOTES, 'UTF-8');
        $note = trim((string)($record['note'] ?? ''));
        $expires = (int)($record['expires'] ?? 0);
        $root = !empty($listing['root']);
        $itemId = (string)($listing['itemId'] ?? '');
        $currentPath = (string)($listing['path'] ?? '');
        ?>
        <!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
        <title><?php echo $title; ?> · Phaise Drop</title>
        <link rel="stylesheet" href="<?php echo htmlspecialchars(fr_with_base_path('/css/phaise-public.css?v={{APP_QVER}}'), ENT_QUOTES, 'UTF-8'); ?>">
        </head><body class="pd-public"><main class="pd-public-shell"><section class="pd-public-card pd-share-card">
        <header class="pd-public-header"><div><div class="pd-public-mark" aria-hidden="true">P</div></div><div class="pd-public-heading">
        <p class="pd-eyebrow">Files shared with you</p><h1><?php echo $title; ?></h1>
        <?php if ($note !== '') : ?>
            <p class="pd-public-note"><?php echo nl2br(htmlspecialchars($note, ENT_QUOTES, 'UTF-8')); ?></p>
        <?php endif; ?>
        <p class="pd-public-meta">Available until <?php echo htmlspecialchars(date('M j, Y \a\t g:i A T', $expires), ENT_QUOTES, 'UTF-8'); ?></p></div></header>
        <div class="pd-public-toolbar"><nav class="pd-breadcrumbs" aria-label="Breadcrumb">
        <a href="<?php echo htmlspecialchars(self::publicUrl($code), ENT_QUOTES, 'UTF-8'); ?>">Shared items</a>
        <?php if (!$root) :
            $parts = $currentPath === '' ? [] : explode('/', $currentPath);
            $acc = '';
            foreach ($parts as $part) :
                $acc = $acc === '' ? $part : $acc . '/' . $part; ?>
                <span>/</span><a href="<?php echo htmlspecialchars(self::publicUrl($code, ['item' => $itemId, 'path' => $acc]), ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($part, ENT_QUOTES, 'UTF-8'); ?></a>
            <?php endforeach;
        endif; ?></nav>
        <?php
        $archiveQuery = $root ? ['code' => $code] : ['code' => $code, 'item' => $itemId, 'path' => $currentPath];
        ?>
        <a class="pd-public-button pd-button-secondary" href="<?php echo htmlspecialchars(fr_with_base_path('/api/links/archive.php?' . http_build_query($archiveQuery, '', '&', PHP_QUERY_RFC3986)), ENT_QUOTES, 'UTF-8'); ?>">Download all</a></div>
        <div class="pd-public-list">
        <?php foreach ((array)($listing['entries'] ?? []) as $entry) :
            $name = htmlspecialchars((string)($entry['name'] ?? 'Item'), ENT_QUOTES, 'UTF-8');
            $type = (string)($entry['type'] ?? 'file');
            $available = !empty($entry['available']);
            $entryId = (string)($entry['id'] ?? '');
            $entryPath = (string)($entry['path'] ?? '');
            if ($root && $type === 'folder') {
                $openUrl = self::publicUrl($code, ['item' => $entryId]);
            } elseif (!$root && $type === 'folder') {
                $openUrl = self::publicUrl($code, ['item' => $entryId, 'path' => $entryPath]);
            } else {
                $downloadQuery = ['code' => $code, 'item' => $entryId];
                if ($entryPath !== '') {
                    $downloadQuery['path'] = $entryPath;
                }
                $openUrl = fr_with_base_path('/api/links/download.php?' . http_build_query($downloadQuery, '', '&', PHP_QUERY_RFC3986));
            }
            ?>
            <article class="pd-public-row<?php echo $available ? '' : ' is-unavailable'; ?>">
            <div class="pd-file-icon" aria-hidden="true"><?php echo $type === 'folder' ? 'folder' : 'file'; ?></div>
            <div class="pd-file-copy"><strong><?php echo $name; ?></strong><span><?php echo $available ? ($type === 'folder' ? 'Folder' : self::formatBytes((int)($entry['size'] ?? 0))) : 'Unavailable'; ?></span></div>
            <?php if ($available) : ?>
                <a class="pd-row-action" href="<?php echo htmlspecialchars($openUrl, ENT_QUOTES, 'UTF-8'); ?>"><?php echo $type === 'folder' ? 'Open' : 'Download'; ?></a>
            <?php endif; ?>
            </article>
        <?php endforeach; ?>
        <?php if (empty($listing['entries'])) : ?>
            <div class="pd-list-empty">This folder is empty.</div>
        <?php endif; ?>
        </div><footer class="pd-public-footer">Phaise Drop · Private file transfer</footer></section></main></body></html>
        <?php
    }

    private static function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        if ($bytes < 1048576) {
            return number_format($bytes / 1024, 1) . ' KB';
        }
        if ($bytes < 1073741824) {
            return number_format($bytes / 1048576, 1) . ' MB';
        }
        return number_format($bytes / 1073741824, 2) . ' GB';
    }

    private static function requireOutboundAccess(): array
    {
        $code = strtolower(self::cleanQuery('code'));
        $found = LinkModel::findOutboundByCode($code);
        if ($found === null || !LinkModel::isUnlocked($found['token'], $found['record'])) {
            self::json(['error' => 'Link unavailable.'], 404);
            exit;
        }
        return [$code, $found['token'], $found['record']];
    }

    public function download(): void
    {
        [, , $record] = self::requireOutboundAccess();
        $resolved = LinkModel::resolveOutboundItem($record, self::cleanQuery('item'), self::cleanQuery('path'));
        if ($resolved === null || $resolved['type'] !== 'file') {
            self::json(['error' => 'File unavailable.'], 404);
            return;
        }
        self::streamFile($resolved['real'], $resolved['name']);
    }

    private static function streamFile(string $path, string $name): void
    {
        $size = @filesize($path);
        if (!is_int($size) || $size < 0) {
            self::json(['error' => 'File unavailable.'], 404);
            return;
        }
        $name = str_replace(["\r", "\n", '"'], '', basename($name));
        $mime = function_exists('mime_content_type') ? (string)@mime_content_type($path) : 'application/octet-stream';
        if ($mime === '') {
            $mime = 'application/octet-stream';
        }
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: ' . $mime);
        header('Content-Disposition: attachment; filename="' . $name . '"; filename*=UTF-8\'\'' . rawurlencode($name));
        header('Accept-Ranges: bytes');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        $start = 0;
        $end = max(0, $size - 1);
        $range = trim((string)($_SERVER['HTTP_RANGE'] ?? ''));
        if ($range !== '' && preg_match('/^bytes=(\d*)-(\d*)$/', $range, $match)) {
            if ($match[1] === '' && $match[2] !== '') {
                $suffix = min($size, max(0, (int)$match[2]));
                $start = max(0, $size - $suffix);
            } else {
                $start = (int)$match[1];
                if ($match[2] !== '') {
                    $end = min($end, (int)$match[2]);
                }
            }
            if ($start > $end || $start >= $size) {
                http_response_code(416);
                header('Content-Range: bytes */' . $size);
                return;
            }
            http_response_code(206);
            header("Content-Range: bytes {$start}-{$end}/{$size}");
        }
        $length = $size === 0 ? 0 : ($end - $start + 1);
        header('Content-Length: ' . $length);
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') {
            return;
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            @session_write_close();
        }
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return;
        }
        @fseek($handle, $start);
        $remaining = $length;
        while ($remaining > 0 && !feof($handle) && !connection_aborted()) {
            $chunk = fread($handle, min(1048576, $remaining));
            if ($chunk === false || $chunk === '') {
                break;
            }
            echo $chunk;
            $remaining -= strlen($chunk);
            flush();
        }
        fclose($handle);
    }

    public function archive(): void
    {
        [, $token, $record] = self::requireOutboundAccess();
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') {
            header('Content-Type: application/zip');
            header('Cache-Control: no-store');
            return;
        }
        $item = self::cleanQuery('item');
        $manifest = LinkModel::archiveManifest($record, $item !== '' ? $item : null, self::cleanQuery('path'));
        if (isset($manifest['error'])) {
            self::json(['error' => (string)$manifest['error']], 413);
            return;
        }
        $lockDir = rtrim((string)META_DIR, '/\\') . DIRECTORY_SEPARATOR . 'archive-locks';
        @mkdir($lockDir, 0775, true);
        $lock = @fopen($lockDir . DIRECTORY_SEPARATOR . hash('sha256', $token) . '.lock', 'c+');
        if ($lock === false || !@flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) {
                @fclose($lock);
            }
            self::json(['error' => 'An archive for this link is already being prepared.'], 429);
            return;
        }
        register_shutdown_function(static function () use ($lock): void {
            @flock($lock, LOCK_UN);
            @fclose($lock);
        });
        if (session_status() === PHP_SESSION_ACTIVE) {
            @session_write_close();
        }
        @set_time_limit(0);
        header('Cache-Control: no-store');
        header('X-Accel-Buffering: no');
        header('X-Content-Type-Options: nosniff');
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string)($record['title'] ?? 'shared-files'));
        $name = trim((string)$name, '-.') ?: 'shared-files';
        $zip = new ZipStream(outputName: $name . '.zip', sendHttpHeaders: true);
        foreach ((array)$manifest['files'] as $file) {
            $zip->addFileFromPath(fileName: (string)$file['archive'], path: (string)$file['real']);
            if (connection_aborted()) {
                break;
            }
        }
        $zip->finish();
    }
}
