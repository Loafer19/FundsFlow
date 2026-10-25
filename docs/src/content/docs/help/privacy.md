---
title: Privacy and data
description: What FundsFlow stores, what is sent to AI providers, and how to export your data.
---

This page describes how FundsFlow handles your data, based on how the product works today.

## What FundsFlow stores

- **Account** — name, email, and a hashed password if you set one; linked Google, GitHub, and Telegram identities;
  preferences like timezone and formats.
- **Your finance data** — transactions, tags, budgets, recurring rules, and the files you attach (receipts and PDFs).
- **Telegram** — your chat id, to message you, and short-lived conversation state (pending drafts and the AI
  assistant's recent context, kept for minutes).

FundsFlow doesn't connect to banks and never asks for bank credentials.

## AI processing

When you use AI features in Telegram, the relevant content is sent to **xAI** for processing:

| Feature | What is sent |
| --- | --- |
| Receipt reading | The photo |
| Voice messages | The audio |
| AI assistant | Your message, recent conversation context, and the account data its tools look up to answer you |

Features that don't use AI — quick add, commands, menu buttons, the web app, and receipts with the amount in the
caption — don't send anything to an AI provider.

When **you** connect an AI client over [MCP](/docs/ai/mcp/), that client (Claude, ChatGPT, Cursor, etc.) receives the
data its tools request, under that provider's own terms.

## Sign-in providers

If you sign in with Google, GitHub, or Telegram, FundsFlow receives basic profile information from that provider to
create or link your account.

## Your control

- **Export**: **Settings → Data → Download JSON** (or `export_account` over MCP).
- **Delete data**: delete transactions, tags, budgets, recurring rules, and attachments at any time.
- **Telegram**: `/mute` stops proactive messages; `/unlink` disconnects the chat.
- **MCP**: revoke your personal token in **Settings → Accounts → MCP**.
- **Account deletion**: there's no self-service option yet — contact [@Loafer19](https://t.me/Loafer19).
