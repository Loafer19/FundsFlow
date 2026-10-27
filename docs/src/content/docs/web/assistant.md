---
title: AI assistant
description: Ask about your finances in the web app's Assistant panel — streamed answers, charts, cards, receipt photos, and changes you confirm.
---

The web app has a built-in AI assistant: the same finance agent as the
[Telegram AI assistant](/docs/telegram/ai-assistant/), in a chat panel next to your charts.

## Open it

Sign in, then click the ✨ **Assistant** button in the header, next to ❓ **Docs**. A chat panel slides in from the
right (on a phone it takes the full screen). Close it with **✕**, **Esc**, or by clicking outside the panel.

Keyboard shortcuts: press **/** (when you're not typing in a field) or **Ctrl+K** (**⌘K** on a Mac) to open the panel
with the message box focused. **Esc** closes it and puts focus back where you were; **Tab** stays inside the panel
while it's open.

The button is only shown while you're signed in.

## Ask anything

Type a question and press **Enter** (**Shift+Enter** adds a new line). Write in any language — the assistant answers
in Ukrainian if you write in Ukrainian, otherwise in English.

An empty chat suggests a few prompts to start with. They depend on the tab you're on — for example *How are my
budgets doing?* on **Budgets** or *Compare this period with the previous one* on **Analytics**. Before a tab is
known it suggests:

- “How much did I spend this month?”
- “Chart of spending by tag this month”
- “Show my budgets”
- “Income vs expenses for the last 6 months”
- “Add -350 groceries today”

More ideas: “what do you think about my finances?”, “how much did I spend on coffee in September?”,
“покажи витрати по тегах за місяць”, “create tags groceries, coffee”, “rename tag Food to Eating out”,
“delete the last transaction”, “how much can I still spend per day?”, “what charges are coming up this week?”,
“compare this quarter with last”, “PDF report for this period”.

Answers **stream in** as they're written. While the assistant looks things up you see what it's doing
(*Reading tags*, *Building chart*, …). Replies can use light formatting — bold text, lists, inline code — and links
open in a new tab.

The chat follows new messages only while you're at the bottom of it. Scroll up to reread something and it stays put
while the answer streams in. A **↓ New messages** button appears above the message box instead (**Jump to latest**
when nothing new has arrived). Click it to scroll back down, and the chat follows new messages again. Sending a
message or opening the panel always jumps to the latest message. If your system asks for reduced motion, the jump is
instant instead of animated.

Under each answer:

- **Copy** copies the answer's text (with its Markdown formatting) and briefly shows *Copied*.
- **Regenerate** (on the last answer only) asks the same question again and replaces the answer. It counts as one
  AI assist, like a new message. It isn't offered when the answer proposed a change (a Confirm card) or for receipt
  photos.

Press **↑** in an empty message box to bring back your last message and edit it. Sending it replaces that message
and its answer, so the assistant doesn't see both versions. Press **Esc** to cancel. Only the last message can be
edited, and photos aren't sent again. If a change from that turn was already confirmed, or the message answered a
receipt question, the text is sent as a new message instead.

## What it can do

The assistant works by calling tools on your account — the same ones as in Telegram, except the Telegram-only menu,
help, and *open the web app* tools.

- **Look things up** — your preferences and today's date, tags, recent transactions, transactions filtered by date
  range, tag, or note text (up to 50), budgets with spent amount and progress, recurring rules, and a month summary
  (income, expenses, net, top expense tags). It uses real numbers from your account and never makes up amounts.
- **Show cards** in the chat — recent transactions, budgets, tags, recurring rules, and this month's summary.
- **Draw charts** — interactive charts drawn with the same chart components as the web app tabs:
  - spending by tag (donut, or a bar list),
  - income vs expenses (bars by day, week, or month),
  - balance trend (line).

  Periods: this month (default), last month, last 7 days, last 30 days, this year, or an explicit date range. Each
  chart also shows income, expenses, and net for the period.
- **Budget status** — for each budget: spent, left, days left, how much you can spend per day, and the projected total
  at your current pace (*On track*, *At risk*, or *Over*), as an answer or a card.
- **Upcoming charges** — recurring transactions due in the next days (7 by default, up to 90) with totals.
- **Compare periods** — income, expenses, and net vs the previous period, plus which tags changed most. Charts of
  spending by tag and income vs expenses can also be drawn side by side with the previous period. More periods are
  available: this or last week, this or last quarter, and last year.
- **Reports** — ask for a report and a card appears with **Download PDF**, **Open / Print** (opens the PDF in a new
  tab, where you can print it), and **Send to Telegram** (when Telegram is linked). Inside the Telegram mini app only
  **Send to Telegram** is shown.
- **Ask you** a clarifying question when something is missing, often with reply buttons — tap one or type your own
  answer.
- **Propose changes** — add, edit, or delete a transaction; create, edit, pause, resume, or delete a budget; create,
  edit, pause, resume, or delete a recurring rule; create tags, or rename tags.

Savings goals work the same way: the assistant can create, edit (including the start date), pause, resume, or delete
a goal, and tells you how much is left and when you will reach it. See [Budgets](/docs/web/budgets/).

## Receipt photos

Send a photo of a receipt and the assistant reads it into a transaction, like
[receipts in Telegram](/docs/telegram/receipts/):

- **Attach** photos with the 📎 button next to the message box (inside the Telegram mini app it opens your gallery or
  camera), **drag and drop** them onto the panel, or **paste** an image with **Ctrl+V** (**⌘V**).
- Up to **5 photos** per message, JPEG, PNG, WebP, or HEIC, up to 10 MB each. Before uploading, each photo is resized
  to at most 1600 px on its long side and converted to JPEG. HEIC works only where the browser can open it (Safari,
  for example). Otherwise, send that photo as JPEG.
- Thumbnails appear above the message box. Remove one with its **✕** before sending.
- The text you send with the photos works like a Telegram photo caption:
  - **With an amount** (`-350 groceries`, `12.10 -40 taxi`), the transaction is prepared from that, with no AI and no
    assist used.
  - **Without an amount** (`lunch with Anna`), the AI reads the photo and uses your text as a hint.
- The AI reads the total, the date, and the shop, and suggests tags matched to your existing ones. A **Confirm** card
  shows the result, for example *🧾 Receipt · -350.00 on 2026-10-25 · Note: Silpo · Tags: 🛒 Groceries · 📎 1 photo*.
  Nothing is saved until you press **Confirm**. The transaction is then saved with the photos attached, so they show
  in the transaction's attachments.
- **Several photos** get a question first: **One transaction** (one long receipt, or a receipt plus its card slip) or
  **Separate** (a transaction per photo).
  - **One transaction**: the clearest photo is read (JPEG first, then the largest) and every photo is attached.
  - **Separate**: each photo is read and one card lists all the transactions, which **Confirm** saves together.
    A photo that can't be read or has no clear total is skipped, and the assistant says so before the card, for
    example *Photo 2 couldn't be read and was skipped. Send it again with the amount, like -350 groceries.*
- **No clear total** (a food photo, a blurry receipt), or no AI assists left: the assistant asks for the amount, with
  **-100** / **-500** buttons. Type it (`-350`, or `-350 lunch`) or press **Cancel**.
- In the chat your message shows the photo thumbnails. After a page reload the conversation shows *📷 1 photo* in their
  place, because the chat history doesn't keep the images. The photos themselves are saved only on the transaction
  you confirm.
- Photos are uploaded privately and kept only until you confirm or cancel the card. If you don't, they're deleted when
  the conversation expires, or when you start a **New chat**.

## Files: PDF, Word, CSV, Excel, OFX, QIF, JSON

Attach a **PDF**, **Word** (`.docx`), **CSV**, **Excel** (`.xlsx`, `.ods`), **OFX / QFX**, **QIF**, **JSON**, or
**HTML** file with the 📎 button or drag it onto the panel: up to 5 files per message. A receipt becomes the usual
Confirm card. Statements and spreadsheets become one card that imports all their transactions at once, and what you
write with them (*only expenses*, *create the tags if needed*) is applied. Screenshots of a bank app's transaction
list work too: attach them and write *statement*. See [Files and bulk import](/docs/web/import/) for formats, limits,
and templates.

## It knows what you're looking at

With each message the panel tells the assistant which tab you're on, the period selected in the date picker, and your
tag filter. So “compare this with the previous period”, “report for this period”, or “what are these expenses?” use
the period and tags you see. If you name a period yourself, that wins. Only these settings are sent, not the screen
contents.

## Changes need Confirm

The assistant never changes your data on its own. A proposed change appears as a card with a preview and
**Confirm** / **Cancel**:

- Nothing is saved until you press **Confirm**. The card then shows the result (for example
  *✅ Saved: -350.00 · 2026-10-25 · 🛒 Groceries*). The open tab updates right away, without a page reload: the
  table and list, analytics, budgets, tags, and the calendar's upcoming charges.
- Transactions added this way, including from receipt photos, are marked with the **Web** source.
- Only the newest proposal can be confirmed. A newer proposal marks the previous card as *outdated*, and each card
  works only once.

## Conversation, reset, and stop

- The chat keeps your conversation for **60 minutes after your last message**, so follow-ups like “and last month?”
  work. The assistant uses the last 12 messages as context. Reopening the panel (or the site on another device) shows
  the same conversation.
- The assistant's memory is shared with your private chat with the [Telegram bot](/docs/telegram/ai-assistant/):
  it sees recent Telegram messages too, including quick adds and receipts saved there (the panel shows only what you
  wrote here), so you can start in Telegram and continue here. A proposal waiting for Confirm in Telegram can only be
  confirmed there; ask here and the assistant proposes it again.
- **New chat** (the ↺ button in the panel header) clears the conversation, including any proposal that's still
  waiting for Confirm, and the memory shared with Telegram. `/reset` in Telegram clears the shared memory too (the
  messages in this panel stay visible).
- While an answer is streaming, the **send** button turns into **stop**. Stop ends the stream in the panel.
- The assistant answers one message at a time. You can send up to 20 messages per minute; a message can be up to
  2000 characters, plus up to 5 photos.

## Daily AI limit

The web assistant shares the [daily AI limit](/docs/telegram/ai-assistant/#daily-ai-limit) with Telegram (receipt
reading, voice messages, and the Telegram assistant): each message you send counts as one **AI assist**, however many
lookups it takes. Receipt photos count the way they do in Telegram:

- one assist per photo the AI reads (one for **One transaction**, one per photo for **Separate**);
- nothing when the text has an amount or the AI couldn't read the photo.

The counter resets at **midnight in your timezone**.

The panel header shows how many assists are left today and when they reset, for example **3/5 · resets 00:00** (the
time is in your timezone and your device's 12/24-hour format). Hover it for a countdown, for example *Resets at
midnight (Europe/Kyiv), in 4h 12m*. The badge turns yellow when you're running low and red when you're out; at zero the
message box and the 📎 button are disabled and the box shows *AI assists reset in 4h 12m*. If you were already out when you sent a message, the
assistant replies:

> You've used all 5 AI assists for today. They reset at midnight (Europe/Kyiv). Until then, you can still add
> transactions with the + button and explore your tabs and analytics.

The number and timezone are yours. Everything else in the web app keeps working without AI. Your usage is also in
**Settings → Accounts → AI assists today**.
