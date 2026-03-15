# 🚀 DEPLOY.md — Deploying Nino Bot to Your VPS

This guide walks you through deploying the bot to your VPS (e.g. `n8n.nubi.my.id`) so Nino runs 24/7 and auto-restarts on crashes or reboots.

---

## Prerequisites (run these checks on your VPS via SSH)

```bash
# Check PHP version (must be 8.1+)
php -v

# Check required extensions
php -m | grep -E "sqlite|curl|pdo"

# If php-sqlite3 or php-curl are missing, install them:
sudo apt update
sudo apt install php-sqlite3 php-curl php-mbstring
```

---

## Step 1 — Upload Your Project

**Option A: Git (Recommended)**
```bash
cd /var/www
git clone https://your-github.com/your-repo/nino_bot.git nino_bot
```

**Option B: SCP from your Windows machine**
```powershell
# Run this from your local terminal (PowerShell):
scp -r D:\nino_tele_bot user@n8n.nubi.my.id:/var/www/nino_bot
```

> ⚠️ **Do NOT upload `.env` via Git!** Upload it separately (see Step 2).

---

## Step 2 — Upload & Configure `.env`

Upload your `.env` file to the server:
```bash
# From your Windows machine:
scp D:\nino_tele_bot\.env user@n8n.nubi.my.id:/var/www/nino_bot/.env
```

Then on the server, check it's correct:
```bash
cat /var/www/nino_bot/.env
```

---

## Step 3 — Install Composer Dependencies

```bash
cd /var/www/nino_bot
composer install --no-dev --optimize-autoloader
```

---

## Step 4 — Fix File Permissions

```bash
# Give the web server user write access to the database and log directories
sudo chown -R www-data:www-data /var/www/nino_bot
sudo chmod -R 755 /var/www/nino_bot
sudo chmod -R 775 /var/www/nino_bot/database
sudo chmod -R 775 /var/www/nino_bot/app/log
```

---

## Step 5 — Install Systemd Services

Copy the service files to the systemd directory:
```bash
sudo cp /var/www/nino_bot/nino-bot.service  /etc/systemd/system/
sudo cp /var/www/nino_bot/nino-cron.service /etc/systemd/system/
```

Reload systemd and enable the services:
```bash
sudo systemctl daemon-reload

# Enable = auto-start on reboot
sudo systemctl enable nino-bot
sudo systemctl enable nino-cron

# Start now
sudo systemctl start nino-bot
sudo systemctl start nino-cron
```

---

## Step 6 — Verify It's Running

```bash
# Check status
sudo systemctl status nino-bot
sudo systemctl status nino-cron

# Watch live logs
sudo journalctl -u nino-bot -f
sudo journalctl -u nino-cron -f
```

You should see `Active: active (running)` for both services.

---

## Useful Commands

| Command | Purpose |
|---|---|
| `sudo systemctl restart nino-bot` | Restart the bot (e.g. after updating code) |
| `sudo systemctl stop nino-bot` | Stop the bot |
| `sudo journalctl -u nino-bot --since "1 hour ago"` | View recent logs |
| `cat /var/www/nino_bot/app/log/debug.log` | View the bot's own debug log |

---

## Updating the Bot (when you make code changes)

```bash
cd /var/www/nino_bot
git pull                          # Pull latest code
composer install --no-dev         # Update dependencies if needed
sudo systemctl restart nino-bot   # Restart bot
sudo systemctl restart nino-cron  # Restart cron
```

---

## ✅ Done!

Nino is now running 24/7 on your VPS. She will:
- Auto-restart if she crashes.
- Auto-start on VPS reboot.
- Remember your entire conversation history, memories, reminders, and relationship state persistently in `database/database.sqlite`.
