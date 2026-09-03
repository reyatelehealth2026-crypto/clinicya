# ADR-005: File Storage Layout + Signed URL Strategy

**Status:** Accepted (2026-05-25)
**Deciders:** Platform Owner + Engineering
**Supersedes:** Implicit "flat `uploads/` directory with predictable names" layout

---

## Context

ADR-001 named the target file storage path:

```
/var/reya/storage/
├── platform/              ← System assets
└── tenant_NNNN/           ← Per-tenant file isolation
    ├── slips/
    ├── products/
    ├── logos/
    └── exports/
```

But it did not define:

- **Which buckets are legal** and what types of files go where
- **Filename rules** — who picks the filename, what characters are allowed
- **How a browser fetches a file** — direct `<img src>` vs. signed URL
- **What stops Tenant A from reading Tenant B's slip** by guessing the URL
- **How quota is enforced** vs. the `storage_quota_mb` entitlement (ADR-002)
- **How backup/restore works** for files alongside the SQL dump

The current production state is the worst case:

- All slips live at `uploads/slips/{customer_id}_{timestamp}.jpg`
- The web server serves `/uploads/` directly (no PHP gate)
- Customer IDs are short integers; an attacker who knows one valid slip
  URL can iterate `_id` to find others — cross-Tenant data leak
- No quota; one Tenant can fill the disk

The product owner's constraint is unambiguous: **"Pharmacy data ห้ามหลุด
เด็ดขาด"** — that includes slips and Rx images, which are PII +
health/payment data under Thai PDPA.

## Decision

Implement a two-level URL strategy on the per-Tenant layout, with explicit
bucket whitelist, strict filename rules, and quota enforcement via cron.

### Storage layout (matches ADR-001)

```
/var/reya/storage/                              # mounted volume
├── platform/
│   ├── logos/                                  # REYA brand assets
│   └── system/                                 # email templates etc.
│
└── tenant_NNNN/                                # one directory per Tenant
    ├── slips/                                  # payment slip uploads
    ├── rx_uploads/                             # customer-uploaded Rx images
    ├── products/                               # product photos
    ├── logos/                                  # this Tenant's pharmacy logo
    ├── profile_pics/                           # staff + customer avatars
    └── exports/                                # generated reports, CSVs
```

**Directory ownership/perms:** `www-data:www-data 0750` on every
`tenant_NNNN/`. Each bucket subdir created at provisioning time
(ADR-002 step appended). Nginx is configured to **NOT serve `/var/reya/
storage/` directly** — all file access flows through PHP (Level 1 or
Level 2 below).

### Bucket whitelist

Centralized in `App\Platform\StorageBuckets`:

```php
final class StorageBuckets {
    public const BUCKETS = [
        'slips'         => ['sensitivity' => 'high',   'max_size_mb' => 10],
        'rx_uploads'    => ['sensitivity' => 'high',   'max_size_mb' => 10],
        'products'      => ['sensitivity' => 'low',    'max_size_mb' => 5],
        'logos'         => ['sensitivity' => 'low',    'max_size_mb' => 2],
        'profile_pics'  => ['sensitivity' => 'low',    'max_size_mb' => 2],
        'exports'       => ['sensitivity' => 'medium', 'max_size_mb' => 100],
    ];

    public static function isAllowed(string $bucket): bool { /* ... */ }
    public static function sensitivity(string $bucket): string { /* ... */ }
}
```

Any code path that writes a file MUST go through
`StorageWriter::put($tenantId, $bucket, $bytes, $hint)`. Direct
filesystem writes are forbidden by code review (linter rule TBD).

### Filename rules

App-generated only. No user input ever becomes part of the path.

```php
final class StorageWriter {
    public static function put(
        int $tenantId,
        string $bucket,
        string $bytes,
        array $hint = []   // ['ext' => 'jpg', 'prefix' => 'slip']
    ): string {
        if (!StorageBuckets::isAllowed($bucket)) {
            throw new \InvalidArgumentException("Unknown bucket: $bucket");
        }
        $ext = self::validateExt($hint['ext'] ?? '');
        // Filename: <YYYYMMDD>_<random32>.<ext>
        // e.g. 20260525_a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6.jpg
        $name = date('Ymd') . '_' . bin2hex(random_bytes(16)) . '.' . $ext;
        $dir  = self::tenantDir($tenantId) . '/' . $bucket;
        if (!is_dir($dir)) mkdir($dir, 0750, true);

        // Validate against rule: ^[A-Za-z0-9._-]+$
        if (!preg_match('/^[A-Za-z0-9._-]+$/', $name)) {
            throw new \RuntimeException("Generated filename failed validation");
        }
        file_put_contents("$dir/$name", $bytes);
        return $name; // store just the filename in DB; bucket+tenant are context
    }
}
```

DB columns store **filename only**, not path or URL. Reconstruction is
the reader's job and is gated by tenant context.

### Two URL levels

We make a deliberate choice **per bucket** between two access models:

#### Level 1 — semi-public via PHP redirect (low-sensitivity buckets)

For `products`, `logos`, `profile_pics`:

- URL shape: `/file/{bucket}/{filename}` (rewritten via `.htaccess` to
  `/api/file.php?bucket=...&name=...`)
- `api/file.php` checks `TenantContext::currentTenantId()` from session,
  validates filename matches charset, then `readfile()` of the resolved path
- Nginx-level `expires 7d` set on response headers → Cloudflare cacheable
- If TenantContext is unset (public LINE Mini App route browsing products),
  the URL accepts a `?tenant=NNNN` query param verified against a
  short-lived signed signature in the storefront context

Why Level 1 is acceptable here: these files are essentially marketing /
public-facing content. A leak of a product image is annoying, not
career-ending. URL is still gated by tenant context so cross-Tenant
guessing fails.

#### Level 2 — signed token (high-sensitivity buckets)

For `slips`, `rx_uploads`, and `exports`:

- URL shape: `/api/file.php?token=<base64url>`
- Token is a JWT-like blob: `base64url(json({tenant_id, bucket, filename,
  uid, exp})) + '.' + hmac_sha256(payload, server_secret)`
- TTL: 5 minutes (configurable per call site; max 1 hour)
- On request:
  ```
  1. Decode + verify HMAC; reject if signature fails
  2. Reject if exp < now
  3. Resolve current session’s tenant_id and user_id
  4. Reject if token.tenant_id != session.tenant_id
  5. Reject if token.uid != session.user_id
     (or if uid is null/'public' AND caller is a permitted public route)
  6. Stream the file with Cache-Control: private, no-store
  ```
- Issued by `StorageReader::signedUrl($tenantId, $bucket, $filename, $uid,
  $ttlSec)` — called by controllers when rendering the page
- HTML embeds the signed URL fresh each render; the URL becomes a
  capability that expires

This means Cloudflare/CDN cannot cache these files, but that's
intentional — they're not public.

#### Cross-cutting: filename validation

Both levels apply:

```php
if (!preg_match('/^[A-Za-z0-9._-]+$/', $filename)) abort(400);
if (strpos($filename, '..') !== false) abort(400);
$resolved = realpath("$tenantDir/$bucket/$filename");
if ($resolved === false || !str_starts_with($resolved, $tenantDir)) abort(404);
```

Path-traversal defense is required at BOTH levels.

### URL routing table

| Bucket          | Level | URL Shape | Cache | Cloudflare |
|-----------------|-------|-----------|-------|------------|
| `slips`         | 2     | `/api/file.php?token=...` | none           | bypass |
| `rx_uploads`    | 2     | `/api/file.php?token=...` | none           | bypass |
| `exports`       | 2     | `/api/file.php?token=...` | none           | bypass |
| `products`      | 1     | `/file/products/{name}`   | `max-age=604800` | cache |
| `logos`         | 1     | `/file/logos/{name}`      | `max-age=604800` | cache |
| `profile_pics`  | 1     | `/file/profile_pics/{name}` | `max-age=86400` | cache |

### Provisioning hook (ADR-002 add-on)

The provisioning script adds one more step between current step 5 and 6:

```
[5.5] Create per-tenant storage tree:
      mkdir -p /var/reya/storage/tenant_NNNN/{slips,rx_uploads,
                                              products,logos,
                                              profile_pics,exports}
      chown -R www-data:www-data /var/reya/storage/tenant_NNNN
      chmod 0750 /var/reya/storage/tenant_NNNN
```

Termination (ADR-002) is unchanged — `rm -rf` of the whole tree.

### Quota enforcement (ADR-002 entitlement: `storage_quota_mb`)

Daily cron `cron/check_storage_quota.php` (wrapped by `withEachTenant`
from ADR-004):

```
For each active Tenant:
  $bytes = du -sb /var/reya/storage/tenant_NNNN
  $mb    = $bytes / (1024*1024)
  $quota = Entitlement::getInt($tenantId, 'storage_quota_mb')

  Record into platform.tenant_storage_usage (tenant_id, mb, checked_at)

  if ($mb >= $quota):       # hard over
      Notification to Tenant Owner + Platform Owner; flag in tenants table
      Subsequent uploads via StorageWriter::put() throw QuotaExceededException
  elif ($mb >= 0.8 * $quota): # soft warn
      Email to Tenant Owner "ใช้พื้นที่ 80% แล้ว"
```

Quota check at write time:

```php
public static function put(...): string {
    self::assertUnderQuota($tenantId); // fast cached lookup; refreshed by cron
    // ... write ...
}
```

The cache lookup uses `platform.tenant_storage_usage.mb` from the latest
daily snapshot. Real-time `du` on every upload is too slow at scale; we
accept up to 24h staleness for soft enforcement. Hard cliff is fine
because writes block on quota; user sees error and contacts Platform
Owner who can grant a temporary entitlement override (ADR-002).

### Backup strategy

Daily cron `cron/backup_tenant.php`:

```bash
# Per-Tenant, atomic snapshot of DB + files together
mysqldump --single-transaction reya_tenant_NNNN \
  | gzip > backups/daily/$(date +%F)/tenant_NNNN_db.sql.gz

tar -cf - /var/reya/storage/tenant_NNNN \
  | gzip > backups/daily/$(date +%F)/tenant_NNNN_files.tar.gz
```

Retention: daily 14 days, weekly 8 weeks, monthly 12 months. Backup
verification cron weekly: `tar -tzf` integrity check.

Restoration playbook in `docs/runbook-tenant-restore.md` (TBD).

### Migration from current flat layout

See `docs/file-storage-migration-plan.md` (to be authored by ops). High-
level shape:

1. Freeze writes to `uploads/` (set read-only at filesystem layer)
2. For each existing Tenant (2 today):
   - For each existing row in tables that reference files
     (`payment_slips`, `business_items.image`, `users.profile_pic`):
     determine target bucket, generate new name, copy file
   - Update DB column to new name
3. After verification: `mv uploads/ uploads.bak.YYYY-MM-DD`
4. Unfreeze writes (now go through `StorageWriter`)
5. Drop `uploads.bak.*` after 30 days

## Consequences

### Positive

- **Cross-Tenant URL guessing eliminated for sensitive buckets** — even if
  attacker knows a slip filename, they can't construct a valid signed
  token without `server_secret`.
- **Cloudflare cacheability preserved for public buckets** — product
  catalogue stays fast.
- **App-generated filenames** kill an entire class of bugs (path
  traversal, weird unicode, RTL override attacks).
- **Per-Tenant directories make backup/restore trivial** — `tar` one
  directory, one DB dump, done.
- **Quota gives the Platform Owner cost control** — disk is not free.
- **Bucket whitelist is centralized** — adding a new bucket is a one-file
  change, reviewable.

### Negative

- **Every file render now goes through PHP** — slight latency vs.
  static-file serving. Mitigated by Cloudflare on Level 1 and HTTP/2
  multiplexing on Level 2.
- **Signed URL expiry creates broken-image potential** — if a customer
  saves a slip URL and revisits 6 minutes later, image breaks. UX must
  always re-render the page to get a fresh URL.
- **Disk usage observability lives in `tenant_storage_usage`** — needs a
  Platform Owner dashboard (one more page to build).
- **`du` daily scan is O(files)** — at 50 Tenants × 100k files this is
  fine; revisit if any Tenant has millions of files.
- **Migrating existing flat layout requires write freeze** — a few
  minutes of "image upload temporarily unavailable" for current Tenants.

### Neutral / Tradeoffs accepted

- We chose Level 1 (semi-public) over forcing Level 2 for products /
  logos / profile pics to keep Cloudflare cache hits. Risk: an attacker
  enumerating a Tenant's product catalogue. Mitigation: product images
  are intended to be shown to anyone browsing the storefront anyway.
- 5-minute default TTL on signed URLs is short; if it causes UX issues
  in long-lived pages (e.g. inbox view that stays open for hours), we
  extend per-call-site to 1h max. Beyond 1h needs design discussion.
- `server_secret` rotation is not yet defined; if rotated, in-flight
  signed URLs invalidate. Acceptable because TTL is short.

## Alternatives Considered

### S3 / object storage with bucket policies
Rejected v1:

- Adds a vendor dependency (and bill) that the <50-Tenant scale doesn't
  justify
- Bucket-per-Tenant on S3 has limits (100 buckets per account default);
  prefix-per-Tenant requires same signed-URL logic anyway
- Backup/restore semantics change (need to learn AWS CLI for ops)
- Reconsider when: any single Tenant exceeds 50 GB; or when we go
  multi-region

### Direct nginx serving with `internal` + `X-Accel-Redirect`
Considered. Faster than `readfile()`. Defer to a follow-up perf ADR if
benchmarks show PHP streaming as a bottleneck. Logic stays identical.

### One CDN signed URL standard (Cloudflare signed URL, AWS CloudFront)
Considered. Locks us to one CDN vendor's tokens. Our HMAC-based scheme
is portable and avoids vendor lock-in at this stage.

### Database BLOB storage
Rejected: 200-table DB already; doubling row count with image bytes hurts
backup/restore and replication. File system is correct for this shape.

### Symlink farm (`/uploads/{tenant}/...` → `/var/reya/storage/...`)
Rejected: invites configuration drift and security holes (symlink
following). Single canonical path is simpler.

## Open questions

> Surfaced for the next grilling round.

1. **`server_secret` rotation policy.** When does it rotate? Where stored
   (env var, secrets manager, encrypted file)? Who has access? On rotate,
   all in-flight signed URLs break — acceptable?
2. **Public storefront product images.** LINE Mini App `/shop` route may
   show product images to non-logged-in users (the shop is public). How
   does that work with Level 1's session-based tenant check? Proposal:
   storefront route includes `?tenant=NNNN` + Cloudflare-cacheable URL;
   no per-user signing. Confirm.
3. **Exports bucket capacity.** Generated CSVs of full transaction history
   could be 50MB+. Should we cap export size or paginate large exports?
4. **Anti-virus scan.** Slips and Rx uploads are customer-uploaded; risk
   of malware. Do we run ClamAV at write time? Defer? Required for PDPA?
5. **Image transcoding.** Customer uploads HEIC from iPhone — should
   `StorageWriter` transcode to JPG? Today: store as-is, browser may not
   render. Decision affects UX.
6. **Metadata table for files.** Currently filename-only in DB. Do we
   also need `tenant_files` table recording (id, tenant_id, bucket, name,
   size, mime, uploaded_by, uploaded_at) for audit + de-dup? Or rely on
   filesystem `stat` calls?
7. **Cloudflare bypass mechanics.** Bypass for Level-2 URLs needs a
   `Cache-Control: private, no-store` header AND a Page Rule on
   `/api/file.php`. Who owns Cloudflare config?
8. **Cross-Tenant exports for Platform Owner.** Platform-level reports
   (super admin view per ADR-006) may produce files spanning Tenants.
   Where do those live — `platform/exports/`? Confirm bucket.

## Related decisions

- **ADR-001:** Per-Tenant directory layout
- **ADR-002:** `storage_quota_mb` entitlement; provisioning creates dirs;
  termination deletes them
- **ADR-004:** Quota cron + backup cron use `withEachTenant`
- **ADR-006:** Super admin accessing files via `/api/file.php` is audited
