---
title: Notifications
description: Budget alerts, weekly digest, and recurring notifications from the FundsFlow bot.
---

When your Telegram chat is linked, the bot can message you on its own:

| Notification | When |
| --- | --- |
| **💰 Budget alert** | Checked hourly. ⚠️ when a limit reaches **80%** for the current period, 🚨 at **100%**. Each threshold is sent once per period, even if you edit the limit during it. Savings goals are skipped. |
| **🎯 Savings goal reached** | Checked hourly. 🎉 once when a savings goal reaches its target (sent again only if you raise the target and reach it). |
| **📬 Weekly digest** | Mondays at 09:00 UTC: last week's income, expenses, net, top expense tag, and the change vs the week before, then your active limits and savings goals. Skipped if you had no transactions last week. |
| **🔁 Added automatically** | When [recurring rules](/docs/web/recurring/) create transactions (runs daily at 06:00 UTC). |

Use `/mute` to turn all of them off and `/unmute` to turn them back on. Muting doesn't affect replies to your own
messages.

## Budget alert format

Each line names the limit, what you spent against it, the percentage (rounded down, so 99.6% shows as 99%) and the
period it applies to:

```text
💰 Budget alert
⚠️ 🛒 Groceries: 7,300 / 9,000 (81%)
01.10.2026 – 31.10.2026
```

## Budgets in the weekly digest

After the week's totals the digest lists your active limits for their current period and your savings goals.
🟠 marks a limit at 80% or more, 🔴 one that is reached or over:

```text
💰 Limits
🛒 Groceries — 5,700 / 9,000 (63%) · 6 days left
🔴 ☕ Cafe — 1,650 / 1,500 (110%) · over by 150

🎯 Goals
Vacation — 17,000 / 50,000 (34%) · behind
```

The section is left out when you have no active limits or goals.
