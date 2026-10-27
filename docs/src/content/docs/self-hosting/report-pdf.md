---
title: Report PDF (Gotenberg)
description: How FundsFlow renders themed report PDFs with Gotenberg, and how to set it up locally or on Dokploy.
---

One renderer for every report PDF: **Settings → Report** (download / Send to Telegram), the assistant report card and the
Telegram `send_report` tool all call the API (`POST /api/report`, `POST /api/agent/report`).

The API mints a one-time, read-only token (120 s, ability `report:print`) and asks [Gotenberg](https://gotenberg.dev)
Chromium to open the real SPA in print mode (`REPORT_RENDER_URL/?print=report#t=…`), so the PDF uses the user's theme
and the real Chart.js charts. If `REPORT_RENDER_URL` is empty or the render fails, the API falls back to its plain
server-side HTML report.

## Local

1. Start Gotenberg:

   ```sh
   docker compose -f docker/compose.gotenberg.yml up -d
   ```

2. In `backend/.env` set `GOTENBERG_URL=http://127.0.0.1:3000` and `REPORT_RENDER_URL` to a frontend URL reachable
   _from the Gotenberg container_ (or leave it empty for the HTML fallback).

## Dokploy

- Add a service from image `gotenberg/gotenberg:8` with the arguments
  `--chromium-disable-javascript=false --api-timeout=120s`, on the same network as the backend.
- On the backend set `GOTENBERG_URL=http://<service>:3000` and `REPORT_RENDER_URL=https://<your frontend domain>`.
- Gotenberg needs outbound access to that frontend and to the API origin in the frontend's `VITE_API_URL`, and
  `CORS_ALLOWED_ORIGINS` must include the frontend origin.

See also: [Reports](/docs/web/reports/) for how users export a report.
