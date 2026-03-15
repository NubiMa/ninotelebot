<?php

namespace App;

class TelegramBot
{
    private string $botToken;
    private string $apiUrl;

    public function __construct()
    {
        $this->botToken = getenv('BOT_TOKEN') ?: $_ENV['BOT_TOKEN'];
        $this->apiUrl = "https://api.telegram.org/bot{$this->botToken}/";
    }

    public function sendRequest(string $method, array $data = []): array
    {
        $url = $this->apiUrl . $method;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false) {
            $response = '';
        }

        // Log response for debugging
        $this->logResponse($method, $httpCode, $response);

        return json_decode($response, true) ?: [];
    }

    private function logResponse(string $method, int $httpCode, string $response): void
    {
        $logMessage = "Method: $method\nHTTP Code: $httpCode\nResponse: $response\n";
        file_put_contents(__DIR__ . '/log/debug.log', $logMessage, FILE_APPEND);
    }
}
