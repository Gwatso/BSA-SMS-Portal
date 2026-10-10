# Changelog

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
