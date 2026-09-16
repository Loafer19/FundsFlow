# FundsFlow

![FundsFlow](./frontend/public/logo.png)

Personal finance tracker

## Features

- Transactions:
  - date, amount, tags, note
  - optional file attachments (JPEG / PNG / WebP / PDF, several per transaction)
  - source: web, Telegram, recurring, or MCP
- Recurring transactions:
  - daily, weekly, monthly, yearly
  - ends after a date or a set number of times
- Budgets:
  - per-tag spending limits
  - pause/resume, full history of past limits
- Tags:
  - fully customizable, with emojis 😋
  - hierarchical (parent/child)
- Telegram bot:
  - quick-add by chatting
  - photo / PDF receipt with caption (e.g. `-350 groceries`)
  - bot-first registration/login
  - budget and digest alerts, mute/unmute
- MCP (AI clients):
  - personal token + connection URL in Settings
  - create / update transactions, attach files (base64), budgets, tags, export

- Filter:
  - by date: week, month, year
  - by tags (List tab + from Budgets)
  - search notes (List tab)
- Calendar:
  - month / week / year views
  - actual transactions + upcoming recurring
  - attachment indicator on items
- Onboarding:
  - welcome modal with starter tags preview
  - optional Telegram bot link
- Settings:
  - money / date format
  - 30+ themes (favorites)
  - Accounts: Telegram link, MCP token / connection URL

  - JSON account export
- Helpful Insights (tabs):
  - Analytics — totals, averages, balances per tag
  - Calendar — day grid + upcoming
  - Budgets — limits and progress
  - Tags — distribution (donuts / list)
  - Flow — income vs expenses over time
  - Trend — running balance
  - List — searchable / filterable table (source + attachments)

|  |  |  |
| ---------- | ---------- | ---------- |
| ![app](./.github/screenshots/tabs/analytics.png) | ![app](./.github/screenshots/tabs/balance_trend.png) | ![app](./.github/screenshots/tabs/money_flow.png) |
| ![app](./.github/screenshots/tabs/table.png) | ![app](./.github/screenshots/tabs/tag_distribution_donuts.png) | ![app](./.github/screenshots/tabs/tag_distribution_list.png) |
| ![app](./.github/screenshots/modals/tags_add.png) | ![app](./.github/screenshots/modals/transactions_add.png) |  |



