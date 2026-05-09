<?php

namespace App\Utils;

/**
 * Splits long strings into Telegram-safe chunks (≤ 4000 chars each).
 *
 * Telegram's sendMessage limit is 4096 chars. Long AI replies were silently
 * truncated before this class was introduced.
 *
 * Split priority:
 *   1. Double newline (paragraph boundary)
 *   2. Single newline
 *   3. Sentence ending (". ")
 *   4. Hard cut at MAX_LENGTH
 */
class MessageChunker
{
    const MAX_LENGTH = 4000;

    /**
     * Split text into an array of Telegram-safe chunks.
     * Returns a single-element array when the text fits in one message.
     *
     * @param  string   $text
     * @return string[]
     */
    public static function split(string $text): array
    {
        $text = trim($text);

        if (mb_strlen($text) <= self::MAX_LENGTH) {
            return [$text];
        }

        $chunks = [];

        while (mb_strlen($text) > self::MAX_LENGTH) {
            $window = mb_substr($text, 0, self::MAX_LENGTH);

            // Priority 1: paragraph boundary (\n\n)
            $breakPos = mb_strrpos($window, "\n\n");

            // Priority 2: single newline — only if paragraph boundary is too early
            if ($breakPos === false || $breakPos < (int) (self::MAX_LENGTH * 0.4)) {
                $pos = mb_strrpos($window, "\n");
                if ($pos !== false && $pos > (int) (self::MAX_LENGTH * 0.4)) {
                    $breakPos = $pos;
                }
            }

            // Priority 3: sentence ending (". ")
            if ($breakPos === false || $breakPos < (int) (self::MAX_LENGTH * 0.4)) {
                $pos = mb_strrpos($window, '. ');
                if ($pos !== false && $pos > (int) (self::MAX_LENGTH * 0.4)) {
                    $breakPos = $pos + 1; // include the period
                }
            }

            // Priority 4: hard cut
            if ($breakPos === false || $breakPos < 100) {
                $breakPos = self::MAX_LENGTH;
            }

            $chunks[] = trim(mb_substr($text, 0, $breakPos));
            $text     = trim(mb_substr($text, $breakPos));
        }

        if ($text !== '') {
            $chunks[] = $text;
        }

        return $chunks;
    }
}
