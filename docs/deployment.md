# Deployment

By owner decision (2026-10-10) changes go directly to production. Each deployment:

1. **Snapshot**: manual backup to `/home/thwxghpf/bsa-backups/<date>-<label>/` (see `backup-and-recovery.md`).
2. **Build**: `bin/build.sh` lints every PHP file and writes `dist/bsa-school-management-<version>.zip`.
3. **Transfer**: this cloud session cannot reach the portal over HTTP, so the zip is sent base64-encoded through Novamira `execute-php` to `/home/thwxghpf/bsa-deploy/` (outside the web root). Its SHA-256 is compared with the local build before going further.
4. **Install**: `wp plugin install /home/thwxghpf/bsa-deploy/<zip> --force` (inactive on first install).
5. **Lint on server**: `php -l` on every file with the server's PHP 8.1.
6. **Activate**: `wp plugin activate bsa-school-management`. WP-CLI loads the plugin first, so a fatal error aborts activation instead of breaking the site.
7. **Verify**: HTTP checks (front page, login, admin redirect, headers) and `tests/integration/access-guard.php`.
8. **Commit and push** to the repo.

## Deployed versions

| Date | Component | Version | SHA-256 (zip) | Verified |
|---|---|---|---|---|
| 2026-10-10 | bsa-school-management | 0.1.0 | `beef319e6fa8764067c0b9a42c452ab814dd5c9690f8fe51e0906a3a3dbcabf4` | HTTP checks + 20/20 integration checks |

## Third-party plugins installed (2026-10-10, from wordpress.org)

| Plugin | Version | Configuration |
|---|---|---|
| Simple History | 5.35.0 | Defaults; also receives "BSA blocked …" events |
| UpdraftPlus | 1.26.8 | See `backup-and-recovery.md` |
| Limit Login Attempts Reloaded | 3.3.10 | Defaults (local lockouts) |
| FluentSMTP | 2.4.1 | **Not configured**: needs the school mailbox's SMTP details |
| Two Factor | 0.17.0 | **Not enabled for any user yet**: the owner turns it on in their profile |

## Plugins added outside this repo

Simple History shows these were installed and activated through the administrator
account in wp-admin at 2026-10-10 15:32 UTC (17:32 Harare), from IP 66.9.171.x (not
the Novamira/Claude session). They come from wordpress.org; owner to confirm.

| Plugin | Version | Notes |
|---|---|---|
| All-In-One Security (AIOS) | 5.4.9 | Overlaps with Limit Login Attempts Reloaded (login lockout) and with BSA hardening (XML-RPC, headers); keep one lockout tool |
| Burst Statistics | 3.7.2 | Adds the must-use plugin `burst_rest_api_optimizer.php` |
| WP-Optimize | 4.7.0 | Page caching must exclude logged-in users and portal pages with personal data |

## Rollback
- BSA extension: `wp plugin deactivate bsa-school-management` (stores no data).
- Any plugin: deactivate via WP-CLI or wp-admin.
- Full: restore the pre-change snapshot.
