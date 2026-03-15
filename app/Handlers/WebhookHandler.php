<?php

namespace App\Handlers;

use App\TelegramBot;
use function App\handleCommand;

class WebhookHandler
{
    public function handle(): void
    {
        $bot    = new TelegramBot();
        $update = json_decode(file_get_contents('php://input'), true);

        if (!$update || !isset($update['message'])) {
            return;
        }

        $message    = $update['message'];
        $userChatId = $message['from']['id'];
        $firstName  = $message['from']['first_name'] ?? 'N/A';
        $lastName   = $message['from']['last_name']  ?? 'N/A';
        $fullName   = $lastName === 'N/A' ? $firstName : "$firstName $lastName";

        // ── Owner-only guard ──────────────────────────────────────────
        $ownerId = (int) ($_ENV['OWNER_ID'] ?? 0);
        if ($ownerId !== 0 && (int) $userChatId !== $ownerId) {
            $botChar = require __DIR__ . '/../../config/bot_character.php';
            $unauthorizedMsg = $botChar['phrases']['unauthorized'] ?? null;
            if ($unauthorizedMsg !== null) {
                $bot->sendRequest('sendMessage', [
                    'chat_id' => $userChatId,
                    'text'    => $unauthorizedMsg,
                ]);
            }
            return;
        }
        // ─────────────────────────────────────────────────────────────

        // ── Detect text vs photo ──────────────────────────────────────
        $userMessage = '';
        $imageBase64 = null;
        $mimeType    = 'image/jpeg';

        if (isset($message['photo'])) {
            // Telegram sends multiple resolutions; pick the largest (last element)
            $photo  = end($message['photo']);
            $fileId = $photo['file_id'];

            // Caption is the "text" the user typed alongside the photo
            $userMessage = $message['caption'] ?? '';
            $imageBase64 = $this->downloadPhotoAsBase64($bot, $fileId);
        } else {
            $userMessage = $message['text'] ?? 'nothing';
        }
        // ─────────────────────────────────────────────────────────────

        $replyMsg = handleCommand($userMessage, $fullName, $imageBase64, $mimeType);

        $bot->sendRequest('sendMessage', [
            'chat_id'    => $userChatId,
            'text'       => $replyMsg,
            'parse_mode' => 'html',
        ]);
    }

    /**
     * Download a Telegram photo and return it as a base64 string, or null on failure.
     */
    private function downloadPhotoAsBase64(TelegramBot $bot, string $fileId): ?string
    {
        // Step 1: get the file path from Telegram
        $fileInfo = $bot->sendRequest('getFile', ['file_id' => $fileId]);

        if (empty($fileInfo['result']['file_path'])) {
            file_put_contents(
                __DIR__ . '/../log/debug.log',
                "getFile failed for file_id: $fileId\n",
                FILE_APPEND
            );
            return null;
        }

        $filePath = $fileInfo['result']['file_path'];
        $token    = $_ENV['BOT_TOKEN'];
        $url      = "https://api.telegram.org/file/bot{$token}/{$filePath}";

        // Step 2: download the raw image bytes
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        $imageBytes = curl_exec($ch);
        $httpCode   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || $imageBytes === false) {
            file_put_contents(
                __DIR__ . '/../log/debug.log',
                "Photo download failed HTTP $httpCode for $url\n",
                FILE_APPEND
            );
            return null;
        }

        return base64_encode($imageBytes);
    }
}
