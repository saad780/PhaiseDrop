<?php
declare(strict_types=1);

$baseDir = dirname(__DIR__, 2);
$tmpBase = $baseDir . '/tests/.tmp_phaise_drop_' . bin2hex(random_bytes(4));
$uploadDir = $tmpBase . '/uploads/';
$usersDir = $tmpBase . '/users/';
$metaDir = $tmpBase . '/metadata/';
$sessionDir = $tmpBase . '/sessions/';

function phaiseDropFailIf(bool $condition, string $message, array &$errors): void
{
    if ($condition) {
        $errors[] = $message;
    }
}

function phaiseDropRmTree(string $dir): void
{
    if (!file_exists($dir) && !is_link($dir)) {
        return;
    }
    if (is_link($dir) || is_file($dir)) {
        @unlink($dir);
        return;
    }
    foreach (scandir($dir) ?: [] as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        phaiseDropRmTree($dir . DIRECTORY_SEPARATOR . $item);
    }
    @rmdir($dir);
}

@mkdir($uploadDir . 'Drop test', 0775, true);
@mkdir($usersDir, 0700, true);
@mkdir($metaDir, 0775, true);
@mkdir($sessionDir, 0700, true);
session_save_path($sessionDir);

putenv('FR_TEST_UPLOAD_DIR=' . $uploadDir);
putenv('FR_TEST_USERS_DIR=' . $usersDir);
putenv('FR_TEST_META_DIR=' . $metaDir);
putenv('FR_PUBLISHED_URL=https://drop.example.test');
putenv('PERSISTENT_TOKENS_KEY=test_persistent_tokens_key_32bytes!');
$_SERVER['HTTP_HOST'] = 'admin.example.test';

require_once $baseDir . '/config/config.php';
require_once $baseDir . '/src/FileRise/Domain/FolderModel.php';
require_once $baseDir . '/src/FileRise/Http/Controllers/FolderController.php';

$errors = [];

try {
    $shareListEntrypoint = (string)file_get_contents($baseDir . '/public/api/folder/getShareFolderLinks.php');
    phaiseDropFailIf(
        strpos($shareListEntrypoint, 'getAllShareFolderLinks()') === false,
        'shared-folder admin list endpoint is not wired to its controller method',
        $errors
    );

    $rulesMethod = new ReflectionMethod(\FileRise\Http\Controllers\FolderController::class, 'validateSharedUploadRules');
    $rulesMethod->setAccessible(true);
    phaiseDropFailIf(
        $rulesMethod->invoke(null, ['mode' => 'drop', 'allowedTypes' => []], 'camera.raw', 1024) !== null,
        'managed drops should accept arbitrary non-dangerous file types when no allow-list is configured',
        $errors
    );
    phaiseDropFailIf(
        $rulesMethod->invoke(null, ['mode' => 'browse', 'allowedTypes' => []], 'archive.zip', 1024) === null,
        'legacy browse shares should keep the default file-type allow-list',
        $errors
    );
    phaiseDropFailIf(
        $rulesMethod->invoke(null, ['mode' => 'drop', 'allowedTypes' => ['pdf']], 'archive.zip', 1024) === null,
        'an explicit managed-drop allow-list should still be enforced',
        $errors
    );

    $share = \FileRise\Domain\FolderModel::createShareFolderLink(
        'Drop test',
        604800,
        '',
        1,
        1,
        [
            'mode' => 'drop',
            'shortCode' => 0,
            'maxFileSizeMb' => 1,
            'maxTotalMb' => 1,
            'closeMode' => 'single',
            'idleTimeoutSeconds' => 172800,
            'title' => 'Client delivery',
            'instructions' => 'Send the originals.',
            'createdBy' => 'admin',
        ]
    );

    phaiseDropFailIf(isset($share['error']), 'drop creation failed: ' . ($share['error'] ?? ''), $errors);
    $token = (string)($share['token'] ?? '');
    phaiseDropFailIf(!preg_match('/^[a-f0-9]{64}$/', $token), 'drop token is not 256-bit lowercase hex', $errors);
    phaiseDropFailIf(
        (string)($share['link'] ?? '') !== 'https://drop.example.test/d/' . $token,
        'legacy drop did not keep its 256-bit capability URL',
        $errors
    );

    $shortShare = \FileRise\Domain\FolderModel::createShareFolderLink(
        'Drop test',
        604800,
        '',
        1,
        1,
        [
            'mode' => 'drop',
            'shortCode' => 1,
            'closeMode' => 'window',
        ]
    );
    phaiseDropFailIf(isset($shortShare['error']), 'short drop creation failed: ' . ($shortShare['error'] ?? ''), $errors);
    $shortToken = (string)($shortShare['token'] ?? '');
    $shortCode = (string)($shortShare['shortCode'] ?? '');
    phaiseDropFailIf(!preg_match('/^[a-f0-9]{64}$/', $shortToken), 'short drop lost its internal 256-bit token', $errors);
    phaiseDropFailIf(!preg_match('/^[a-z]{4}$/', $shortCode), 'short drop did not receive a four-letter alias', $errors);
    phaiseDropFailIf(
        (string)($shortShare['link'] ?? '') !== 'https://drop.example.test/' . $shortCode,
        'short drop did not use the root-level four-letter URL',
        $errors
    );
    phaiseDropFailIf(
        \FileRise\Domain\FolderModel::resolveShareFolderReference($shortCode) !== $shortToken,
        'four-letter alias did not resolve to its internal token',
        $errors
    );
    phaiseDropFailIf(
        isset(\FileRise\Domain\FolderModel::getSharedUploadContext($shortToken, null)['error']),
        'direct short drop unexpectedly required a password',
        $errors
    );
    phaiseDropFailIf(
        !empty(\FileRise\Domain\FolderModel::getShareFolderRecord($shortToken)['accessCodeRequired']),
        'direct short drop was marked as access-code protected',
        $errors
    );

    $secondShortShare = \FileRise\Domain\FolderModel::createShareFolderLink(
        'Drop test',
        604800,
        '',
        1,
        1,
        ['mode' => 'drop', 'closeMode' => 'window']
    );
    phaiseDropFailIf(
        (string)($secondShortShare['shortCode'] ?? '') === $shortCode,
        'active four-letter aliases must be unique',
        $errors
    );

    $registryFile = $metaDir . 'used_drop_codes.json';
    $registry = json_decode((string)file_get_contents($registryFile), true) ?: [];
    $usedCodes = is_array($registry['codes'] ?? null) ? $registry['codes'] : [];
    phaiseDropFailIf(
        !isset($usedCodes[$shortCode]) || !isset($usedCodes[(string)$secondShortShare['shortCode']]),
        'allocated aliases were not permanently recorded',
        $errors
    );

    phaiseDropFailIf(
        !\FileRise\Domain\FolderModel::deleteShareFolderLink($shortToken),
        'could not revoke the first short drop',
        $errors
    );
    phaiseDropFailIf(
        \FileRise\Domain\FolderModel::resolveShareFolderReference($shortCode) !== null,
        'revoked short alias still resolved',
        $errors
    );
    $registryAfterRevoke = json_decode((string)file_get_contents($registryFile), true) ?: [];
    phaiseDropFailIf(
        !isset($registryAfterRevoke['codes'][$shortCode]),
        'revoking a drop removed its permanent alias tombstone',
        $errors
    );

    $thirdShortShare = \FileRise\Domain\FolderModel::createShareFolderLink(
        'Drop test',
        604800,
        'IGNORED-FOR-DIRECT-DROP',
        1,
        1,
        ['mode' => 'drop', 'closeMode' => 'window']
    );
    phaiseDropFailIf(
        (string)($thirdShortShare['shortCode'] ?? '') === $shortCode,
        'a default drop reused a revoked alias',
        $errors
    );
    phaiseDropFailIf(
        !preg_match('/^[a-z]{4}$/', (string)($thirdShortShare['shortCode'] ?? '')),
        'new drops should use four-letter URLs by default',
        $errors
    );
    phaiseDropFailIf(
        isset(\FileRise\Domain\FolderModel::getSharedUploadContext((string)$thirdShortShare['token'], null)['error']),
        'new short drops should ignore legacy password input unless explicitly marked for compatibility',
        $errors
    );

    $legacyProtected = \FileRise\Domain\FolderModel::createShareFolderLink(
        'Drop test',
        604800,
        'ABCDEFGH',
        1,
        1,
        ['mode' => 'drop', 'shortCode' => 1, 'accessCodeRequired' => 1, 'closeMode' => 'window']
    );
    $legacyProtectedToken = (string)($legacyProtected['token'] ?? '');
    phaiseDropFailIf(
        empty(\FileRise\Domain\FolderModel::getSharedUploadContext($legacyProtectedToken, null)['needs_password']),
        'legacy access-code drop compatibility was lost',
        $errors
    );
    phaiseDropFailIf(
        isset(\FileRise\Domain\FolderModel::getSharedUploadContext($legacyProtectedToken, 'ABCDEFGH')['error']),
        'legacy access-code drop rejected its password',
        $errors
    );

    $missLimit = new ReflectionMethod(\FileRise\Http\Controllers\FolderController::class, 'applyInvalidDropLookupLimit');
    $missLimit->setAccessible(true);
    for ($attempt = 1; $attempt <= 8; $attempt++) {
        phaiseDropFailIf(
            $missLimit->invoke(null, '203.0.113.44') !== null,
            'unknown-link limiter rejected a miss before the per-IP threshold',
            $errors
        );
    }
    $limited = $missLimit->invoke(null, '203.0.113.44');
    phaiseDropFailIf(
        !is_array($limited) || (int)($limited['status'] ?? 0) !== 429,
        'unknown-link limiter did not block the ninth miss in its window',
        $errors
    );
    phaiseDropFailIf(
        \FileRise\Domain\FolderModel::resolveShareFolderReference((string)$secondShortShare['shortCode'])
            !== (string)$secondShortShare['token'],
        'valid aliases should remain resolvable after misses from the same IP',
        $errors
    );

    $first = \FileRise\Domain\FolderModel::reserveSharedDropUpload($token, 'upload_one', 700000);
    phaiseDropFailIf(empty($first['success']), 'first reservation should succeed', $errors);

    $racing = \FileRise\Domain\FolderModel::reserveSharedDropUpload($token, 'upload_two', 700000);
    phaiseDropFailIf(empty($racing['error']), 'concurrent reservations should not bypass total quota', $errors);

    $complete = \FileRise\Domain\FolderModel::completeSharedDropUpload($token, 'upload_one', 700000);
    phaiseDropFailIf((int)($complete['acceptedBytes'] ?? 0) !== 700000, 'completed bytes were not committed', $errors);
    phaiseDropFailIf((int)($complete['uploadedFiles'] ?? 0) !== 1, 'completed file count was not committed', $errors);

    $duplicate = \FileRise\Domain\FolderModel::completeSharedDropUpload($token, 'upload_one', 700000);
    phaiseDropFailIf(empty($duplicate['alreadyCompleted']), 'duplicate completion should be idempotent', $errors);

    $remaining = \FileRise\Domain\FolderModel::reserveSharedDropUpload($token, 'upload_three', 400000);
    phaiseDropFailIf(empty($remaining['error']), 'committed bytes should count against total quota', $errors);

    $finish = \FileRise\Domain\FolderModel::finishSharedDrop($token);
    phaiseDropFailIf(empty($finish['closed']), 'single-submission drop should close on Finish', $errors);
    $closedContext = \FileRise\Domain\FolderModel::getSharedUploadContext($token, null);
    phaiseDropFailIf(empty($closedContext['closed']), 'closed drop should reject future upload contexts', $errors);

    $window = \FileRise\Domain\FolderModel::createShareFolderLink(
        'Drop test',
        604800,
        '',
        1,
        1,
        ['mode' => 'drop', 'closeMode' => 'window', 'idleTimeoutSeconds' => 172800]
    );
    $windowToken = (string)($window['token'] ?? '');
    $windowFinish = \FileRise\Domain\FolderModel::finishSharedDrop($windowToken);
    phaiseDropFailIf(!empty($windowFinish['closed']), 'time-window drop should stay open when Finish is called', $errors);
    phaiseDropFailIf(isset(\FileRise\Domain\FolderModel::getSharedUploadContext($windowToken, null)['error']), 'time-window drop unexpectedly closed', $errors);

    $idle = \FileRise\Domain\FolderModel::createShareFolderLink(
        'Drop test',
        604800,
        '',
        1,
        1,
        ['mode' => 'drop', 'closeMode' => 'single', 'idleTimeoutSeconds' => 3600]
    );
    $idleToken = (string)($idle['token'] ?? '');
    $shareFile = $metaDir . 'share_folder_links.json';
    $records = json_decode((string)file_get_contents($shareFile), true) ?: [];
    $records[$idleToken]['lastActivityAt'] = time() - 7200;
    file_put_contents($shareFile, json_encode($records, JSON_PRETTY_PRINT), LOCK_EX);
    $idleContext = \FileRise\Domain\FolderModel::getSharedUploadContext($idleToken, null);
    phaiseDropFailIf(empty($idleContext['closed']), 'idle timeout should close an abandoned drop', $errors);
} finally {
    phaiseDropRmTree($tmpBase);
}

if ($errors) {
    fwrite(STDERR, "Phaise Drop lifecycle regression failures:\n- " . implode("\n- ", $errors) . "\n");
    exit(1);
}

echo "Phaise Drop lifecycle regressions passed\n";
