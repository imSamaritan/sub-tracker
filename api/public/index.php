<?php
declare(strict_types=1);

use SubTracker\App;

require_once __DIR__ . '/../vendor/autoload.php';

$app = App::create();
$app->run();
