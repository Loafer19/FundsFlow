---
title: AI assistant
description: Ask about your finances in the web app's Assistant panel — streamed answers, charts, cards, and changes you confirm.
---

The web app has a built-in AI assistant: the same finance agent as the
[Telegram AI assistant](/docs/telegram/ai-assistant/), in a chat panel next to your charts.

## Open it

Sign in, then click the ✨ **Assistant** button in the header, next to ❓ **Docs**. A chat panel slides in from the
right (on a phone it takes the full screen). Close it with **✕** or by clicking outside the panel.

The button is only shown while you're signed in.

## Ask anything

Type a question and press **Enter** (**Shift+Enter** adds a new line). Write in any language — the assistant answers
in Ukrainian if you write in Ukrainian, otherwise in English.

An empty chat suggests a few prompts to start with:

- “How much did I spend this month?”
- “Chart of spending by tag this month”
- “Show my budgets”
- “Income vs expenses for the last 6 months”
- “Add -350 groceries today”

More ideas: “what do you think about my finances?”, “how much did I spend on coffee in September?”,
“покажи витрати по тегах за місяць”, “create tags groceries, coffee”, “rename tag Food to Eating out”,
“delete the last transaction”.

Answers **stream in** as they're written. While the assistant looks things up you see what it's doing
(*Reading tags*, *Building chart*, …). Replies can use light formatting — bold text, lists, inline code — and links
open in a new tab.

## What it can do

The assistant works by calling tools on your account — the same ones as in Telegram, except the Telegram-only menu,
help, and *open the web app* tools.

- **Look things up** — your preferences and today's date, tags, recent transactions, transactions filtered by date
  range, tag, or note text (up to 50), budgets with spent amount and progress, recurring rules, and a month summary
  (income, expenses, net, top expense tags). It uses real numbers from your account and never makes up amounts.
- **Show cards** in the chat — recent transactions, budgets, tags, recurring rules, and this month's summary.
- **Draw charts** — interactive charts drawn with the same chart components as the web app tabs:
  - spending by tag (donut, or a bar list),
  - income vs expenses (bars by day, week, or month),
  - balance trend (line).

  Periods: this month (default), last month, last 7 days, last 30 days, this year, or an explicit date range. Each
  chart also shows income, expenses, and net for the period.
- **Ask you** a clarifying question when something is missing, often with reply buttons — tap one or type your own
  answer.
- **Propose changes** — add, edit, or delete a transaction, create a budget, create tags, or rename tags.

## Changes need Confirm

The assistant never changes your data on its own. A proposed change appears as a card with a preview and
**Confirm** / **Cancel**:

- Nothing is saved until you press **Confirm**. The card then shows the result (for example
  *✅ Saved: -350.00 · 2026-10-25 · 🛒 Groceries*) and the web app refreshes its data.
- Transactions added this way are marked with the **Web** source.
- Only the newest proposal can be confirmed. A newer proposal marks the previous card as *outdated*, and each card
  works only once.

## Conversation, reset, and stop

- The chat keeps your conversation for **60 minutes after your last message**, so follow-ups like “and last month?”
  work. The assistant uses the last 12 messages as context. Reopening the panel (or the site on another device) shows
  the same conversation.
- The web chat is separate from your Telegram conversation.
- **New chat** (the ↺ button in the panel header) clears the conversation, including any proposal that's still
  waiting for Confirm.
- While an answer is streaming, the **send** button turns into **stop**. Stop ends the stream in the panel.
- The assistant answers one message at a time. You can send up to 20 messages per minute; a message can be up to
  2000 characters.

## Daily AI limit

The web assistant shares the [daily AI limit](/docs/telegram/ai-assistant/#daily-ai-limit) with Telegram (receipt
reading, voice messages, and the Telegram assistant): each message you send counts as one **AI assist**, however many
lookups it takes. The counter resets at **midnight in your timezone**.

The panel header shows how many assists are left today, for example **3/5 left** (hover it for the reset time). The
badge turns yellow when you're running low and red when you're out. When the limit is used up, the assistant replies:

> You've used all 5 AI assists for today. They reset at midnight (Europe/Kyiv). Until then, you can still add
> transactions with the + button and explore your tabs and analytics.

The number and timezone are yours. Everything else in the web app keeps working without AI. Your usage is also in
**Settings → Accounts → AI assists today**.
