<?php
declare(strict_types=1);

$baseDir = dirname(__DIR__, 2);
$tmpBase = $baseDir . '/tests/.tmp_clamav_closed_' . bin2hex(random_bytes(4));
$uploadDir = $tmpBase . '/uploads/';
$usersDir = $tmpBase . '/users/';
$metaDir = $tmpBase . '/metadata/';
$sessionDir = $tmpBase . '/sessions/';

function clamavClosedRmTree(string $dir): void
{
    if (!file_exists($dir) && !is_link($dir)) {
        return;
    }
    if (is_file($dir) || is_link($dir)) {
        @unlink($dir);
        return;
    }
    foreach (scandir($dir) ?: [] as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        clamavClosedRmTree($dir . DIRECTORY_SEPARATOR . $item);
    }
    @rmdir($dir);
}

@mkdir($uploadDir, 0775, true);
@mkdir($usersDir, 0700, true);
@mkdir($metaDir, 0775, true);
@mkdir($sessionDir, 0700, true);
session_save_path($sessionDir);

putenv('FR_TEST_UPLOAD_DIR=' . $uploadDir);
putenv('FR_TEST_USERS_DIR=' . $usersDir);
putenv('FR_TEST_META_DIR=' . $metaDir);
putenv('PERSISTENT_TOKENS_KEY=test_persistent_tokens_key_32bytes!');
putenv('VIRUS_SCAN_ENABLED=true');
putenv('VIRUS_SCAN_CMD=/bin/false');

require_once $baseDir . '/config/config.php';
require_once $baseDir . '/src/FileRise/Support/WorkerLauncher.php';
require_once $baseDir . '/src/FileRise/Domain/UploadModel.php';

$path = $uploadDir . 'scanner-error.txt';
file_put_contents($path, 'test payload', LOCK_EX);

$method = new ReflectionMethod(\FileRise\Domain\UploadModel::class, 'scanFileIfEnabled');
$method->setAccessible(true);
$result = $method->invoke(null, $path, ['folder' => 'root', 'file' => 'scanner-error.txt', 'source' => 'shared']);

$errors = [];
if (!is_array($result) || empty($result['error'])) {
    $errors[] = 'scanner execution errors must block the upload';
}
if (is_file($path)) {
    $errors[] = 'unverified file must be deleted after a scanner error';
}

clamavClosedRmTree($tmpBase);

if ($errors) {
    fwrite(STDERR, "ClamAV fail-closed regression failures:\n- " . implode("\n- ", $errors) . "\n");
    exit(1);
}

echo "ClamAV fail-closed regressions passed\n";
