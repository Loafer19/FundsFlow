# FundsFlow MCP

Streamable HTTP MCP + OAuth for Grok and other MCP clients.

**Production:** `https://mcp.fundsflow.fun/mcp`

## Grok.com OAuth form

| Field | Value |
|-------|--------|
| MCP / Server URL | `https://mcp.fundsflow.fun/mcp` |
| Client ID | `fundsflow` |
| Client Secret | _(leave empty)_ |
| Authorization Endpoint | `https://mcp.fundsflow.fun/oauth/authorize` |
| Token Endpoint | `https://mcp.fundsflow.fun/oauth/token` |
| Scopes | `mcp` |
| Token Auth Method | `none` |

Grok opens the authorize page → sign in with FundsFlow email/password → done.

## CLI (optional, bearer token)

Settings → Accounts → MCP → Generate token, then:

```bash
grok mcp add --transport http fundsflow https://mcp.fundsflow.fun/mcp \
  --header "Authorization: Bearer YOUR_TOKEN"
```

## Tools

Account, tags, transactions (incl. bulk create + file attachments via base64), budgets (incl. pause/resume), recurring, preferences, export.

Creates are stored with `source=mcp`. Bulk (up to 50, all-or-nothing): `create_transactions`, `update_transactions`, `delete_transactions`. Attachment tools: `attach_transaction_file`, `list_transaction_attachments`, `get_transaction_attachment`, `delete_transaction_attachment` (JPEG/PNG/WebP/PDF, max 8 MB, 5 per transaction).



