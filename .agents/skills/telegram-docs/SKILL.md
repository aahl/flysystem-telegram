---
name: telegram-docs
description: Telegram API documentation and helper
allowed-tools: Read Grep Glob Search WebFetch
---

# Telegram Docs

## Ask context7
```bash
npx -y ctx7 docs /websites/core_telegram_api "How to ..."
```

## Ask deepwiki
```bash
npx -y mcporter call mcp.deepwiki.com/mcp.ask_question --timeout 120000 --args '{
  "repoName": "irazasyed/telegram-bot-sdk",
  "question": "How to ..."
}'
```
