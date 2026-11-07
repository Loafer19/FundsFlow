---
title: FAQ
description: Answers to common questions about FundsFlow, and how to get help when something doesn't work.
---

Didn't find your answer? [Ask us](#how-do-i-report-a-problem-or-send-feedback): we reply in the app and in
Telegram.

### Does FundsFlow connect to my bank?

No. You add transactions yourself: in the web app, in the Telegram bot (text, receipt photos, voice), with
recurring rules, through the [AI assistant](/docs/web/assistant/), or by
[importing a bank statement](/docs/web/import/) or screenshots of your bank app.

### Which currency does it use?

FundsFlow tracks plain amounts and doesn't convert currencies. Choose how numbers look in
**Settings → Formatting**.

### I signed up in Telegram. How do I use the website?

Send `/website` to the bot and enter the code on the site, or open the **Web UI** button in the bot. See
[Sign in](/docs/getting-started/sign-in/#telegram-code).

### A recurring payment didn't show up today.

Recurring rules are processed once a day, at 06:00 UTC, so a payment due today can appear a few hours into the
day. Check that the rule isn't paused and that its start date has come in **Recurring** (🔁). See
[Recurring transactions](/docs/web/recurring/).

### My savings goal shows less than I saved.

A goal counts transactions with the goal's tags (and their child tags) since its start date, plus **Already saved**.
Transfers to savings are usually logged as expenses (−500 adds 500 to the goal), so put the goal's tag, or one of its
child tags, on each transfer and check that the transfer is dated on or after the goal's start date. See [How progress is counted](/docs/web/budgets/#how-progress-is-counted).

### The bot or the Assistant says I've used my AI requests for today.

The [AI limit](/docs/telegram/ai-assistant/#daily-ai-limit) is shared by receipt reading, voice messages and the
assistant in Telegram and on the web. It resets at midnight in your timezone. Until then, quick add
(`-350 groceries`), receipts with the amount in the caption, commands and menu buttons still work in Telegram, and
the **+** button and all tabs still work on the web.

### The receipt was read wrong.

Tap **✏️ Edit amount** before confirming, or **Cancel** and type the amount in the photo caption: a caption with an
amount is saved without AI. After saving, use the transaction card's buttons to fix the tags or the amount.

### Can I export my data?

Yes. **Settings → Data → Download JSON** downloads everything: tags, transactions, budgets and recurring rules.

### How do I stop notifications?

Send `/mute` to the bot. `/unmute` turns them back on.

### How do I delete my account?

There's no self-service account deletion yet. [Send a request](#how-do-i-report-a-problem-or-send-feedback) and
we'll delete it for you.

### How do I report a problem or send feedback?

- **In the web app:** click **Feedback** at the bottom of the page, write what happened, and **Send**. The app
  version and your browser are added for you.
- **In Telegram:** send `/support` with your message, for example `/support The PDF report doesn't open`. Or just
  tell the assistant that something doesn't work; it offers to send a request for you.

We reply in the web app (**Settings → Support**, where you can also answer) and, if your Telegram is linked, in the
bot. To add to an earlier request from Telegram, send `/support #12 your message` with the request number.
