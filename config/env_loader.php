<?php

use Dotenv\Dotenv;

function loadEnvironmentVariables(): void
{
    // Check if running in GitHub Actions
    if (!getenv('GITHUB_ACTIONS')) {
        if (file_exists(__DIR__ . '/../.env')) {
            $dotenv = Dotenv::createImmutable(__DIR__ . '/../');
            $dotenv->load();

            // Validate required environment variables
            $required = ['BOT_TOKEN'];
            foreach ($required as $var) {
                if (!isset($_ENV[$var])) {
                    throw new Exception("Missing required environment variable: $var");
                }
            }
            // $dotenv = Dotenv::createImmutable(__DIR__ . '/../');
            // $dotenv->load();
        }
    }
}
