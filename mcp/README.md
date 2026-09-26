# FundsFlow MCP

Streamable HTTP MCP for AI clients (Claude, Cursor, Grok, etc.).

**Production:** `https://mcp.fundsflow.fun/mcp`

Server advertises `title`, `websiteUrl`, and bee brand `icons` (PNG from fundsflow.fun) in MCP `initialize` / discovery JSON. Clients may or may not render them.

## Connect with a token (recommended)

Settings → Accounts → MCP → Generate token.

- Clients with header support: `Authorization: Bearer YOUR_TOKEN`
- Tip for Grok.com (no header field): use the connection URL with `?token=…` as the only MCP URL (skip OAuth)

```bash
# Example: any MCP CLI that supports HTTP + headers
# (Grok CLI example)
grok mcp add --transport http fundsflow https://mcp.fundsflow.fun/mcp \
  --header "Authorization: Bearer YOUR_TOKEN"
```

## OAuth (optional)

Some clients can use OAuth instead of a personal token:

| Field | Value |
|-------|--------|
| MCP / Server URL | `https://mcp.fundsflow.fun/mcp` |
| Client ID | `fundsflow` |
| Client Secret | _(leave empty)_ |
| Authorization Endpoint | `https://mcp.fundsflow.fun/oauth/authorize` |
| Token Endpoint | `https://mcp.fundsflow.fun/oauth/token` |
| Scopes | `mcp` |
| Token Auth Method | `none` |

## Tools

Account, tags, transactions (incl. bulk create + file attachments via base64 or `source_url`), budgets (incl. pause/resume), recurring, preferences, export.

Creates are stored with `source=mcp`. Bulk (up to 50, all-or-nothing): `create_transactions`, `update_transactions`, `delete_transactions`. Attachment tools: `attach_transaction_file`, `list_transaction_attachments`, `get_transaction_attachment`, `delete_transaction_attachment` (JPEG/PNG/WebP/PDF, max 8 MB, 5 per transaction).

`attach_transaction_file` accepts **exactly one** of:
- `content_base64` — raw base64 (no `data:` prefix); `name` + `mime` required
- `source_url` — public `https` URL; MCP downloads (max 8 MB, blocks private/localhost hosts), sniffs type, then uploads via the existing base64 API. `name` / `mime` optional.
