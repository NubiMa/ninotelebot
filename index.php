<?php
require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/config/env_loader.php';

use App\Handlers\WebhookHandler;
use App\Handlers\PollingHandler;

// Load environment variables
loadEnvironmentVariables();

// Select bot mode
$mode = $_ENV['BOT_MODE'] ?? 'longpolling';

if ($mode === 'webhook') {
    (new WebhookHandler())->handle();
} elseif ($mode === 'longpolling') {
    (new PollingHandler())->handle();
} else {
    echo "Invalid bot mode. Please use 'webhook' or 'longpolling'.";
}
