---
name: flysystem-docs
description: FlySystem documentation and helper
allowed-tools: Read Grep Glob Search WebFetch
---

# FlySystem Docs

## Ask context7
```bash
npx -y ctx7 docs /websites/flysystem_thephpleague "How to ..."
```

## Ask deepwiki
```bash
npx -y mcporter call mcp.deepwiki.com/mcp.ask_question --timeout 120000 --args '{
  "repoName": "thephpleague/flysystem",
  "question": "How to ..."
}'
```
