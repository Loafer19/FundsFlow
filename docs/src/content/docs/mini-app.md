---
title: Mini App
description: Open the FundsFlow web app inside Telegram.
---

The Mini App is the full FundsFlow web app running inside Telegram.

## Open it

- Tap the **Web UI** menu button next to the message field in the bot chat, or
- tap **🌐 Web UI** in the reply menu (or send `/app`) for a reminder of where the button is.

## Sign-in

- If this Telegram account is **linked** to FundsFlow, you're signed in automatically.
- If it isn't linked yet, the sign-in window opens. After you sign in (email, Google, GitHub, or a Telegram code),
  FundsFlow links this Telegram account to the account you signed in to, so next time it opens directly.

## Differences from the browser

The Mini App uses Telegram's native controls:

- The Telegram **Back** button closes the open dialog instead of closing the whole app.
- The Telegram **main button** (at the bottom, above the keyboard) submits the open form, e.g. **Save** in
  *Add Transaction*.
- Telegram asks for confirmation before closing while a form is open, so you don't lose a half-filled form.
- Haptic feedback on some actions.

A few things that need a regular browser are hidden in the Mini App:

- **Settings → Data → Download JSON** (export) and **Settings → Report → Download PDF**. Use **Send to Telegram** in
  the Report tab instead, or open [fundsflow.fun](https://fundsflow.fun/) in a browser.
