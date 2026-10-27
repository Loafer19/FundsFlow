---
title: Admin panel (Filament)
description: The operator-only Filament admin panel at /admin on the API host — access, roles, AI limits, blocking, env and deploy.
---

A [Filament](https://filamentphp.com) panel lives on the API host at `/admin` (e.g. `https://api.fundsflow.fun/admin`).
It is for operators only: users list and detail (read-only transactions, tags, budgets, recurring), per-user AI limit /
bonus / reset, block and unblock, admin role, a dashboard with stats and an audit log of every admin action
(`admin_audit_logs` table plus `admin.*` log lines).

## Access

Only users with `is_admin` and no `blocked_at` can sign in. TOTP two-factor is required (set up on first sign-in,
recovery codes shown once).

## Grant or revoke

```sh
php artisan admin:grant you@example.com [--password] [--revoke] [--reset-mfa]
```

- `--password` sets a sign-in password (Google/Telegram-only accounts have none you know).
- `--reset-mfa` clears a lost authenticator.

## AI limit

`AI_RECEIPT_DAILY_LIMIT` is the server default; `users.ai_daily_limit` overrides it per user (empty means the default,
`0` turns AI off for that user). A bonus from the panel adds to today only.

## Blocking

Blocking (`users.blocked_at`) rejects API tokens, password/OAuth sign-in and Telegram messages.

## Environment (backend)

- `SESSION_DRIVER=redis`
- `SESSION_DOMAIN=<api host>`
- `SESSION_SECURE_COOKIE=true`
- `APP_URL=https://<api host>` (asset and Livewire URLs are built from it)

Only `/admin` and `/livewire-*` start a session; the API, CORS and the Telegram webhook are unchanged.

## Deploy

The image publishes the panel's JS/CSS during `composer install` (`filament:upgrade`). Migrations are **not**
automatic, so run this in the backend container after the first deploy (and after every deploy that adds migrations),
then `admin:grant`:

```sh
php artisan migrate --force
```
