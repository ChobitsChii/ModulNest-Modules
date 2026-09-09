#!/usr/bin/env php
<?php

declare(strict_types=1);

use ModulNest\Tools\ToolsSpeechService;
use Modulon\Core\Database;
use Modulon\Core\Env;

$options = getopt('', ['job-id:', 'base-path:']);
$basePath = (string) ($options['base-path'] ?? dirname(__DIR__));
$jobId = (string) ($options['job-id'] ?? '');
$moduleRoot = dirname(__DIR__);

require $basePath . '/vendor/autoload.php';
require $moduleRoot . '/src/ToolsSpeechService.php';
Env::load($basePath . '/.env');

if ($jobId === '') {
    fwrite(STDERR, "Missing --job-id.\n");
    exit(1);
}

$service = new ToolsSpeechService($basePath, $moduleRoot, Database::connect(require $basePath . '/app/Config/database.php'));
exit($service->processJob($jobId));
