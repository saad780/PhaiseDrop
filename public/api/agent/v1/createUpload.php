<?php
declare(strict_types=1);
require_once __DIR__ . '/../../../../config/config.php';
(new \FileRise\Http\Controllers\AgentShareController())->createUpload();
