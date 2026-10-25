---
title: Notifications
description: Budget alerts, weekly digest, and recurring notifications from the FundsFlow bot.
---

When your Telegram chat is linked, the bot can message you on its own:

| Notification | When |
| --- | --- |
| **Budget alert** | Checked hourly. ⚠️ when a budget reaches **80%** of its limit for the current period, 🚨 at **100%**. Each threshold is sent once per budget period. |
| **📬 Weekly digest** | Mondays at 09:00 UTC: last week's income, expenses, net, top expense tag, and the change vs the week before. Skipped if you had no transactions last week. |
| **🔁 Added automatically** | When [recurring rules](/docs/web/recurring/) create transactions (runs daily at 06:00 UTC). |

Use `/mute` to turn all three off and `/unmute` to turn them back on. Muting doesn't affect replies to your own
messages.
