<?php

namespace App;

use PDO;
use PDOException;

class Database
{
    private static ?PDO $pdo = null;

    // Max messages to keep in DB for context window
    const MAX_HISTORY = 30;

    public static function getConnection(): PDO
    {
        if (self::$pdo === null) {
            $dbPath = __DIR__ . '/../database/database.sqlite';
            $dir = dirname($dbPath);
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }
            try {
                self::$pdo = new PDO('sqlite:' . $dbPath);
                self::$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                self::$pdo->exec('PRAGMA journal_mode=WAL'); // Better concurrency
                self::initTables();
            } catch (PDOException $e) {
                file_put_contents(__DIR__ . '/log/debug.log', "DB Error: " . $e->getMessage() . "\n", FILE_APPEND);
                throw $e;
            }
        }
        return self::$pdo;
    }

    private static function initTables(): void
    {
        $db = self::$pdo;

        // Long-term memories (facts about the user)
        $db->exec("
            CREATE TABLE IF NOT EXISTS memories (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                fact TEXT NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");

        // Scheduled reminders
        $db->exec("
            CREATE TABLE IF NOT EXISTS reminders (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                trigger_time DATETIME NOT NULL,
                message TEXT NOT NULL,
                is_sent INTEGER DEFAULT 0,
                is_cancelled INTEGER DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");

        // Persistent conversation history (survives restarts)
        $db->exec("
            CREATE TABLE IF NOT EXISTS conversations (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                role TEXT NOT NULL,
                content TEXT NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");

        // Relationship state (key-value store for evolving personality/bond)
        $db->exec("
            CREATE TABLE IF NOT EXISTS relationship (
                key TEXT PRIMARY KEY,
                value TEXT NOT NULL,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");
    }

    // ── MEMORIES ────────────────────────────────────────────────────────────

    public static function addMemory(string $fact): void
    {
        $stmt = self::getConnection()->prepare("INSERT INTO memories (fact) VALUES (:fact)");
        $stmt->execute(['fact' => $fact]);
    }

    public static function getAllMemoriesFormatted(): string
    {
        $stmt = self::getConnection()->query("SELECT fact FROM memories ORDER BY created_at ASC");
        $facts = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if (empty($facts)) {
            return "No memories saved yet.";
        }
        $formatted = "Things you must remember about the user:\n";
        foreach ($facts as $fact) {
            $formatted .= "- $fact\n";
        }
        return $formatted;
    }

    // ── REMINDERS ───────────────────────────────────────────────────────────

    public static function addReminder(string $triggerTime, string $message): void
    {
        $stmt = self::getConnection()->prepare(
            "INSERT INTO reminders (trigger_time, message) VALUES (:time, :msg)"
        );
        $stmt->execute(['time' => $triggerTime, 'msg' => $message]);
    }

    /**
     * Cancel the most recent unsent reminder.
     */
    public static function cancelLastReminder(): void
    {
        self::getConnection()->exec(
            "UPDATE reminders SET is_cancelled = 1
             WHERE id = (SELECT id FROM reminders WHERE is_sent = 0 AND is_cancelled = 0 ORDER BY id DESC LIMIT 1)"
        );
    }

    // ── CONVERSATIONS ────────────────────────────────────────────────────────

    /**
     * Persist a message turn to the DB.
     */
    public static function saveConversationTurn(string $role, string $content): void
    {
        $db = self::getConnection();
        $stmt = $db->prepare("INSERT INTO conversations (role, content) VALUES (:role, :content)");
        $stmt->execute(['role' => $role, 'content' => $content]);

        // Keep only the last MAX_HISTORY rows to avoid growing forever
        $db->exec("DELETE FROM conversations WHERE id NOT IN
            (SELECT id FROM conversations ORDER BY id DESC LIMIT " . self::MAX_HISTORY . ")");
    }

    /**
     * Load the last N messages for hydrating the GeminiClient history.
     * Returns array of ['role' => ..., 'content' => ...].
     */
    public static function loadConversationHistory(): array
    {
        $stmt = self::getConnection()->prepare(
            "SELECT role, content FROM conversations ORDER BY id DESC LIMIT :limit"
        );
        $stmt->execute(['limit' => self::MAX_HISTORY]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return array_reverse($rows); // Chronological order
    }

    // ── RELATIONSHIP ─────────────────────────────────────────────────────────

    /**
     * Set or update a relationship key-value pair.
     */
    public static function setRelation(string $key, string $value): void
    {
        $stmt = self::getConnection()->prepare(
            "INSERT INTO relationship (key, value, updated_at)
             VALUES (:key, :value, CURRENT_TIMESTAMP)
             ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = CURRENT_TIMESTAMP"
        );
        $stmt->execute(['key' => $key, 'value' => $value]);
    }

    /**
     * Get a relationship value by key.
     */
    public static function getRelation(string $key, string $default = ''): string
    {
        $stmt = self::getConnection()->prepare("SELECT value FROM relationship WHERE key = :key");
        $stmt->execute(['key' => $key]);
        $result = $stmt->fetchColumn();
        return $result !== false ? $result : $default;
    }

    /**
     * Get all relationship state as a formatted string for the system prompt.
     */
    public static function getRelationshipFormatted(): string
    {
        $stmt = self::getConnection()->query("SELECT key, value FROM relationship ORDER BY key ASC");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (empty($rows)) {
            return "No relationship data yet. This is a fresh start.";
        }
        $formatted = "Current relationship state with the user:\n";
        foreach ($rows as $row) {
            $formatted .= "- {$row['key']}: {$row['value']}\n";
        }
        return $formatted;
    }
}
