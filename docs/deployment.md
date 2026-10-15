# Deployment

By owner decision (2026-10-10) changes go directly to production. Each deployment:

1. **Snapshot**: manual backup to `/home/thwxghpf/bsa-backups/<date>-<label>/` (see `backup-and-recovery.md`).
2. **Build**: `bin/build.sh` lints every PHP file and writes `dist/bsa-school-management-<version>.zip`.
3. **Transfer**: this cloud session cannot reach the portal over HTTP, so files are either sent base64-encoded through Novamira `execute-php`, or (since 2026-10-14, the repository being public) pushed first and downloaded by the server from `raw.githubusercontent.com` at the exact commit. Either way they land in `/home/thwxghpf/bsa-deploy/` (outside the web root) and their SHA-256 is compared with the local copy before going further.
4. **Install**: first install with `wp plugin install /home/thwxghpf/bsa-deploy/<zip>` (inactive). Updates are unpacked into a stage folder next to the zip, linted, then copied over the live plugin folder (`cp -a`).
5. **Lint on server**: `php -l` on every file with the server's PHP 8.1 before anything goes live.
6. **Activate** (first install only): `wp plugin activate bsa-school-management`. WP-CLI loads the plugin first, so a fatal error aborts activation instead of breaking the site.
7. **Verify**: HTTP checks (front page, login, admin redirect, headers) and the integration tests in `tests/integration/`.

Small theme updates (CSS or a few lines of PHP) may instead be written file by file
through `execute-php`: each file is staged, its SHA-256 compared with the repo copy,
PHP linted, and only then copied into the theme. The table records the file checksums.
8. **Commit and push** to the repo.

## Deployed versions

| Date | Component | Version | SHA-256 (zip) | Verified |
|---|---|---|---|---|
| 2026-10-10 | bsa-school-management | 0.1.0 | `beef319e6fa8764067c0b9a42c452ab814dd5c9690f8fe51e0906a3a3dbcabf4` | HTTP checks + 20/20 integration checks |
| 2026-10-10 | bsa-school-management | 0.2.0 | `2a3a506ff4e5869ff3538ef371da43944d341dbd8a10ccfe89c759c95b7fbb55` | boot check; migration; private store 403; 60/60 + 20/20 integration checks |
| 2026-10-13 | bsa-school-management | 0.3.0 | `f99e5bc64a8c71b61ab58a6a68f782669f41deb0c0fd1ca05e211c8180c9f106` (files: class-bsa-finance.php `100831c9…`, main `1d1d82eb…`, readme `35a2b1ca…`, all matched) | staged copy tested 49/49 before going live; pages 200; hooks registered |
| 2026-10-14 | bsa-school-management | 0.4.0 | `52a74e295d5ae23c5dd54b44fa957609119f5bce25679b593f3c6be726af16b1` (files matched: school `8d8e4f21…`, terms `77311146…`, register `cef291ed…`, main `ecd2bc27…`, readme `d0fbc81a…`) | staged copy 53/53; snapshot `2026-10-14-pre-register`; pages 200; menus per role checked |
| 2026-10-14 | bsa-school-management | 0.5.0 | `42fd18d7edf0de0e19da9bb0c8c52a57c8480ccea4698a100d492445dedc4988` (files matched: grades `e4715ec1…`, marks `449bd88f…`, reports `aa525239…`, main `a211aa7c…`, readme `6653ab97…`; all 14 files checked) | staged copy 56/56 (+ register 53/53); snapshot `2026-10-14-pre-marks`; tables created; pages 200; menus per role checked |
| 2026-10-14 | bsa-school-management | 0.6.0 | `2fb4129c16cdac739f1dcb7f8e1f2c12e7eac9f659da7fa0e15f318a7e7fed31` (files downloaded by the server from the pushed commit, SHA-256 matched: family `a1118323…`, reports `cb9ae1a3…`, main `cd7f174b…`, readme `fba2112e…`) | staged copy 28/28; snapshot `2026-10-14-pre-family`; script 09 run; end to end 14/14 |
| 2026-10-15 | bsa-school-management | 0.7.0 | `2eaf353af20910cb6c40a27e3129b7952df5ef4d5677552339ee55913a74c35f` (files downloaded from the pushed commit, matched: accounts `f9fb295d…`, main `0d1324b2…`, readme `ba5fff53…`) | staged copy 19/19; snapshot `2026-10-15-pre-accounts`; rights per role; live check run |
| 2026-10-15 | bsa-school-management | 0.7.1 | `2f861d38432505772b4299bb082bed6cfcc3670d65c43718610b835b3d629d3c` (files from the pushed commit, matched: hardening `6cd11724…`, family `6f5a39fb…`, main `a6b97a81…`, readme `e686062e…`) | snapshot `2026-10-15-pre-hardening`; security 49/49; family 29/29; family-http 14/14 |

### Child theme deployment (bsa-portal)

The theme zip (`bin/build-theme.sh`) carries only text files. The server downloads the
font files from the URLs in `bsa-portal/assets/fonts/SOURCES.txt` and keeps each one only
if its SHA-256 matches. Updates to an active theme are unpacked over the existing
folder (not `wp theme install --force`, which would delete the fonts). PHP opcache
revalidates every 2 s, so wait a few seconds before verifying.

| Date | Component | Version | SHA-256 (code zip) | Verified |
|---|---|---|---|---|
| 2026-10-10 | bsa-portal | 0.1.0 | `ce5e07d60258f5c72d33dcbabaa8871d73ecef1c314bf046c18b2962d8b5fb6d` | fonts hash-matched; home/login 200 |
| 2026-10-10 | bsa-portal | 0.1.1 | `3203052a3c92ccef1cc6f72cc8edd7fc5262441f597dd9f090ab6a75ffd18d6e` | footer credit fixed |
| 2026-10-10 | bsa-portal | 0.1.2 | `76debf331de0a6864d3c8088ea3331717cdc5a2ef8f8e6592cb4baf645a664f1` | header layout; rendered at 1366 px and 390 px; design pre-flight ok |
| 2026-10-10 | bsa-portal | 0.2.0 | `4d6cfcacd842abe80321db07e4406f58355e1236811b9c76a4ed5e784a6825e8` | Portal home template, account styles; home and account 200 |
| 2026-10-10 | bsa-portal | 0.2.1 | files: functions.php, style.css, account.css, home.css (SHA-256 matched repo) | sign-in card and phone overflow fixes; rendered |
| 2026-10-10 | bsa-portal | 0.2.2 | files: functions.php `d0a9970b…`, style.css `5546f3bb…`, account.css `7520062f…` (matched repo) | signed-in dashboards; rendered at 1366 px and 390 px; design pre-flight ok |
| 2026-10-14 | bsa-portal | 0.2.3 | files: screenshot.png `d911d7af…`, style.css `16c6a987…` (downloaded by the server from the pushed commit on GitHub, SHA-256 matched) | WordPress reports the cover (1200 × 900, served 200 image/png); home 200 |
| 2026-10-14 | bsa-portal | 0.2.4 | files: account.css `1a35c4d4…`, functions.php `faef6ca0…`, style.css `33042f73…` (downloaded from the pushed commit, matched) | phone cards, BSA pages; home, account, help 200 |
| 2026-10-14 | bsa-portal | 0.2.5 | files: account.css `211600f3…`, functions.php `cec0d956…`, style.css `374db36e…` (matched) | dashboard notices visible again; duplicate settings link hidden; home, account, help 200 |
| 2026-10-15 | bsa-portal | 0.2.6 | files: account.css `87a31f81…`, functions.php `94c0d938…`, style.css `df939470…` (matched) | email wraps on the learner dashboard at 320 px; pages 200 |

## Third-party plugins installed (2026-10-10, from wordpress.org)

| Plugin | Version | Configuration |
|---|---|---|
| Simple History | 5.35.0 | Defaults; also receives "BSA blocked …" events |
| UpdraftPlus | 1.26.8 | See `backup-and-recovery.md` |
| Limit Login Attempts Reloaded | 3.3.10 | Defaults (local lockouts) |
| FluentSMTP | 2.4.1 | PHP mail() connection sending as Bindura Science Academy <info@bindurascienceacademy.co.zw> |
| Two Factor | 0.17.0 | Enabled on the administrator account (owner, 2026-10-10) |

## Plugins added outside this repo

Simple History shows these were installed and activated through the administrator
account in wp-admin at 2026-10-10 15:32 UTC (17:32 Harare), from IP 66.9.171.x (not
the Novamira/Claude session). They come from wordpress.org; owner to confirm.

| Plugin | Version | Notes |
|---|---|---|
| All-In-One Security (AIOS) | 5.4.9 | **Removed by owner 2026-10-13** (Limit Login Attempts Reloaded kept) |
| Burst Statistics | 3.7.2 | Adds the must-use plugin `burst_rest_api_optimizer.php` |
| WP-Optimize | 4.7.0 | Page caching must exclude logged-in users and portal pages with personal data |

## Rollback
- Fee rules only: remove `BSA_Finance::init();` from the main plugin file (Weblizar then
  behaves as before; invoice numbers already fixed stay fixed).
- BSA extension: `wp plugin deactivate bsa-school-management`. Since 0.2.0 it keeps
  admission documents (table `bsa_admission_records`, files in `uploads/bsa-private/`);
  deactivating leaves them in place, they are just not reachable until reactivated.
- Portal pages: Settings → Reading can point the front page elsewhere; the Home and My
  Account pages can be unpublished.
- Any plugin: deactivate via WP-CLI or wp-admin.
- Full: restore the pre-change snapshot.
