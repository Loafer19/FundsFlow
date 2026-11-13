---
title: Receipts, photos, and albums
description: Attach receipt photos and PDFs to transactions from Telegram, with optional AI reading of the total.
---

Send a photo or a file to the bot to create a transaction with that file attached. To add a file to a transaction you
already have, see [Attach to an existing transaction](#attach-to-an-existing-transaction).

- Supported files: **JPEG, PNG, WebP, HEIC, and PDF**. HEIC (iPhone photos sent as a file) is saved as JPEG.
- Each file can be up to **8 MB**; a transaction can have up to **5** attachments.
- **Word, CSV, Excel, OFX / QFX, QIF, JSON, and HTML** files, and PDF bank statements, are read too: one receipt
  becomes a draft card, and a list of transactions becomes one card that imports them all on **Confirm**. Up to 5
  files sent together become one import. See [Files and bulk import](/docs/web/import/).

## AI draft

Send a photo (JPEG, PNG, WebP or HEIC), with or without a caption. A caption helps: an amount in it (`350 groceries`,
`20.08 350 groceries`) wins over what the AI reads on the image, a date in it sets the day, and its words become the
note and tags. A hint like `dinner with friends` works too.

1. The bot replies *Reading receipt…* and AI vision extracts the **total**, the **date** if clearly visible, a short
   **note** (merchant or place), and up to three **suggested tags**, which are matched to your existing tags.
2. The *Reading receipt…* message turns into a **🧾 Receipt draft** card with **✅ Confirm**, **✏️ Edit**, and
   **↩️ Cancel**. **✏️ Edit** opens the same [editor](/docs/telegram/quick-add/#edit-a-transaction) as for saved
   transactions (amount, expense or income, date, tags, note); **Save** there updates the draft and **Discard** drops
   it. In a group the button is **✏️ Amount** instead.
3. On **Confirm** the transaction is saved with the photo attached, and the card shows **✅ Saved with receipt**
   with **↩️ Undo** for 5 minutes.

If the AI can't find a clear total (for example a photo of food), it asks you to send the amount, like `350`, or
choose **−100**, **−500**, **Other**, or **Cancel**.

A **PDF** is read by its text (a scanned PDF by its first page) and gets the same draft card, or a
bulk import card when it's a statement.

The bot falls back to the same amount buttons, without AI, when:

- your [daily AI limit](/docs/telegram/ai-assistant/#daily-ai-limit) is used up;
- the AI couldn't read the receipt.

A successful AI read counts as one AI assist.

## Albums (several photos at once)

When you send several photos as one album, the bot waits a few seconds to collect them and asks:
*Got N photos. Is this one receipt, separate receipts, or screenshots of a transaction list?*

- **One transaction** — all photos (up to 5) are attached to a single transaction. If the album caption has an amount,
  it is saved immediately; otherwise AI reads the best photo and shows a draft.
- **Separate** — each photo is processed as its own receipt, one after another (*Processing 1 of N…*).
  A photo the bot can't download is skipped with a message naming it (*Photo 2 couldn't be read and was skipped…*),
  and the next one is processed.
- **📋 Transaction list** — the photos are screenshots of your bank app's history (up to 10). They are read together
  in one AI assist and become one bulk import; rows shown on two overlapping screenshots are kept once. A caption like
  *statement* or *виписка* picks this right away, without the question.

## Attach to an existing transaction

- **+ Attach** — open the transaction in the `/recent` widget, tap **📎 Files** and then **+ Attach** (in a group, tap
  **📎 Attach** on its card). The bot
  asks *Send a photo or file to attach to …* with **↩️ Cancel**; send the photo, PDF or an album within 10 minutes.
  Sending text or a command instead cancels it.
- **Reply** — send the photo or file as a reply to a message about that transaction: its saved card or text card,
  a **✅ Attached** message, the attach prompt, an open editor, or the `/recent` widget while that transaction is open
  in it.

The bot answers **✅ Attached N files to …** with **📎 Files (N)**, and no new transaction is created. The usual limits
apply: JPEG, PNG, WebP, HEIC or PDF, up to 8 MB each, at most 5 files per transaction (**+ Attach** and **📎 Attach** are
hidden when a transaction has 5). In a group, the prompt waits only for the member who tapped **📎 Attach**; if the bot can't see
your photos there (privacy mode), send them as a reply to the prompt.

## Get the files back

Open the transaction in the `/recent` widget (📎 marks transactions with files) and tap **📎 Files (N)**: the files
are listed with their names and sizes, and **Send** next to one sends it to the chat (photos as photos, PDFs as
documents), and the red 🗑 next to it asks *Delete?* with **Yes** and **No** before removing that file. In a group, **📎 Files (N)** on the transaction card sends all of them. On the web, open the [transaction](/docs/web/transactions/) to preview, download or send them.

Pending receipts and drafts expire after about 15 minutes; send the photo again if a card says it is outdated.
