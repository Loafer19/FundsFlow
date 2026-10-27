---
title: AI assistant
description: Ask the FundsFlow bot about your finances in plain language, draw charts, and make changes with confirmation.
---

Anything you send that isn't a command or a [quick add](/docs/telegram/quick-add/) and contains words is handled by the
AI assistant. Write in any language. While it works the chat shows *typing…*, and in your private chat the answer
appears as it's being written; then the finished answer arrives (in a group chat it comes in one go). Answers reply
to your message and can use simple formatting (bold, italic, code, bullet lists, links). A very long answer comes as
several messages.

Examples:

- “show recent”, “show my budgets”, “menu”, “tags”
- “month summary”, “how much did I spend on coffee in September?”
- “what do you think about my finances?”
- “draw my spending by tag for last month”
- “create tags groceries, coffee”
- “rename tag Food to Eating out”
- “add -250 taxi yesterday”, “delete the last transaction”
- “how is my groceries budget?”, “how much can I still spend per day?”, “pause my travel budget”
- “save 60 000 for a Japan trip by December”, “how is my emergency fund going?”
- “what charges are coming up this week?”, “add a monthly -15 Netflix subscription from the 5th”
- “compare this quarter with last quarter”, “send me a PDF report for last month”

If something is ambiguous it asks a clarifying question, sometimes with suggested reply buttons.

## Conversation memory and /reset

- In your private chat with the bot the assistant remembers the conversation for **60 minutes after the last
  message**, so follow-ups like “and last month?” work. It uses the last 12 messages as context.
- This memory is **shared with the [web assistant](/docs/web/assistant/)**: you can ask something in Telegram and
  continue in the web app, or the other way round. A proposal still waiting for **Confirm** can only be confirmed
  where it was shown; the other side knows about it and can propose it again.
- To ask about a specific bot message, **reply** to it: the assistant gets that message as context.
- `/reset` (or `/new`) starts a new conversation: it forgets the earlier messages (here and in the web assistant) and
  any question or preview still waiting in this chat. The web app's **New chat** clears the shared memory too.
- Quick add, menu views and receipts are noted in the memory as one line each (for example *Saved: -350.00 ₴
  groceries (Food)*), so you can ask “change the tag of the one I just added” here or in the web app.
- In a group chat the assistant doesn't use the shared memory; `/reset` there clears only that chat.

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
| `list_budgets` | Budgets with the current-period limit, spent amount, and progress, plus savings goals with saved / target |
| `list_recurring` | Recurring rules |
| `period_summary` | Income, expenses, net, and top expense tags for a month |
| `budget_status` | For each active budget (or one): spent, left, days left, per-day allowance, average daily spend, and the projected total at the current pace (*on track*, *at risk*, or *over*). For savings goals: saved, left, days to the deadline, the amount needed per week or month, and when you will reach it at the current pace (*on track*, *behind*, *completed*) |
| `upcoming_recurring` | Recurring charges and income due in the next N days (default 7, up to 90) with totals |
| `compare_periods` | Income, expenses, and net for a period vs the previous one, plus per-tag spending changes. If the current period isn't over yet, it also compares with the same number of days of the previous period |

**Show** (sends the same cards as the menu buttons): `show_menu`, `show_help`, `show_recent`, `show_budgets`,
`show_tags`, `show_recurring`, `show_month`, `show_web`.

**Charts** — `send_chart` renders a PNG chart and sends it to the chat:

| Chart type | Description |
| --- | --- |
| `expenses_by_tag` | Spending by tag as a donut (default) or bar chart |
| `income_vs_expenses` | Grouped bars by day, week, or month |
| `balance_trend` | Line chart of your balance over time |

Periods: this month (default), last month, this or last week, last 7 days, last 30 days, this or last quarter, this
or last year, or an explicit date range.

Ask for a comparison (“spending by tag this month vs last month”) and `expenses_by_tag` or `income_vs_expenses` is
drawn side by side with the previous period (the previous month, quarter, or year; for a custom range, the same number
of days just before it).

**Report** — `send_report` builds a PDF report and sends it to the chat as a document: income, expenses, and net vs the
previous period, spending and income by tag, your budgets, and the transaction list. Ask for any of the periods above
and, optionally, some tags (“PDF report for groceries and coffee this year”).

**Ask** — `ask_user` asks you a clarifying question.

**Write** (always needs your confirmation):

| Tool | What it proposes |
| --- | --- |
| `create_tags` | Create 1–10 tags |
| `rename_tags` | Rename existing tags |
| `create_transaction` | Add a transaction |
| `update_transaction` | Change a transaction |
| `delete_transaction` | Delete a transaction |
| `create_budget` | Create a weekly, monthly, or yearly budget for some tags, optionally from a start date (periods repeat from it: monthly from the 15th runs 15th → 14th), or a savings goal (target, tags, optional start date, deadline and amount already saved) |
| `update_budget` | Change a budget's amount, period, tags, title, or start date (a new start date moves the current period; other changes apply from today on the same schedule); for a goal — its target, tags, title, start date, deadline, or amount already saved |
| `pause_budget` / `resume_budget` | Pause or resume a budget |
| `delete_budget` | Delete a budget |
| `create_recurring` | Add a recurring transaction (daily, weekly, monthly, or yearly). If the start date is in the past, the preview tells you how many past transactions will be added |
| `update_recurring` | Change a recurring rule's amount, frequency, dates, note, or tags |
| `pause_recurring` / `resume_recurring` | Pause or resume a recurring rule |
| `delete_recurring` | Delete a recurring rule (transactions it already created stay) |

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

Savings goals are budgets too: ask “how is my emergency fund?” or “when will I reach my Japan trip goal?” and the
assistant answers from `budget_status`; “create a goal of 60 000 for 🗾 Japan trip by December, I started saving on
1 September” proposes a goal with that start date. See [Budgets](/docs/web/budgets/#create-a-savings-goal).

## PDF reports in Telegram

Ask the assistant: “send me a PDF report for last month” (or any period, optionally for some tags) and it sends the
PDF to the chat. The web app can send its own report too: **Settings → Report → Send to Telegram**. See
[Reports](/docs/web/reports/).
