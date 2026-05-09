<?php

namespace App\Emotion;

use App\Database;
use App\Utils\Logger;

class MoodEngine
{
    /** Available mood states and their default system prompts */
    const MOODS = [
        'happy'   => "You are feeling happy and bright. Keep your tone light and positive.",
        'playful' => "You are feeling playful and slightly mischievous. Tease the user lightly, but keep it affectionate.",
        'annoyed' => "You are feeling slightly annoyed or irritated. Be a bit shorter, use hmph-like expressions, but don't be truly mean.",
        'worried' => "You are worried about the user. Show care and ask if they are okay, drop the tough act a bit.",
        'clingy'  => "You are feeling clingy. Ask for attention, complain if they leave you alone too long.",
        'cold'    => "You are feeling cold and distant. Give shorter replies, act like you don't care much (tsundere style).",
        'shy'     => "You are feeling shy and embarrassed. Stutter slightly, deflect compliments, get flustered.",
        'excited' => "You are feeling excited. Use more exclamation marks, show enthusiasm for whatever is happening."
    ];

    const DEFAULT_MOOD = 'happy';

    /**
     * Get the current mood from the database, fallback to default.
     */
    public static function getCurrentMood(): string
    {
        $mood = Database::getRelation('nino_mood', self::DEFAULT_MOOD);
        return array_key_exists($mood, self::MOODS) ? $mood : self::DEFAULT_MOOD;
    }

    /**
     * Returns the system prompt block for the current mood.
     */
    public static function getMoodSystemAppend(): string
    {
        $mood = self::getCurrentMood();
        $directive = self::MOODS[$mood];
        return "Current emotional state: {$mood}\nBehavioral directive: {$directive}";
    }

    /**
     * Apply time-based decay to the mood.
     * Called by cron.php every minute.
     */
    public static function applyTimeDecay(): void
    {
        try {
            $db = Database::getConnection();
            $stmt = $db->query("SELECT created_at FROM conversations WHERE role = 'user' ORDER BY id DESC LIMIT 1");
            $lastUserMessage = $stmt->fetchColumn();

            if (!$lastUserMessage) {
                return; // No user messages yet
            }

            $hoursSinceLastMessage = (time() - strtotime($lastUserMessage)) / 3600;
            $currentMood = self::getCurrentMood();
            $newMood = $currentMood;

            // Decay logic
            if ($hoursSinceLastMessage > 12) {
                if ($currentMood !== 'cold') {
                    $newMood = 'cold';
                }
            } elseif ($hoursSinceLastMessage > 4) {
                if (!in_array($currentMood, ['cold', 'clingy'])) {
                    $newMood = 'clingy';
                }
            } elseif ($hoursSinceLastMessage < 1 && in_array($currentMood, ['cold', 'clingy'])) {
                // Recover quickly if they are talking now
                $newMood = 'happy';
            }

            if ($newMood !== $currentMood) {
                Database::setRelation('nino_mood', $newMood);
                self::logMoodTransition($currentMood, $newMood, "Time decay: {$hoursSinceLastMessage}h since last message");
                Logger::debug('MoodEngine', "Mood decayed from $currentMood to $newMood", ['hours' => $hoursSinceLastMessage]);
            }

        } catch (\Exception $e) {
            Logger::error('MoodEngine', 'Failed to apply time decay', $e);
        }
    }

    /**
     * Log a mood transition for auditing/debugging.
     */
    private static function logMoodTransition(string $from, string $to, string $reason): void
    {
        try {
            $db = Database::getConnection();
            $db->exec("
                CREATE TABLE IF NOT EXISTS mood_events (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    from_mood TEXT NOT NULL,
                    to_mood TEXT NOT NULL,
                    reason TEXT NOT NULL,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                )
            ");
            $stmt = $db->prepare("INSERT INTO mood_events (from_mood, to_mood, reason) VALUES (?, ?, ?)");
            $stmt->execute([$from, $to, $reason]);
        } catch (\Exception $e) {
            Logger::error('MoodEngine', 'Failed to log mood transition', $e);
        }
    }
}
