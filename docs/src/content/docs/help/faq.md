---
title: FAQ
description: Frequently asked questions about FundsFlow.
---

### Does FundsFlow connect to my bank?

No. You add transactions yourself — in the web app, through the Telegram bot (text, receipt photos, voice), through
recurring rules, the built-in [AI assistant](/docs/web/assistant/), or with your own AI client over
[MCP](/docs/ai/mcp/).

### Which currency does it use?

FundsFlow tracks plain amounts and doesn't convert currencies. Choose how numbers look in
**Settings → Formatting**.

### I signed up in Telegram. How do I use the website?

Send `/website` to the bot and enter the code on the site, or open the **Web UI** button in the bot. See
[Sign in](/docs/getting-started/sign-in/#telegram-code).

### Why does MCP OAuth ask for an email and password?

OAuth sign-in for AI clients uses FundsFlow email and password. If you signed up with Telegram, Google, or GitHub, set
them in **Settings → Accounts → Login credentials**, or use a [personal token](/docs/ai/connect-clients/#personal-token).

### Can I ask an AI about my finances in the web app?

Yes. Click ✨ **Assistant** in the header. It answers questions, draws charts, and proposes changes that you confirm.
See [AI assistant](/docs/web/assistant/).

### The bot or the web Assistant says I've used my AI assists for today.

The [AI limit](/docs/telegram/ai-assistant/#daily-ai-limit) is shared by receipt reading, voice messages, and the AI
assistant in Telegram and in the web app, and resets at midnight in your timezone. Meanwhile, quick add
(`-350 groceries`), receipts with the amount in the caption, commands, and menu buttons still work in Telegram, and the
**+** button and all tabs still work on the web.

### The receipt was read wrong.

Tap **✏️ Edit amount** before confirming, or **Cancel** and type the amount in the photo caption — a caption with an
amount is saved without AI. After saving, use the transaction card's buttons to fix tags or the amount.

### Can I export my data?

Yes. **Settings → Data → Download JSON** downloads everything: tags, transactions (with attachment metadata), budgets,
and recurring rules. AI clients can do the same with `export_account`.

### How do I stop notifications?

Send `/mute` to the bot. `/unmute` turns them back on.

### How do I delete my account?

There's no self-service account deletion yet. Contact [@Loafer19](https://t.me/Loafer19) on Telegram.

### Where do I send feedback or report a bug?

[@Loafer19](https://t.me/Loafer19) on Telegram (also linked in the web app footer).
