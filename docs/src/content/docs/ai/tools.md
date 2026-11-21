---
title: MCP tool reference
description: All 35 tools exposed by the FundsFlow MCP server.
---

Amounts are **signed**: negative for expenses, positive for income. Dates use `YYYY-MM-DD`.

## Account

| Tool | Description |
| --- | --- |
| `get_me` | The signed-in user and linked identities |
| `get_bootstrap` | Tags, transaction types, transactions, budgets, and recurring rules in one request |
| `update_preferences` | Change money format, date format, decimals, or IANA timezone (only the fields you pass) |
| `export_account` | The full JSON export: profile, tags, transactions, budgets, recurring rules, transaction types |

## Tags

| Tool | Description |
| --- | --- |
| `list_tags` | All tags |
| `create_tag` | Create a tag (emoji required, optional parent) |
| `update_tag` | Update a tag by id |
| `delete_tag` | Delete a tag by id (soft delete) |

## Transactions

| Tool | Description |
| --- | --- |
| `list_transactions` | All transactions, newest first |
| `list_recent_transactions` | The newest N transactions |
| `create_transaction` | Create one transaction (source recorded as MCP) |
| `create_transactions` | Create up to 50 transactions, all-or-nothing |
| `update_transaction` | Update a transaction by id |
| `update_transactions` | Update up to 50 transactions, all-or-nothing |
| `delete_transaction` | Delete a transaction by id |
| `delete_transactions` | Delete up to 50 transactions by id, all-or-nothing |

`create_transaction`, `update_transaction` and each item of `create_transactions` / `update_transactions` also take
`transaction_type_id`, `data` (field values by key, e.g.
`{"liters": 40, "price_per_liter": 58.9}`) and `add_options` (new choices for *one of* / *several of* fields).
`amount` can be left out when the type has a computed field that fills it; the result is saved as an expense.

## Transaction types

| Tool | Description |
| --- | --- |
| `list_transaction_types` | All types with their fields (keys, kinds, units, options, formulas), archived ones included |
| `analyze_transaction_type` | Totals and field stats for one type (name or id): count, signed total, average, and sum/avg/min/max for numeric fields (liters, price per liter, …). Optional `filters` (e.g. `fuel=A-95`), `period` (`this_year`, `this_month`, …) or `from`/`to`, and `group_by` (`none`, `month`, or a field key) |
| `create_transaction_type` | Create a type: name, emoji, color, up to 30 fields, default tags |
| `update_transaction_type` | Update a type by id; send existing fields with their keys (a field left out is hidden, its values kept) |
| `delete_transaction_type` | Archive a type by id; its transactions keep the type and values |

## Attachments

| Tool | Description |
| --- | --- |
| `attach_transaction_file` | Attach a JPEG, PNG, WebP, HEIC, or PDF (max 8 MB, 5 per transaction; HEIC is saved as JPEG) from base64 content or an `https` URL |
| `list_transaction_attachments` | Attachment metadata for a transaction |
| `get_transaction_attachment` | Download an attachment (images are returned as images) |
| `delete_transaction_attachment` | Delete an attachment |

## Budgets

| Tool | Description |
| --- | --- |
| `list_budgets` | All budgets: limits (`type: limit`; active ones include `current_period`: start, end, amount, spent, left, percent, days left, status, plus `scope` when limited by type or field) and savings goals (`type: goal`, with a `progress` object: saved, target, remaining, percent, status, days left, required amount per week or month, projected date) |
| `create_budget` | Create a limit: either `tag_ids` (at least one; a parent tag includes its child tags) OR `scope` (`{kind: "type", type_id}` or `{kind: "field_sum", type_id, field}`), plus amount, `week`, `month`, or `year`, and optional `start_date` (YYYY-MM-DD, today or earlier; default in the assistant: start of the current week/month/year). Or a savings goal: `type: goal`, tags, amount (the target), optional `start_date`, `target_date` and `start_amount` |
| `update_budget` | Update a budget. Limits: a new amount, length, tags, or `scope` apply to the whole current period (pass `scope: null` with `tag_ids` to switch back to tags; history is kept); `start_date` moves the start of the current period in place; goals are edited in place (`start_date`, `target_date`, `start_amount`). The type cannot change |
| `delete_budget` | Delete a budget |
| `pause_budget` | Pause a budget |
| `resume_budget` | Resume a paused budget |

## Recurring rules

| Tool | Description |
| --- | --- |
| `list_recurring` | All recurring rules |
| `create_recurring` | Create a rule: `daily`, `weekly`, `monthly`, or `yearly`. Optional `transaction_type_id` and `data` (Period and Next charge are filled from the schedule when the type has those fields) |
| `update_recurring` | Update a rule; optional `transaction_type_id` and `data` (`transaction_type_id` null clears the type) |
| `delete_recurring` | Delete a rule |
