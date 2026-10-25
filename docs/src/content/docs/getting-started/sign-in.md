---
title: Sign in
description: Create a FundsFlow account and sign in with email and password, Google, GitHub, or a Telegram code.
---

Open [fundsflow.fun](https://fundsflow.fun/) and click **Login**. The sign-in window offers four options.

## Email and password

Use the **Login** / **Register** tabs. Registration asks for a name, an email, and a password of at least
8 characters.

## Google or GitHub

Click the Google or GitHub icon at the bottom of the sign-in window. You are sent to the provider and back to
FundsFlow, already signed in.

## Telegram code

If you use the [Telegram bot](https://t.me/funds_flow_bot), you can sign in to the website without a password:

1. In the sign-in window click the **Telegram** icon (*Log in with Telegram code*).
2. Open the bot and send `/website`.
3. The bot replies with a one-time code such as `K7QM-2XPA` and two buttons:
   - **Open FundsFlow** opens the website and signs you in automatically.
   - **Copy code** copies the code so you can paste it into the sign-in window.

Codes are 8 characters long (shown as two groups of four), valid for **10 minutes**, and work only once. They are
case-insensitive, and the dash is optional.

:::note
`/website` only works in a chat that is already linked to a FundsFlow account. Send `/start` first if you have never
used the bot — see [Telegram bot overview](/docs/telegram/overview/).
:::

## Starting from Telegram

Sending `/start` to the bot **without** a link creates a new FundsFlow account tied to that chat. Use `/website` to get
into the web app. To use email and password later (for example for [MCP OAuth](/docs/ai/connect-clients/)), set them
in **Settings → Accounts → Login credentials**.

## Linking more sign-in methods

Linked methods are listed in **Settings → Accounts**. To link Telegram from the website, use
**Settings → Accounts → Telegram → Open Bot** (see [Linking Telegram](/docs/telegram/overview/#link-telegram-to-an-existing-account)).
If you open the [Mini App](/docs/mini-app/) from a Telegram account that is not linked yet, signing in there links that
Telegram account to the FundsFlow account you sign in to.
