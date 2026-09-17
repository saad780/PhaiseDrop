<?php

declare(strict_types=1);

/**
 * @OA\Post(
 *   path="/api/agent/v1/createShare.php",
 *   summary="Create an assistant file share",
 *   operationId="agentCreateShare",
 *   tags={"Agent Shares"},
 *   security={{"agentBearerAuth": {}}},
 *   @OA\RequestBody(required=true, @OA\JsonContent(
 *     type="object",
 *     required={"title","items"},
 *     @OA\Property(property="title", type="string", maxLength=120),
 *     @OA\Property(property="note", type="string", maxLength=1000),
 *     @OA\Property(property="pin", type="string", pattern="^\\d{4,12}$"),
 *     @OA\Property(property="expiresInSeconds", type="integer", minimum=3600, maximum=2592000, default=172800),
 *     @OA\Property(property="items", type="array", @OA\Items(
 *       type="object",
 *       required={"path","type"},
 *       @OA\Property(property="path", type="string", example="Assistant Shares/report.pdf"),
 *       @OA\Property(property="type", type="string", enum={"file","folder"})
 *     ))
 *   )),
 *   @OA\Response(response=201, description="Share created without exposing its internal token"),
 *   @OA\Response(response=400, description="Invalid or unsafe selection"),
 *   @OA\Response(response=401, description="Invalid bearer token"),
 *   @OA\Response(response=503, description="Agent API is not configured")
 * )
 */
require_once __DIR__ . '/../../../../config/config.php';
(new \FileRise\Http\Controllers\AgentShareController())->create();
