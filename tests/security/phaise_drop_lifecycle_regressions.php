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
        'drop did not use the short public capability URL',
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
