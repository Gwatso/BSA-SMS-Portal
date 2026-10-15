# Phase 9: QA and hardening report (2026-10-15)

Scope: the whole portal as deployed (WordPress 7.1.3, PHP 8.1.34, Weblizar School
Management 10.3.9, BSA plugin 0.7.1, BSA Portal theme 0.2.6). All checks ran on the live
site, with synthetic data rolled back or deleted afterwards.

## Summary

| Area | Result |
|---|---|
| Regression: all 8 test suites | **All pass** (300 checks) |
| Security sweep (`tests/integration/security.php`) | **49/49** after one fix |
| Access per role (7 BSA screens × 6 roles, over HTTP) | Matches the permission matrix exactly |
| Accessibility (axe-core, WCAG 2.1 A/AA, 9 pages) | 1 issue, fixed |
| Phones (390 px and 320 px) | No sideways scrolling; 1 small overflow fixed |
| PHP error logs (7 days) | No errors from BSA code; no fatal errors |
| Updates pending | None (core, plugins, themes) |
| Backups | **Problem found and fixed**: only weekly backups had been running |

## Findings and fixes

1. **Administrator login name visible to anyone (fixed, plugin 0.7.1).** `/?author=1`
   redirected to `/author/<login>/`, giving attackers the username to try passwords
   against. Author archives and `?author=` / `?author_name=` now answer 404; the users
   sitemap is removed; oEmbed data no longer names the author. (REST user listing was
   already hidden.)
2. **Backups ran weekly, not daily (fixed).** UpdraftPlus was set to daily files and
   daily database backups, but its schedule still had files weekly (the setting was
   changed without the schedule being rebuilt), and UpdraftPlus deliberately skips a
   database-only run when files and database share the same setting. Result: the last
   backup was 11 October and the next would have been 18 October. The schedule was
   rebuilt with UpdraftPlus's own function: files and database daily at 02:00
   Africa/Harare, first run 16 October; a full backup was started immediately.
3. **Notice badge without alt text (fixed, plugin 0.7.1).** Weblizar's animated "new"
   image beside recent notices had no text alternative (WCAG 1.1.1). It now reads "New".
4. **School email overflowing on narrow phones (fixed, theme 0.2.6).** On the learner
   dashboard at 320 px the school email ran 16 px past its panel; it now wraps.

## Regression run

| Suite | Result |
|---|---|
| access-guard (invoice and record ownership, REST and AJAX) | 20/20 |
| admission-and-portal (roles, admission documents, sign-in routing) | 60/60 |
| finance (payment rules, invoice numbers, Fee Reports, audit) | 49/49 |
| register (terms, Class Register) | 53/53 |
| reports (grades, marks, report cards) | 56/56 |
| family (learner and parent pages) | 29/29 |
| family-http (the same pages over HTTP, end to end) | 14/14 |
| accounts (Family Accounts check) | 19/19 |

Note for whoever runs the suites together: each suite installs its own `wp_die`
interception; a runner must not add another one in front of it (the admission suite
reported its expected refusals as failures until run on its own).

## Security sweep (49 checks)

- **Visitors over HTTP:** home, My Account, Help and sign-in answer 200 with no PHP
  output; HSTS, X-Frame-Options, X-Content-Type-Options and Referrer-Policy present;
  no X-Pingback; XML-RPC offers no `wp.*` methods; REST users list and user 1 hidden;
  author archives, users sitemap and oEmbed reveal no login names; the private uploads
  folder is refused; plugin files print nothing when requested directly and the folder
  is not listed; no public debug.log; the five Weblizar AJAX actions that exposed
  learner data are refused; a report-card link sends visitors to sign-in; the seven BSA
  form handlers do nothing for visitors; wp-admin sends visitors to sign-in.
- **Each role over HTTP** (owner, school administrator, Form 1 A teacher, bursar, sample
  parent, learner BSA001; sign-in sessions created for the run and destroyed after):

  | Screen | Owner | School admin | Teacher | Bursar | Parent | Learner |
  |---|---|---|---|---|---|---|
  | Admission Documents | ✓ | ✓ | – | – | – | – |
  | Family Accounts | ✓ | ✓ | – | – | – | – |
  | Fee Reports | ✓ | ✓ | – | ✓ | – | – |
  | School Terms | ✓ | ✓ | – | – | – | – |
  | Class Register | ✓ | ✓ | ✓ (own class) | – | – | – |
  | Marks | ✓ | ✓ | ✓ (own class / subjects) | – | – | – |
  | Report Cards | ✓ | ✓ | ✓ (own class) | – | – | – |

  Parents and learners asking for wp-admin are sent to My Account; their account page
  opens without PHP errors; a parent asking for another learner's report card gets 404.
- **Form handlers (code review):** all nine BSA form handlers check a nonce and then
  the user's rights before changing anything.
- **Configuration:** debug output off; theme/plugin file editor disabled; one WordPress
  administrator (the owner) with two-factor on; login lockout plugin active.

## Accessibility and phones

axe-core 4.10 with the WCAG 2.0/2.1 A and AA rules (including colour contrast) on the
home page, sign-in, My Account (signed out), Help, learner dashboard, learner Report
Cards, parent dashboard, parent Payment History and parent Fee Structure, rendered with
the live theme: no violations apart from the notice badge (fixed). At 320 px wide no
page scrolls sideways.

## Not covered

- A real person signing in on their own phone (pages are checked over HTTP and in a
  headless browser; no real family account is in use yet).
- Printing on paper (report cards, register).
- A full restore drill was done on 2026-10-10 (`backup-and-recovery.md`); not repeated.
- Content-Security-Policy: still not set (Weblizar relies on inline scripts).

## How to re-run

1. Regression: run each file in `tests/integration/` through Novamira (each keeps its
   results in a `bsa_test_*` transient).
2. Security and health: `tests/integration/security.php` (read-only).
3. Accessibility: render the pages and run axe-core with the WCAG A/AA tags.
