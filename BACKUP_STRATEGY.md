# Backup Strategy

This document covers what's backed up, what isn't, and how to recover. It was
written by auditing the codebase and deploy config — **the items marked
"⚠️ needs confirming" are things only the Render dashboard / provider console
can answer, not the repo.** Treat this as a starting checklist to verify
against production, not a guarantee of what's currently configured.

## What data exists and where

| Data | Where it lives | Loss impact |
|---|---|---|
| Primary data (users, transactions, ledger, KYC records, lands, etc.) | PostgreSQL + PostGIS | Critical — this is the system of record for money and ownership |
| Session/cache/queue state | Redis | Low — ephemeral by design; queue jobs would be lost mid-flight, no financial state lives here (see `EnsureIdempotency`, which is itself designed to survive a retry) |
| KYC images (ID photos, liveness captures) | Cloudflare R2 (S3-compatible), via the `r2` disk in `config/filesystems.php` | Critical — regulatory requirement, not easily re-collected from users |
| Application logs, audit log (`LogSensitiveRequests` → `audit` channel) | Local disk on the web/worker containers | Medium — useful for incident review and compliance, not required for the app to function |
| Uploaded support ticket attachments | Local or R2 depending on `storeAttachment()` config — confirm which | Low-medium |

## Current state (as found in this repo)

- `render.yaml` defines the web service and queue worker but **does not
  define a managed Postgres or Redis instance** — they're provisioned
  separately (Render dashboard or an external managed provider). This means
  backup policy for the actual database is **not controlled by anything in
  this repo** and must be verified directly where the database is hosted.
- No backup or restore scripts, cron jobs, or documentation exist anywhere
  in the codebase prior to this file.
- KYC images already live on R2 rather than local disk (see item #5 in the
  main todo list), which removes one single-point-of-failure risk — R2
  storage durability is Cloudflare's responsibility, not this app's.

## ⚠️ Needs confirming in the Render dashboard / DB provider console

- [ ] **Automated backup schedule** — does the Postgres instance have
      point-in-time recovery (PITR) or scheduled snapshots enabled? Render's
      managed Postgres offers daily backups on paid plans by default, but
      this must be confirmed per-instance, not assumed.
- [ ] **Backup retention window** — how many days/weeks of backups are kept?
- [ ] **Backup region** — is the backup stored redundantly outside the
      primary instance's region/availability zone?
- [ ] **Restore testing** — has a restore ever actually been performed
      end-to-end (not just "backups exist")? An untested backup is not a
      verified backup.
- [ ] **R2 bucket versioning/replication** — is versioning enabled on the
      KYC images bucket to protect against accidental overwrite/delete
      (as opposed to instance loss, which R2 already handles)?
- [ ] **Who has restore access, and is it logged?** Restoring production
      data is itself a sensitive admin action — confirm it would show up
      somewhere (e.g. provider audit log), consistent with how in-app admin
      actions are tracked via `AdminActionLog`.

## Recommended minimum bar

1. **Daily automated Postgres backups**, retained at least 7–14 days,
   stored in a different region/zone than the primary instance.
2. **Point-in-time recovery (PITR)** if the provider offers it — daily
   snapshots alone mean losing up to 24h of transactions (deposits,
   withdrawals, purchases) on any incident; PITR narrows that to minutes.
3. **A documented, tested restore procedure** — not just "backups are
   enabled." At minimum: how to spin up a new Postgres instance from the
   latest backup, point a staging copy of the app at it, and confirm the
   app boots and reads data correctly. Do this at least once, and repeat
   after any major schema change (e.g. after `2026_08_12_000003_create_rbac_tables.php`
   or similar migrations that reshape core tables).
4. **R2 bucket versioning** enabled for the KYC images bucket, given the
   regulatory/compliance weight of that data and that it can't be
   regenerated from other sources if deleted.
5. **A named owner and a written incident runbook** for "production
   database is unavailable / corrupted" — who gets paged, who has restore
   permissions, and what the rollback communication plan is (status page,
   user comms) while a restore is in progress. This app moves real money
   (Paystack deposits/withdrawals), so a restore-in-progress window has
   real user impact beyond downtime — e.g. deposits/withdrawals that were
   in `processing` state (see the #7 fix on `WithdrawalController`) need an
   explicit reconciliation step against Paystack's own records after any
   restore, since a restored snapshot could be older than the last
   confirmed gateway callback.

## Out of scope for this document

This covers infrastructure backup/restore, not:
- Idempotency/retry correctness during normal operation (already covered:
  `EnsureIdempotency` middleware, the #7 withdrawal transaction fix)
- Audit trail of *application-level* changes (already covered:
  `AdminActionLog`, `ledger_entries`, `LogSensitiveRequests`)

Those solve "did we record what happened correctly." This document is about
"can we get the data back if the infrastructure holding it is lost."
