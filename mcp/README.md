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

Account, tags, transactions, budgets (incl. pause/resume), recurring, preferences, export.

Creates are stored with `source=mcp`.
