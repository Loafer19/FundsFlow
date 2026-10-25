---
title: MCP tool reference
description: All 30 tools exposed by the FundsFlow MCP server.
---

Amounts are **signed**: negative for expenses, positive for income. Dates use `YYYY-MM-DD`.

## Account

| Tool | Description |
| --- | --- |
| `get_me` | The signed-in user and linked identities |
| `get_bootstrap` | Tags, transactions, budgets, and recurring rules in one request |
| `update_preferences` | Change money format, date format, decimals, or IANA timezone (only the fields you pass) |
| `export_account` | The full JSON export: profile, tags, transactions, budgets, recurring rules |

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

## Attachments

| Tool | Description |
| --- | --- |
| `attach_transaction_file` | Attach a JPEG, PNG, WebP, or PDF (max 8 MB, 5 per transaction) from base64 content or an `https` URL |
| `list_transaction_attachments` | Attachment metadata for a transaction |
| `get_transaction_attachment` | Download an attachment (images are returned as images) |
| `delete_transaction_attachment` | Delete an attachment |

## Budgets

| Tool | Description |
| --- | --- |
| `list_budgets` | All budgets |
| `create_budget` | Create a budget: tags (at least one), amount, and `week`, `month`, or `year` |
| `update_budget` | Update a budget (starts a new period version; history is kept) |
| `delete_budget` | Delete a budget |
| `pause_budget` | Pause a budget |
| `resume_budget` | Resume a paused budget |

## Recurring rules

| Tool | Description |
| --- | --- |
| `list_recurring` | All recurring rules |
| `create_recurring` | Create a rule: `daily`, `weekly`, `monthly`, or `yearly` |
| `update_recurring` | Update a rule |
| `delete_recurring` | Delete a rule |
