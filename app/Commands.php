<?php

namespace App;

require __DIR__ . '/helper_function.php';

/**
 * Single shared GeminiClient instance — keeps chat history alive
 * across messages within the same long-polling process.
 */
function gemini(): GeminiClient
{
    static $client = null;
    if ($client === null) {
        $client = new GeminiClient();
    }
    return $client;
}

/**
 * @param string      $userMessage  Text from the user (empty string for image-only messages).
 * @param string      $fullName     User's display name.
 * @param string|null $imageBase64  Base64-encoded image bytes, or null if no image.
 * @param string|null $mimeType     MIME type of the image (default: image/jpeg).
 */
function handleCommand(
    string  $userMessage,
    string  $fullName,
    ?string $imageBase64 = null,
    ?string $mimeType    = 'image/jpeg'
): string {
    static $botChar = null;
    if ($botChar === null) {
        $botChar = require __DIR__ . '/../config/bot_character.php';
    }

    $botName = $botChar['name'];
    $phrases = $botChar['phrases'];

    // ── Slash commands (only triggered for text-only messages starting with /) ──
    if ($imageBase64 === null && str_starts_with($userMessage, '/')) {

        // /code #number
        if (preg_match('/^\/code\s+#(\d+)/i', $userMessage, $matches)) {
            return parseInput($matches[1]);
        }

        // /strlowercase #text
        if (preg_match('/^\/strlowercase\s+#(.+)/i', $userMessage, $matches)) {
            return strlowercase($matches[1]);
        }

        switch ($userMessage) {
            case '/start':
                gemini()->resetHistory();
                $msg  = sprintf($phrases['greeting'], $fullName, $botName);
                $msg .= "\n\n<b>Commands:</b>"
                     . "\n /code #code — Test bot input recognition"
                     . "\n /strlowercase #text — Convert text to lowercase"
                     . "\n /ping — Ping the bot"
                     . "\n /list — List all commands"
                     . "\n\nOr just chat / send me an image! 💬🖼";
                return $msg;

            case '/ping':
                return $phrases['pong'];

            case '/list':
                return "<b>Available commands:</b>"
                     . "\n /code #code — Test bot input recognition"
                     . "\n /strlowercase #text — Convert text to lowercase"
                     . "\n /ping — Ping the bot"
                     . "\n /list — List all commands"
                     . "\n\nYou can also just talk or send images! 💬🖼";

            default:
                return $phrases['unknown_cmd'];
        }
    }

    // ── Free-form text or image → Gemini ─────────────────────────────
    // Prepend the user's actual name to the text so the bot knows who is speaking
    $contextualMessage = "[{$fullName} says]:\n" . $userMessage;
    
    return gemini()->chat($contextualMessage, $imageBase64, $mimeType);
}

function parseInput(string $input): string
{
    return "You submitted code: " . $input;
}

