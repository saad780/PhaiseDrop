<?php

declare(strict_types=1);

/**
 * @OA\Post(
 *   path="/api/agent/v1/revokeShare.php",
 *   summary="Revoke an active assistant-compatible share",
 *   operationId="agentRevokeShare",
 *   tags={"Agent Shares"},
 *   security={{"agentBearerAuth": {}}},
 *   @OA\RequestBody(required=true, @OA\JsonContent(
 *     type="object",
 *     @OA\Property(property="code", type="string", pattern="^[a-z]{4}$"),
 *     @OA\Property(property="url", type="string", format="uri")
 *   )),
 *   @OA\Response(response=200, description="Share revoked"),
 *   @OA\Response(response=401, description="Invalid bearer token"),
 *   @OA\Response(response=404, description="Active share not found"),
 *   @OA\Response(response=503, description="Agent API is not configured")
 * )
 */
require_once __DIR__ . '/../../../../config/config.php';
(new \FileRise\Http\Controllers\AgentShareController())->revoke();
