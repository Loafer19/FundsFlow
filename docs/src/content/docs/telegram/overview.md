---
title: Telegram bot
description: Use the FundsFlow Telegram bot to log transactions, scan receipts, send voice notes, and ask questions.
---

The FundsFlow bot is [@funds_flow_bot](https://t.me/funds_flow_bot). It lets you:

- log transactions with a short message — [quick add](/docs/telegram/quick-add/);
- attach [receipt photos and PDFs](/docs/telegram/receipts/), with AI reading the total when you don't type one;
- send [voice messages](/docs/telegram/voice/) instead of typing;
- ask questions and request changes in plain language — the [AI assistant](/docs/telegram/ai-assistant/);
- browse this month, recent transactions, tags, budgets, and recurring rules from the [reply menu and commands](/docs/telegram/commands/);
- receive [notifications](/docs/telegram/notifications/) about budgets, recurring payments, and a weekly digest.

## Start the bot

Open the bot and send `/start`.

- If the chat is **not linked** yet and you didn't come from a link, the bot creates a **new FundsFlow account** for this
  chat and replies with **✅ Account created**.
- If the chat is already linked, it replies *You're already linked to your FundsFlow account*.

Either way you get a short usage guide and the reply menu.

## Link Telegram to an existing account

If you already have a FundsFlow account on the website (email, Google, or GitHub):

1. On [fundsflow.fun](https://fundsflow.fun/) open **Settings → Accounts**.
2. Next to **Telegram**, click **Open Bot**. Telegram opens the bot with a one-time link code.
3. Press **Start**. The bot replies **✅ Linked to your FundsFlow account**.

Link codes are valid for **10 minutes**. If a link expired, generate a new one from **Settings → Accounts**.
You can also type `/start K7QM-2XPA` with the code by hand.

## Unlink

Send `/unlink`. This disconnects the chat; the FundsFlow account and its data stay on the website. If you later send
`/start` again from the same chat, the bot links you back to the account it originally created for that chat.

## Open the web app inside Telegram

Use the **Web UI** menu button next to the message field (or the **🌐 Web UI** reply button / `/app`). See
[Mini App](/docs/mini-app/).
