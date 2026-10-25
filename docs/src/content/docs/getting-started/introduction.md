---
title: What is FundsFlow
description: An overview of FundsFlow, a personal finance tracker with a web app, a Telegram bot, and an MCP server.
---

FundsFlow is a personal finance tracker. You record income and expenses as **transactions**, organize them with
**tags**, keep spending in check with **budgets**, and plan rent, salary, or subscriptions as **recurring** rules.
Charts show where your money goes over a week, a month, or a year.

You can use FundsFlow from several places, all backed by the same account:

| Where | Best for |
| --- | --- |
| [Web app](/docs/web/overview/) at [fundsflow.fun](https://fundsflow.fun/) | Analytics, calendar, budgets, editing, reports, settings, and an [AI assistant](/docs/web/assistant/) panel |
| [Telegram bot](/docs/telegram/overview/) | Logging expenses on the go: text, receipt photos, voice notes, AI questions |
| [Mini App](/docs/mini-app/) | The web app opened inside Telegram |
| [MCP server](/docs/ai/mcp/) | Letting an AI assistant (Claude, ChatGPT, Cursor, VS Code, Grok) read and update your data |

## Core concepts

- **Transaction** — a date, a signed amount (negative is an expense, positive is income), optional tags, an optional
  note, and up to 5 file attachments (images or PDFs). Each transaction remembers where it came from: web, Telegram,
  recurring, or MCP.
- **Tag** — a category with an emoji. Tags can be nested under a parent tag (for example *Fast Food* under *Food*).
- **Budget** — a spending limit for one or more tags, repeated weekly, monthly, or yearly. Budgets can be paused and
  resumed, and keep a history of past periods.
- **Recurring rule** — a transaction that repeats daily, weekly, monthly, or yearly and is added automatically.

## Next steps

1. [Sign in](/docs/getting-started/sign-in/) with email, Google, GitHub, or Telegram.
2. Follow the [first steps](/docs/getting-started/first-steps/) to add a transaction and link Telegram.
