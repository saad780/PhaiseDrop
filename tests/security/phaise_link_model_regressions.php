<?php

declare(strict_types=1);

$baseDir = dirname(__DIR__, 2);
$tmpBase = $baseDir . '/tests/.tmp_phaise_links_' . bin2hex(random_bytes(4));
$uploadDir = $tmpBase . '/uploads/';
$usersDir = $tmpBase . '/users/';
$metaDir = $tmpBase . '/metadata/';
$sessionDir = $tmpBase . '/sessions/';

function phaiseLinkFailIf(bool $condition, string $message, array &$errors): void
{
    if ($condition) {
        $errors[] = $message;
    }
}

function phaiseLinkRmTree(string $path): void
{
    if (!file_exists($path) && !is_link($path)) {
        return;
    }
    if (is_link($path) || is_file($path)) {
        @unlink($path);
        return;
    }
    foreach (scandir($path) ?: [] as $item) {
        if ($item !== '.' && $item !== '..') {
            phaiseLinkRmTree($path . DIRECTORY_SEPARATOR . $item);
        }
    }
    @rmdir($path);
}

@mkdir($uploadDir . 'Project/Sub', 0775, true);
@mkdir($uploadDir . 'Other', 0775, true);
@mkdir($usersDir, 0700, true);
@mkdir($metaDir, 0775, true);
@mkdir($sessionDir, 0700, true);
file_put_contents($uploadDir . 'Project/readme.txt', 'project');
file_put_contents($uploadDir . 'Project/Sub/image.bin', str_repeat('x', 32));
file_put_contents($uploadDir . 'Other/readme.txt', 'other');
file_put_contents($uploadDir . 'loose.txt', 'loose');
session_save_path($sessionDir);

putenv('FR_TEST_UPLOAD_DIR=' . $uploadDir);
putenv('FR_TEST_USERS_DIR=' . $usersDir);
putenv('FR_TEST_META_DIR=' . $metaDir);
putenv('FR_PUBLISHED_URL=https://drop.example.test');
putenv('PERSISTENT_TOKENS_KEY=test_persistent_tokens_key_32bytes!');
$_SERVER['HTTP_HOST'] = 'admin.example.test';

require_once $baseDir . '/config/config.php';

$errors = [];

try {
    $share = \FileRise\Domain\LinkModel::createShare([
        'title' => 'Project delivery',
        'note' => 'Download everything.',
        'pin' => '4826',
        'expiresInSeconds' => 172800,
        'items' => [
            ['path' => 'Project', 'type' => 'folder'],
            ['path' => 'Project/readme.txt', 'type' => 'file'],
            ['path' => 'Other/readme.txt', 'type' => 'file'],
        ],
    ], 'admin');

    phaiseLinkFailIf(isset($share['error']), 'multi-item share creation failed: ' . ($share['error'] ?? ''), $errors);
    $token = (string)($share['id'] ?? '');
    $code = (string)($share['code'] ?? '');
    phaiseLinkFailIf(!preg_match('/^[a-f0-9]{64}$/', $token), 'outbound internal token is not 256-bit hex', $errors);
    phaiseLinkFailIf(!preg_match('/^[a-z]{4}$/', $code), 'outbound share did not receive a four-letter code', $errors);
    phaiseLinkFailIf(($share['url'] ?? '') !== 'https://drop.example.test/' . $code, 'outbound share URL is not published at the short-code root', $errors);

    $found = \FileRise\Domain\LinkModel::findOutboundByCode($code);
    phaiseLinkFailIf($found === null, 'outbound code did not resolve', $errors);
    $record = is_array($found['record'] ?? null) ? $found['record'] : [];
    phaiseLinkFailIf(count((array)($record['items'] ?? [])) !== 2, 'a child selection was not deduplicated beneath its selected folder', $errors);
    phaiseLinkFailIf(\FileRise\Domain\LinkModel::isUnlocked($token, $record), 'PIN-protected share started unlocked', $errors);
    phaiseLinkFailIf(\FileRise\Domain\LinkModel::verifyPin($token, $record, '0000'), 'incorrect PIN was accepted', $errors);
    phaiseLinkFailIf(!\FileRise\Domain\LinkModel::verifyPin($token, $record, '4826'), 'correct PIN was rejected', $errors);
    phaiseLinkFailIf(!\FileRise\Domain\LinkModel::isUnlocked($token, $record), 'correct PIN did not unlock the current session', $errors);

    $listing = \FileRise\Domain\LinkModel::publicListing($record);
    phaiseLinkFailIf(count((array)($listing['entries'] ?? [])) !== 2, 'public root listing did not contain both selected roots', $errors);
    $folderItem = null;
    foreach ((array)($record['items'] ?? []) as $item) {
        if (($item['type'] ?? '') === 'folder') {
            $folderItem = $item;
            break;
        }
    }
    $folderId = (string)($folderItem['id'] ?? '');
    phaiseLinkFailIf(\FileRise\Domain\LinkModel::resolveOutboundItem($record, $folderId, '../loose.txt') !== null, 'outbound path traversal escaped a selected folder', $errors);
    $folderListing = \FileRise\Domain\LinkModel::publicListing($record, $folderId);
    phaiseLinkFailIf(count((array)($folderListing['entries'] ?? [])) !== 2, 'folder browsing did not expose live safe children', $errors);

    $manifest = \FileRise\Domain\LinkModel::archiveManifest($record);
    phaiseLinkFailIf(isset($manifest['error']), 'safe download-all manifest failed: ' . ($manifest['error'] ?? ''), $errors);
    phaiseLinkFailIf((int)($manifest['count'] ?? 0) !== 3, 'download-all manifest did not include all selected files', $errors);
    $archiveNames = array_column((array)($manifest['files'] ?? []), 'archive');
    phaiseLinkFailIf(count($archiveNames) !== count(array_unique(array_map('strtolower', $archiveNames))), 'download-all produced duplicate archive paths', $errors);

    $active = \FileRise\Domain\LinkModel::listActive()['links'] ?? [];
    $activeShare = array_values(array_filter($active, static fn(array $link): bool => ($link['id'] ?? '') === $token))[0] ?? [];
    phaiseLinkFailIf(($activeShare['note'] ?? '') !== 'Download everything.', 'dashboard list omitted the share note needed for editing', $errors);
    phaiseLinkFailIf(empty($activeShare['pinProtected']), 'dashboard list lost the PIN-protected state', $errors);

    $updated = \FileRise\Domain\LinkModel::update('share', $token, [
        'title' => 'Revised delivery',
        'note' => 'Revised note',
        'expiresInSeconds' => 3600,
    ]);
    phaiseLinkFailIf(empty($updated['success']), 'outbound share update failed', $errors);

    $upload = \FileRise\Domain\LinkModel::createUpload([
        'title' => 'Originals request',
        'instructions' => 'Please include RAW files.',
        'expiresInSeconds' => 172800,
        'idleTimeoutSeconds' => 172800,
        'maxTotalBytes' => 2 * 1073741824,
        'maxFileBytes' => 1073741824,
    ], 'admin');
    phaiseLinkFailIf(isset($upload['error']), 'upload request creation failed: ' . ($upload['error'] ?? ''), $errors);
    phaiseLinkFailIf(!preg_match('/^\d{4}-\d{2}-\d{2} \d{4}(?:-\d+)?$/', (string)($upload['folder'] ?? '')), 'upload destination did not use the dated folder format', $errors);
    phaiseLinkFailIf(!is_dir($uploadDir . (string)($upload['folder'] ?? '')), 'upload destination folder was not created', $errors);
    phaiseLinkFailIf(!preg_match('/^[a-z]{4}$/', (string)($upload['code'] ?? '')), 'upload request did not receive a four-letter code', $errors);
    phaiseLinkFailIf((string)($upload['code'] ?? '') === $code, 'upload and share code registries collided', $errors);

    $active = \FileRise\Domain\LinkModel::listActive()['links'] ?? [];
    $activeUpload = array_values(array_filter($active, static fn(array $link): bool => ($link['id'] ?? '') === ($upload['id'] ?? '')))[0] ?? [];
    phaiseLinkFailIf(empty($activeUpload['editable']), 'new upload request was not marked editable', $errors);
    phaiseLinkFailIf(($activeUpload['instructions'] ?? '') !== 'Please include RAW files.', 'dashboard list omitted upload instructions', $errors);
    phaiseLinkFailIf((int)($activeUpload['idleTimeoutSeconds'] ?? 0) !== 172800, 'dashboard list omitted upload idle timeout', $errors);

    $uploadFolder = (string)($upload['folder'] ?? '');
    $revokedUpload = \FileRise\Domain\LinkModel::revoke('upload', (string)($upload['id'] ?? ''));
    phaiseLinkFailIf(empty($revokedUpload['success']), 'upload request could not be closed', $errors);
    phaiseLinkFailIf(!is_dir($uploadDir . $uploadFolder), 'closing an upload request deleted received NAS files', $errors);

    $revokedShare = \FileRise\Domain\LinkModel::revoke('share', $token);
    phaiseLinkFailIf(empty($revokedShare['success']), 'outbound share could not be closed', $errors);
    phaiseLinkFailIf(\FileRise\Domain\LinkModel::findOutboundByCode($code) !== null, 'closed outbound share still resolved publicly', $errors);
    $registry = json_decode((string)file_get_contents($metaDir . 'used_drop_codes.json'), true) ?: [];
    phaiseLinkFailIf(!isset($registry['codes'][$code]), 'closing a share removed its permanent code tombstone', $errors);

    if (function_exists('symlink') && @symlink($tmpBase, $uploadDir . 'unsafe-link')) {
        $unsafe = \FileRise\Domain\LinkModel::createShare([
            'title' => 'Unsafe',
            'items' => [['path' => 'unsafe-link', 'type' => 'folder']],
        ], 'admin');
        phaiseLinkFailIf(empty($unsafe['error']), 'a symlinked NAS selection was accepted', $errors);
    }
} finally {
    phaiseLinkRmTree($tmpBase);
}

if ($errors) {
    fwrite(STDERR, "Phaise link model regression failures:\n- " . implode("\n- ", $errors) . "\n");
    exit(1);
}

echo "Phaise link model regressions passed\n";
