---
title: What's new in 2.0
description: Everything new in FundsFlow 2.0 — the AI assistant on the web and in Telegram, document import, receipt photos, themed PDF reports, charts, the admin panel, and security fixes.
---

FundsFlow 2.0 is the biggest update since the project started. It covers everything shipped after
**1.12.0** (Mini App, PDF report, Send to Telegram). The full history is on
[GitHub](https://github.com/Loafer19/FundsFlow/commits/main).

## Assistant

- **AI assistant in the web app.** Click ✨ **Assistant** in the header, or press **/** or **Ctrl+K** (**⌘K** on a
  Mac). Answers stream in as they're written, with Markdown, interactive charts, and cards.
  [Learn more](/docs/web/assistant/)
- **AI assistant in Telegram.** Write or say anything that isn't a quick add, and the bot answers with your real
  data. It uses a Grok (grok-4) tool-calling loop over FundsFlow's tools and asks a follow-up question when something
  is unclear. [Learn more](/docs/telegram/ai-assistant/)
- **Changes always wait for you.** Creating, editing, or deleting anything shows a **Confirm** card first. Buttons
  are single-use, so an old or repeated tap can't apply a change twice.
- **Shared memory.** The web chat and your private Telegram chat share one conversation, so you can start on the
  phone and continue on the laptop. **New chat** on the web or `/reset` (`/new`) in Telegram starts over.
- **More it can do:** budgets, recurring payments, period comparisons (“this month vs last month”), PDF reports, and
  answers about the tab you have open.
- **Web chat tools:** copy an answer, edit your last message, regenerate a reply, and smart auto-scroll. The data on
  the page refreshes after you confirm a change.
- **Telegram polish:** a typing indicator, streamed drafts in private chats, formatted replies, replies to your
  message (a quoted bot message is used as context), and long answers split into several messages.
- **Daily AI limit** shared by every AI feature. The assistant panel shows how many assists are left and when they
  reset; the count is also in **Settings → Accounts**.

## Imports

- **Send files to the assistant** on the web or in Telegram: PDF (text or scanned), Word (`.docx`), CSV/TSV, Excel
  (`.xlsx`), OpenDocument (`.ods`), JSON (including a FundsFlow export), OFX/QFX, QIF, saved HTML statement pages, and
  screenshots of a bank app.
- **Up to 5 files at once** become one import, shown as one Confirm card. A transaction that appears in two files or
  on two overlapping screenshots is imported once.
- **Say what to change** in the message: *only expenses*, *skip transfers*, *tag everything Travel*, *dates are month
  first*, and so on. The card lists what was applied.
- **Tags that don't exist yet:** the assistant asks whether to create them, map them to your tags, or skip them.
- OFX, QIF, FundsFlow JSON, and CSV or spreadsheets with recognised columns are read without AI.
  [Learn more](/docs/web/import/)

## Telegram

- **Receipt photos.** Send a photo without an amount in the caption, and the bot reads the total, date, note, and
  suggested tags into a draft for you to confirm. The web assistant reads receipt photos too. For an album, choose one transaction or a separate one per photo. Photos that can't be
  read are named and skipped. [Learn more](/docs/telegram/receipts/)
- **Voice messages** are transcribed and handled like text: a quick add, a question, or a change.
  [Learn more](/docs/telegram/voice/)
- **Chart images.** Ask for spending by tag, income vs expenses, or your balance trend, and the bot sends a PNG
  chart, optionally against the previous period.
- **Natural language** for menus and tags: “show my budgets”, “rename tag Cafe to Coffee”, “create tags Gym and
  Pool”, each with a preview and Confirm.
- **Mini App:** the back button closes the open form instead of the whole app, the main button saves the form,
  haptic feedback, a smarter amount field (`1 500,50`, `120+35`), and Today / Yesterday date chips.
  [Learn more](/docs/mini-app/)
- Sign-in codes from the bot are now alphanumeric (like `K7QM-2XPA`).
- Webhook updates are processed in the background, and each Telegram update is handled once even if Telegram sends
  it again.

## Reports & analytics

- **Themed PDF reports.** Reports match the app: your light or dark theme and the same charts as the tabs. They are
  rendered by a Chromium service (Gotenberg). Download them, send them to Telegram, or ask the assistant for one.
  [Learn more](/docs/web/reports/)
- **Previous-period comparison** in the Money Flow tab.
- Redesigned period bar and Calendar year view.

## Web app

- **Savings goals** on the **Budgets** tab: set a target, the tags you save with, a start date (today or earlier), an
  optional deadline, and how much you already have. Cards show saved vs target, what you need per month, and when you will reach it at your current
  pace. Also in Telegram (`/budgets`), the assistant, MCP, and the PDF report. [Learn more](/docs/web/budgets/)
- **Clearer limit cards:** On track / Near limit / Over status, what is left or over, a per-day allowance and the
  projected total at the current pace, a time marker on the spending bar, and compact collapsible history.
- **Limit start date** instead of the “Start from the beginning of this month” checkbox: pick any day up to today,
  and periods repeat from it (monthly from the 15th runs 15th → 14th). Also in the assistant and MCP.
- **Fix:** budget alerts, `/budgets` in Telegram, the assistant and the PDF report now count every limit transaction
  logged today (previously only the web app did).
- **Documentation** at [fundsflow.fun/docs](/docs/) (this site) and a new
  [landing page](https://fundsflow.fun/landing/).
- **Faster loading:** tabs and the emoji picker load on demand. The app and docs are served with precompressed
  Brotli/gzip files, ETags, and a strict, hash-based Content Security Policy.
- **MCP:** attach a file from a public `source_url` as well as base64, aligned tool schemas, and clearer errors.
  [Learn more](/docs/ai/tools/)

## Admin & security

- **Admin panel** (Filament) for the server owner: a dashboard with sign-up and transaction charts and system health,
  user management (AI limits, blocking, deletion), an audit log of admin actions, and two-factor authentication for
  admins.
- **Server settings:** admins can turn all AI features off, set the default daily AI limit, and close new
  registrations.
- **MCP OAuth hardening:** PKCE (S256 only) is required, and the personal token fallback only serves local clients.
- **Fixes:** a server file-read leak through the Telegram send-file endpoint was closed; recurring payments no longer
  drift (the 31st no longer becomes the 28th); edits to records with deleted tags work again.
- Proxy-aware HTTPS URLs, so the app works correctly behind a TLS proxy.
