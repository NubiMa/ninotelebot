<?php

require __DIR__ . '/vendor/autoload.php';

use App\Database;
use App\TelegramBot;

// Load environment variables
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

date_default_timezone_set('Asia/Jakarta');

$bot = new TelegramBot();
$ownerId = $_ENV['OWNER_ID'] ?? 0;

if (!$ownerId) {
    die("Error: OWNER_ID is not set in .env\n");
}

echo "Starting Nino's reminder worker...\n";

while (true) {
    try {
        $db = Database::getConnection();
        
        $now = date('Y-m-d H:i:s');
        
        // Find due reminders that haven't been sent or cancelled
        $stmt = $db->prepare("SELECT id, message FROM reminders WHERE trigger_time <= :now AND is_sent = 0 AND is_cancelled = 0");
        $stmt->execute(['now' => $now]);
        $reminders = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($reminders as $reminder) {
            $msg = "⏰ <b>REMINDER:</b>\n" . htmlspecialchars($reminder['message']);
            
            $response = $bot->sendRequest('sendMessage', [
                'chat_id'    => $ownerId,
                'text'       => $msg,
                'parse_mode' => 'html'
            ]);

            if ($response && ($response['ok'] ?? false)) {
                // Mark as sent
                $updateStmt = $db->prepare("UPDATE reminders SET is_sent = 1 WHERE id = :id");
                $updateStmt->execute(['id' => $reminder['id']]);
                echo "[$now] Sent reminder ID {$reminder['id']}\n";
            } else {
                echo "[$now] Failed to send reminder ID {$reminder['id']}\n";
            }
        }

    } catch (Exception $e) {
        echo "Error checking reminders: " . $e->getMessage() . "\n";
    }

    // Wait exactly 60 seconds before checking again
    sleep(60);
}
