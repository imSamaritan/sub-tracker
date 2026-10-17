<?php
declare(strict_types=1);

/**
 * SubTracker Production Bootstrap (§15.1)
 *
 * Allows cPanel's `public_html/api/index.php` entry point to instantiate the
 * Slim 4 application when `sub-tracker-backend/` is stored above `public_html`.
 */

use SubTracker\App;

require_once __DIR__ . '/../vendor/autoload.php';

return App::create(dirname(__DIR__));
