# Vernuable Official Scripts

**Version:** `0.0.0`

Official offerwall scripts powered by [Vernuable](https://vernuable.my.id).

## BitcoTasks.com PTC Bot

HAR-accurate PHP bot (motion captcha, dynamic `ctoken`, multi-account).

### Install

```bash
git clone https://github.com/GLITCH083/vernuable-official-scripts.git
cd vernuable-official-scripts/scripts/offerwall
php extract.php
php bitcotasks.com.php
```

### Files

| File | Purpose |
|------|---------|
| `extract.php` | Decodes bot into `bitcotasks.com.php` |
| `bot.part1.b64` / `bot.part2.b64` | Compressed bot payload |
| `bitcotasks.com.php` | Main bot (after extract) |

### Requirements

- PHP 7.4+ (`curl`, `json`, `zlib`)
- Vernuable API key

### Public URL

https://github.com/GLITCH083/vernuable-official-scripts

**Legacy:** https://github.com/GLITCH083/bitcotask-bot
