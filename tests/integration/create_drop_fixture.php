<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/config.php';

$folder = 'Integration Drop ' . bin2hex(random_bytes(4));
$created = \FileRise\Domain\FolderModel::createFolder($folder, 'root', 'admin');
if (empty($created['success']) && (string)($created['error'] ?? '') !== 'Folder already exists') {
    fwrite(STDERR, (string)($created['error'] ?? 'Could not create fixture folder.') . PHP_EOL);
    exit(1);
}

$share = \FileRise\Domain\FolderModel::createShareFolderLink(
    $folder,
    3600,
    '',
    1,
    1,
    [
        'mode' => 'drop',
        'shortCode' => 1,
        'hideListing' => 1,
        'preserveFolderStructure' => 1,
        'maxFileSizeMb' => 10,
        'maxTotalMb' => 20,
        'closeMode' => 'single',
        'idleTimeoutSeconds' => 3600,
        'title' => 'Integration delivery',
        'instructions' => 'Upload the test payload.',
        'createdBy' => 'admin',
    ]
);
if (!empty($share['error'])) {
    fwrite(STDERR, (string)$share['error'] . PHP_EOL);
    exit(1);
}

$token = (string)$share['token'];
$secret = (string)($GLOBALS['encryptionKey'] ?? '');
echo json_encode([
    'token' => $token,
    'shortCode' => (string)$share['shortCode'],
    'link' => (string)$share['link'],
    'uploadToken' => hash_hmac('sha256', $token . '|', $secret),
    'folder' => $folder,
], JSON_UNESCAPED_SLASHES) . PHP_EOL;
