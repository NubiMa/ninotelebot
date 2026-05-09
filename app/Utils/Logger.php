<?php

namespace App\Utils;

/**
 * Centralized rotating logger.
 *
 * Log files are written to: {project_root}/storage/logs/
 *  - debug_YYYY-MM-DD.log   — general debug info
 *  - error_YYYY-MM-DD.log   — errors and exceptions
 *  - api_YYYY-MM-DD.log     — structured AI/API call records (JSON lines)
 *
 * Files older than LOG_MAX_DAYS (.env) are automatically pruned.
 */
class Logger
{
    private static string $logDir  = '';
    private static int    $maxDays = 7;

    // ── Bootstrap ──────────────────────────────────────────────────────────

    private static function dir(): string
    {
        if (self::$logDir === '') {
            // app/Utils/ → app/ → project root → storage/logs
            self::$logDir = dirname(__DIR__, 2) . '/storage/logs';
            if (!is_dir(self::$logDir)) {
                mkdir(self::$logDir, 0775, true);
            }
            self::$maxDays = (int) ($_ENV['LOG_MAX_DAYS'] ?? 7);
        }
        return self::$logDir;
    }

    // ── Public API ─────────────────────────────────────────────────────────

    /**
     * Log a debug-level message.
     *
     * @param string $context  Short label, e.g. 'AiClient', 'Database'
     * @param string $message  Human-readable description
     * @param array  $data     Optional key-value context data
     */
    public static function debug(string $context, string $message, array $data = []): void
    {
        self::write('debug', $context, $message, $data);
    }

    /**
     * Log an error, optionally with the originating exception or context array.
     */
    public static function error(string $context, string $message, \Throwable|array|null $e = null): void
    {
        $data = [];
        if ($e instanceof \Throwable) {
            $data['exception'] = get_class($e);
            $data['msg']       = $e->getMessage();
            $data['at']        = $e->getFile() . ':' . $e->getLine();
        } elseif (is_array($e)) {
            $data = $e;
        }
        self::write('error', $context, $message, $data);
    }

    /**
     * Log a structured AI/API call record (JSON line to api_YYYY-MM-DD.log).
     *
     * @param string $provider  e.g. 'Gemini', 'Groq'
     * @param int    $httpCode
     * @param array  $usage     Keys: 'prompt', 'output', 'total' (token counts)
     * @param float  $latencyMs Wall-clock milliseconds for the request
     */
    public static function api(string $provider, int $httpCode, array $usage = [], float $latencyMs = 0.0): void
    {
        $line = json_encode([
            'ts'            => date('c'),
            'provider'      => $provider,
            'http_code'     => $httpCode,
            'prompt_tokens' => $usage['prompt'] ?? 'N/A',
            'output_tokens' => $usage['output'] ?? 'N/A',
            'total_tokens'  => $usage['total']  ?? 'N/A',
            'latency_ms'    => round($latencyMs, 2),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $file = self::dir() . '/api_' . date('Y-m-d') . '.log';
        file_put_contents($file, $line . "\n", FILE_APPEND | LOCK_EX);
        self::prune('api_');
    }

    // ── Internals ──────────────────────────────────────────────────────────

    private static function write(string $level, string $context, string $message, array $data): void
    {
        $ts      = date('Y-m-d H:i:s');
        $extra   = empty($data) ? '' : ' ' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $line    = "[{$ts}][{$level}][{$context}] {$message}{$extra}\n";
        $prefix  = ($level === 'error') ? 'error_' : 'debug_';
        $file    = self::dir() . '/' . $prefix . date('Y-m-d') . '.log';

        file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
        self::prune($prefix);
    }

    private static function prune(string $prefix): void
    {
        // Run only ~1% of the time to avoid stat() overhead on every log call
        if (mt_rand(1, 100) !== 1) {
            return;
        }
        $cutoff = strtotime('-' . self::$maxDays . ' days');
        foreach (glob(self::dir() . '/' . $prefix . '*.log') ?: [] as $file) {
            if (filemtime($file) < $cutoff) {
                @unlink($file);
            }
        }
    }
}
