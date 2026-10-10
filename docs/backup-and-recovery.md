# Backup and Recovery

## Current arrangement (2026-10-10)

| Layer | What | Where | Schedule | Status |
|---|---|---|---|---|
| Hosting (cPanel) | Account backups | — | — | **Not available**: the cPanel `backup` feature is disabled on this account |
| UpdraftPlus 1.26.8 | Database | `wp-content/updraft/` (web access denied), then Google Drive | Daily 01:30 (Africa/Harare), keep 14 | Local working; **Google Drive not yet authorised** |
| UpdraftPlus 1.26.8 | Plugins, themes, uploads, other `wp-content` | same | Weekly, Sunday 02:00, keep 4 | Local working; Drive pending |
| Manual snapshot | `wp db export` + `tar` of `wp-content`, `wp-config.php`, `.htaccess` | `/home/thwxghpf/bsa-backups/<date>-<label>/` (outside web root, mode 600) | Before risky changes | Taken 2026-10-10 "pre-phase1" |

"Delete local backup after upload" is **off** until Google Drive works, so backups
stay on the server.

### Snapshots taken
| Label | Contents | SHA-256 |
|---|---|---|
| 2026-10-10-pre-phase1 | `portal-db.sql` (84 tables, complete dump) | `7880815296…a7a0cf9cc` |
| | `portal-files.tar.gz` (6,626 entries, gzip test OK) | `86b150effd…a3d1ee1004e` |
| UpdraftPlus 2026-10-10 17:03 | db, plugins, themes, uploads, others (31.6 MB) | recorded in UpdraftPlus history |

## Owner action: connect Google Drive

1. Log in to `https://portal.bindurascienceacademy.co.zw/wp-admin/` as the administrator.
2. **Settings → UpdraftPlus Backups → Settings**. Google Drive is already selected.
3. Click the Google Drive authentication link and sign in as **info.afriflarenet@gmail.com**, then allow access.
4. Click **Save Changes**, then **Backup Now** (tick "send this backup to remote storage") to confirm a copy appears in Drive under `UpdraftPlus`.
5. Tell Claude. We'll then switch "delete local after upload" on.

## Restore procedures

**Full restore with UpdraftPlus (preferred):** Settings → UpdraftPlus Backups →
Existing backups → Restore → tick components → Restore.

**Manual restore from a snapshot (if WordPress is down):**
```bash
cd /home/thwxghpf/portal.bindurascienceacademy.co.zw
wp db import /home/thwxghpf/bsa-backups/<snapshot>/portal-db.sql
tar -xzf /home/thwxghpf/bsa-backups/<snapshot>/portal-files.tar.gz -C .
```

**Rolling back the BSA extension only:** `wp plugin deactivate bsa-school-management`.
It stores no data, so deactivating it is fully reversible.

## Not yet tested
- A restore has **not** been tested, because there is no second environment to restore into. Before real learner data is entered, restore into a temporary subdomain or a local copy to prove the backups work.
- Off-site copy (Google Drive) is pending owner authorisation.
