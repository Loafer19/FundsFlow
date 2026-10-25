---
title: Transactions
description: Add, edit, attach files to, and delete transactions in the FundsFlow web app.
---

## Add a transaction

Click **Add Transaction** (or the round **+** button):

- **Date** — **Today**, **Yesterday**, or **Other date** (opens a date picker).
- **Income / Expense** and **amount**. The amount field understands how people type numbers:
  `1 500,50`, `1,500.50`, `12.5`, and simple arithmetic like `120+35-5` (the result is previewed below the field).
  A leading `+` or `-` switches between income and expense.
- **Tags** — pick any number of tags.
- **Note** — optional, up to 255 characters.
- **Attachments** — optional images (JPEG, PNG, WebP) or PDFs, up to **5 files** of up to **8 MB** each.

## Edit a transaction

Click the edit button on a transaction in the [List](/docs/web/tabs/#list) tab or a calendar day. You can change all
fields, add or remove attachments, or delete the transaction.

## Attachments

Click an attachment to preview it. From the preview you can **Download** it or **Send to Telegram** (to your linked
chat).

## Delete

Delete buttons in FundsFlow are **hold-to-delete**: press and hold the 🗑 button until the progress bar fills. A
simple click does nothing, which prevents accidental deletions.

## Where transactions come from

Each transaction has a source, shown as an icon in the List tab: **web**, **Telegram**, **recurring** (created by a
[recurring rule](/docs/web/recurring/)), or **MCP** (created by an [AI assistant](/docs/ai/mcp/)).
