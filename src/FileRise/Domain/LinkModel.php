<?php

declare(strict_types=1);

namespace FileRise\Domain;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;

/**
 * Phaise Drop's link-first domain model.
 *
 * Upload requests intentionally continue to use FileRise's
 * share_folder_links.json records so existing public upload code remains
 * compatible. Multi-item outbound links live in outbound_links.json. Both
 * record types share the permanent used_drop_codes.json namespace.
 */
final class LinkModel
{
    private const CODE_PATTERN = '/^[a-z]{4}$/';
    private const TOKEN_PATTERN = '/^[a-f0-9]{64}$/';
    private const MAX_DURATION_SECONDS = 2592000; // 30 days
    private const MAX_UPLOAD_MB = 2000000;
    private const MAX_FILE_MB = 102400;
    private const ARCHIVE_MAX_BYTES = 21474836480; // 20 GiB
    private const ARCHIVE_MAX_FILES = 100000;

    private static function metaPath(string $name): string
    {
        return rtrim((string)META_DIR, '/\\') . DIRECTORY_SEPARATOR . $name;
    }

    private static function outboundPath(): string
    {
        return self::metaPath('outbound_links.json');
    }

    private static function uploadLinksPath(): string
    {
        return self::metaPath('share_folder_links.json');
    }

    private static function legacyFileLinksPath(): string
    {
        return self::metaPath('share_links.json');
    }

    private static function registryPath(): string
    {
        return self::metaPath('used_drop_codes.json');
    }

    private static function publishedBase(): string
    {
        $published = defined('FR_PUBLISHED_URL_EFFECTIVE')
            ? trim((string)FR_PUBLISHED_URL_EFFECTIVE)
            : '';
        if ($published !== '') {
            return rtrim($published, '/');
        }
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        $host = preg_replace('/[^A-Za-z0-9.:[\]-]/', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost'));
        return ($https ? 'https://' : 'http://') . ($host !== '' ? $host : 'localhost');
    }

    private static function readMap(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }
        $decoded = json_decode((string)@file_get_contents($path), true);
        return is_array($decoded) ? $decoded : [];
    }

    /** @return array<string,mixed> */
    private static function withLockedMap(string $path, callable $callback): array
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return ['error' => 'Could not create link metadata directory.'];
        }
        $lock = @fopen($path . '.lock', 'c+');
        if ($lock === false || !@flock($lock, LOCK_EX)) {
            if (is_resource($lock)) {
                @fclose($lock);
            }
            return ['error' => 'Could not lock link metadata.'];
        }

        try {
            $map = self::readMap($path);
            $result = $callback($map);
            if (!is_array($result) || !isset($result['map']) || !is_array($result['map'])) {
                return ['error' => 'Invalid link metadata update.'];
            }
            $payload = json_encode($result['map'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if (!is_string($payload)) {
                return ['error' => 'Could not encode link metadata.'];
            }
            $tmp = @tempnam($dir, '.phaise-links-');
            if ($tmp === false || @file_put_contents($tmp, $payload) === false || !@rename($tmp, $path)) {
                if (is_string($tmp) && is_file($tmp)) {
                    @unlink($tmp);
                }
                return ['error' => 'Could not save link metadata.'];
            }
            @chmod($path, 0660);
            return is_array($result['result'] ?? null) ? $result['result'] : ['success' => true];
        } finally {
            @flock($lock, LOCK_UN);
            @fclose($lock);
        }
    }

    /** Allocate and permanently burn a four-letter code. */
    private static function allocateCode(): array
    {
        $path = self::registryPath();
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return ['error' => 'Could not create the public-code registry.'];
        }
        $lock = @fopen($path . '.lock', 'c+');
        if ($lock === false || !@flock($lock, LOCK_EX)) {
            if (is_resource($lock)) {
                @fclose($lock);
            }
            return ['error' => 'Could not lock the public-code registry.'];
        }

        try {
            $decoded = self::readMap($path);
            $used = is_array($decoded['codes'] ?? null) ? $decoded['codes'] : $decoded;
            foreach ($used as $code => $usedAt) {
                if (!is_string($code) || !preg_match(self::CODE_PATTERN, $code)) {
                    unset($used[$code]);
                }
            }
            foreach ([self::uploadLinksPath(), self::outboundPath()] as $recordPath) {
                foreach (self::readMap($recordPath) as $record) {
                    if (!is_array($record)) {
                        continue;
                    }
                    $code = strtolower(trim((string)($record['shortCode'] ?? '')));
                    if (preg_match(self::CODE_PATTERN, $code) && !isset($used[$code])) {
                        $used[$code] = max(1, (int)($record['createdAt'] ?? time()));
                    }
                }
            }

            $capacity = 26 ** 4;
            if (count($used) >= $capacity) {
                return ['error' => 'All four-letter public codes have been used.'];
            }
            $offset = random_int(0, $capacity - 1);
            $alphabet = 'abcdefghijklmnopqrstuvwxyz';
            $allocated = '';
            for ($scan = 0; $scan < $capacity; $scan++) {
                $value = ($offset + $scan) % $capacity;
                $candidate = '';
                for ($position = 0; $position < 4; $position++) {
                    $candidate = $alphabet[$value % 26] . $candidate;
                    $value = intdiv($value, 26);
                }
                if (!isset($used[$candidate])) {
                    $allocated = $candidate;
                    break;
                }
            }
            if ($allocated === '') {
                return ['error' => 'Could not allocate a public code.'];
            }
            $used[$allocated] = time();
            $payload = json_encode(['version' => 1, 'codes' => $used], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            $tmp = @tempnam($dir, '.phaise-codes-');
            if (!is_string($payload) || $tmp === false || @file_put_contents($tmp, $payload) === false || !@rename($tmp, $path)) {
                if (is_string($tmp) && is_file($tmp)) {
                    @unlink($tmp);
                }
                return ['error' => 'Could not save the public-code registry.'];
            }
            @chmod($path, 0660);
            return ['success' => true, 'code' => $allocated];
        } catch (Throwable $e) {
            return ['error' => 'Could not generate a public code.'];
        } finally {
            @flock($lock, LOCK_UN);
            @fclose($lock);
        }
    }

    private static function cleanText($value, int $max): string
    {
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/u', '', trim((string)$value));
        return mb_substr(is_string($value) ? $value : '', 0, $max);
    }

    private static function clampDuration($value, int $default = 172800): int
    {
        if (!is_numeric($value)) {
            return $default;
        }
        return max(3600, min(self::MAX_DURATION_SECONDS, (int)$value));
    }

    private static function uploadRootReal(): ?string
    {
        $root = realpath(rtrim((string)UPLOAD_DIR, '/\\'));
        return is_string($root) && $root !== '' ? rtrim($root, DIRECTORY_SEPARATOR) : null;
    }

    private static function normalizeRelativePath($value): ?string
    {
        $path = trim(str_replace('\\', '/', (string)$value), '/');
        if ($path === '' || str_contains($path, "\0")) {
            return null;
        }
        $parts = explode('/', $path);
        foreach ($parts as $part) {
            if ($part === '' || $part === '.' || $part === '..' || str_starts_with($part, '.')) {
                return null;
            }
        }
        return implode('/', $parts);
    }

    private static function pathHasSymlink(string $root, string $relative): bool
    {
        $cursor = $root;
        foreach (explode('/', $relative) as $part) {
            $cursor .= DIRECTORY_SEPARATOR . $part;
            if (is_link($cursor)) {
                return true;
            }
        }
        return false;
    }

    /** @return array{relative:string,real:string,type:string,name:string}|null */
    private static function resolveLivePath($value, ?string $expectedType = null): ?array
    {
        $relative = self::normalizeRelativePath($value);
        $root = self::uploadRootReal();
        if ($relative === null || $root === null || self::pathHasSymlink($root, $relative)) {
            return null;
        }
        $candidate = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $real = realpath($candidate);
        if (!is_string($real) || ($real !== $root && !str_starts_with($real . DIRECTORY_SEPARATOR, $root . DIRECTORY_SEPARATOR))) {
            return null;
        }
        $type = is_dir($real) ? 'folder' : (is_file($real) ? 'file' : '');
        if ($type === '' || ($expectedType !== null && $expectedType !== $type)) {
            return null;
        }
        return ['relative' => $relative, 'real' => $real, 'type' => $type, 'name' => basename($relative)];
    }

    public static function browse(string $path = ''): array
    {
        $root = self::uploadRootReal();
        if ($root === null) {
            return ['error' => 'The Drop storage root is unavailable.'];
        }
        $relative = trim(str_replace('\\', '/', $path), '/');
        if ($relative === '') {
            $real = $root;
        } else {
            $resolved = self::resolveLivePath($relative, 'folder');
            if ($resolved === null) {
                return ['error' => 'Folder not found.'];
            }
            $relative = $resolved['relative'];
            $real = $resolved['real'];
        }

        $entries = [];
        $iterator = new FilesystemIterator($real, FilesystemIterator::SKIP_DOTS);
        foreach ($iterator as $entry) {
            $name = $entry->getFilename();
            if ($name === '' || str_starts_with($name, '.') || $entry->isLink()) {
                continue;
            }
            $type = $entry->isDir() ? 'folder' : ($entry->isFile() ? 'file' : '');
            if ($type === '') {
                continue;
            }
            $child = $relative === '' ? $name : $relative . '/' . $name;
            $entries[] = [
                'name' => $name,
                'path' => str_replace('\\', '/', $child),
                'type' => $type,
                'size' => $type === 'file' ? max(0, (int)$entry->getSize()) : null,
                'modifiedAt' => max(0, (int)$entry->getMTime()),
            ];
        }
        usort($entries, static function (array $a, array $b): int {
            if ($a['type'] !== $b['type']) {
                return $a['type'] === 'folder' ? -1 : 1;
            }
            return strnatcasecmp((string)$a['name'], (string)$b['name']);
        });
        return ['path' => $relative, 'entries' => $entries];
    }

    public static function createUpload(array $input, string $username): array
    {
        $title = self::cleanText($input['title'] ?? '', 120);
        if ($title === '') {
            return ['error' => 'Enter a name for this upload request.'];
        }
        $instructions = self::cleanText($input['instructions'] ?? '', 1000);
        $duration = self::clampDuration($input['expiresInSeconds'] ?? null);
        $idle = self::clampDuration($input['idleTimeoutSeconds'] ?? null);
        $maxTotalBytes = isset($input['maxTotalBytes']) && is_numeric($input['maxTotalBytes'])
            ? max(1048576, min(self::MAX_UPLOAD_MB * 1048576, (int)$input['maxTotalBytes']))
            : 100 * 1024 * 1024 * 1024;
        $maxFileBytes = isset($input['maxFileBytes']) && is_numeric($input['maxFileBytes'])
            ? max(1048576, min(self::MAX_FILE_MB * 1048576, $maxTotalBytes, (int)$input['maxFileBytes']))
            : min(self::MAX_FILE_MB * 1048576, $maxTotalBytes);

        $base = date('Y-m-d Hi');
        $folder = $base;
        $created = [];
        for ($suffix = 1; $suffix <= 999; $suffix++) {
            $folder = $suffix === 1 ? $base : $base . '-' . $suffix;
            $created = FolderModel::createFolder($folder, 'root', $username);
            if (!empty($created['success'])) {
                break;
            }
            if ((string)($created['error'] ?? '') !== 'Folder already exists') {
                return ['error' => (string)($created['error'] ?? 'Could not create the destination folder.')];
            }
        }
        if (empty($created['success'])) {
            return ['error' => 'Could not allocate a dated destination folder.'];
        }

        $share = FolderModel::createShareFolderLink(
            $folder,
            $duration,
            '',
            1,
            1,
            [
                'mode' => 'drop',
                'shortCode' => 1,
                'hideListing' => 1,
                'preserveFolderStructure' => 1,
                'maxFileSizeMb' => (int)ceil($maxFileBytes / 1048576),
                'maxTotalMb' => (int)ceil($maxTotalBytes / 1048576),
                'closeMode' => 'single',
                'idleTimeoutSeconds' => $idle,
                'title' => $title,
                'instructions' => $instructions,
                'createdBy' => $username,
                'createdAt' => time(),
                'schemaVersion' => 2,
            ]
        );
        if (!empty($share['error'])) {
            FolderModel::deleteFolderRecursiveAdmin($folder);
            return ['error' => (string)$share['error']];
        }
        return [
            'success' => true,
            'id' => (string)$share['token'],
            'type' => 'upload',
            'code' => (string)$share['shortCode'],
            'url' => (string)$share['link'],
            'folder' => $folder,
            'createdAt' => time(),
            'expiresAt' => (int)$share['expires'],
            'idleTimeoutSeconds' => $idle,
        ];
    }

    public static function createShare(array $input, string $username): array
    {
        $title = self::cleanText($input['title'] ?? '', 120);
        if ($title === '') {
            return ['error' => 'Enter a name for this share.'];
        }
        $rawItems = is_array($input['items'] ?? null) ? $input['items'] : [];
        if ($rawItems === []) {
            return ['error' => 'Select at least one file or folder.'];
        }

        $resolvedItems = [];
        foreach ($rawItems as $raw) {
            if (!is_array($raw)) {
                continue;
            }
            $resolved = self::resolveLivePath($raw['path'] ?? '', isset($raw['type']) ? (string)$raw['type'] : null);
            if ($resolved === null) {
                return ['error' => 'One or more selected items are unavailable or unsafe.'];
            }
            $resolvedItems[$resolved['relative']] = $resolved;
        }
        if ($resolvedItems === []) {
            return ['error' => 'Select at least one valid file or folder.'];
        }
        uksort($resolvedItems, static fn(string $a, string $b): int => strlen($a) <=> strlen($b));
        $items = [];
        foreach ($resolvedItems as $relative => $resolved) {
            $covered = false;
            foreach ($items as $existing) {
                if ($existing['type'] === 'folder' && str_starts_with($relative, $existing['path'] . '/')) {
                    $covered = true;
                    break;
                }
            }
            if ($covered) {
                continue;
            }
            $items[] = [
                'id' => bin2hex(random_bytes(8)),
                'path' => $relative,
                'type' => $resolved['type'],
                'name' => $resolved['name'],
            ];
        }

        $pin = trim((string)($input['pin'] ?? ''));
        if ($pin !== '' && !preg_match('/^\d{4,12}$/', $pin)) {
            return ['error' => 'PINs must contain 4 to 12 digits.'];
        }
        $pinHash = $pin !== '' ? password_hash($pin, PASSWORD_DEFAULT) : '';
        if ($pin !== '' && (!is_string($pinHash) || $pinHash === '')) {
            return ['error' => 'Could not protect this share.'];
        }

        $allocation = self::allocateCode();
        if (empty($allocation['success'])) {
            return ['error' => (string)($allocation['error'] ?? 'Could not allocate a public code.')];
        }
        $token = bin2hex(random_bytes(32));
        $code = (string)$allocation['code'];
        $now = time();
        $expires = $now + self::clampDuration($input['expiresInSeconds'] ?? null);
        $record = [
            'schemaVersion' => 1,
            'type' => 'share',
            'shortCode' => $code,
            'title' => $title,
            'note' => self::cleanText($input['note'] ?? '', 1000),
            'items' => $items,
            'password' => $pinHash,
            'pinRevision' => 1,
            'createdBy' => self::cleanText($username, 120),
            'createdAt' => $now,
            'expires' => $expires,
            'closedAt' => 0,
        ];

        $saved = self::withLockedMap(self::outboundPath(), static function (array $map) use ($token, $record): array {
            $map[$token] = $record;
            return ['map' => $map, 'result' => ['success' => true]];
        });
        if (empty($saved['success'])) {
            return ['error' => (string)($saved['error'] ?? 'Could not save this share.')];
        }
        return [
            'success' => true,
            'id' => $token,
            'type' => 'share',
            'code' => $code,
            'url' => self::publishedBase() . '/' . $code,
            'createdAt' => $now,
            'expiresAt' => $expires,
        ];
    }

    private static function isClosed(array $record, int $now): bool
    {
        if (!empty($record['closedAt']) || (!empty($record['expires']) && (int)$record['expires'] <= $now)) {
            return true;
        }
        if (($record['mode'] ?? '') === 'drop') {
            $idle = max(0, (int)($record['idleTimeoutSeconds'] ?? 0));
            $last = max(0, (int)($record['lastActivityAt'] ?? ($record['createdAt'] ?? 0)));
            if ($idle > 0 && $last > 0 && ($last + $idle) <= $now) {
                return true;
            }
        }
        return false;
    }

    public static function listActive(): array
    {
        $now = time();
        $base = self::publishedBase();
        $out = [];
        foreach (self::readMap(self::uploadLinksPath()) as $token => $record) {
            if (!is_array($record) || self::isClosed($record, $now)) {
                continue;
            }
            $mode = strtolower((string)($record['mode'] ?? 'browse'));
            $isUpload = $mode === 'drop' || !empty($record['hideListing']);
            $code = strtolower((string)($record['shortCode'] ?? ''));
            $url = $code !== ''
                ? $base . '/' . $code
                : $base . '/api/folder/shareFolder.php?token=' . rawurlencode((string)$token);
            $idle = max(0, (int)($record['idleTimeoutSeconds'] ?? 0));
            $last = max(0, (int)($record['lastActivityAt'] ?? ($record['createdAt'] ?? 0)));
            $out[] = [
                'id' => (string)$token,
                'type' => $isUpload ? 'upload' : 'legacy_folder',
                'title' => (string)($record['title'] ?? ($record['folder'] ?? 'Folder share')),
                'code' => $code,
                'url' => $url,
                'createdAt' => max(0, (int)($record['createdAt'] ?? 0)),
                'expiresAt' => max(0, (int)($record['expires'] ?? 0)),
                'idleExpiresAt' => $isUpload && $idle > 0 && $last > 0 ? $last + $idle : 0,
                'idleTimeoutSeconds' => $idle,
                'folder' => (string)($record['folder'] ?? ''),
                'instructions' => (string)($record['instructions'] ?? ''),
                'acceptedBytes' => max(0, (int)($record['acceptedBytes'] ?? 0)),
                'maxTotalBytes' => max(0, (int)($record['maxTotalMb'] ?? 0)) * 1048576,
                'maxFileBytes' => max(0, (int)($record['maxFileSizeMb'] ?? 0)) * 1048576,
                'uploadedFiles' => max(0, (int)($record['uploadedFiles'] ?? 0)),
                'itemCount' => 1,
                'pinProtected' => !empty($record['password']),
                'legacy' => !$isUpload || (int)($record['schemaVersion'] ?? 0) < 2,
                'editable' => $isUpload && (int)($record['schemaVersion'] ?? 0) >= 2,
            ];
        }
        foreach (self::readMap(self::outboundPath()) as $token => $record) {
            if (!is_array($record) || self::isClosed($record, $now)) {
                continue;
            }
            $code = strtolower((string)($record['shortCode'] ?? ''));
            $out[] = [
                'id' => (string)$token,
                'type' => 'share',
                'title' => (string)($record['title'] ?? 'Shared files'),
                'code' => $code,
                'url' => $base . '/' . $code,
                'createdAt' => max(0, (int)($record['createdAt'] ?? 0)),
                'expiresAt' => max(0, (int)($record['expires'] ?? 0)),
                'idleExpiresAt' => 0,
                'idleTimeoutSeconds' => 0,
                'folder' => '',
                'note' => (string)($record['note'] ?? ''),
                'acceptedBytes' => 0,
                'maxTotalBytes' => 0,
                'maxFileBytes' => 0,
                'uploadedFiles' => 0,
                'itemCount' => count(is_array($record['items'] ?? null) ? $record['items'] : []),
                'pinProtected' => !empty($record['password']),
                'legacy' => false,
                'editable' => true,
            ];
        }
        foreach (self::readMap(self::legacyFileLinksPath()) as $token => $record) {
            if (!is_array($record) || self::isClosed($record, $now)) {
                continue;
            }
            $folder = (string)($record['folder'] ?? 'root');
            $file = (string)($record['file'] ?? 'Shared file');
            $out[] = [
                'id' => (string)$token,
                'type' => 'legacy_file',
                'title' => $file,
                'code' => '',
                'url' => $base . '/api/file/share.php?token=' . rawurlencode((string)$token) . '&view=1',
                'createdAt' => max(0, (int)($record['createdAt'] ?? 0)),
                'expiresAt' => max(0, (int)($record['expires'] ?? 0)),
                'idleExpiresAt' => 0,
                'idleTimeoutSeconds' => 0,
                'folder' => $folder,
                'acceptedBytes' => 0,
                'maxTotalBytes' => 0,
                'maxFileBytes' => 0,
                'uploadedFiles' => 0,
                'itemCount' => 1,
                'pinProtected' => !empty($record['password']),
                'legacy' => true,
                'editable' => false,
            ];
        }
        usort($out, static fn(array $a, array $b): int => ((int)$b['createdAt']) <=> ((int)$a['createdAt']));
        return ['links' => $out, 'generatedAt' => $now];
    }

    public static function update(string $type, string $token, array $input): array
    {
        if (!preg_match(self::TOKEN_PATTERN, $token)) {
            return ['error' => 'Invalid link identifier.'];
        }
        if ($type === 'share') {
            return self::withLockedMap(self::outboundPath(), static function (array $map) use ($token, $input): array {
                if (!isset($map[$token]) || !is_array($map[$token]) || self::isClosed($map[$token], time())) {
                    return ['map' => $map, 'result' => ['error' => 'Active link not found.']];
                }
                $record = $map[$token];
                if (array_key_exists('title', $input)) {
                    $title = self::cleanText($input['title'], 120);
                    if ($title === '') {
                        return ['map' => $map, 'result' => ['error' => 'Title cannot be empty.']];
                    }
                    $record['title'] = $title;
                }
                if (array_key_exists('note', $input)) {
                    $record['note'] = self::cleanText($input['note'], 1000);
                }
                if (array_key_exists('expiresInSeconds', $input)) {
                    $record['expires'] = time() + self::clampDuration($input['expiresInSeconds']);
                }
                if (array_key_exists('pin', $input)) {
                    $pin = trim((string)$input['pin']);
                    if ($pin !== '' && !preg_match('/^\d{4,12}$/', $pin)) {
                        return ['map' => $map, 'result' => ['error' => 'PINs must contain 4 to 12 digits.']];
                    }
                    $record['password'] = $pin !== '' ? password_hash($pin, PASSWORD_DEFAULT) : '';
                    $record['pinRevision'] = max(1, (int)($record['pinRevision'] ?? 1)) + 1;
                }
                $map[$token] = $record;
                return ['map' => $map, 'result' => ['success' => true]];
            });
        }
        if ($type !== 'upload') {
            return ['error' => 'Legacy links cannot be edited.'];
        }
        return self::withLockedMap(self::uploadLinksPath(), static function (array $map) use ($token, $input): array {
            if (!isset($map[$token]) || !is_array($map[$token]) || self::isClosed($map[$token], time())) {
                return ['map' => $map, 'result' => ['error' => 'Active link not found.']];
            }
            $record = $map[$token];
            if ((int)($record['schemaVersion'] ?? 0) < 2) {
                return ['map' => $map, 'result' => ['error' => 'Legacy upload links cannot be edited.']];
            }
            if (array_key_exists('title', $input)) {
                $title = self::cleanText($input['title'], 120);
                if ($title === '') {
                    return ['map' => $map, 'result' => ['error' => 'Title cannot be empty.']];
                }
                $record['title'] = $title;
            }
            if (array_key_exists('instructions', $input)) {
                $record['instructions'] = self::cleanText($input['instructions'], 1000);
            }
            if (array_key_exists('expiresInSeconds', $input)) {
                $record['expires'] = time() + self::clampDuration($input['expiresInSeconds']);
            }
            if (array_key_exists('idleTimeoutSeconds', $input)) {
                $record['idleTimeoutSeconds'] = self::clampDuration($input['idleTimeoutSeconds']);
            }
            $reserved = 0;
            foreach ((array)($record['uploadReservations'] ?? []) as $reservation) {
                $reserved += is_array($reservation) ? max(0, (int)($reservation['bytes'] ?? 0)) : 0;
            }
            $minimum = max(0, (int)($record['acceptedBytes'] ?? 0)) + $reserved;
            if (array_key_exists('maxTotalBytes', $input)) {
                $bytes = is_numeric($input['maxTotalBytes']) ? (int)$input['maxTotalBytes'] : 0;
                if ($bytes < max(1048576, $minimum) || $bytes > self::MAX_UPLOAD_MB * 1048576) {
                    return ['map' => $map, 'result' => ['error' => 'Total capacity cannot be below accepted or active upload bytes.']];
                }
                $record['maxTotalMb'] = (int)ceil($bytes / 1048576);
            }
            if (array_key_exists('maxFileBytes', $input)) {
                $bytes = is_numeric($input['maxFileBytes']) ? (int)$input['maxFileBytes'] : 0;
                $totalBytes = max(1048576, (int)($record['maxTotalMb'] ?? 0) * 1048576);
                if ($bytes < 1048576 || $bytes > min($totalBytes, self::MAX_FILE_MB * 1048576)) {
                    return ['map' => $map, 'result' => ['error' => 'Per-file capacity must be between 1 MB and 100 GB, without exceeding total capacity.']];
                }
                $record['maxFileSizeMb'] = (int)ceil($bytes / 1048576);
            }
            $map[$token] = $record;
            return ['map' => $map, 'result' => ['success' => true]];
        });
    }

    public static function revoke(string $type, string $token): array
    {
        if ($type === 'upload' || $type === 'legacy_folder') {
            return FolderModel::deleteShareFolderLink($token)
                ? ['success' => true]
                : ['error' => 'Link not found.'];
        }
        if ($type === 'legacy_file') {
            return self::withLockedMap(self::legacyFileLinksPath(), static function (array $map) use ($token): array {
                if (!isset($map[$token])) {
                    return ['map' => $map, 'result' => ['error' => 'Link not found.']];
                }
                unset($map[$token]);
                return ['map' => $map, 'result' => ['success' => true]];
            });
        }
        if ($type !== 'share') {
            return ['error' => 'Invalid link type.'];
        }
        return self::withLockedMap(self::outboundPath(), static function (array $map) use ($token): array {
            if (!isset($map[$token]) || !is_array($map[$token])) {
                return ['map' => $map, 'result' => ['error' => 'Link not found.']];
            }
            $map[$token]['closedAt'] = time();
            return ['map' => $map, 'result' => ['success' => true]];
        });
    }

    /** @return array{token:string,record:array}|null */
    public static function findOutboundByCode(string $code, bool $includeClosed = false): ?array
    {
        $code = strtolower(trim($code));
        if (!preg_match(self::CODE_PATTERN, $code)) {
            return null;
        }
        foreach (self::readMap(self::outboundPath()) as $token => $record) {
            if (!is_array($record) || !hash_equals((string)($record['shortCode'] ?? ''), $code)) {
                continue;
            }
            if (!$includeClosed && self::isClosed($record, time())) {
                return null;
            }
            return ['token' => (string)$token, 'record' => $record];
        }
        return null;
    }

    public static function pinSessionKey(string $token, array $record): string
    {
        return hash('sha256', 'phaise-share|' . $token . '|' . max(1, (int)($record['pinRevision'] ?? 1)));
    }

    public static function isUnlocked(string $token, array $record): bool
    {
        if (empty($record['password'])) {
            return true;
        }
        $key = self::pinSessionKey($token, $record);
        return !empty($_SESSION['phaise_share_unlocks'][$key])
            && (int)$_SESSION['phaise_share_unlocks'][$key] > time();
    }

    public static function verifyPin(string $token, array $record, string $pin): bool
    {
        if (empty($record['password']) || !password_verify($pin, (string)$record['password'])) {
            return false;
        }
        if (!isset($_SESSION['phaise_share_unlocks']) || !is_array($_SESSION['phaise_share_unlocks'])) {
            $_SESSION['phaise_share_unlocks'] = [];
        }
        $_SESSION['phaise_share_unlocks'][self::pinSessionKey($token, $record)] = min(
            (int)($record['expires'] ?? (time() + 7200)),
            time() + 28800
        );
        return true;
    }

    private static function itemById(array $record, string $itemId): ?array
    {
        foreach ((array)($record['items'] ?? []) as $item) {
            if (is_array($item) && hash_equals((string)($item['id'] ?? ''), $itemId)) {
                return $item;
            }
        }
        return null;
    }

    /** Resolve a public item's file/folder path, constrained to its selected root. */
    public static function resolveOutboundItem(array $record, string $itemId, string $subPath = ''): ?array
    {
        $item = self::itemById($record, $itemId);
        if ($item === null) {
            return null;
        }
        $base = self::resolveLivePath($item['path'] ?? '', (string)($item['type'] ?? ''));
        if ($base === null) {
            return null;
        }
        $sub = trim(str_replace('\\', '/', $subPath), '/');
        if ($sub === '') {
            return $base + ['item' => $item, 'subPath' => ''];
        }
        if ($base['type'] !== 'folder') {
            return null;
        }
        $normalizedSub = self::normalizeRelativePath($sub);
        if ($normalizedSub === null || self::pathHasSymlink($base['real'], $normalizedSub)) {
            return null;
        }
        $candidate = $base['real'] . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $normalizedSub);
        $real = realpath($candidate);
        $baseReal = rtrim($base['real'], DIRECTORY_SEPARATOR);
        if (!is_string($real) || !str_starts_with($real . DIRECTORY_SEPARATOR, $baseReal . DIRECTORY_SEPARATOR)) {
            return null;
        }
        $type = is_dir($real) ? 'folder' : (is_file($real) ? 'file' : '');
        if ($type === '') {
            return null;
        }
        return [
            'relative' => $base['relative'] . '/' . $normalizedSub,
            'real' => $real,
            'type' => $type,
            'name' => basename($real),
            'item' => $item,
            'subPath' => $normalizedSub,
        ];
    }

    public static function publicListing(array $record, ?string $itemId = null, string $path = ''): array
    {
        if ($itemId === null || $itemId === '') {
            $entries = [];
            foreach ((array)($record['items'] ?? []) as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $resolved = self::resolveOutboundItem($record, (string)($item['id'] ?? ''));
                $entries[] = [
                    'id' => (string)($item['id'] ?? ''),
                    'name' => (string)($item['name'] ?? 'Item'),
                    'type' => (string)($item['type'] ?? 'file'),
                    'available' => $resolved !== null,
                    'size' => $resolved !== null && $resolved['type'] === 'file' ? max(0, (int)@filesize($resolved['real'])) : null,
                ];
            }
            return ['entries' => $entries, 'itemId' => '', 'path' => '', 'root' => true];
        }
        $resolved = self::resolveOutboundItem($record, $itemId, $path);
        if ($resolved === null || $resolved['type'] !== 'folder') {
            return ['error' => 'Folder is unavailable.'];
        }
        $entries = [];
        foreach (new FilesystemIterator($resolved['real'], FilesystemIterator::SKIP_DOTS) as $entry) {
            $name = $entry->getFilename();
            if ($name === '' || str_starts_with($name, '.') || $entry->isLink()) {
                continue;
            }
            $type = $entry->isDir() ? 'folder' : ($entry->isFile() ? 'file' : '');
            if ($type === '') {
                continue;
            }
            $childPath = $resolved['subPath'] === '' ? $name : $resolved['subPath'] . '/' . $name;
            $entries[] = [
                'id' => $itemId,
                'name' => $name,
                'path' => str_replace('\\', '/', $childPath),
                'type' => $type,
                'available' => true,
                'size' => $type === 'file' ? max(0, (int)$entry->getSize()) : null,
            ];
        }
        usort($entries, static function (array $a, array $b): int {
            if ($a['type'] !== $b['type']) {
                return $a['type'] === 'folder' ? -1 : 1;
            }
            return strnatcasecmp((string)$a['name'], (string)$b['name']);
        });
        return ['entries' => $entries, 'itemId' => $itemId, 'path' => $resolved['subPath'], 'root' => false];
    }

    /** Build a safe archive manifest before any response bytes are sent. */
    public static function archiveManifest(array $record, ?string $itemId = null, string $path = ''): array
    {
        $targets = [];
        if ($itemId !== null && $itemId !== '') {
            $resolved = self::resolveOutboundItem($record, $itemId, $path);
            if ($resolved === null) {
                return ['error' => 'Selected item is unavailable.'];
            }
            $targets[] = $resolved;
        } else {
            foreach ((array)($record['items'] ?? []) as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $resolved = self::resolveOutboundItem($record, (string)($item['id'] ?? ''));
                if ($resolved !== null) {
                    $targets[] = $resolved;
                }
            }
        }
        if ($targets === []) {
            return ['error' => 'No available files were found.'];
        }

        $files = [];
        $bytes = 0;
        $archiveRoots = [];
        foreach ($targets as $target) {
            $archiveRoot = (string)$target['name'];
            $candidateRoot = $archiveRoot;
            $suffix = 2;
            while (isset($archiveRoots[strtolower($candidateRoot)])) {
                $extension = $target['type'] === 'file' ? pathinfo($archiveRoot, PATHINFO_EXTENSION) : '';
                $stem = $extension !== '' ? substr($archiveRoot, 0, -(strlen($extension) + 1)) : $archiveRoot;
                $candidateRoot = $stem . '-' . $suffix . ($extension !== '' ? '.' . $extension : '');
                $suffix++;
            }
            $archiveRoot = $candidateRoot;
            $archiveRoots[strtolower($archiveRoot)] = true;
            if ($target['type'] === 'file') {
                $size = max(0, (int)@filesize($target['real']));
                $files[] = ['real' => $target['real'], 'archive' => $archiveRoot, 'size' => $size];
                $bytes += $size;
            } else {
                $baseName = $archiveRoot;
                $baseLen = strlen(rtrim($target['real'], DIRECTORY_SEPARATOR));
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($target['real'], FilesystemIterator::SKIP_DOTS)
                );
                foreach ($iterator as $entry) {
                    if (!$entry->isFile() || $entry->isLink()) {
                        continue;
                    }
                    $real = realpath($entry->getPathname());
                    if (!is_string($real) || self::pathHasSymlink($target['real'], ltrim(substr($real, $baseLen), DIRECTORY_SEPARATOR))) {
                        continue;
                    }
                    $relative = str_replace('\\', '/', ltrim(substr($real, $baseLen), DIRECTORY_SEPARATOR));
                    if ($relative === '' || self::normalizeRelativePath($relative) === null) {
                        continue;
                    }
                    $size = max(0, (int)$entry->getSize());
                    $files[] = ['real' => $real, 'archive' => $baseName . '/' . $relative, 'size' => $size];
                    $bytes += $size;
                    if (count($files) > self::ARCHIVE_MAX_FILES || $bytes > self::ARCHIVE_MAX_BYTES) {
                        return ['error' => 'Download all is limited to 20 GB and 100,000 files.'];
                    }
                }
            }
            if (count($files) > self::ARCHIVE_MAX_FILES || $bytes > self::ARCHIVE_MAX_BYTES) {
                return ['error' => 'Download all is limited to 20 GB and 100,000 files.'];
            }
        }
        return ['files' => $files, 'bytes' => $bytes, 'count' => count($files)];
    }
}
