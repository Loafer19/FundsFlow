---
title: Files and bulk import
description: Send statements, spreadsheets, OFX / QIF / JSON exports, bank-app screenshots, or a receipt to the assistant — or upload them on the Import page — then review a preview before anything is saved.
---

If you use monobank, [connect it in Settings → Connections → Monobank](/docs/help/monobank/) instead of uploading statements: new purchases arrive on their own.

The assistant reads files as well as receipt photos, in the [web Assistant panel](/docs/web/assistant/) and in the
[Telegram bot](/docs/telegram/receipts/). You can also upload a file on the dedicated **Import** page (`/import`).
A file is either one receipt or a list of transactions (a bank statement, a spreadsheet, an export from another app).
A list is staged as an **import preview**: you see every row, totals, duplicates and transfers *before* anything is
saved. The assistant opens that page (and the Telegram bot sends a link to it). Nothing is written until you press
**Import** there. You can send **up to 5 files together**. They become one import, and a transaction that appears in
two files is imported once.

## Preview page

After a file is read you land on `/import/{id}`:

- A summary: how many rows, the date range, expenses and income, and totals by tag.
- **Bank categories**: each category from the file, with a default (map to a matching tag, or ignore). You can map it to one of your tags, create it as a new tag (and rename it first), or leave it ignored.
- A table you can filter (new, duplicate, transfer, skipped, needs a tag, error) and page through.
- Inline tag edits and bulk rules such as *all Glovo → Food* or *skip before 2025*. A tag you set on one row is kept when you later apply a bulk rule.
- A **Type** column when you have [transaction types](/docs/web/types/). Expenses at a known merchant get a suggested
  type (✨): OKKO or WOG → *Fuel*, Silpo or ATB → *Groceries*, Netflix or Spotify → *Subscription*, Glovo or a café →
  *Restaurant*, and a type whose name is in the note. Change it on any row, or use **Set type** in the bulk rules
  (*OKKO → Fuel*, or leave the text empty for every row). A type you pick on one row is kept when you apply rules.
  Imported rows get the type and its default tags; fill in the field values later in **Edit Transaction**.
- **Import** saves the new rows (large files run in the background and show progress). New tags from “create” decisions are added then.
- **Undo import** deletes every transaction created by that batch, and any new tags it created that you are not using elsewhere.

You can refine the same preview in the assistant chat (“Glovo is Food, skip transfers to Olya”) — the page refreshes.
Saved column mappings (the header signature of each bank export) mean the next file from the same bank usually needs
no AI, and your category choices for that bank are remembered too. The app is single-currency: non-UAH rows are skipped unless you choose to include them. Rows that look like
transfers, or that match an existing transaction (same date, amount and a similar description, including monobank
sync), are held back.

**On Telegram**, sending a statement gets a short summary and the preview link. The bot will not import without that
review step.

## Supported files

| Format | What happens | Limit |
| --- | --- | --- |
| **PDF** with text (an e-receipt, a bank statement) | The text is read. One purchase becomes a receipt card with the PDF attached. A statement becomes a bulk import. | 8 MB, first 20 pages |
| **Scanned PDF** (just an image) | The first page is read like a receipt photo. | 8 MB |
| **Word** (`.docx`) | The text is read, like a PDF. The file itself isn't attached. | 5 MB |
| **CSV** (`.csv`, `.tsv`, `.txt`) | Rows are read directly, without AI. | 5 MB, 1000 rows |
| **Excel** (`.xlsx`), **OpenDocument** (`.ods`) | The first sheet is read, like a CSV. | 5 MB, 1000 rows |
| **OFX / QFX** (`.ofx`, `.qfx`) | The bank export format (old SGML and new XML both work). Read without AI; the bank's transaction IDs prevent doubles. | 5 MB, 1000 rows |
| **QIF** (`.qif`) | Quicken / old money-app export. Read without AI; the category becomes the tag (`Food:Groceries` → *Groceries*). | 5 MB, 1000 rows |
| **JSON** (`.json`) | A FundsFlow export (Settings → **Download JSON**) is read as is, tags included. Any other list of objects with a date and an amount is read like a CSV. | 5 MB, 1000 rows |
| **HTML** (`.html`, `.htm`) | A saved statement page. The table with dates and amounts is read like a CSV; a page without such a table is read by the AI, like a PDF. | 5 MB |
| **Screenshots** of a bank app's transaction list | All screenshots are read together; transactions that show on two overlapping screenshots are kept once. See [below](#screenshots-of-a-bank-app). | up to 10 in Telegram, 5 on the web |

Old or macro formats are refused with a hint: save `.xls`, `.xlsm`, or `.xlsb` as `.xlsx` or `.csv`, and `.doc`,
`.docm`, or `.rtf` as `.docx` or PDF. Macros are never run; files with macros are refused. A `.txt` file that is
really OFX, QIF, JSON, or HTML is recognised by its contents.

**On the web**, attach files with the 📎 button or drag them onto the panel. Each file shows as a chip with its name
and size above the message box; remove one with **✕**. Up to **5 files** per message, **20 MB** together, and not
together with photos. **In Telegram**, send them as files (documents). Up to 5 files sent together (one album) become
one import; files sent separately are read one by one.

A PDF receipt with an amount in the text (`-350 groceries`) is saved right away with the PDF attached, as before, and
uses no AI.

## Say what to change

What you write with the files is applied to the import, and the card lists it under **📝 From your message**. For
example:

- *only expenses*, *only income*, *only USD*
- *skip the first line*, *skip Rent and transfers between my cards*, *only October*
- *expenses are positive* or *flip the signs*
- *dates are month first* (or *day first*) when a file uses dates like `03/04/2026`
- *tag everything Travel*, *put Uber and Bolt under Transport*
- *create the tags if needed*, *without tags*, *Cafe → Food*, *don't suggest tags*

Ukrainian and Russian work too (*лише витрати*, *створи відповідні теги при потребі*, *спочатку місяць*). Common
phrases like these are understood without AI. Anything else uses **one AI assist**, or none if the import already
used one (a PDF statement or screenshots). A word or two (*October*, *Monobank card*) is treated as a label, not an
instruction, and costs nothing. If something can't be applied, the card says so.

## Bank categories

If the file has a category column (or QIF categories), the preview lists each name under **Bank categories**. A name
that matches one of your tags — or a common Ukrainian / Russian equivalent, such as *Продукти* → Groceries or
*Кафе та ресторани* → Restaurants — is mapped automatically. Anything else is ignored, so those rows stay untagged
unless a merchant rule (for example *all Glovo → Food*) applies.

You can change any category: map it to an existing tag, create it as a new tag (you can type a different name), or
ignore it. New tags are created only when you press **Import**, and they are removed again if you undo the import and
nothing else uses them. A tag you set on a single row is not overwritten by later category or merchant rules.

Tags the AI only guessed (from a PDF or screenshots) are never created on their own: they're kept if you have them.
Rows that have no tags get **suggestions from your history**: when you've saved the same note before (*Silpo*), the
tags you used most for it are added. These show with `~` in the preview. Turn this off with *don't suggest tags*.

## Screenshots of a bank app

Send screenshots of the transaction list from your bank app:

- **On the web**, attach up to 5 screenshots and write *statement* (or *transactions*, *виписка*, *список*).
- **In Telegram**, send up to 10 as one album. Write *statement* with them, or press **📋 Transaction list** when the
  bot asks *One transaction / Separate / Transaction list*.
- A single screenshot that shows a list (not a receipt) is recognised on its own.

All screenshots are read in **one AI assist**. When screenshots overlap, the rows they share are counted once. Rows
without a visible date are skipped and listed. The screenshots themselves aren't saved.

## Bulk import preview

The Confirm card shows:

- how many transactions, their date range, and which files they came from;
- total income and expenses;
- the first 10 rows (*…and N more*), with 🆕 on tags that will be created and `~` on suggested tags;
- what your message changed, and how tags were mapped;
- warnings: rows that were skipped and why, rows in more than one file (imported once), rows that are **already
  saved** (same date, amount, and note), more than one currency (*only USD* keeps one), ambiguous dates, or a file
  where every amount is positive (*flip the signs* fixes it).

Rows that are already saved are skipped, so sending the same file twice doesn't add anything twice. Nothing is saved
until you press **Confirm**. Then all rows (and new tags) are saved together: either all of them or, if something
fails, none. Transactions get the **Web** or **Telegram** source, like other assistant changes. Amounts are saved as
they are, with no currency conversion.

## CSV and Excel layout

Download a template: [CSV](/docs/fundsflow-import-template.csv), [JSON](/docs/fundsflow-import-template.json),
[OFX](/docs/fundsflow-import-template.ofx), or [QIF](/docs/fundsflow-import-template.qif).

```csv
date,amount,note,tags
2026-10-01,-350.50,Silpo,Groceries
2026-10-02,-120,Coffee with Anna,Coffee
2026-10-05,25000,Salary,
2026-10-07,-899.99,Netflix and Spotify,Subscriptions|Entertainment
```

The same in JSON (any list of objects works; the key names follow the CSV rules below):

```json
{
  "transactions": [
    { "date": "2026-10-01", "amount": -350.50, "note": "Silpo", "tags": ["Groceries"] },
    { "date": "2026-10-05", "amount": 25000, "note": "Salary" }
  ]
}
```

- **Required:** a date column and an amount column. Expenses are negative and income is positive. Instead of one
  amount you can have two columns, such as *Debit* / *Credit*, or *Expense* / *Income*. A *Type* column with
  *debit* / *credit* or *expense* / *income* sets the sign too.
- **Optional:** a note (description, merchant, purpose) and tags. Separate several tags with `|`, `,`, or `;`.
- **Headers** can be in English, Ukrainian, or Russian, and the header row doesn't have to be the first one. Bank
  exports such as Monobank or PrivatBank usually work as they are. If the columns can't be recognised, the AI looks at
  the header and 3 sample rows to work them out.
- **Separators:** `,`, `;`, tab, or `|`. Decimal comma (`-350,50`), spaces or apostrophes in thousands, and amounts in
  parentheses `(350.00)` all work. UTF-8 and Windows-1251 files work.
- **Dates:** `2026-10-01`, `01.10.2026`, `01/10/2026`, `20261001`, `1 Oct 2026`, or real Excel dates. When a file only
  has dates like `03/04/2026` that could be either way, the AI decides from the file if it's reading it anyway;
  otherwise your date format in Settings is used and the card warns you. Write *dates are month first* (or *day
  first*) to set it. QIF files are read month first unless the dates show otherwise.
- Up to 1000 rows per import, from all files together. A longer import gets a warning; split it into shorter periods.

## AI assists

- CSV, Excel, OFX, QIF, JSON, or an HTML table whose columns are recognised: **free**, no AI assist used.
- A spreadsheet whose columns need the AI, any PDF or Word file, or an HTML page without a table: **one AI assist per
  file**.
- Screenshots: **one AI assist** for all of them.
- Your message with the files: free for common phrases or when the import already used an assist, otherwise one
  assist. Replies to the tags question work the same way.
- An assist is counted only when the AI actually read something. When none are left, a PDF receipt asks for the
  amount instead (the same as a photo), and files with recognised columns still work.

Very long PDF statements may not fit in one AI read. If rows are missing from the preview, export the statement as CSV,
Excel, or OFX instead.

## Privacy

Files are stored privately like receipt photos and deleted once they're read, or when the conversation expires. Only a
PDF receipt sent on its own is kept, attached to its transaction. The text inside a file is treated as data:
instructions written in a document are ignored. Only your own message can change the import.
