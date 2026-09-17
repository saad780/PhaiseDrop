<?php

$baseDir = dirname(__DIR__, 2);
$tmpBase = $baseDir . '/tests/.tmp_upload_mtime_' . bin2hex(random_bytes(4));
$uploadDir = $tmpBase . '/uploads/';
$metaDir = $tmpBase . '/metadata/';
$usersDir = $tmpBase . '/users/';
$errors = [];

function uploadMtimeRmTree(string $dir): void
{
    if (!is_dir($dir) || is_link($dir)) {
        return;
    }
    foreach (scandir($dir) ?: [] as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path) && !is_link($path)) {
            uploadMtimeRmTree($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

try {
    @mkdir($uploadDir, 0775, true);
    @mkdir($metaDir, 0775, true);
    @mkdir($usersDir, 0775, true);

    putenv('FR_TEST_UPLOAD_DIR=' . $uploadDir);
    putenv('FR_TEST_META_DIR=' . $metaDir);
    putenv('FR_TEST_USERS_DIR=' . $usersDir);
    require_once $baseDir . '/config/config.php';
    require_once $baseDir . '/src/FileRise/Domain/UploadModel.php';

    $apply = new ReflectionMethod(\FileRise\Domain\UploadModel::class, 'applyClientModifiedTime');
    $target = $uploadDir . 'preserved.txt';
    file_put_contents($target, 'timestamp');

    $expected = 946684800;
    $apply->invoke(null, $target, ['clientModifiedAtMs' => (string)($expected * 1000)]);
    clearstatcache(true, $target);
    if (filemtime($target) !== $expected) {
        $errors[] = 'A valid browser modification timestamp was not preserved.';
    }

    $unchanged = 1609459200;
    touch($target, $unchanged);
    $apply->invoke(null, $target, ['clientModifiedAtMs' => (string)((time() + 172800) * 1000)]);
    clearstatcache(true, $target);
    if (filemtime($target) !== $unchanged) {
        $errors[] = 'A future client timestamp should be ignored.';
    }

    $apply->invoke(null, $target, ['clientModifiedAtMs' => 'not-a-timestamp']);
    clearstatcache(true, $target);
    if (filemtime($target) !== $unchanged) {
        $errors[] = 'A malformed client timestamp should be ignored.';
    }

    $outside = $tmpBase . '/outside.txt';
    file_put_contents($outside, 'outside');
    touch($outside, $unchanged);
    $link = $uploadDir . 'linked.txt';
    if (@symlink($outside, $link)) {
        $apply->invoke(null, $link, ['clientModifiedAtMs' => (string)($expected * 1000)]);
        clearstatcache(true, $outside);
        if (filemtime($outside) !== $unchanged) {
            $errors[] = 'Timestamp preservation followed a symlink outside the upload root.';
        }
    }
} finally {
    putenv('FR_TEST_UPLOAD_DIR');
    putenv('FR_TEST_META_DIR');
    putenv('FR_TEST_USERS_DIR');
    uploadMtimeRmTree($tmpBase);
}

if ($errors !== []) {
    fwrite(STDERR, implode(PHP_EOL, $errors) . PHP_EOL);
    exit(1);
}

echo "upload modified-time regressions passed\n";
