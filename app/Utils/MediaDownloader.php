<?php

namespace App\Utils;

use App\TelegramBot;

/**
 * Centralized media downloader — eliminates the copy-paste in both handlers.
 *
 * Usage:
 *   $base64 = MediaDownloader::photoToBase64($bot, $fileId);   // image
 *   $bytes  = MediaDownloader::voiceToBytes($bot, $fileId);    // voice (Phase 3)
 */
class MediaDownloader
{
    /**
     * Download a Telegram photo by file_id and return it base64-encoded.
     * Returns null on any failure.
     */
    public static function photoToBase64(TelegramBot $bot, string $fileId): ?string
    {
        $bytes = self::download($bot, $fileId, 'photo');
        return $bytes !== null ? base64_encode($bytes) : null;
    }

    /**
     * Download a Telegram voice/audio file and return the raw bytes.
     * Returns null on any failure.
     * Used by VoiceHandler (Phase 3).
     */
    public static function voiceToBytes(TelegramBot $bot, string $fileId): ?string
    {
        return self::download($bot, $fileId, 'voice');
    }

    // ── Internal ───────────────────────────────────────────────────────────

    private static function download(TelegramBot $bot, string $fileId, string $type): ?string
    {
        // Step 1 — resolve file_id to a CDN path
        $fileInfo = $bot->sendRequest('getFile', ['file_id' => $fileId]);

        if (empty($fileInfo['result']['file_path'])) {
            Logger::error('MediaDownloader', "getFile failed for $type", ['file_id' => $fileId]);
            return null;
        }

        $filePath = $fileInfo['result']['file_path'];
        $token    = $_ENV['BOT_TOKEN'];
        $url      = "https://api.telegram.org/file/bot{$token}/{$filePath}";

        // Step 2 — download raw bytes
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $bytes    = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || $bytes === false || $bytes === '') {
            Logger::error('MediaDownloader', "Download failed HTTP $httpCode for $type", ['url' => $url]);
            return null;
        }

        Logger::debug('MediaDownloader', "Downloaded $type OK", ['file_id' => $fileId, 'bytes' => strlen($bytes)]);
        return $bytes;
    }
}
