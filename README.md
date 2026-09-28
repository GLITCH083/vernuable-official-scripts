# Vernuable Official Scripts

**Version:** `0.0.0`

## Structure

```
bot.php                          ← MAIN launcher (run this)
version.json
functions/
  function.php                   ← colors, theme, configPath, saveData
  vernuable.php                  ← Vernuable API (balance + bitcotask solve)
scripts/
  offerwall/
    bitcotasks.com.php           ← BitcoTasks PTC bot
configs/                         ← auto-created (API key, accounts)
```

## Run

```bash
git clone https://github.com/GLITCH083/vernuable-official-scripts.git
cd vernuable-official-scripts
php bot.php
```

Then: **[1] Offerwall** → **bitcotasks.com.php**

## Requirements

- PHP 7.4+ with `curl`, `json`
- Vernuable API key (https://vernuable.my.id)
