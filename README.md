# Bindura Science Academy — School Management System (BSA SMS)

Development repository for the BSA school management system, built on the WordPress
portal `portal.bindurascienceacademy.co.zw` (Weblizar School Management + Blocksy).

## Layout

| Path | Purpose |
|---|---|
| `bsa-school-management/` | BSA extension plugin (deployed to `wp-content/plugins/`) |
| `bsa-portal/` | Blocksy child theme with the school's branding (deployed to `wp-content/themes/`) |
| `bin/build.sh`, `bin/build-theme.sh` | Lint and package the plugin / theme into `dist/` |
| `scripts/` | Re-runnable configuration scripts (run through Novamira) |
| `tests/integration/` | Tests run on the site through Novamira, with synthetic data rolled back |
| `docs/` | Audit, decisions, permissions, deployment, backups, testing |

## Status

| Phase | Status |
|---|---|
| 0. Audit | Done ([audit](docs/audit-report.md)) |
| 1. Safety baseline | **Done** (off-site backups, 2FA, email verified). Open: DKIM DNS record |
| 3. School configuration | **Done**: school, Forms 1–4 (Sciences/Commercials streams in Forms 3–4), sessions, subjects, term fees, admission fields and documents, email, Teacher and Bursar roles ([permissions](docs/permission-matrix.md)). Branding and portal pages live (Home, My Account) |
| 4. Academics & report cards | Waiting for the report template and grade bands |
| 7. Extra lessons | Deferred by owner |

By owner decision, work happens directly on production. Every change is committed here.
