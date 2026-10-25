---
title: Receipts, photos, and albums
description: Attach receipt photos and PDFs to transactions from Telegram, with optional AI reading of the total.
---

Send a photo or a file to the bot to create a transaction with that file attached.

- Supported files: **JPEG, PNG, WebP, and PDF**.
- Each file can be up to **8 MB**; a transaction can have up to **5** attachments.

## With an amount in the caption (instant, no AI)

Add a caption in [quick add](/docs/telegram/quick-add/) format, for example `-350 groceries` or
`20.08 -350 groceries`. The transaction is created immediately with the file attached. No AI is used and no
[AI quota](/docs/telegram/ai-assistant/#daily-ai-limit) is spent.

## Without an amount (AI draft)

Send a JPEG or PNG photo with no caption, or with a caption that is just a hint (e.g. `dinner with friends`):

1. The bot replies *Reading receipt…* and AI vision extracts the **total**, the **date** if clearly visible, a short
   **note** (merchant or place), and up to three **suggested tags**, which are matched to your existing tags.
2. You get a draft card with **✅ Confirm**, **✏️ Edit amount**, and **Cancel**.
3. On **Confirm** the transaction is saved with the photo attached.

If the AI can't find a clear total (for example a photo of food), it asks you to send the amount like `-350`, or
choose **−100**, **−500**, **Other**, or **Cancel**.

The bot falls back to the same amount buttons, without AI, when:

- the file is a PDF or WebP (AI reading works on JPEG and PNG only);
- your [daily AI limit](/docs/telegram/ai-assistant/#daily-ai-limit) is used up;
- the AI couldn't read the receipt.

A successful AI read counts as one AI assist.

## Albums (several photos at once)

When you send several photos as one album, the bot waits a few seconds to collect them and asks:
*Got N photos. Is this one receipt or separate transactions?*

- **One transaction** — all photos (up to 5) are attached to a single transaction. If the album caption has an amount,
  it is saved immediately; otherwise AI reads the best photo and shows a draft.
- **Separate** — each photo is processed as its own receipt, one after another (*Processing 1 of N…*).
  A photo the bot can't download is skipped with a message naming it (*Photo 2 couldn't be read and was skipped…*),
  and the next one is processed.

Pending receipts and drafts expire after about 15 minutes; send the photo again if a card says it is outdated.
