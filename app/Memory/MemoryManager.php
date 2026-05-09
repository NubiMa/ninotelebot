<?php

namespace App\Memory;

use App\Utils\Logger;
use PDO;

/**
 * Scored memory retrieval — returns the most contextually relevant memories
 * for a given user message instead of dumping all memories into the prompt.
 *
 * Scoring formula (per memory):
 *   score = (keyword_jaccard * 0.6) + (recency * 0.3) + (recall_boost * 0.1)
 *
 * No external dependencies — pure PHP keyword matching.
 */
class MemoryManager
{
    const MAX_MEMORIES = 12;

    /** Indonesian + English stop words to skip during keyword extraction */
    const STOP_WORDS = [
        'yang','dan','di','ke','dari','ini','itu','ada','tidak','bisa',
        'aku','kamu','saya','dia','kami','kita','mereka','sih','deh',
        'nih','dong','aja','juga','udah','sudah','banget','sekali',
        'the','and','for','are','but','not','with','have','this',
        'that','from','they','will','been','were','their',
    ];

    /**
     * Return a formatted string of the most relevant memories for the given message.
     * Falls back to recency-sorted if no message context is provided.
     */
    public static function getRelevantMemories(string $userMessage = ''): string
    {
        try {
            $db = \App\Database::getConnection();
        } catch (\Exception $e) {
            Logger::error('MemoryManager', 'DB connection failed', $e);
            return 'No memories available.';
        }

        $stmt = $db->query(
            "SELECT id, fact, tags, recall_count, created_at
             FROM memories
             ORDER BY created_at ASC"
        );
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($rows)) {
            return 'No memories saved yet.';
        }

        // Score each memory
        $queryKeywords = self::extractKeywords($userMessage);
        $scored = [];

        foreach ($rows as $row) {
            $factKeywords = self::extractKeywords($row['fact']);
            $jaccard      = self::jaccardSimilarity($queryKeywords, $factKeywords);
            $recency      = self::recencyScore($row['created_at']);
            $recallBoost  = min((int) $row['recall_count'] / 10, 0.5);
            $combined     = ($jaccard * 0.6) + ($recency * 0.3) + ($recallBoost * 0.1);

            $scored[] = array_merge($row, ['_score' => $combined]);
        }

        // Sort by combined score DESC, take top MAX_MEMORIES
        usort($scored, fn($a, $b) => $b['_score'] <=> $a['_score']);
        $selected = array_slice($scored, 0, self::MAX_MEMORIES);

        // Update recall metadata for selected memories
        self::updateRecallStats($db, array_column($selected, 'id'));

        // Format output
        $lines = ["Things you must remember about the user:"];
        foreach ($selected as $mem) {
            $tag  = $mem['tags'] ? " [{$mem['tags']}]" : '';
            $lines[] = "- {$mem['fact']}{$tag}";
        }

        Logger::debug('MemoryManager', 'Retrieved memories', [
            'total'    => count($rows),
            'selected' => count($selected),
            'context'  => mb_substr($userMessage, 0, 60),
        ]);

        return implode("\n", $lines);
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    /**
     * Extract meaningful keywords from a string (lowercase, de-stopped, >2 chars).
     * Returns an associative array keyed by word for fast intersection.
     */
    private static function extractKeywords(string $text): array
    {
        if ($text === '') return [];
        $words = preg_split('/[\s\W]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY);
        $stop  = array_flip(self::STOP_WORDS);
        $out   = [];
        foreach ($words as $w) {
            if (mb_strlen($w) > 2 && !isset($stop[$w])) {
                $out[$w] = true;
            }
        }
        return $out;
    }

    /**
     * Jaccard similarity: |A ∩ B| / |A ∪ B|. Returns 0.0–1.0.
     * Returns 0.5 (neutral) when either set is empty (no context → don't penalize).
     */
    private static function jaccardSimilarity(array $a, array $b): float
    {
        if (empty($a) || empty($b)) return 0.5;
        $intersection = count(array_intersect_key($a, $b));
        $union        = count(array_merge($a, $b));
        return $union > 0 ? $intersection / $union : 0.0;
    }

    /**
     * Recency score: exponential decay over 90 days.
     * Today → ~1.0, 90 days ago → ~0.05.
     */
    private static function recencyScore(string $createdAt): float
    {
        $days = max(0, (time() - strtotime($createdAt)) / 86400);
        return exp(-$days / 45);
    }

    /**
     * Bump recall_count and set last_recalled = now for the retrieved memory IDs.
     */
    private static function updateRecallStats(object $db, array $ids): void
    {
        if (empty($ids)) return;
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        try {
            $stmt = $db->prepare(
                "UPDATE memories
                 SET recall_count = recall_count + 1,
                     last_recalled = CURRENT_TIMESTAMP
                 WHERE id IN ($placeholders)"
            );
            $stmt->execute($ids);
        } catch (\Exception $e) {
            Logger::error('MemoryManager', 'Failed to update recall stats', $e);
        }
    }
}
