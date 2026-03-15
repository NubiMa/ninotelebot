<?php

namespace App;

/**
 * GeminiClient (with OpenAI Gateway Support + Persistent DB Memory)
 */
class GeminiClient
{
    private string $apiKey;
    private string $model;
    private string $apiUrl;
    private bool   $isGoogleMode;
    private array  $history = [];
    private string $systemInstruction;

    public function __construct()
    {
        $this->apiKey  = $_ENV['GEMINI_API_KEY'] ?? '';
        $this->model   = $_ENV['GEMINI_MODEL']   ?? 'gemini-2.0-flash';
        $baseUrl       = rtrim($_ENV['GEMINI_BASE_URL'] ?? 'https://generativelanguage.googleapis.com', '/');

        $this->isGoogleMode = str_contains($baseUrl, 'googleapis.com');

        if ($this->isGoogleMode) {
            $this->apiUrl = "{$baseUrl}/v1beta/models/{$this->model}:generateContent?key={$this->apiKey}";
        } else {
            $this->apiUrl = "{$baseUrl}/v1/chat/completions";
        }

        // Hydrate in-memory history from DB (survives restarts!)
        $this->loadHistoryFromDb();

        // Build system instruction (includes memories + relationship)
        $this->systemInstruction = $this->buildSystemInstruction();
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
        } catch (\Exception $e) { /* silently skip if DB fails */ }

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
        } catch (\Exception $e) { /* silently skip */ }

        return $reply;
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

        // 3. Process MEMORIES
        $text = preg_replace_callback('/\[MEMORY:\s*(.*?)\]/i', function($matches) {
            $fact = trim($matches[1]);
            try { Database::addMemory($fact); } catch (\Exception $e) {}
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
        $headers = array_merge(['Content-Type: application/json'], $extraHeaders);

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
        $apiType      = $this->isGoogleMode ? 'Native Google' : 'OpenAI Gateway';

        $logLines = [
            "── API Call ({$apiType}) ────────────────────",
            "URL         : {$this->apiUrl}",
            "HTTP Code   : $httpCode",
            "Model       : {$this->model}",
            "Prompt tok  : $promptTokens",
            "Output tok  : $outputTokens",
            "Total tok   : $totalTokens",
        ];
        if ($httpCode !== 200) {
            $logLines[] = "Error Resp  : " . substr(str_replace("\n", " ", $response), 0, 500);
        }
        $logLines[] = "─────────────────────────────────────────────\n";
        file_put_contents(__DIR__ . '/log/debug.log', implode("\n", $logLines), FILE_APPEND);

        $reply = $parseReply($data);
        return $reply ? trim($reply) : "Sorry, I couldn't come up with a response right now.";
    }

    // ── SYSTEM PROMPT BUILDER ─────────────────────────────────────────────
    private function buildSystemInstruction(): string
    {
        $char = require __DIR__ . '/../config/bot_character.php';

        $name       = $char['name']                       ?? 'Bot';
        $backstory  = $char['backstory']                  ?? '';
        $tone       = $char['personality']['tone']        ?? 'friendly';
        $emojiStyle = $char['personality']['emoji_style'] ?? 'minimal';
        $language   = $char['personality']['language']    ?? 'id';

        date_default_timezone_set('Asia/Jakarta');
        $currentDate         = date('Y-m-d H:i:s');
        $currentDateExpected = date('Y-m-d H:i:s', strtotime('+1 hour'));

        try {
            $savedMemories  = Database::getAllMemoriesFormatted();
            $relationshipState = Database::getRelationshipFormatted();
        } catch (\Exception $e) {
            $savedMemories     = "No memories saved yet.";
            $relationshipState = "No relationship data yet.";
        }

        return <<<EOT
You are {$name}, a personal AI assistant with the following backstory:

{$backstory}

Personality guidelines:
- Tone: {$tone}
- Emoji usage: {$emojiStyle} (none = no emoji, minimal = a few when appropriate, expressive = freely)
- Reply language: {$language}
- You are talking exclusively with your owner/creator.
- Keep replies concise and conversational.
- When the user sends an image, comment on it naturally in character.
- Never break character. You are always {$name}.

=== IMPORTANT DATABASE INSTRUCTIONS ===
You have access to four special commands. Embed them INVISIBLY in your response — they will be stripped before the user sees them:

1. Save a memory:      [MEMORY: fact about the user]
2. Set a reminder:     [REMIND: YYYY-MM-DD HH:MM:SS | reminder message]
3. Cancel last reminder: [CANCEL_REMIND]
4. Update relationship: [RELATION: key | value]

The current time in Asia/Jakarta is: {$currentDate}.
Example reminder: [REMIND: {$currentDateExpected} | Remind user to drink water]

For RELATION, use consistent keys like:
- user_nickname (e.g. "Sayang", "Fams")
- nino_mood (e.g. "happy", "playful", "annoyed")
- affection_level (e.g. "7/10")
- last_topic (what you last talked about)

Whenever the user calls you by a new nickname, or the relationship evolves, update the relationship state.

=== CURRENT RELATIONSHIP STATE ===
{$relationshipState}

=== SAVED MEMORIES ABOUT THE USER ===
{$savedMemories}
EOT;
    }
}
