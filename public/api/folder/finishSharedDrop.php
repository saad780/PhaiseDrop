<?php

require_once __DIR__ . '/../../../config/config.php';

$folderController = new \FileRise\Http\Controllers\FolderController();
$folderController->finishSharedDrop();
