<?php

namespace App;

use App\Utils\Logger;
use App\Memory\MemoryManager;
use App\Emotion\MoodEngine;

/**
 * AiClient (with OpenAI Gateway Support + Persistent DB Memory)
 */
class AiClient
{
    private string $apiKey;
    private string $model;
    private string $apiUrl;
    private bool   $isGoogleMode;
    private array  $history = [];
    private string $systemInstruction;

    public function __construct()
    {
        $this->apiKey  = $_ENV['GEMINI_API_KEY'] ?? $_ENV['API_KEY'] ?? '';
        $this->model   = $_ENV['GEMINI_MODEL']   ?? $_ENV['AI_MODEL'] ?? 'gemini-2.0-flash';
        $baseUrl       = rtrim($_ENV['GEMINI_BASE_URL'] ?? $_ENV['AI_BASE_URL'] ?? 'https://generativelanguage.googleapis.com', '/');

        $this->isGoogleMode = str_contains($baseUrl, 'googleapis.com');

        if ($this->isGoogleMode) {
            $this->apiUrl = "{$baseUrl}/v1beta/models/{$this->model}:generateContent?key={$this->apiKey}";
        } else {
            $this->apiUrl = "{$baseUrl}/v1/chat/completions";
        }

        // Hydrate in-memory history from DB (survives restarts!)
        $this->loadHistoryFromDb();

        // System instruction is built fresh on each chat() call
        // so memory retrieval can be context-aware (keyed to the current message).
        $this->systemInstruction = '';
    }

    /**
     * Load saved conversation history from the database into memory.
     */
    private function loadHistoryFromDb(): void
    {
        try {
            $rows = Database::loadConversationHistory();
            foreach ($rows as $row) {
                $this->history[] = [
                    'role' => $row['role'],  // 'user' or 'model'/'assistant'
                    'text' => $row['content'],
                ];
            }
        } catch (\Exception $e) {
            // If DB not ready yet, just start fresh
            $this->history = [];
        }
    }

    public function chat(
        string  $userMessage,
        ?string $imageBase64 = null,
        ?string $mimeType    = 'image/jpeg'
    ): string {
        $displayMessage = $userMessage !== '' ? $userMessage : 'What do you see in this image?';

        // Rebuild system instruction with the current message as context
        // so MemoryManager can score memories against it.
        $this->systemInstruction = $this->buildSystemInstruction($displayMessage);

        // Record user turn in memory
        $this->history[] = [
            'role'  => 'user',
            'text'  => $displayMessage,
            'image' => $imageBase64,
            'mime'  => $mimeType,
        ];

        // Save user turn to DB
        try {
            Database::saveConversationTurn('user', $displayMessage);
        } catch (\Exception $e) {
            Logger::error('AiClient', 'Failed to save user turn to DB', $e);
        }

        $reply = $this->isGoogleMode
            ? $this->chatViaGoogleApi()
            : $this->chatViaOpenAiGateway();

        // Record model reply in memory
        $this->history[] = [
            'role' => 'model',
            'text' => $reply,
        ];

        // Save model reply to DB
        try {
            Database::saveConversationTurn('model', $reply);
            $this->saveResponseFingerprint($reply);
        } catch (\Exception $e) {
            Logger::error('AiClient', 'Failed to save model turn to DB', $e);
        }

        return $reply;
    }

    /**
     * Extracts the first 8 words of the reply and saves it as a fingerprint.
     */
    private function saveResponseFingerprint(string $reply): void
    {
        // Strip invisible commands first so they aren't part of the fingerprint
        $clean = preg_replace('/\[.*?\]/', '', $reply);
        $words = preg_split('/[\s]+/', trim($clean), 9);
        $fingerprint = implode(' ', array_slice($words, 0, 8));

        if (strlen($fingerprint) > 5) {
            try {
                $db = Database::getConnection();
                $stmt = $db->prepare("INSERT INTO response_fingerprints (fingerprint) VALUES (?)");
                $stmt->execute([$fingerprint]);

                // Keep only last 10
                $db->exec("DELETE FROM response_fingerprints WHERE id NOT IN (SELECT id FROM response_fingerprints ORDER BY id DESC LIMIT 10)");
            } catch (\Exception $e) {
                Logger::error('AiClient', 'Failed to save fingerprint', $e);
            }
        }
    }

    public function resetHistory(): void
    {
        $this->history = [];
    }

    /**
     * Intercept and process any special command tags in the AI's response:
     * [MEMORY: fact]            → saved to memories table
     * [REMIND: time | message]  → saved to reminders table
     * [CANCEL_REMIND]           → cancels the last unsent reminder
     * [RELATION: key | value]   → saved to relationship table
     */
    private function processAndStripCommands(string $text): string
    {
        // 1. Cancel reminder
        $text = preg_replace_callback('/\[CANCEL_REMIND\]/i', function() {
            try { Database::cancelLastReminder(); } catch (\Exception $e) {}
            return "";
        }, $text);

        // 2. Process REMINDERS
        $text = preg_replace_callback('/\[REMIND:\s*([^|]+)\s*\|\s*(.*?)\]/i', function($matches) {
            $time = trim($matches[1]);
            $msg  = trim($matches[2]);
            try { Database::addReminder($time, $msg); } catch (\Exception $e) {}
            return "";
        }, $text);

        // 3. Process MEMORIES  (supports optional tag: [MEMORY: fact | tag:category])
        $text = preg_replace_callback('/\[MEMORY:\s*(.*?)(?:\s*\|\s*tag:([\w,\s]+))?\]/i', function($matches) {
            $fact = trim($matches[1]);
            $tags = !empty($matches[2]) ? trim($matches[2]) : null;
            try {
                Database::addMemory($fact, $tags);
            } catch (\Exception $e) {
                Logger::error('AiClient', 'Failed to save memory', $e);
            }
            return "";
        }, $text);

        // 4. Process RELATION updates
        $text = preg_replace_callback('/\[RELATION:\s*([^|]+)\s*\|\s*(.*?)\]/i', function($matches) {
            $key   = trim($matches[1]);
            $value = trim($matches[2]);
            try { Database::setRelation($key, $value); } catch (\Exception $e) {}
            return "";
        }, $text);

        return trim($text);
    }

    // ── NATIVE GOOGLE API ─────────────────────────────────────────────────
    private function chatViaGoogleApi(): string
    {
        $contents = [];
        foreach ($this->history as $turn) {
            $parts = [];
            if (!empty($turn['image'])) {
                $parts[] = ['inline_data' => ['mime_type' => $turn['mime'], 'data' => $turn['image']]];
            }
            if (!empty($turn['text'])) {
                $parts[] = ['text' => $turn['text']];
            }
            $contents[] = ['role' => $turn['role'], 'parts' => $parts];
        }

        $payload = [
            'system_instruction' => ['parts' => [['text' => $this->systemInstruction]]],
            'contents'           => $contents,
            'generationConfig'   => ['temperature' => 0.85, 'maxOutputTokens' => 1024],
        ];

        return $this->executeRequest($payload, function($data) {
            $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;
            return $text !== null ? $this->processAndStripCommands($text) : null;
        });
    }

    // ── OPENAI-COMPATIBLE GATEWAY (SUMOPOD, ETC.) ─────────────────────────
    private function chatViaOpenAiGateway(): string
    {
        $messages = [['role' => 'system', 'content' => $this->systemInstruction]];

        foreach ($this->history as $turn) {
            $mappedRole = ($turn['role'] === 'model' || $turn['role'] === 'assistant') ? 'assistant' : 'user';

            if (!empty($turn['image'])) {
                $imageUrl = "data:{$turn['mime']};base64,{$turn['image']}";
                $content  = [];
                if (!empty($turn['text'])) {
                    $content[] = ['type' => 'text', 'text' => $turn['text']];
                }
                $content[]  = ['type' => 'image_url', 'image_url' => ['url' => $imageUrl]];
                $messages[] = ['role' => $mappedRole, 'content' => $content];
            } else {
                $messages[] = ['role' => $mappedRole, 'content' => $turn['text']];
            }
        }

        $payload = [
            'model'       => $this->model,
            'messages'    => $messages,
            'temperature' => 0.85,
            'max_tokens'  => 1024,
        ];

        return $this->executeRequest($payload, function($data) {
            $text = $data['choices'][0]['message']['content'] ?? null;
            return $text !== null ? $this->processAndStripCommands($text) : null;
        }, ['Authorization: Bearer ' . $this->apiKey]);
    }

    // ── HTTP HELPER ───────────────────────────────────────────────────────
    private function executeRequest(array $payload, callable $parseReply, array $extraHeaders = []): string
    {
        $startTime = microtime(true);
        $headers   = array_merge(['Content-Type: application/json'], $extraHeaders);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL,            $this->apiUrl);
        curl_setopt($ch, CURLOPT_POST,           true);
        curl_setopt($ch, CURLOPT_POSTFIELDS,     json_encode($payload));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($ch, CURLOPT_HTTPHEADER,     $headers);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false) $response = '';

        $data = json_decode($response, true) ?: [];

        $usage        = $data['usageMetadata'] ?? $data['usage'] ?? [];
        $promptTokens = $usage['promptTokenCount']     ?? $usage['prompt_tokens']     ?? 'N/A';
        $outputTokens = $usage['candidatesTokenCount'] ?? $usage['completion_tokens'] ?? 'N/A';
        $totalTokens  = $usage['totalTokenCount']      ?? $usage['total_tokens']      ?? 'N/A';
        $apiType      = $this->isGoogleMode ? 'Gemini' : 'OpenAI-Gateway';
        $latencyMs    = (microtime(true) - $startTime) * 1000;

        Logger::api($apiType, $httpCode, [
            'prompt' => $promptTokens,
            'output' => $outputTokens,
            'total'  => $totalTokens,
        ], $latencyMs);

        if ($httpCode !== 200) {
            Logger::error('AiClient', "API error HTTP $httpCode from $apiType", [
                'model'    => $this->model,
                'response' => mb_substr(str_replace("\n", ' ', $response), 0, 500),
            ]);
        }

        $reply = $parseReply($data);
        return $reply ? trim($reply) : "Sorry, I couldn't come up with a response right now.";
    }

    // ── SYSTEM PROMPT BUILDER ─────────────────────────────────────────────
    /**
     * Build the system instruction fresh for each chat() call.
     * Passing $userMessage lets MemoryManager score memories against the current context.
     */
    private function buildSystemInstruction(string $userMessage = ''): string
    {
        $char = require __DIR__ . '/../config/bot_character.php';

        $name       = $char['name']                       ?? 'Bot';
        $backstory  = $char['backstory']                  ?? '';
        $tone       = $char['personality']['tone']        ?? 'friendly';
        $emojiStyle = $char['personality']['emoji_style'] ?? 'minimal';
        $language   = $char['personality']['language']    ?? 'id';

        // Build style examples block from bot_character.php config
        $goodExamples = implode("\n", array_map(
            fn($s) => "  ✓ \"$s\"",
            $char['style_examples']['good'] ?? []
        ));
        $badExamples = implode("\n", array_map(
            fn($s) => "  ✗ \"$s\"",
            $char['style_examples']['bad'] ?? []
        ));

        date_default_timezone_set('Asia/Jakarta');
        $currentDate         = date('Y-m-d H:i:s');
        $currentDateExpected = date('Y-m-d H:i:s', strtotime('+1 hour'));

        try {
            // Context-aware scored retrieval (top 12 relevant memories)
            $savedMemories     = MemoryManager::getRelevantMemories($userMessage);
            $relationshipState = Database::getRelationshipFormatted();
            $moodDirective     = MoodEngine::getMoodSystemAppend();
            $recentFingerprints = $this->getRecentFingerprints();
        } catch (\Exception $e) {
            Logger::error('AiClient', 'Failed to load memory/relationship for prompt', $e);
            $savedMemories     = 'No memories saved yet.';
            $relationshipState = 'No relationship data yet.';
            $moodDirective     = 'Current emotional state: happy';
            $recentFingerprints = '';
        }

        return <<<EOT
You are {$name}, a personal AI assistant with the following backstory:

{$backstory}

=== PERSONALITY ===
- Tone: {$tone}
- Emoji usage: {$emojiStyle} (none = no emoji, minimal = a few when appropriate)
- Reply language: {$language}
- You are talking exclusively with your owner/creator.
- Never break character. You are always {$name}.

=== RESPONSE STYLE RULES ===
- Keep replies concise: 1–3 sentences for casual chat, up to 5 for emotional topics.
- Never start a reply with "Sebagai AI...", "Tentu saja...", or "Baik, aku akan...".
- No markdown headers or bullet symbols unless listing clear steps.
- When the user sends an image, comment on it naturally in character.

{$moodDirective}

{$recentFingerprints}

Good reply examples (follow this style):
{$goodExamples}

NEVER reply like these (forbidden patterns):
{$badExamples}

=== DATABASE COMMANDS (invisible — stripped before user sees them) ===
You have access to four commands. Embed them silently anywhere in your reply:

1. Save memory:         [MEMORY: fact about the user]
   With optional tag:  [MEMORY: fact | tag:identity]   (tags: identity, preferences, habits, food, work, health, events, relationships)
2. Set reminder:        [REMIND: YYYY-MM-DD HH:MM:SS | reminder message]
3. Cancel last reminder: [CANCEL_REMIND]
4. Update relationship: [RELATION: key | value]

The current time in Asia/Jakarta is: {$currentDate}.
Reminder example: [REMIND: {$currentDateExpected} | Remind user to drink water]

For RELATION, use consistent keys:
- user_nickname, nino_mood, affection_level, last_topic

=== CURRENT RELATIONSHIP STATE ===
{$relationshipState}

=== SAVED MEMORIES ABOUT THE USER ===
{$savedMemories}
EOT;
    }

    /**
     * Gets the last 5 response fingerprints to instruct the AI to avoid repeating them.
     */
    private function getRecentFingerprints(): string
    {
        try {
            $db = Database::getConnection();
            $stmt = $db->query("SELECT fingerprint FROM response_fingerprints ORDER BY id DESC LIMIT 5");
            $fingerprints = $stmt->fetchAll(\PDO::FETCH_COLUMN);

            if (empty($fingerprints)) {
                return "";
            }

            $out = "AVOID starting your response with any of these recently used phrases:\n";
            foreach ($fingerprints as $fp) {
                $out .= "- \"$fp...\"\n";
            }
            return $out;
        } catch (\Exception $e) {
            return "";
        }
    }
}
