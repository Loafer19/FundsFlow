---
title: Notifications
description: Budget alerts, weekly digest, recurring reminders, a pinned month summary and monobank notifications from the FundsFlow bot.
---

When your Telegram chat is linked, the bot can message you on its own:

| Notification | When |
| --- | --- |
| **💰 Budget alert** | Checked hourly. ⚠️ when a limit reaches **80%** for the current period, 🚨 at **100%**. Each threshold is sent once per period, even if you edit the limit during it. Savings goals are skipped. |
| **🎯 Savings goal reached** | Checked hourly. 🎉 once when a savings goal reaches its target (sent again only if you raise the target and reach it). |
| **📬 Weekly digest** | Mondays at 09:00 UTC: last week's income, expenses, net, top expense tag (top-level, child tags included), and the change vs the week before, then your active limits and savings goals. Skipped if you had no transactions last week. |
| **🔔 Recurring reminder** | The evening before a recurring payment, see [below](#recurring-reminders). |
| **🔁 Added automatically** | When [recurring rules](/docs/web/recurring/) create transactions (runs daily at 06:00 UTC). |
| **🏦 Monobank** | When a new purchase is imported from a connected monobank card. Includes buttons to change the tag or delete it. Turn these off in **Settings → Connections → Monobank** without muting the rest. |

## Switches

Send `/notifications` for three switches:

- **Alerts and digest** — budget alerts, goal messages, the weekly digest, and automatic recurring and bank sync
  messages. `/mute` and `/unmute` do the same.
- **Recurring reminders** — a message the day before each recurring payment. Off while alerts are muted.
- **Pinned month summary** — a live `/month` pinned at the top of the chat.

Muting doesn't affect replies to your own messages.

## Budget alert

In a private chat the alert is a widget with a progress bar for each limit: blue up to 100%, red when it's over,
with what you spent against the limit and how many days are left (or how much you're over). A **💰 Budgets** button
opens your budgets.

If the widget can't be shown, the bot sends the same alert as text. Each line names the limit, what you spent against
it, the percentage (rounded down, so 99.6% shows as 99%) and the period it applies to:

```text
💰 Budget alert
⚠️ 🛒 Groceries: 7,300 / 9,000 (81%)
01.10.2026 – 31.10.2026
```

**🎯 Savings goal reached** looks the same: a green full bar with what you saved against the target, and a
**💰 Budgets** button. As text it reads `🎉 Vacation: 20,500 / 20,000 (102%)`.

## Weekly digest

The digest is a widget too: last week's dates, **Income**, **Expenses** and **Net**, your top expense tag, the
number of transactions and how much more or less you spent than the week before. Below that are progress bars for
your active limits and savings goals. **📊 Month** and **💰 Budgets** buttons open those widgets.

If the widget can't be shown, the digest comes as text. After the week's totals it lists your active limits for their
current period and your savings goals. 🟠 marks a limit at 80% or more, 🔴 one that is reached or over:

```text
💰 Limits
🛒 Groceries — 5,700 / 9,000 (63%) · 6 days left
🔴 ☕ Cafe — 1,650 / 1,500 (110%) · over by 150

🎯 Goals
Vacation — 17,000 / 50,000 (34%) · behind
```

The section is left out when you have no active limits or goals.

## Recurring reminders

The day before a [recurring payment](/docs/web/recurring/), at about **18:00 in your timezone**, the bot sends:

```text
🔔 Tomorrow

-299.00 · Netflix
📅 12.11.2026 · monthly
🏷 🎬 Fun
```

- **⏭ Skip** — skip only this payment; the rule moves on to the next date.
- **⏸ Pause** — pause the rule; the message then offers **▶️ Resume**.

Daily rules don't get reminders. Turn them off with the **Recurring reminders**
switch in `/notifications`.

## Pinned month summary

Turn on **Pinned month summary** in `/notifications` and the bot sends the `/month` widget and pins it at the top of
the chat, without a notification sound. It updates by itself after every transaction, so this month's income,
expenses and top tags are always one tap away.

When a new month starts, the bot pins a fresh summary for it. The old one stays in the chat as a record of the
previous month and stops updating. Turning the switch off unpins it. Works only in a private chat with the bot.
