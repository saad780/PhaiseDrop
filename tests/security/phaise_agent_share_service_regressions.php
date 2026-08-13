<?php

declare(strict_types=1);

$baseDir = dirname(__DIR__, 2);
$tmpBase = $baseDir . '/tests/.tmp_phaise_agent_' . bin2hex(random_bytes(4));
$uploadDir = $tmpBase . '/uploads/';
$usersDir = $tmpBase . '/users/';
$metaDir = $tmpBase . '/metadata/';

function phaiseAgentRemoveTree(string $path): void
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
            phaiseAgentRemoveTree($path . DIRECTORY_SEPARATOR . $item);
        }
    }
    @rmdir($path);
}

@mkdir($uploadDir . 'Assistant Shares/Delivery', 0775, true);
@mkdir($usersDir, 0700, true);
@mkdir($metaDir, 0775, true);
file_put_contents($uploadDir . 'Assistant Shares/Delivery/report.txt', 'report');

putenv('FR_TEST_UPLOAD_DIR=' . $uploadDir);
putenv('FR_TEST_USERS_DIR=' . $usersDir);
putenv('FR_TEST_META_DIR=' . $metaDir);
putenv('FR_PUBLISHED_URL=https://drop.example.test');
putenv('PERSISTENT_TOKENS_KEY=test_persistent_tokens_key_32bytes!');

require_once $baseDir . '/config/config.php';

$errors = [];
$failIf = static function (bool $condition, string $message) use (&$errors): void {
    if ($condition) {
        $errors[] = $message;
    }
};

try {
    $created = \FileRise\Domain\AgentShareService::create([
        'title' => 'Delivery',
        'note' => 'Assistant-created snapshot.',
        'expiresInSeconds' => 172800,
        'items' => [
            ['path' => 'Assistant Shares/Delivery', 'type' => 'folder'],
            ['path' => 'Assistant Shares/Delivery/report.txt', 'type' => 'file'],
        ],
    ]);
    $failIf(isset($created['error']), 'agent share creation failed: ' . ($created['error'] ?? ''));
    $code = (string)($created['code'] ?? '');
    $failIf(!preg_match('/^[a-z]{4}$/', $code), 'agent response omitted the public code');
    $failIf(($created['url'] ?? '') !== 'https://drop.example.test/' . $code, 'agent response returned the wrong public URL');
    $failIf((int)($created['itemCount'] ?? 0) !== 1, 'agent response did not report the deduplicated item count');
    $failIf(isset($created['id']) || str_contains(json_encode($created) ?: '', 'Assistant Shares'), 'agent response exposed an internal token or path');

    $listed = \FileRise\Domain\AgentShareService::list();
    $failIf(count($listed['shares'] ?? []) !== 1, 'agent share listing omitted the active share');
    $encoded = json_encode($listed) ?: '';
    $failIf(str_contains($encoded, '"id"') || str_contains($encoded, 'Assistant Shares'), 'agent listing exposed an internal token or path');

    $failIf(\FileRise\Domain\AgentShareService::extractCode('https://drop.example.test/' . $code) !== $code, 'share URL did not resolve to a code');
    $failIf(\FileRise\Domain\AgentShareService::extractCode('https://drop.example.test/' . $code . '/extra') !== null, 'nested URL was accepted as a code');
    $failIf(\FileRise\Domain\AgentShareService::extractCode('../' . $code) !== null, 'path-like input was accepted as a code');

    $revoked = \FileRise\Domain\AgentShareService::revoke('https://drop.example.test/' . $code);
    $failIf(empty($revoked['success']), 'active agent share could not be revoked');
    $failIf((\FileRise\Domain\AgentShareService::list()['shares'] ?? []) !== [], 'revoked share remained in the active agent listing');
    $failIf(empty(\FileRise\Domain\AgentShareService::revoke($code)['error']), 'closed share was revocable a second time');
} finally {
    phaiseAgentRemoveTree($tmpBase);
}

if ($errors !== []) {
    fwrite(STDERR, "Phaise agent share service regression failures:\n- " . implode("\n- ", $errors) . "\n");
    exit(1);
}

echo "Phaise agent share service regressions passed\n";
