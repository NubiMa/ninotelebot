# Doujinshi BOT

This is a Telegram bot built with native PHP and uses `vlucas/phpdotenv` for environment handling.

---

## Requirements

1. **PHP** (v8.2.23 or higher)  
   [Download PHP](https://www.php.net/downloads.php)

2. **Composer**  
   [Download Composer](https://getcomposer.org/download/)

3. **Ngrok** (v3.18.4 or higher, optional for webhook mode)  
   [Download Ngrok](https://download.ngrok.com/windows)

---

## How to Run the Bot

1. Install dependencies using Composer:  
   ```bash
   composer install
   ```
   or 
   ```bash
   composer update
   ```
2. Copy the `.env.example` file and rename it to `.env`.

3. Open the `.env` file and update the following:
   - Set the `BOT_TOKEN` to your own Telegram bot token.
   - (Optional) Set `BOT_MODE` to `webhook` if you prefer to use the webhook mode instead of long polling.

4. Ensure PHP is installed and properly added to your system's PATH.

5. Start the bot by running: 
    ```bash
   php index.php
   ```
  
---

## How to Run in Webhook Mode (Locally Via Ngrok)

1. Start the php built-in server
   ```bash
   php -S localhost:3000
   ```

2. Start the Ngrok server
   ```bash
   ngrok.exe http 3000
   ```

3. Set the webhook URL using the "forwarding" URL from ngrok
   ```bash
   https://api.telegram.org/botBOT_TOKEN/setWebhook?url=forwarded_ngrok_url
   ```
     
---

## Notes

- Webhook Mode is real-time and reacts to code changes immediately.
- Long Polling Mode requires you to rerun the bot to apply code changes, but it is easier to set up.

I recommend using the default Long Polling Mode for simplicity.