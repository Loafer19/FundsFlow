---
title: Tags
description: Organize FundsFlow transactions with emoji tags and nested categories.
---

Open **Tags** from the header to see, add, edit, and archive tags. New accounts start with a set of starter tags.

Each tag has:

- **Title**
- **Parent** — optional. Nest tags (for example *Fast Food* under *Food*). A parent includes its children everywhere:
  totals on the Tags and Analytics tabs, the tag filter, limits, savings goals, reports, and the Telegram bot. A
  transaction with several tags under one parent counts once for it.
- **Emoji** — chosen from a picker.
- **Show in Balances** — include the tag in **Balances Per Tag** on the [Analytics](/docs/web/tabs/#analytics) tab.

Archiving a tag uses hold-to-archive. A parent is archived with its children. Archived tags sit at the bottom
of the list with **Restore**; restoring a parent does not restore its children. Archived tags leave the picker,
the tag filter, and new transactions, but stay on records that already have them.

Tags can also be created from Telegram with
[`/newtag`](/docs/telegram/commands/#newtag) or by asking the [AI assistant](/docs/telegram/ai-assistant/).

A [transaction type](/docs/web/types/) can add default tags to every new transaction of that type.

## Filter by tag

Use **Filter tags** at the top of the page to show only some tags, or **Untagged** transactions, across all tabs.
Selecting a parent tag also shows transactions with its child tags.
