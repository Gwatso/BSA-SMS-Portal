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
| 6. Finance | **Core done** (plugin 0.3.0): cash/bank rules, part-payments, unique invoice numbers, audit trail, Fee Reports. Waiting: bank details, term dates |
| 5. Attendance | **Done** (plugin 0.4.0): School Terms, Class Register mirroring the paper register. Waiting: term dates |
| 4. Academics & report cards | **Done** (plugin 0.5.0): Marks entry, grading rules, Report Cards (results, positions, comments, publish, print, parent view) ([spec](docs/academics-spec.md)). Waiting: subject-teacher assignments, remaining term dates |
| 8. Parent and learner portal | **Done** (plugin 0.6.0, theme 0.2.5): Report Cards for learners and parents, parent Fee Structure, account settings link, phone-friendly tables, notices fixed, fee lock-out off, unused Weblizar items hidden, Help page for families |
| 9. QA and hardening | **Done** (plugin 0.7.1, theme 0.2.6; home hero theme 0.3.0): all suites green, security sweep 49/49, accessibility and phone checks, login-name leak and backup schedule fixed ([report](docs/qa-report-phase9.md)) |
| — BSA Intranet | **Done** (plugin 0.11.0): /intranet staff hub with sliding top stories, news, notices with read receipts, achievements, events, OneDrive/Drive resources, calendar, tasks from school data, attendance chart with WhatsApp sharing |
| — Results dashboards | **Done** (plugin 0.10.0): results by class and subject, learners at risk with reasons (staff only), progression by term, one learner's year; Progress for families from published report cards |
| — Branded staff area | **Done** (plugin 0.9.0): no WordPress for anyone but the owner; BSA menu and toolbar; home screen per role (class teacher, subject teacher, bursar) |
| — School Dashboard | **Done** (plugin 0.8.0): one wp-admin screen for the owner and school administrator: needs attention, fees per class, today's register, marks entry |
| — Family Accounts check | **Done** (plugin 0.7.0): flags shared, crossed or missing family logins and bad emails; office guide |
| 7. Extra lessons | On hold until the owner confirms |

By owner decision, work happens directly on production. Every change is committed here.
