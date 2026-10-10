# Bindura Science Academy — School Management System (BSA SMS)

Development repository for the BSA school management system, built on the WordPress
portal `portal.bindurascienceacademy.co.zw` (Weblizar School Management + Blocksy).

## Layout

| Path | Purpose |
|---|---|
| `bsa-school-management/` | BSA extension plugin (deployed to `wp-content/plugins/`) |
| `bin/build.sh` | Lint and package the plugin into `dist/` |
| `tests/integration/` | Tests run on the site through Novamira, with synthetic data rolled back |
| `docs/` | Audit, decisions, deployment, backups, testing |

## Status

| Phase | Status |
|---|---|
| 0. Audit | Done ([audit](docs/audit-report.md)) |
| 1. Safety baseline | **Done** except owner actions: Google Drive authorisation, 2FA, SMTP details ([decisions](docs/decisions-log.md)) |
| 3. School configuration | Next |
| 4. Academics & report cards | Waiting for the report template and grade bands |
| 7. Extra lessons | Deferred by owner |

By owner decision, work happens directly on production. Every change is committed here.
