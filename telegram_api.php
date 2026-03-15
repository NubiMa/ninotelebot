<?php

// Send reply On Webhook Mode
function send_webhook($method, $data)
{
    /*DEBUG */
    echo ("method: " . $method . "\n");
    echo ("chat_id: " . $data['chat_id'] . "\n");
    echo ("reply message: " . $data['text']);
    /*DEBUG END*/

    $BOT_TOKEN = $_ENV['BOT_TOKEN'];
    $url = "https://api.telegram.org/bot$BOT_TOKEN/$method";

    $ch = curl_init();

    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $output = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE); // Capture HTTP status
    $ch = null;

    /*DEBUG */
    // Log response and HTTP code for debugging
    file_put_contents('./log/debug.log', "HTTP Code: $httpCode\nResponse: $output\n", FILE_APPEND);
    /*DEBUG END*/

    return $output;
}

// Send Reply on long polling Mode
function send_polling($method, $data = [])
{
    $BOT_TOKEN = $_ENV['BOT_TOKEN'];
    $url = "https://api.telegram.org/bot$BOT_TOKEN/$method";

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE); // Capture HTTP status
    $ch = null;

    // Log response for debugging
    file_put_contents('./log/debug.log', "Method: $method\nHTTP Code: $httpCode\nResponse: $response\n", FILE_APPEND);

    return json_decode($response, true);
}
