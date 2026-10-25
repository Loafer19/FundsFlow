---
title: MCP server
description: Connect AI assistants to your FundsFlow data with the Model Context Protocol.
---

FundsFlow has a remote [MCP](https://modelcontextprotocol.io/) server. Connect it to an AI assistant — Claude, ChatGPT,
Cursor, VS Code, Grok, and others — and the assistant can read and manage your FundsFlow data: “how much did I spend on
groceries last month?”, “add yesterday's receipts”, “create a monthly budget of 300 for coffee”.

| | |
| --- | --- |
| **Endpoint** | `https://mcp.fundsflow.fun/mcp` |
| **Transport** | Streamable HTTP |
| **Auth** | OAuth 2.1 with PKCE, or a personal bearer token |

## Two ways to authenticate

- **OAuth** (recommended where the client supports it) — the client opens a FundsFlow sign-in page, you log in with your
  FundsFlow **email and password**, and the client gets an access token valid for 30 days.
- **Personal token** — generate one in **Settings → Accounts → MCP** and paste it into the client.

See [Connect AI clients](/docs/ai/connect-clients/) for step-by-step setup.

:::note[Signed up with Telegram, Google, or GitHub?]
OAuth sign-in uses email and password. Set them first in **Settings → Accounts → Login credentials**, or use a
personal token instead.
:::

## What the assistant can do

The server exposes 30 tools: tags, transactions (including bulk operations of up to 50), attachments, budgets,
recurring rules, preferences, and a full JSON export. See the [tool reference](/docs/ai/tools/).

- Transactions created through MCP are marked with the **MCP** source in the web app.
- Bulk operations are all-or-nothing: if one item fails, none are saved.
- The assistant acts as you, with the same access as your account. Most clients ask for your approval before each tool
  call — keep that on for write tools.
