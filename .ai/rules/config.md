---
paths:
  - config/sanctum.php
  - config/filesystems.php
---

# Config

## Tokens expire; the only question is when
`sanctum.expiration` is a ceiling on every token, measured from issue time,
and must stay non-null. Tokens are handed to consuming apps by an operator and
there is no self-serve refresh, so the default is deliberately long (365 days)
— but a token that never expires is a leak that never closes, and there is no
way to notice one in use.
`tenant:token --expires-in-days` sets a tighter per-token expiry. Sanctum
enforces the config ceiling and the token's own `expires_at` independently, so
the stricter of the two wins. `sanctum:prune-expired` runs daily.

## One S3 disk, stock AWS_* names
The `media` disk is the only disk this app writes to. Do not add a second S3 disk: Laravel's stock `s3` disk was deleted because nothing referenced it and two S3 disks with different credentials is the confusion this replaced.

It reads the stock `AWS_ACCESS_KEY_ID` / `AWS_SECRET_ACCESS_KEY` / `AWS_BUCKET` / `AWS_DEFAULT_REGION` / `AWS_ENDPOINT` names, so R2 needs no private namespace. Target is Cloudflare R2, hence the `auto` region default.

Two settings are why the disk is declared at all rather than reusing a stock one, and both are load-bearing:
- `throw => true`. A write returning false instead of raising records a successful download of a file that is not there; it only surfaces later as a broken stream.
- No `visibility` key. Setting one makes Flysystem send an ACL header on every write, which R2 rejects.

The `MEDIA_DISK_DRIVER` toggle stays: tests and local dev run the disk on `local`, and `AssetDelivery::isLocal()` branches on that driver to choose streaming over a pre-signed redirect.
