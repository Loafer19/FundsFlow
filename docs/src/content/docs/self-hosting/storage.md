---
title: Attachments and storage
description: Keep receipt photos and other uploads across deploys by mounting a volume on the API container.
---

Uploads (receipt photos, transaction attachments, import files the API stores) use the Laravel `local` disk.
`FILESYSTEM_DISK` defaults to `local`, and that disk's root is `storage/app/private` inside the container
(`/app/storage/app/private`). The database only stores the path. The bytes live on the container filesystem.

The backend image does not declare a Docker volume. On Dokploy a redeploy replaces the container filesystem,
so without a mount every deploy deletes the files and leaves the rows behind.

## Dokploy

On the **API** service add a volume:

| Mount | Container path | What it keeps |
| --- | --- | --- |
| Named volume or a host directory | `/app/storage` | Uploads, framework cache, and `storage/logs` |

Mount `/app/storage`, not all of `/app`. A mount on `/app` hides the code that the image ships.

Leave `FILESYSTEM_DISK` unset, or set it to `local`. Set it to `s3` only when the files should live in object
storage instead of this volume; the app reads the disk name stored on each attachment, so a disk change does
not move files that are already saved.

The queue worker container does not need this volume while background work runs in the API process
([Queue worker and alerts](/docs/self-hosting/queue-worker/)). If a worker later stores uploads, give it
the same volume.

## Local

`php artisan serve` writes to `backend/storage` on the host. Nothing extra to mount.
