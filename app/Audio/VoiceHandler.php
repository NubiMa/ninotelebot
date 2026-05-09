<?php

namespace App\Audio;

use App\Utils\Logger;

class VoiceHandler
{
    /**
     * Transcribe an OGG audio byte string to text using Groq's Whisper API.
     * Returns the transcribed text, or a fallback message on failure.
     */
    public static function transcribe(string $oggBytes): string
    {
        $provider = $_ENV['STT_PROVIDER'] ?? 'groq';
        $apiKey   = $_ENV['GROQ_API_KEY'] ?? '';

        if ($provider !== 'groq' || empty($apiKey)) {
            Logger::error('VoiceHandler', 'STT misconfigured or missing API key');
            return "*[Voice note received, but I cannot listen to it right now]*";
        }

        // Write bytes to a temporary file because CURLFile needs a physical file
        $tmpFile = sys_get_temp_dir() . '/nino_voice_' . uniqid() . '.ogg';
        file_put_contents($tmpFile, $oggBytes);

        $url = 'https://api.groq.com/openai/v1/audio/transcriptions';

        $cfile = new \CURLFile($tmpFile, 'audio/ogg', 'voice.ogg');
        $postData = [
            'file'     => $cfile,
            'model'    => 'whisper-large-v3-turbo',
            'language' => 'id',  // Force Indonesian for better accuracy
            'response_format' => 'json'
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer {$apiKey}"
        ]);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $startTime = microtime(true);
        $response  = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // Cleanup temp file
        @unlink($tmpFile);

        $latencyMs = (microtime(true) - $startTime) * 1000;
        Logger::api('Groq-STT', $httpCode, [], $latencyMs);

        if ($httpCode !== 200 || $response === false) {
            Logger::error('VoiceHandler', "Transcription failed: HTTP $httpCode", [
                'response' => mb_substr(str_replace("\n", ' ', $response), 0, 500)
            ]);
            return "*[Voice note received, but I couldn't understand the audio]*";
        }

        $data = json_decode($response, true);
        $text = $data['text'] ?? '';

        if (empty(trim($text))) {
            return "*[Voice note received, but it was silent or unclear]*";
        }

        Logger::debug('VoiceHandler', 'Transcription successful', ['text_len' => strlen($text)]);
        return trim($text);
    }
}
