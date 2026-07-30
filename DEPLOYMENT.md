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
- Back up `metadata/used_drop_codes.json` with the metadata volume. It is the permanent no-reuse ledger for public four-letter URLs.

## Security behavior

- New sender URLs are direct, random four-letter capabilities such as `https://drop.phaise.com/ynei`. There is no password or second access-code prompt.
- The server resolves the four letters to an internal 256-bit token. Sender pages and upload requests carry only the four-letter reference plus a scoped HMAC; the internal token is never exposed by the new flow.
- A code is permanently tombstoned when allocated and is never assigned to another drop, even after expiry or revocation. This prevents an old message from ever opening a future recipient's drop.
- Repeated requests for nonexistent codes are limited to 8 misses per source IP per 15 minutes, in addition to the public gateway limit. Valid drops are resolved before this limiter and remain usable.
- The public gateway exposes only `/<four letters>` for sender pages. Existing 256-bit `/d/<token>` links remain available only through the private application for legacy compatibility.
- Four lowercase letters provide 456,976 possible URLs (about 19 bits). Rate limiting reduces casual enumeration, but the short URL is intentionally less secret than a long cryptographic capability. Drops remain upload-only: no listing or downloads are exposed.
- No file listing on a drop link.
- Default limits exposed in the admin dialog: 25 GB per file, 100 GB total, 48-hour idle timeout, 7-day hard expiry.
- Total-byte reservations are serialized under a file lock to prevent concurrent quota bypass.
- Single-submission links close permanently when the sender chooses **Finish upload**.
- ClamAV is enabled and fails closed if scanning cannot run or returns an error.
- Browser uploads use 2 MB chunks, so the public gateway accepts only 4 MB request bodies even when a logical file is much larger.
- Chunk identifiers are stable per file and the sender checks server-side chunk state, allowing an interrupted upload to resume after a page reload.
