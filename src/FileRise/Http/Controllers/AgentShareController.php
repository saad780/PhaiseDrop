<?php

declare(strict_types=1);

namespace FileRise\Http\Controllers;

use FileRise\Domain\AgentShareService;

final class AgentShareController
{
    public function create(): void
    {
        self::requireMethod('POST');
        self::requireBearer();
        $result = AgentShareService::create(self::input());
        self::json($result, isset($result['error']) ? 400 : 201);
    }

    public function list(): void
    {
        self::requireMethod('GET');
        self::requireBearer();
        self::json(AgentShareService::list());
    }

    public function revoke(): void
    {
        self::requireMethod('POST');
        self::requireBearer();
        $input = self::input();
        $value = $input['code'] ?? ($input['url'] ?? '');
        if (is_array($value)) {
            $value = '';
        }
        $result = AgentShareService::revoke((string)$value);
        self::json($result, isset($result['error']) ? 404 : 200);
    }

    private static function requireBearer(): void
    {
        $expected = trim((string)getenv('PHAISE_DROP_AGENT_API_KEY'));
        if ($expected === '') {
            self::json(['error' => 'Agent API is not configured.'], 503);
            exit;
        }

        $header = trim((string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''));
        if ($header === '' && function_exists('getallheaders')) {
            foreach ((array)getallheaders() as $name => $value) {
                if (strcasecmp((string)$name, 'Authorization') === 0) {
                    $header = trim((string)$value);
                    break;
                }
            }
        }
        if (!preg_match('/^Bearer\s+(\S+)$/i', $header, $match) || !hash_equals($expected, (string)$match[1])) {
            header('WWW-Authenticate: Bearer');
            self::json(['error' => 'Unauthorized.'], 401);
            exit;
        }
    }

    private static function requireMethod(string $method): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== $method) {
            header('Allow: ' . $method);
            self::json(['error' => 'Method not allowed.'], 405);
            exit;
        }
    }

    /** @return array<string,mixed> */
    private static function input(): array
    {
        $raw = (string)file_get_contents('php://input');
        $decoded = json_decode($raw, true);
        if ($raw !== '' && !is_array($decoded)) {
            self::json(['error' => 'Invalid JSON payload.'], 400);
            exit;
        }
        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string,mixed> $payload */
    private static function json(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    }
}
