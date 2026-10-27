---
title: Budgets
description: Set spending limits and savings goals per tag in FundsFlow, pause and resume them, and review history.
---

The **Budgets** tab holds two kinds of budgets:

- a **limit** — a spending limit for one or more tags that repeats **weekly**, **monthly**, or **yearly**;
- a **savings goal** — a target amount you save toward, optionally by a deadline.

Both are built on tags, so there is nothing extra to log: tag your transactions as usual.

## Create a limit

On the **Budgets** tab click **Add Budget or Goal** and keep **Limit** selected:

- **Title** — optional; without it the budget is labelled by its tags' emojis.
- **Tags** — at least one. Spending on these tags counts toward the budget.
- **Amount** — the limit per period.
- **Weekly / Monthly / Yearly**.
- **Start from the beginning of this week/month/year** — align periods to the calendar instead of starting today.

### The limit card

- a status: **On track**, **Near limit** (80% or more of the limit spent) or **Over**;
- **Spent X of Y** with the percentage and a progress bar coloured by status. The thin marker on the bar shows how
  much of the week/month/year has passed, so you can see at a glance whether spending runs ahead of time;
- the amount **left** (or **over** the limit);
- the pace: how much you can spend per day for the rest of the period (**keep under ~N/day**) and where the current
  pace leads (**At current pace ~X by …**: spent so far ÷ days elapsed × days in the period);
- the period dates, frequency and **day N of M**;
- **History** (collapsed by default): one row per previous week/month/year with a small bar, spent / limit and a
  ✓ or ✗ for whether it stayed within the limit.

Spending counts every transaction dated within the period, including everything logged today.

## Create a savings goal

Click **Add Budget or Goal** and switch to **Goal**:

- **Goal name** — e.g. “🛟 Emergency fund”. Optional; without it the goal is labelled by its tags' emojis.
- **Target amount** — how much you want to have saved.
- **Savings tags** — at least one. Transactions with these tags are your contributions.
- **Start date** — the day contributions start counting from. Defaults to today; pick an earlier date if you
  already started saving (e.g. transfers you logged last month). It cannot be in the future or after the deadline.
- **Deadline** — optional date you want to reach the target by.
- **Already saved** — optional amount you had before the start date.

### How progress is counted

Saving money is usually logged as a transfer *out* of your wallet, i.e. an expense on a savings tag. So:

- **Progress = Already saved + the net of transactions with the goal's tags since the goal started, with the sign
  flipped**: a −500 transfer to savings adds 500, a +200 withdrawal back to your wallet takes 200 off.
- A transaction counts once, even if it has several of the goal's tags. As with limits, only the exact tags count
  (child tags are not included).
- Progress never goes below zero, and it can go above the target.
- Pausing a goal does not reset it: progress always counts from the start date. Changing the **Start date** when
  editing recalculates progress from the new date.

Tip: create a dedicated tag (e.g. 🏦 Savings, or one tag per goal) and put every transfer to savings on it.

### The goal card

- **Saved X of Y** with a progress bar and percentage, and the amount **left to save**;
- with a deadline: **days left** and how much you **need per month** (per week when less than two months are left);
- the pace projection: **“At current pace you will reach it in …”**, based on your average contributions over the
  last three months (at least a 30-day window, so a single early transfer does not skew it);
- a status: **On track** (the projection meets the deadline), **Behind**, **In progress** (no deadline), **Paused**,
  or **Completed**.

A completed goal gets a **Completed** badge and stays on the list until you delete it — it is never removed
automatically. The summary above the goals shows the total saved, what is left, and how many goals are complete.

## Pause, resume, edit

- **Pause** stops tracking; **Resume** starts a new period from today with the same settings. A paused goal keeps
  its progress.
- **Edit** changes a limit. FundsFlow keeps the earlier settings in history instead of rewriting the past.
- **Edit** on a goal changes it in place (target, tags, start date, deadline, already saved); its progress is
  recalculated.
  A limit cannot be turned into a goal or back — create a new one instead.
- Delete with hold-to-delete.

## Alerts

With Telegram linked, the bot sends a budget alert at **80%** and **100%** of a limit. Savings goals never trigger
limit alerts; instead the bot sends a one-time 🎉 message when a goal reaches its target. See
[Notifications](/docs/telegram/notifications/).
