---
title: Connect AI clients
description: Set up the FundsFlow MCP server in Claude, ChatGPT, Cursor, VS Code, Grok, and other MCP clients.
---

Endpoint for every client:

```text
https://mcp.fundsflow.fun/mcp
```

## OAuth settings

FundsFlow doesn't support dynamic client registration, so if your client asks for OAuth details, enter:

| Field | Value |
| --- | --- |
| Client ID | `fundsflow` |
| Client secret | *(leave empty)* |
| Authorization URL | `https://mcp.fundsflow.fun/oauth/authorize` |
| Token URL | `https://mcp.fundsflow.fun/oauth/token` |
| Scope | `mcp` |
| Token auth method | none (public client, PKCE S256) |

When you connect, a FundsFlow sign-in page opens. Log in with your FundsFlow **email and password** (set them in
**Settings → Accounts → Login credentials** if you signed up another way).

## Personal token

1. In the web app open **Settings → Accounts → MCP**.
2. Click **Generate token**. FundsFlow shows a connection URL with the token in it
   (`https://mcp.fundsflow.fun/mcp?token=…`) and a **Copy connection URL** button. It's shown **only once**, so copy it
   now.
3. Prefer sending the token as a header when your client supports headers:

   ```text
   Authorization: Bearer YOUR_TOKEN
   ```

   For clients without a header field (like grok.com), paste the whole connection URL as the MCP server URL.

You have one token at a time: **Regenerate** revokes the old one, and **Revoke** removes it.

## Clients

### Claude

Claude.ai / Claude Desktop: add a **custom connector** with the endpoint URL and, under advanced settings, the OAuth
client ID `fundsflow`. Then connect and sign in.

Claude Code:

```bash
claude mcp add --transport http fundsflow https://mcp.fundsflow.fun/mcp
```

Then run `/mcp` in Claude Code to authenticate.

### ChatGPT

Add a custom connector (requires a plan and mode that support custom MCP connectors) with the endpoint URL and OAuth
client ID `fundsflow`, then sign in.

### Cursor

Add to `~/.cursor/mcp.json` (or `.cursor/mcp.json` in a project):

```json
{
  "mcpServers": {
    "fundsflow": {
      "url": "https://mcp.fundsflow.fun/mcp"
    }
  }
}
```

Cursor will prompt you to log in. To use a token instead, add
`"headers": { "Authorization": "Bearer YOUR_TOKEN" }`.

### VS Code

Add to `.vscode/mcp.json` (or your user MCP configuration):

```json
{
  "servers": {
    "fundsflow": {
      "type": "http",
      "url": "https://mcp.fundsflow.fun/mcp"
    }
  }
}
```

Start the server and VS Code will walk you through sign-in. If asked for a client ID, enter `fundsflow`.

### Grok

On grok.com add a custom connector with the endpoint URL and sign in with OAuth, or paste the connection URL with your
personal token (from **Copy connection URL**) and skip OAuth:

```text
https://mcp.fundsflow.fun/mcp?token=YOUR_TOKEN
```

Grok CLI:

```bash
grok mcp add --transport http fundsflow https://mcp.fundsflow.fun/mcp \
  --header "Authorization: Bearer YOUR_TOKEN"
```

### Other clients

Any MCP client that supports streamable HTTP works with a bearer token. OAuth works for clients whose redirect URL is
on the FundsFlow allowlist (local loopback redirects are always allowed, which covers most desktop and CLI tools).

:::caution
Treat the token like a password. Anyone who has it can read and change your FundsFlow data. If it leaks, generate a
new one — the old one stops working immediately.
:::
