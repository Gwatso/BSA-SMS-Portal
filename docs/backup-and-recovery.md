# Backup and Recovery

## Current arrangement (2026-10-10)

| Layer | What | Where | Schedule | Status |
|---|---|---|---|---|
| Hosting (cPanel) | Account backups | — | — | **Not available**: the cPanel `backup` feature is disabled on this account |
| UpdraftPlus 1.26.8 | Database | `wp-content/updraft/` (web access denied), then Google Drive | Daily 01:30 (Africa/Harare), keep 14 | Working: local and Google Drive |
| UpdraftPlus 1.26.8 | Plugins, themes, uploads, other `wp-content` | same | Weekly, Sunday 02:00, keep 4 | Working: local and Google Drive |
| Manual snapshot | `wp db export` + `tar` of `wp-content`, `wp-config.php`, `.htaccess` | `/home/thwxghpf/bsa-backups/<date>-<label>/` (outside web root, mode 600) | Before risky changes | Taken 2026-10-10 "pre-phase1" |

Google Drive (folder `UpdraftPlus`, account owner "Afriflarenet Pty Ltd") was authorised
by the owner on 2026-10-10. A verification backup at 17:49 uploaded every archive with no
errors. "Delete local backup after upload" was then switched **on**; manual snapshots in
`/home/thwxghpf/bsa-backups/` are kept separately.

### Snapshots taken
| Label | Contents | SHA-256 |
|---|---|---|
| 2026-10-10-pre-phase1 | `portal-db.sql` (84 tables, complete dump) | `7880815296…a7a0cf9cc` |
| | `portal-files.tar.gz` (6,626 entries, gzip test OK) | `86b150effd…a3d1ee1004e` |
| UpdraftPlus 2026-10-10 17:03 | db, plugins, themes, uploads, others (31.6 MB) | recorded in UpdraftPlus history |

## Google Drive

Connected 2026-10-10. To reconnect (e.g. after a password change): Settings → UpdraftPlus
Backups → Settings → Google Drive → re-authenticate as info.afriflarenet@gmail.com.

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
