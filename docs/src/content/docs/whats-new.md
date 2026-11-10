---
title: What's new in 2.0
description: What you can do in FundsFlow 2.0 — an AI assistant on the web and in Telegram, imports from bank files and screenshots, receipt photos, savings goals, clearer budgets, and better reports.
---

FundsFlow 2.0 is the biggest update so far. It brings together everything added since version 1.12, including the
**1.13** update that put an AI assistant in the Telegram bot. Self-hosting and technical changes are listed in the
[GitHub releases](https://github.com/Loafer19/FundsFlow/releases).

## 2.2.0

- **Monobank sync.** Connect a personal token in Settings → Bank: cards sync automatically, including the last 31
  days, with auto-tagging and optional Telegram pings. UAH jars can be tracked as savings goals. Purchases you
  delete stay deleted, even after **Sync now**. A small bank icon in the List shows where a transaction came from.
  [Learn more](/docs/help/monobank/)
- **Import preview.** Click **Import** on the List tab (or send a file to the assistant) and a preview page opens
  before anything is saved. It finds duplicates and transfers between your own cards, lets you match the bank's
  categories to your tags, tag a shop in bulk or skip rows, and then import — or undo the whole batch later. The
  bank's file format is remembered for next time. The assistant opens that page; the Telegram bot
  sends a link instead of importing right away. [Learn more](/docs/web/import/)

## 2.1.0

- **Ask for help right from the app.** Click **Feedback** in the footer to report a problem or share an idea.
  Follow your requests and our replies in **Settings → Support**; replies also reach you in Telegram if it's linked.
- **Or from Telegram.** Send `/support` with your message, or just tell the assistant something isn't working.
- **See what's new after an update.** A short list of highlights appears once when the app updates, with a link to
  this page.
- **Answers to common questions.** A new [FAQ](/docs/help/faq/) covers sign-in, Telegram, imports, budgets and more.
- **Parent tags include their child tags everywhere.** A limit or savings goal on *Food* now also counts *Groceries*
  and *Restaurants* under it, so you don't have to pick every subtag. The same goes for the tag filter, the Tags
  tab, reports and the Telegram bot. A transaction is still counted only once.
  [Learn more](/docs/web/tags/)

## 2.0.1

- **See your totals under the List.** Below the table you now get income, expenses and net for the period, plus
  averages: spend per day, average expense, days with spending, and your busiest day. They follow the period, tag
  filter, picked day and search.
- **No more empty screens while loading.** Right after you sign in, a short “Loading your data…” shows until
  your numbers are ready.
- **A tidier footer** with the app version (opens this page), the Telegram bot and Feedback. It stays at the
  bottom of the screen on short pages.

## Assistant

- **Ask the AI assistant about your money on the web.** Click ✨ **Assistant** in the header, or press **/** or
  **Ctrl+K** (**⌘K** on a Mac). It answers with your real numbers, draws charts and shows cards.
  [Learn more](/docs/web/assistant/)
- **Talk to the bot in plain words.** In Telegram, write or say anything that isn't a quick add (“how much did I
  spend on food this month?”), and the bot answers. If something is unclear, it asks.
  [Learn more](/docs/telegram/ai-assistant/)
- **Nothing changes without your OK.** When the assistant wants to add, edit or delete something, you see a
  **Confirm** card first. Tapping an old button twice can't apply a change twice.
- **Start on your phone, finish on your laptop.** The web chat and your private Telegram chat share one
  conversation. **New chat** on the web or `/reset` in Telegram starts over.
- **It can do more:** set up and check budgets, manage recurring payments, compare periods (“this month vs last
  month”), make PDF reports, and answer questions about the tab you have open.
- **Handy chat tools on the web:** copy an answer, edit your last message, or ask again. The page refreshes after
  you confirm a change.
- **A smoother bot:** you see when it's typing, answers appear as they're written, and long answers are split into
  several messages. Reply to a bot message to ask about it.
- **A daily AI limit**, shared by all AI features. The assistant shows how many requests are left and when they
  reset; you can also see it in **Settings → Accounts**.
- **Better suggestions:** the example questions in the assistant fit your data, like budget questions once you
  have budgets.

## Imports

- **Bring in transactions from files.** Send a bank statement or a list to the assistant on the web or in
  Telegram: PDF (even scanned), Word, CSV, Excel, OpenDocument, OFX/QFX, QIF, a FundsFlow export, a saved bank web
  page, or screenshots of your bank app.
- **Up to 5 files at once**, checked together on one Confirm card. A transaction that shows up in two files or on two
  overlapping screenshots is added only once.
- **Say what you want** in the same message: *only expenses*, *skip transfers*, *tag everything Travel*, and so
  on. The card shows what was applied.
- **New tags?** If the file mentions tags you don't have, the assistant asks whether to create them, use your own
  tags, or skip them. [Learn more](/docs/web/import/)

## Telegram

- **Snap a receipt.** Send a photo without an amount, and the bot reads the total, date, note and tags into a
  draft for you to confirm. Send an album to save it as one transaction or one per photo. The web assistant reads
  receipts too. [Learn more](/docs/telegram/receipts/)
- **Send a voice message** instead of typing: add a transaction, ask a question or make a change.
  [Learn more](/docs/telegram/voice/)
- **Charts in the chat.** Ask for spending by tag, income vs expenses, or your balance over time, and the bot sends
  a picture, optionally next to the previous period.
- **Manage tags in words:** “rename tag Cafe to Coffee”, “create tags Gym and Pool”, each with a preview first.
- **Mini App:** the back button closes the open form, the main button saves it, the amount field understands
  `1 500,50` and `120+35`, and Today / Yesterday buttons pick the date. [Learn more](/docs/mini-app/)
- **Clearer budgets in the bot:** `/budgets` shows each limit's dates, what's left or over and days left, and how
  each savings goal is going. Budget alerts come once per period, even if you edit the limit.
  [Learn more](/docs/telegram/commands/)
- **The weekly digest** now ends with your limits and savings goals. [Learn more](/docs/telegram/notifications/)

## Reports & analytics

- **Good-looking PDF reports** in your app theme with the same charts as the tabs. Download them, send them to
  Telegram, or ask the assistant for one. [Learn more](/docs/web/reports/)
- **A richer Analytics tab:** income, expenses, net, savings rate, number of transactions and average daily
  spend, each compared with the previous period; a forecast for the end of the period; tips like your priciest
  weekday and no-spend days; your top tags and largest expenses. [Learn more](/docs/web/tabs/#analytics)
- **Tag details:** click a tag to see its total, transactions, the last 12 months, subtags and the biggest
  expenses. [Learn more](/docs/web/tabs/#tags)
- **Flow:** totals and averages per day, week or month; click a bar to open those transactions in the List.
  [Learn more](/docs/web/tabs/#flow)
- **Trend:** your net change, average daily change, lowest and highest points, and a forecast.
- **Calendar:** totals compared with the previous period and a forecast that includes upcoming recurring payments.
  Click a month in the year view to open it.

## Web app

- **Savings goals** on the **Budgets** tab: set a target, the tags you save with, an optional deadline and how
  much you already have. See how much you need per month and when you'll get there.
  [Learn more](/docs/web/budgets/)
- **Clearer spending limits:** On track / Near limit / Over, what's left, how much you can spend per day, and
  where you'll end up at your current pace.
- **Start a limit on any day:** a monthly limit from the 15th runs from the 15th to the 14th.
- **Editing a limit keeps the period:** a new amount counts everything you've spent since the period started.
- **Limit history:** one row per period, so you can see how each month went.
- **Easier tag picking:** subtags fold under their parent, and you can search when you have many tags.
- **Forms that look alike:** transactions, tags and recurring payments share one layout, and signing in shows clear
  Google, GitHub and Telegram buttons.
- **Help pages** at [fundsflow.fun/docs](/docs/) (you're here) and a new
  [landing page](https://fundsflow.fun/landing/).
- **Faster:** tabs load when you open them, and pages are smaller.
- **Fixes:** budget alerts, the bot, the assistant and reports now count everything you log today; recurring
  payments on the 31st no longer move to the 28th; editing records with deleted tags works again. Plus security
  fixes.
