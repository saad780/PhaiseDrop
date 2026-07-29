# Phaise Drop deployment

This repository is a security-focused FileRise fork for one-way uploads to the NAS dataset mounted at `/mnt/main/NAS/drop` on the Coolify host.

## Network boundary

- `drop.phaise.com` routes only to `public-gateway`. The gateway exposes capability URLs, chunk uploads, the Finish action, and the exact static files required by the sender page. Every other path returns `404`.
- `https://truenas-nas.taila25076.ts.net` is served by the NAS's existing Tailscale node and is reachable only inside the tailnet. Tailscale Serve proxies to the app's loopback-only host binding; FileRise authentication is still required.
- No container publishes a port on a LAN or public interface. The admin app binds only to host loopback at `127.0.0.1:18443` for Tailscale Serve.

## Deployment secrets and variables

- `PERSISTENT_TOKENS_KEY` is intentionally not passed through Coolify's stack environment. On a pristine install FileRise generates it in the persistent metadata volume. Back up `metadata/persistent_tokens.key` with that volume.
- `DROP_PUID` and `DROP_PGID`: numeric owner/group with write access to `/mnt/main/NAS/drop`. Confirm these against the TrueNAS ACL before first deployment.

The compose definition intentionally uses `CHOWN_ON_START=false`: startup leaves both the ownership and mode of the SMB dataset root untouched and never recursively rewrites its ACLs.

## Storage

- Delivered files and preserved folder trees: `/mnt/main/NAS/drop` on the host.
- User database, application metadata, audit data, and sessions: named Docker volumes, separate from the SMB-visible dataset.

## Security behavior

- 256-bit unguessable drop tokens.
- No file listing on a drop link.
- Default limits exposed in the admin dialog: 25 GB per file, 100 GB total, 48-hour idle timeout, 7-day hard expiry.
- Total-byte reservations are serialized under a file lock to prevent concurrent quota bypass.
- Single-submission links close permanently when the sender chooses **Finish upload**.
- ClamAV is enabled and fails closed if scanning cannot run or returns an error.
- Browser uploads use 2 MB chunks, so the public gateway accepts only 4 MB request bodies even when a logical file is much larger.
- Chunk identifiers are stable per file and the sender checks server-side chunk state, allowing an interrupted upload to resume after a page reload.
