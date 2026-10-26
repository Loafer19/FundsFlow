---
title: Privacy and data
description: What FundsFlow stores, what is sent to AI providers, and how to export your data.
---

This page describes how FundsFlow handles your data, based on how the product works today.

## What FundsFlow stores

- **Account** — name, email, and a hashed password if you set one; linked Google, GitHub, and Telegram identities;
  preferences like timezone and formats.
- **Your finance data** — transactions, tags, budgets, recurring rules, and the files you attach (receipts and PDFs).
- **Telegram** — your chat id, to message you, and short-lived conversation state (pending drafts, kept for minutes).
- **AI assistant conversation** — the recent messages shared by the web panel and your private Telegram chat, kept in
  the server cache for about an hour after your last message (by default) and cleared by **New chat** or `/reset`.

FundsFlow doesn't connect to banks and never asks for bank credentials.

## AI processing (xAI)

FundsFlow's AI features are powered by **xAI's Grok API**. When — and only when — you use one of them, on the web or
in Telegram, the content needed for that request is sent to xAI for processing:

| Feature | What is sent to xAI |
| --- | --- |
| AI assistant (web panel and Telegram) | Your message text, recent conversation context, and the account data its tools look up to answer you (transactions, tags, budgets, recurring payments) |
| Receipt reading | The receipt photo |
| Voice messages | The audio recording, to turn it into text |
| Document import | PDF, Word, and HTML text (HTML only when the page has no readable table); scanned PDFs and screenshots as images; for a spreadsheet with columns FundsFlow doesn't recognise, the header and a few sample rows; for an instruction that isn't a common phrase, your message plus a short summary of the import (file names, a sample of notes and dates, tag names) |

Some things never go to an AI provider:

- Quick add, commands, menu buttons, and the web app's tabs and forms.
- Receipts and PDFs with the amount in the caption (`-350 groceries`).
- OFX / QFX, QIF, and FundsFlow JSON files, and CSV, Excel (`.xlsx`), OpenDocument (`.ods`), and HTML tables whose
  columns are recognised — these are read on the FundsFlow server. Common import instructions (*only expenses*,
  *tag everything Travel*) are also applied without AI.

xAI processes this data under its own terms; see the
[xAI privacy policy](https://x.ai/legal/privacy-policy). Each AI request counts toward your
[daily AI limit](/docs/telegram/ai-assistant/#daily-ai-limit). If you don't want anything sent to xAI, don't use the AI
features: everything else in FundsFlow works without them.

The server administrator can turn all AI features off, or set your daily AI limit to zero, in the admin panel. When AI
is off, AI requests are refused and nothing is sent to xAI; manual entry, quick add, and the menus keep working.

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
