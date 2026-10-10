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

## Restore test: 2026-10-10 (PASS)

Restored the UpdraftPlus set of 2026-10-10 17:49 into an isolated copy. Live site untouched.

1. **Retrieval from Google Drive:** local copies of the `db` and `themes` archives moved
   aside; both re-downloaded with UpdraftPlus's own `get_remote_file()`. SHA-256 of each
   download matched the checksum recorded at backup time.
2. **Isolated environment:** a temporary MySQL database/user (cPanel `setup_db_and_user`) and
   folder `/home/thwxghpf/bsa-restore-test` (outside every web root), WordPress 7.1.3
   core, `wp-config.php` with `DISABLE_WP_CRON`, `DISALLOW_FILE_MODS` and
   `WP_HOME=https://restore-test.invalid`, so the copy could not act as the live site.
3. **Restore:** plugins, themes, uploads, others and mu-plugins archives extracted; database
   dump (118 tables) imported with `wp db import`.
4. **Verification:** 118/118 tables present; 116 tables with identical row counts to live.
   The only two differences were changes made *after* the backup (Form 4 "Commercials"
   section +1, options +2). School name, 46 subjects and 12 fee rows correct. The copy
   booted through WP-CLI with all 12 plugins active, Weblizar 10.3.9, the BSA guard
   loaded and the admin user present.
5. **Clean-up:** temporary database, user and folder deleted; live verified (home 200,
   database `thwxghpf_wp_ak9qh`, data intact).

Repeat at least once a term, and after major changes.
