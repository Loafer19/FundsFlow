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

PKCE with `S256` is required. `redirect_uri` must be on the allowlist (exact match), otherwise `/oauth/authorize` answers 400 before the sign-in form.

### OAuth redirect URIs

| Redirect URI | Client | Source |
| --- | --- | --- |
| `http://127.0.0.1:<any port>/…`, `http://localhost:<any port>/…`, `http://[::1]:<any port>/…` | Claude Code, Cursor desktop (`:8787/callback`), VS Code / Copilot (`:33418/` or ephemeral), MCP Inspector, Windsurf and other native clients | RFC 8252 §7.3; https://claude.com/docs/connectors/building/authentication; https://cursor.com/docs/mcp; https://code.visualstudio.com/api/extension-guides/ai/mcp |
| `https://claude.ai/api/mcp/auth_callback` | Claude web / desktop / mobile | https://claude.com/docs/connectors/building/authentication |
| `https://claude.com/api/mcp/auth_callback` | Claude (claude.com domain) | Anthropic docs list claude.ai only; required per Microsoft Learn (Databricks MCP) and vendor guides |
| `https://chatgpt.com/connector_platform_oauth_redirect` | ChatGPT / Codex (stable) | https://developers.openai.com/apps-sdk/build/auth |
| `https://chatgpt.com/connector/oauth/<callback_id>` (one `[A-Za-z0-9_-]` segment, no query) | ChatGPT when the server does not return `iss` (our case) | https://developers.openai.com/apps-sdk/build/auth |
| `https://www.cursor.com/agents/mcp/oauth/callback` | Cursor web / Cursor Agents | https://cursor.com/docs/mcp |
| `cursor://anysphere.cursor-mcp/oauth/callback` | Cursor desktop, legacy custom scheme | still sent by some builds (forum.cursor.com) |
| `https://vscode.dev/redirect`, `https://insiders.vscode.dev/redirect` | VS Code / Copilot | https://code.visualstudio.com/api/extension-guides/ai/mcp; VS Code DCR metadata |
| `https://grok.com/connectors-oauth-exchange-code/` (and without trailing slash) | Grok custom connectors | https://docs.x.com/x-ads-api/mcp ("Grok (web)") |

To add another client set `OAUTH_REDIRECT_ALLOWLIST` (comma-separated full URIs, exact match including path and trailing slash) and restart. If a client fails with "redirect_uri is not allowed", the server log line `oauth authorize GET` shows the URI it sent. Built-in entries live in `DEFAULT_REDIRECT_ALLOWLIST` in `oauth.ts`.

## Tools

Account, tags, transactions (incl. bulk create + file attachments via base64 or `source_url`), budgets and savings goals (incl. pause/resume), recurring, preferences, export.

Transactions created via MCP are stored with `source=mcp` (only transactions have a `source` field; tags, budgets and recurring rules are not marked). Bulk (up to 50, all-or-nothing): `create_transactions`, `update_transactions`, `delete_transactions`. Attachment tools: `attach_transaction_file`, `list_transaction_attachments`, `get_transaction_attachment`, `delete_transaction_attachment` (JPEG/PNG/WebP/PDF, max 8 MB, 5 per transaction).

`attach_transaction_file` accepts **exactly one** of:
- `content_base64` — raw base64 (no `data:` prefix); `name` + `mime` required
- `source_url` — public `https` URL; MCP downloads (max 8 MB, blocks private/localhost hosts), sniffs type, then uploads via the existing base64 API. `name` / `mime` optional.

Optional fields like `note` (and recurring `ends_at`) are omitted from the API body when not provided — MCP does not send `null`.
