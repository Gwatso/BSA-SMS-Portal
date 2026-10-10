# Changelog

## 2026-10-10: Restore test

- Restore test passed: backup retrieved from Google Drive (checksums match) and restored
  into an isolated temporary copy (118/118 tables, copy boots with all plugins).
  Temporary database, user and folder deleted.
- Found and documented: AIOS 5.4.9, Burst Statistics 3.7.2 and WP-Optimize 4.7.0, installed
  via wp-admin as the administrator at 15:32 UTC (owner to confirm).

## 2026-10-10: Phase 3 step 3 + backups off-site (production)

- Form 4: section "A" renamed **Sciences**; **Commercials** added (`scripts/03-form4-streams.php`,
  snapshot `portal-db-step3.sql` first).
- UpdraftPlus: Google Drive authorised by owner; verification backup 17:49 uploaded with no
  errors; "delete local after upload" switched on.
- Two Factor enabled on the administrator account (owner).

## 2026-10-10: Phase 3 step 2: subjects, streams, admission fields, email (production)

Applied by `scripts/02-subjects-streams-fields-email.php` after snapshot
`/home/thwxghpf/bsa-backups/2026-10-10-pre-phase3/portal-db-step2.sql`.

- Forms 1–2: removed Combined Science, Business Enterprise Skills, Heritage Studies (10 subjects each).
- Form 3: section "A" renamed **Sciences**; section **Commercials** added.
- Admission fields: caste, blood group, transport off; religion stays on.
- Email: Weblizar carrier → wp_mail; FluentSMTP connection "PHP mail()" sending as
  Bindura Science Academy <info@bindurascienceacademy.co.zw> (forced from name/email).
  One test email sent to info@ (FluentSMTP log: sent).
- `scripts/configure-school.php` renamed `scripts/01-configure-school.php`.

## 2026-10-10: Phase 3 school configuration (production)

Applied through Weblizar's own admin handlers by `scripts/configure-school.php`
(snapshot first: `/home/thwxghpf/bsa-backups/2026-10-10-pre-phase3/portal-db.sql`).

- School: "Default School" → **Bindura Science Academy**, phone +263 77 260 9503,
  info@bindurascienceacademy.co.zw, 3159 Aerodrome Medium, Bindura.
- Classes: default "1st"–"4th" renamed **Form 1–Form 4**; unused "5th"–"12th" deleted;
  Forms 1–4 assigned to the school with section "A".
- Sessions: 2026-2027 / 2027-2028 (Apr–Mar) → **2026** and **2027** (Jan–Dec).
- Subjects: 13 website subjects for each form (52 records).
- Fees: Term 1, Term 2, Term 3 Fees, USD 250 each, per form (12 records), one-time, active on admission.

## 2026-10-10: Phase 1 safety baseline (production)

### Added
- `bsa-school-management` 0.1.0 deployed and active:
  - Access guard: blocks anonymous Weblizar lookups of learner invoices, results,
    admit cards and certificates, and public learner/staff self-registration; enforces
    learner/parent ownership on invoice AJAX and REST endpoints.
  - Hardening: XML-RPC disabled, file editor disabled, security headers (HSTS,
    X-Frame-Options, X-Content-Type-Options, Referrer-Policy, Permissions-Policy),
    REST users list hidden from anonymous visitors.
  - Notice if Weblizar is missing or at an unreviewed version.
- Plugins from wordpress.org: Simple History 5.35.0, UpdraftPlus 1.26.8, Limit Login
  Attempts Reloaded 3.3.10, FluentSMTP 2.4.1, Two Factor 0.17.0.
- `tests/integration/access-guard.php` (20/20 pass), `bin/build.sh`.

### Changed
- Site timezone UTC → Africa/Harare.
- UpdraftPlus: database daily 01:30 (keep 14), files weekly Sunday 02:00 (keep 4), Google Drive selected (authorisation pending).

### Backups
- Manual pre-change snapshot `/home/thwxghpf/bsa-backups/2026-10-10-pre-phase1/`.
- First UpdraftPlus backup 2026-10-10 17:03 (31.6 MB).

## 2026-10-10: Phase 0
- Read-only audit: `docs/audit-report.md`.
