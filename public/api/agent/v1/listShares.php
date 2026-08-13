<?php

declare(strict_types=1);

/**
 * @OA\Get(
 *   path="/api/agent/v1/listShares.php",
 *   summary="List active assistant-compatible shares",
 *   operationId="agentListShares",
 *   tags={"Agent Shares"},
 *   security={{"agentBearerAuth": {}}},
 *   @OA\Response(response=200, description="Active outbound shares without internal tokens or paths"),
 *   @OA\Response(response=401, description="Invalid bearer token"),
 *   @OA\Response(response=503, description="Agent API is not configured")
 * )
 */
require_once __DIR__ . '/../../../../config/config.php';
(new \FileRise\Http\Controllers\AgentShareController())->list();
