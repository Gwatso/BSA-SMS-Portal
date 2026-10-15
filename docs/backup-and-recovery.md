# Backup and Recovery

## Current arrangement (updated 2026-10-15)

| Layer | What | Where | Schedule | Status |
|---|---|---|---|---|
| Hosting (cPanel) | Account backups | — | — | **Not available**: the cPanel `backup` feature is disabled on this account |
| UpdraftPlus 1.26.9 | Database | `wp-content/updraft/` (web access denied), then Google Drive | Daily (with the 02:00 run; see note), keep 14 | Working: local and Google Drive |
| UpdraftPlus 1.26.9 | Plugins, themes, uploads, other `wp-content` | same | Daily 02:00 (Africa/Harare), keep 14 (database included in the same run) | Working: local and Google Drive (verified 2026-10-15) |
| Manual snapshot | `wp db export` + `tar` of `wp-content`, `wp-config.php`, `.htaccess` | `/home/thwxghpf/bsa-backups/<date>-<label>/` (outside web root, mode 600) | Before risky changes | Taken 2026-10-10 "pre-phase1" |

Google Drive (folder `UpdraftPlus`, account owner "Afriflarenet Pty Ltd") was authorised
by the owner on 2026-10-10. A verification backup at 17:49 uploaded every archive with no
errors. "Delete local backup after upload" was then switched **on**; manual snapshots in
`/home/thwxghpf/bsa-backups/` are kept separately.

**2026-10-15 schedule fix (Phase 9).** The UpdraftPlus settings read "daily" for files
and database, but the files schedule had stayed weekly (it is rebuilt only when the
settings are saved in the UpdraftPlus screen), and UpdraftPlus skips its separate
database run when files and database share a setting. So backups were in fact weekly:
none between 11 and 15 October. The schedule was rebuilt with UpdraftPlus's own function
(daily at 02:00 Africa/Harare, first run 16 October) and a full backup was run at once
(2026-10-15 08:08 UTC, uploaded to Google Drive, no errors). Check after any change to
the UpdraftPlus settings: *Settings → UpdraftPlus Backups → Existing backups* should show
a backup from the last day. `tests/integration/security.php` fails if the last backup is
more than 8 days old. Daily file backups use about 70 MB of Drive space each (14 kept);
switch files to weekly in UpdraftPlus settings if Drive space becomes short.

Database snapshots before each release are also kept in
`/home/thwxghpf/bsa-backups/<date>-<label>/portal-db.sql` (latest:
`2026-10-15-pre-hardening`).

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
