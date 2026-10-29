<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

if (!defined('CHOMIK_EXAMPLES_DIR')) {
    define('CHOMIK_EXAMPLES_DIR', getenv('CHOMIK_EXAMPLES_DIR') ?: dirname(__DIR__, 2) . '/api/examples');
}
