---
title: Transaction types
description: Add your own fields to transactions in FundsFlow — liters and price for fuel, a store for groceries, a rating for a restaurant.
---

A **type** adds fields of your own to a transaction. A *Fuel* type can ask for **Liters**, **Price per liter** and a
**Station**, and work out the total for you. Types are optional: a transaction without one works as before.

New accounts start with four types you can change or archive:

- **⛽ Fuel** — Liters, Price per liter, Fuel (A-95, A-95+, Diesel, LPG), Station, and a Total that fills the amount;
  tagged *Transport*.
- **🛒 Groceries** — Store and Kind (Big shop, Quick buy).
- **🔄 Subscription** — Service, Period (Monthly, Yearly) and Next charge; tagged *Bills & subscriptions*.
- **🍽 Restaurant** — Place, Format (Cafe, Restaurant, Delivery) and Tip.

Accounts created before types appeared start with none — add your own in **Manage Types**.

## Manage types

Click the **Types** button in the header, next to Tags, or open **Settings → Data & reports → Manage Types** (on a
phone: **More → Types**). The list on the left shows each
type with its number of fields and transactions; **New type** starts an empty one.

Each type has:

- **Emoji**, **Name** (up to 64 characters, unique) and **Color**.
- **Fields** — up to 30. Use the arrows to reorder them, **Required** to make one compulsory, and 🗑 to remove one.
- **Default tags** — added when you pick the type for a new transaction. You can still remove them in the form.

The **Preview** on the right shows the form as it will look and an example message for the Telegram bot.

### Field kinds

| Kind | What you enter | Options |
| --- | --- | --- |
| **Number** | `40`, `58,90`, `1 500` | Unit (`l`, `km`, `₴`), min and max |
| **Rating** | 1 to 3, 5 or 10 | Scale |
| **One of** | One option | The options; new ones can be added while logging |
| **Several of** | Any number of options | The options |
| **Text** | Up to 500 characters | |
| **Date** | A date | |
| **Yes / No** | Yes or No | |
| **Duration** | `45m`, `1h 20m`, `1:20` | |
| **Computed** | Worked out for you | A formula and **Fill amount** |

A **Computed** field uses a formula over the type's number, rating, duration and yes/no fields: their keys
(shown under each field, like `key: liters`), numbers, `+ - * /` and parentheses — for example
`liters * price_per_liter`. Turn on **Fill amount** (one field per type) and the result becomes the transaction
amount.

### Renaming and removing fields

Renaming a field keeps its values: each field has a key that never changes. Removing a field hides it but keeps the
values already saved; it is listed under **Removed fields keep their old values**, and a click brings it back.
Hidden fields still count toward the 30.

### Archive

**Archive** takes a type out of the pickers and the assistant. Transactions that already have it keep the type and
their values, and can still be edited. Archived types are listed at the bottom of the list with **Restore**.

## Log a transaction with a type

In **Add Transaction**, pick a type under **Type**. Its fields appear below the amount and its default tags are
selected. When a computed field fills the amount, the amount follows it as you type — the hint below it shows how,
for example *From Fuel total · 40 l × 58,90 ₴*.

You can still type a different amount (a discount at the station, say). The hint then shows the computed total with
↺ to go back to it; changing a field also brings the computed amount back.

In the [List](/docs/web/tabs/#list), a transaction with a type shows it as the first chip in **Tags**, tinted with
the type color: *⛽ Fuel · 40 l · 58,90 ₴* (the first one or two values). The note stays as you wrote it; hover the
chip to see all fields. The same chip appears in **Largest expenses**, tag details, the Calendar day (name only) and
the assistant's recent list. Search also finds the type and any of its field values.

## With the assistants

The [web assistant](/docs/web/assistant/) and the [Telegram assistant](/docs/telegram/ai-assistant/) know your types.
Write `Fuel: 40 l at 58.9 OKKO` and the transaction is saved as *Fuel* with Liters 40, Price per liter 58,90, Station
OKKO, and the amount from the total.

They can also manage the types. Ask *create these types: Fuel (liters, price/l, fuel type A-95/A-95+/Diesel/LPG,
station, total computed), Groceries, Subscription, Restaurant* and one **Confirm** card lists every new type with its
emoji, name, fields and default tags. Nothing is created until you press **Confirm**. The same works for changes
(*add an Odometer field to Fuel*, *rename Station to Gas station*, *hide Fuel type*, *add A-100 to the options*) and
for archiving a type. A hidden field keeps its saved values. MCP clients can manage types and fill fields too — see the
[tool reference](/docs/ai/tools/#transaction-types).

Types and their fields are part of the **Backup** JSON (`transaction_types`, plus `transaction_type_id` and `data` on
each transaction). [Bank statement imports](/docs/web/import/#preview-page) suggest a type for known merchants, and you
can set one per row or for the whole file.
