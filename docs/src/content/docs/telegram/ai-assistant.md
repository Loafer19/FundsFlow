---
title: AI assistant
description: Ask the FundsFlow bot about your finances in plain language, draw charts, and make changes with confirmation.
---

Anything you send that isn't a command or a [quick add](/docs/telegram/quick-add/) and contains words is handled by the
AI assistant. Write in any language. The bot replies *Thinking…* and then answers or acts.

Examples:

- “show recent”, “show my budgets”, “menu”, “tags”
- “month summary”, “how much did I spend on coffee in September?”
- “what do you think about my finances?”
- “draw my spending by tag for last month”
- “create tags groceries, coffee”
- “rename tag Food to Eating out”
- “add -250 taxi yesterday”, “delete the last transaction”

The assistant keeps a short conversation context for about 20 minutes, so follow-up questions like “and last month?”
work. If something is ambiguous it asks a clarifying question, sometimes with suggested reply buttons.

:::tip[Also in the web app]
The same assistant is in the web app: click ✨ **Assistant** in the header. It streams answers, draws interactive
charts, and shares the same daily AI limit. See [AI assistant in the web app](/docs/web/assistant/).
:::

## What it can do

The assistant works by calling tools on your account.

**Read** (runs immediately):

| Tool | What it does |
| --- | --- |
| `get_me` | Your preferences (timezone, money format) and today's date |
| `list_tags` | All tags with emoji and parent |
| `list_recent_transactions` | Newest transactions (default 10, up to 20) |
| `list_transactions` | Transactions filtered by date range, tag, or note text (up to 50 rows) |
| `list_budgets` | Budgets with the current-period limit, spent amount, and progress |
| `list_recurring` | Recurring rules |
| `period_summary` | Income, expenses, net, and top expense tags for a month |

**Show** (sends the same cards as the menu buttons): `show_menu`, `show_help`, `show_recent`, `show_budgets`,
`show_tags`, `show_recurring`, `show_month`, `show_web`.

**Charts** — `send_chart` renders a PNG chart and sends it to the chat:

| Chart type | Description |
| --- | --- |
| `expenses_by_tag` | Spending by tag as a donut (default) or bar chart |
| `income_vs_expenses` | Grouped bars by day, week, or month |
| `balance_trend` | Line chart of your balance over time |

Periods: this month (default), last month, last 7 days, last 30 days, this year, or an explicit date range.

**Ask** — `ask_user` asks you a clarifying question.

**Write** (always needs your confirmation):

| Tool | What it proposes |
| --- | --- |
| `create_tags` | Create 1–10 tags |
| `rename_tags` | Rename existing tags |
| `create_transaction` | Add a transaction |
| `update_transaction` | Change a transaction |
| `delete_transaction` | Delete a transaction |
| `create_budget` | Create a weekly, monthly, or yearly budget for some tags |

:::caution[Changes need Confirm]
The assistant never changes your data silently. Every write shows a preview with **✅ Confirm** and **Cancel**;
nothing is saved until you press **Confirm**. Old previews stop working once handled or replaced.
:::

## Daily AI limit

AI features share one daily budget of **AI assists**:

- reading a receipt photo with AI,
- transcribing a voice message,
- each message handled by the AI assistant (in Telegram or in the web app's [Assistant](/docs/web/assistant/) panel).

The default limit is **5 assists per day** (configurable by the operator). The counter resets at **midnight in your
timezone**. When only a couple are left the bot warns you, and when you run out it tells you that the limit resets at
midnight and what still works without AI.

You can always see *AI assists today* (used / limit and reset time) in **Settings → Accounts** in the web app.

Quick add, commands, menu buttons, and receipts with an amount in the caption **don't** use AI and keep working when
the limit is reached.

## PDF reports in Telegram

The bot itself doesn't build PDF reports, but the web app can send one to your chat: **Settings → Report → Send to
Telegram**. See [Reports](/docs/web/reports/).
