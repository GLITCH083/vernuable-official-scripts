# Vernuable Official Scripts

**Version:** `0.0.0`

Official offerwall scripts powered by [Vernuable](https://vernuable.my.id) captcha solving.

## BitcoTasks.com PTC Bot

PHP multi-account bot for [bitcotasks.com](https://bitcotasks.com) offerwall:

- Motion captcha (captcha2) — HAR-accurate flow
- Dynamic field names (`ctoken`, validate field, success/token keys)
- Firewall → offerwall → lead claim
- Multi-account + proxy support
- Vernuable `bitcotask` solver

### Requirements

- PHP 7.4+ with `curl` and `json`
- Vernuable API key ([vernuable.my.id](https://vernuable.my.id))

### Install

```bash
git clone https://github.com/GLITCH083/vernuable-official-scripts.git
cd vernuable-official-scripts
php scripts/offerwall/bitcotasks.com.php
```

### Path

```
scripts/offerwall/bitcotasks.com.php
```

### Claim flow (summary)

1. Firewall captcha → validate → offerwall
2. Lead page → captcha → `start_view` → wait
3. `processLead` with dynamic `ctoken` field + captcha token

### Notes

- Keep your Vernuable balance topped up
- Use residential proxies when possible
- Do not share API keys or account credentials

### License

For educational / personal automation use. Respect site terms of service.

---

**Repo:** https://github.com/GLITCH083/vernuable-official-scripts  
**Legacy:** https://github.com/GLITCH083/bitcotask-bot (superseded)
