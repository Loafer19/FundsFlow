---
title: Monobank sync
description: Connect your monobank cards with a personal token so new transactions appear in FundsFlow automatically, and track jars as savings goals.
---

FundsFlow can import transactions from your monobank cards. You paste a personal token from
[api.monobank.ua](https://api.monobank.ua/); FundsFlow never asks for your monobank password.

## How to connect

1. In the web app, open **Settings → Bank**.
2. Open [api.monobank.ua](https://api.monobank.ua/) and sign in with the monobank app or Diia.
3. Copy the personal token and paste it in FundsFlow, then click **Connect**.

FundsFlow checks the token, lists your cards, and starts an import of the last 31 days. After that, new
purchases arrive through a webhook: monobank sends each new item to FundsFlow as it happens.

Only **UAH** cards are synced. A dollar or euro card stays in the list so you can see it, but it is left off.

## How it works

- Transfers between your own cards and jars are not counted as income or expenses.
- Only UAH cards are synced. Foreign-currency cards are listed but left off.
- Pending (hold) purchases appear immediately; the amount updates when they settle.
- The last 31 days are imported when you connect. New purchases arrive instantly after that.
- Auto-tagging learns from the tags you change on imported purchases.
- Telegram notifications are sent only for new purchases, not the first import or **Sync now**.
- Your token is stored encrypted. You can revoke it any time at [api.monobank.ua](https://api.monobank.ua/).
- UAH jars can be tracked as savings goals: progress follows the jar balance in monobank.

## Cards and tags

Each card can be turned on or off. You can also attach an extra tag to a card (for example *Black card*),
so every import from that card gets that tag as well as a category tag when FundsFlow can guess one.

FundsFlow guesses a category from the merchant name if you have tagged that merchant before, or from the
MCC code when you still have a matching default tag (such as *Groceries* or *Fuel*). If it is unsure, the
transaction is left untagged. Changing the tag on an imported transaction teaches FundsFlow for next time.

## Transfers between your own cards

A transfer from one of your monobank cards to another (or to one of your jars) is skipped. It is not
income and not an expense, so it does not change your totals. Top-ups from a card into a jar are treated
the same way.

## Jars as savings goals

Jars are not imported as accounts or as transactions. Instead, each UAH jar in **Settings → Bank** has a
**Track as goal** toggle (off until you turn it on). Turning it on creates a savings goal with the jar's
title and, if monobank has one, the jar's target. Progress is the jar's current balance from monobank,
not tagged contributions. You can still set a deadline on the goal; required-per-week or per-month and
the projected date use that balance.

The goal card shows a **Mono jar** badge. If you delete the jar in monobank, or disconnect the bank,
the goal is kept and marked **Unlinked jar**, using the last known balance. Turning **Track as goal** off
keeps the goal as a regular one, seeded with the last jar balance.

Jar balances refresh when you connect, when you click **Sync now**, after new monobank activity, and about
once an hour (monobank allows one client-info request per minute).

## Telegram

When a new purchase is imported, the bot can send a short message with the merchant, the amount and the
tag, and buttons to change the tag or delete it. Turn **Notify in Telegram** off in **Settings → Bank**
to keep the sync and hide those messages. `/mute` also silences them, along with the other bot
notifications.

The first 31-day import and **Sync now** do not send a message for every historical item.

## Sync now and Disconnect

**Sync now** pulls anything missed since the last sync and refreshes jar balances (Monobank allows one
refresh per minute). **Disconnect** tells monobank to stop sending webhooks and deletes the stored token.
Transactions that were already imported stay in your list. Goals linked to jars stay too, and are marked
as unlinked.

The token is stored encrypted. It is never shown again in the app, included in a data export, or sent to
the AI assistant.
