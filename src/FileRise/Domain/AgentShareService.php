<?php

declare(strict_types=1);

namespace FileRise\Domain;

/**
 * Token-free presentation layer for the private assistant sharing API.
 */
final class AgentShareService
{
    private const CODE_PATTERN = '/^[a-z]{4}$/';

    /** @return array<string,mixed> */
    public static function create(array $input): array
    {
        $result = LinkModel::createShare($input, 'assistant-api');
        if (isset($result['error'])) {
            return ['error' => (string)$result['error']];
        }

        $found = LinkModel::findOutboundByCode((string)($result['code'] ?? ''));
        $record = is_array($found['record'] ?? null) ? $found['record'] : [];

        return [
            'success' => true,
            'code' => (string)($result['code'] ?? ''),
            'url' => (string)($result['url'] ?? ''),
            'title' => (string)($record['title'] ?? self::cleanText($input['title'] ?? '', 120)),
            'createdAt' => max(0, (int)($result['createdAt'] ?? 0)),
            'expiresAt' => max(0, (int)($result['expiresAt'] ?? 0)),
            'itemCount' => count(is_array($record['items'] ?? null) ? $record['items'] : []),
            'pinProtected' => !empty($record['password']),
        ];
    }

    /** @return array{shares:array<int,array<string,mixed>>,generatedAt:int} */
    public static function list(): array
    {
        $listing = LinkModel::listActive();
        $shares = [];
        foreach ((array)($listing['links'] ?? []) as $link) {
            if (!is_array($link) || ($link['type'] ?? '') !== 'share') {
                continue;
            }
            $shares[] = [
                'code' => (string)($link['code'] ?? ''),
                'url' => (string)($link['url'] ?? ''),
                'title' => (string)($link['title'] ?? 'Shared files'),
                'note' => (string)($link['note'] ?? ''),
                'createdAt' => max(0, (int)($link['createdAt'] ?? 0)),
                'expiresAt' => max(0, (int)($link['expiresAt'] ?? 0)),
                'itemCount' => max(0, (int)($link['itemCount'] ?? 0)),
                'pinProtected' => !empty($link['pinProtected']),
            ];
        }

        return [
            'shares' => $shares,
            'generatedAt' => max(0, (int)($listing['generatedAt'] ?? time())),
        ];
    }

    /** @return array<string,mixed> */
    public static function revoke(string $codeOrUrl): array
    {
        $code = self::extractCode($codeOrUrl);
        if ($code === null) {
            return ['error' => 'Enter a valid four-letter share code or URL.'];
        }

        $found = LinkModel::findOutboundByCode($code);
        if ($found === null) {
            return ['error' => 'Active share not found.'];
        }
        $result = LinkModel::revoke('share', (string)$found['token']);
        if (isset($result['error'])) {
            return ['error' => (string)$result['error']];
        }

        return ['success' => true, 'revoked' => true, 'code' => $code];
    }

    public static function extractCode(string $codeOrUrl): ?string
    {
        $candidate = strtolower(trim($codeOrUrl));
        if (preg_match(self::CODE_PATTERN, $candidate)) {
            return $candidate;
        }

        $path = parse_url($candidate, PHP_URL_PATH);
        if (!is_string($path)) {
            return null;
        }
        $parts = array_values(array_filter(explode('/', trim($path, '/')), static fn(string $part): bool => $part !== ''));
        if (count($parts) !== 1 || !preg_match(self::CODE_PATTERN, $parts[0])) {
            return null;
        }
        return $parts[0];
    }

    private static function cleanText($value, int $max): string
    {
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/u', '', trim((string)$value));
        return mb_substr(is_string($value) ? $value : '', 0, $max);
    }
}
