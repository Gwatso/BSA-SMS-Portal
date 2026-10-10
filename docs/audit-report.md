# Bindura Science Academy — School Management System
# Technical Audit and Implementation Plan

Audit date: 2026-10-10 · Phase 0 (read-only) · Target: `portal.bindurascienceacademy.co.zw`

Status labels: **VERIFIED** · **PARTIALLY VERIFIED** · **NOT VERIFIED** · **NOT ACCESSIBLE**

Method: Novamira MCP on the live portal (read-only PHP and file reads), cPanel UAPI
(read-only listings), and server-side HTTP fetches of the public website. No settings,
content, files, users or data were changed. No personal records were read: the
database holds none yet.

---

## 0. Executive summary

- **The portal is a fresh install.** Weblizar *The School Management* (Pro build,
  v10.3.9) was activated on 2026-10-10 at 13:47 server time and is still at its
  defaults ("Default School", classes "1st"–"12th", April–March sessions, English/Hindi
  media). It holds **0 students, 0 fees, 0 results** and 1 WordPress user. There are no
  pages and no media. Nothing needs to be migrated.
- **Critical: the plugin copy looks nulled.** Its licence check is edited to always pass
  (`WLSM_LM::is_valid()` and `WLSM_Helper::lm_valid()` begin with `return true;`), and
  no licence key is stored. The vendor build is from April 2024, so it gets no updates.
  A pattern scan found no obvious backdoor, but that does not prove the code is clean.
  Get a genuine licence and a clean copy from Weblizar before any real learner data is
  entered.
- **High: there is no backup.** The cPanel account's `backup` feature is disabled,
  WordPress Manager isn't installed, and no backup plugin is active.
- **Medium: the plugin has an insecure direct object reference (IDOR).** Its student and
  parent invoice REST endpoints return any unpaid invoice by ID without checking that it
  belongs to the requesting learner (§7.1). Nothing is exposed today because there is no
  data.
- **The plugin covers most of the requested scope**: admissions, classes and sections,
  subjects, timetable, attendance, exams with grade criteria, results, academic reports,
  fees, invoices, partial and offline payments, notices, parent and student accounts,
  and SMS/email. The main gaps are an extra-lessons model, result approval, an audit
  trail, a BSA-specific dashboard, and branding.
- **The plugin offers almost no extension hooks** (only `wp_login` and
  `widget_title`). A BSA extension will have to read the plugin's tables directly and
  use generic WordPress and REST filters. This dependency on plugin internals is
  documented in §8.

---

## 1. Confirmed environment

| Item | Finding | Status |
|---|---|---|
| Connection | Novamira connector `novamira-portal-bindurasc…`, OAuth, acting as WP user ID 1 | VERIFIED |
| Site / home URL | `https://portal.bindurascienceacademy.co.zw` | VERIFIED |
| `wp_get_environment_type()` | `production` | VERIFIED |
| Staging | None. **The owner directed work directly on production (2026-10-10).** | VERIFIED |
| Backups | cPanel `backup` feature disabled; `WordPressBackup` module not installed; no backup plugin | VERIFIED (none exist) |
| Hosting | Shared cPanel account (LiteSpeed) that also serves ~30 other domains and subdomains under the same system user | VERIFIED |
| Novamira AI abilities | Enabled (PHP execution, file write, WP-CLI) | VERIFIED |

## 2. Technology inventory

| Component | Value | Status |
|---|---|---|
| WordPress | 7.1.3 (latest available per update transient) | VERIFIED |
| PHP | 8.1.34 | VERIFIED |
| Database | MariaDB 10.11.19, `utf8mb4`, non-default table prefix | VERIFIED |
| Theme | Blocksy 2.1.59 (parent only, no child theme) | VERIFIED |
| Plugins | Blocksy Companion 2.1.59 · Novamira 1.12.8 (auto-update on) · The School Management (Pro) 10.3.9 by Weblizar | VERIFIED |
| MU plugins / drop-ins | None | VERIFIED |
| Novamira sandbox | Exists, empty (only protective `.htaccess`/`index.html`/`web.config`) | VERIFIED |
| Caching / security / backup / SMTP / activity-log plugins | None | VERIFIED |
| `WP_DEBUG` | false (`WP_DEBUG_DISPLAY` true, irrelevant while debug off) | VERIFIED |
| `DISALLOW_FILE_EDIT` | Not set (theme/plugin editor available to admins) | VERIFIED |
| `WP_MEMORY_LIMIT` | 40M (low for PDF/report generation) | VERIFIED |
| Timezone | Not set (UTC+0); Zimbabwe is UTC+2 (`Africa/Harare`) | VERIFIED |
| Date format | WP `m/d/Y`; plugin `d-m-Y` | VERIFIED |
| Registration | `users_can_register` = 0 | VERIFIED |
| Search visibility | `blog_public` = 1 (portal is indexable) | VERIFIED |
| Front page | Blog posts listing (no pages exist) | VERIFIED |

## 3. Verified website branding

Source: the public site `bindurascienceacademy.co.zw`, a Lovable-built React app (not
WordPress), fetched from the server. Its JSON-LD and JS bundle hold the school's own
data.

| Item | Value | Status |
|---|---|---|
| Official logo | Shield crest, navy/red/white, book + mortarboard + lightbulb, ribbon "PASSION FOR EXCELLENCE". PNG 677×800, 744 KB: `https://bindurascienceacademy.co.zw/__l5e/assets-v1/7fd33cf8-d3ea-4aeb-9dc9-54bf52680d60/bsa-logo-premium.png` | VERIFIED (viewed) |
| Logo variations | Only the one full-colour PNG found. No SVG, monochrome or horizontal lock-up. | VERIFIED (none others) |
| Portal media library | Empty; WordPress custom logo not set | VERIFIED |
| Primary colour | Navy `#1B3674` (hsl 222 62% 28%) | VERIFIED |
| Primary light | `#376DBE` (hsl 216 55% 48%) | VERIFIED |
| Accent (gold) | `#CEA378` (hsl 30 47% 64%) | VERIFIED |
| Crest red | `#A71B32` (hsl 350 72% 38%, used as "destructive") | VERIFIED |
| Text / muted / surface / border | `#1D212B` / `#6B7280` / `#F6F7F9` / `#E5E7EB` | VERIFIED |
| Typography | Headings: **Playfair Display** (serif). Body: **Plus Jakarta Sans**. Both Google Fonts. | VERIFIED |
| Motto | "Passion for Excellence" (on the crest, JSON-LD `slogan`, site config) | VERIFIED |
| Other tagline | Page `<title>`: "Excellence Through Science" | VERIFIED: **discrepancy, see Q8** |
| Name / short name | Bindura Science Academy / BSA; founded 2022 | VERIFIED |
| Address | 3159 Aerodrome Medium, Bindura, Mashonaland Central, Zimbabwe | VERIFIED |
| Email | info@bindurascienceacademy.co.zw | VERIFIED |
| Phones | +263 77 260 9503 (also WhatsApp), +263 77 807 0422, +263 78 365 5488 | VERIFIED |
| Registration | "MoPSE, ZIMSEC & EMIS 4770" | VERIFIED (as published) |
| Imagery | Campus, labs, computer lab, robotics, sports, assembly, leadership photos hosted on the public site | VERIFIED |

**Discrepancies with the project brief (need a decision, see §11):**
1. The brief says formal classes are **Form 1–4**. The public site says **"Form 1 to A' Level"** and advertises a **Lower Sixth intake in January 2027**.
2. The public site lists **O' Level (13)** and **A' Level (8)** subjects, and "ZJC" subjects. These are a starting point for configuration only, not confirmed data.
3. Extra lessons are advertised as covering **ZIMSEC and Cambridge International** ("Cambridge International is offered only through Extra Lessons").

## 4. Current Weblizar capabilities

Plugin: `school-management-pro/` v10.3.9, changelog dated 06-04-2024. Multi-school
architecture. 2,596 PHP files including vendored SDKs (Stripe, Twilio, Guzzle,
Firebase JWT).

| Area | Present in code | Configured | Notes |
|---|---|---|---|
| Schools (multi-school) | Yes | 1 ("Default School", no contact details) | Admission/enrolment number prefixes per school |
| Sessions | Yes | 2026-2027, 2027-2028 (Apr–Mar defaults) | No "term" entity; terms would be exams/exam groups |
| Classes / sections | Yes | Global labels "1st"–"12th"; no class↔school links; 0 sections | Need Form 1–4 (+6th?) |
| Admissions / inquiries / online registration | Yes (shortcodes) | Not configured | Student record fields include religion, caste, blood group: hide unneeded ones |
| Students / ID cards / certificates / transfers | Yes | — | |
| Promotion | Yes | — | Creates a **new** `student_records` row per session, linked by `wlsm_promotions`. Old records are kept. |
| Parent accounts | Yes (`parent_user_id` on student record) | — | One parent user per student record |
| Staff, roles, granular permissions | Yes: 33 `manage_*` permissions in `wlsm_roles` | No roles defined | Permissions are all-or-nothing per area (e.g. `manage_exams` covers both mark entry and publishing) |
| Subjects / teacher–subject | Yes (`wlsm_admin_subject`) | 0 subjects | Subject types: Theory/Practical/Subjective/Objective |
| Timetable | Yes | — | |
| Attendance (student/staff) | Yes (daily or per subject, with reason) | — | |
| Homework, study materials, lessons, live classes (Zoom/BBB) | Yes | — | |
| Exams, papers, admit cards | Yes | — | |
| Marks, results, grade criteria, remarks, rank | Yes (`grade_criteria` per exam, teacher/school remarks, psychomotor) | — | Grading rules must come from BSA |
| Academic reports (multi-exam) / bulk print results | Yes | — | |
| Fees, invoices, partial payments, receipts | Yes | Currency **USD** (default) | Offline payments recorded by staff with method and note |
| Online gateways | Razorpay, Paytm, SSLCommerz, Stripe, PayPal, Pesapal, Paystack, Authorize.net, bank/UPI transfer | None enabled | No Zimbabwe-specific gateway (e.g. Paynow) |
| Income / expenses | Yes | — | |
| Noticeboard / events | Yes | — | |
| Email | Yes (wp_mail or plugin SMTP) + templates | Not configured | |
| SMS | 25+ providers (Twilio, Nexmo, etc.) + WhatsApp | Not configured | |
| Library, transport, hostel | Yes | — | **Out of scope:** leave unused |
| Logs | `wlsm_logs` table | Empty | Not a full audit trail for edits and deletions |
| REST API | `/sm/…` (45 routes) for student/parent apps | — | See §7 |
| Extension hooks | Only `wp_login`, `widget_title` | — | Integration has to rely on plugin internals |
| Licence | Check bypassed in code; no key stored | — | See §7.2 |

## 5. Current portal page map

| URL | Purpose | Status |
|---|---|---|
| `/` | Default Blocksy blog index, titled "BSA School Management System" | VERIFIED |
| `/wp-login.php` | WordPress login (unbranded) | VERIFIED |
| `/wp-admin/` | Weblizar admin menus | VERIFIED (by code) |
| Other pages | **None.** No pages exist, so no shortcode pages (account, registration, fees, results, noticeboard). | VERIFIED |
| Menus | One `wp_navigation` post; no classic menu locations assigned | VERIFIED |

Everything learners, parents and staff see on the front end still needs to be built.

## 6. Feature-gap matrix

Methods: A configure · B frontend around existing · C BSA extension · D other plugin ·
E external service · F defer · G owner decision.

| Requirement | Existing capability | Gap | Proposed solution | Method | Priority | Needs approval |
|---|---|---|---|---|---|---|
| School identity | Default School, no details | Name, contacts, logo | Configure school record, logo, admission prefixes | A | P0 | No (verified data) |
| Forms 1–4 (+A' Level?) | Classes "1st"–"12th" | Wrong labels | Create Form 1–4 (and Lower/Upper 6th if confirmed), link to school, sections | A | P0 | **Q1** |
| Sessions / terms | Apr–Mar sessions | ZW calendar is Jan–Dec, 3 terms | Session per calendar year; terms as exam groups | A | P0 | **Q1** |
| Subjects & teacher assignment | Supported | Subject lists | Configure per form from approved list | A | P1 | **Q2** |
| Learner admission & records | Supported | Unneeded sensitive fields (religion, caste, blood group) | Hide fields in BSA frontend/admin CSS+filters; data-minimisation policy | A+C | P1 | **Q7** |
| Stable learner identity | Per-session records + promotion links | Readable history across sessions | BSA learner-history view joining promotions | C | P2 | No |
| Marks, grading | `grade_criteria` per exam | BSA rules unknown | Configure from approved rules | A | P1 | **Q3** |
| Result verification / publishing control | Single `manage_exams` permission | No teacher-enters / head-approves split; no change history | BSA approval and audit layer on results | C | P1 | **Q3** |
| Report cards | Result/academic-report print | BSA branding, template, comments | BSA-branded print template | B/C | P1 | **Q4** |
| Attendance | Daily/subject, reasons | Status labels per BSA policy | Configure; BSA register UI if needed | A | P1 | Default OK |
| Fees, invoices, partial, receipts | Supported, offline payments | Currency/methods; reversal audit | Configure; BSA audit log on payment edit/delete; restrict delete | A+C | P1 | **Q6** |
| Online payments | Gateways without ZW coverage | Paynow/EcoCash etc. | Defer; manual recording first | F/E | P3 | Later |
| Teacher scoped access | Roles + per-staff permissions | Coarse granularity | Define BSA roles from the 33 permissions | A | P1 | Permission matrix |
| Parent / learner portal | `[school_management_account]` + REST | No pages; invoice IDOR | Build pages; patch IDOR in BSA extension | B+C | P1 | No (security fix) |
| **Extra lessons** | Multi-school; `student_type` | No programme model | See §8.3: second Weblizar school "BSA Extra Lessons" (recommended) or custom tables | A or C | P1 | **Q5** |
| Notices / communication | Noticeboard per class; email/SMS | No verified mail/SMS | FluentSMTP; SMS provider later | A+D | P2 | **Q6** |
| Admin dashboard | Generic plugin dashboard | BSA KPIs, formal vs extra-lessons split | BSA dashboard page | C | P2 | No |
| Reports / exports | Plugin reports, CSV exports | Extra-lessons/formal split | Use plugin; add only gaps | A/C | P2 | No |
| Audit trail | `wlsm_logs` minimal | Who changed marks/payments | Activity-log plugin + BSA hooks on plugin AJAX actions | D+C | P1 | Plugin install |
| Backup | None | Everything | Backup plugin to off-site storage | D | **P0** | **Install approval** |
| Branding | None on portal | Logo, colours, fonts, login | Blocksy child theme + BSA tokens | C | P1 | No (verified brand) |

## 7. Security and privacy findings

### 7.1 Invoice IDOR in Weblizar REST API: **Medium** · PARTIALLY VERIFIED (code review, not exploited)
`WLSM_Api::fee_invoice()` (`/sm/student/fee-invoices/{invoice_id}`) and
`WLSM_Api::parent_fee_invoice()` (`/sm/parent/students/{student_id}/fee-invoices/{invoice_id}`)
load the invoice with `WLSM_M_Staff_Accountant::get_student_pending_invoice($invoice_id)`.
That query filters only on invoice ID and status, not on the requesting learner. The
parent handler checks that `student_id` is the parent's own child, then returns
**another learner's** unpaid invoice if any ID is supplied. Exposed fields: learner
name, enrolment number, class, section, invoice number and amounts. The payment,
exam-result and attendance handlers do scope correctly to the learner.
**Fix:** a BSA extension `rest_request_before_callbacks` guard that verifies
`invoice.student_record_id` belongs to the current student or parent. The shortcode
account pages need the same review.

### 7.2 Probable nulled premium plugin: **Critical (supply-chain / legal)** · VERIFIED (code)
- `admin/inc/manager/WLSM_LM.php`: `public function is_valid() { return true; …`
- `includes/helpers/WLSM_Helper.php`: `public static function lm_valid() { return true; …`
- No `wlsm-key` option. Version 10.3.9 (April 2024), so no vendor updates.
- A pattern scan of 2,596 PHP files (eval/obfuscation/shell/user-creation) found only
  legitimate uses: licence checker, payment SDKs, image upload, exports. **This doesn't
  rule out a well-hidden backdoor.**
- **Action:** buy a licence from Weblizar and replace the plugin with a vendor-supplied
  copy *before* real records are entered. With no data today, replacing it now is cheap.

### 7.3 Other findings
| Finding | Risk | Status |
|---|---|---|
| No backups of any kind | High | VERIFIED |
| Shared hosting account with ~30 other sites under one Unix user: a compromise of any of them reaches the portal | High | VERIFIED |
| Novamira AI abilities (arbitrary PHP) enabled on production, connected as the only administrator | High (by owner decision) | VERIFIED |
| No security headers (HSTS, X-Frame-Options, X-Content-Type-Options, Referrer-Policy, CSP) | Medium | VERIFIED |
| XML-RPC enabled (`system.listMethods` answers) | Medium (brute-force amplification) | VERIFIED |
| No login rate limiting or 2FA | Medium | VERIFIED |
| `DISALLOW_FILE_EDIT` not set | Low–Medium | VERIFIED |
| Portal indexable (`blog_public`=1) | Low | VERIFIED |
| Sole administrator account; no break-glass second admin | Low–Medium | VERIFIED |
| Uploads directory listing | Blocked (403) | VERIFIED |
| `/wp/v2/users` | Returns 0 users today (admin has no posts) | VERIFIED |
| REST `/sm/global/settings` public | Returns only currency and date format | VERIFIED: acceptable |
| Plugin student records include religion, caste, blood group, parent ID proof fields | Privacy (data minimisation) | VERIFIED |
| Plugin `get_robots()` writes to its own `changelog.txt` | Low (code quality) | VERIFIED |
| Zimbabwe Cyber and Data Protection Act [Ch. 12:07]: learner data, data of minors, a possible POTRAZ data-controller licence | Needs institutional/legal review | NOT VERIFIED (out of technical scope) |

## 8. Proposed technical architecture

```
WordPress 7.1 (production portal)
 ├─ Blocksy (parent) ─ bsa-portal child theme   ← branding, login, page templates
 ├─ Weblizar School Management (licensed copy)   ← system of record
 ├─ bsa-school-management (BSA extension)        ← security patches, audit, approvals,
 │                                                  dashboard, report-card template,
 │                                                  extra-lessons glue
 └─ Essentials only: backup, SMTP, activity log, login protection
```

### 8.1 Rules
- Weblizar tables remain the system of record. Never edit vendor files. Write to vendor
  tables only through the plugin's own UI and AJAX handlers, unless an approved
  migration says otherwise.
- The BSA extension **reads** vendor tables. Their schema (verified in this audit) is a
  documented dependency, and the extension checks `WLSM_VERSION` at load and refuses to
  run on an untested major version.
- Custom tables only where needed (result approvals, audit entries), prefixed
  `{$prefix}bsa_`, with versioned migrations.
- All code lives in this repo and is deployed to the server as a normal plugin and child
  theme. No business logic is left in the Novamira sandbox.

### 8.2 Data relationships (verified)
`wlsm_schools` 1─* `wlsm_class_school` (class×school) 1─* `wlsm_sections` 1─* `wlsm_student_records` (one per learner per session)
`wlsm_student_records` → `user_id` (learner login), `parent_user_id` (parent login), `session_id`, `student_type`
`wlsm_promotions(from_student_record, to_student_record)` links a learner across sessions
Exams: `wlsm_exams` 1─* `wlsm_exam_papers`; `wlsm_admit_cards(student_record_id, exam_id)` 1─* `wlsm_exam_results`
Fees: `wlsm_invoices(student_record_id)` 1─* `wlsm_payments`
Attendance: `wlsm_attendance(student_record_id, attendance_date, status, subject_id)`

### 8.3 Extra-lessons options (decision Q5)
| Option | How | Pros | Cons |
|---|---|---|---|
| **A. Second Weblizar school "BSA Extra Lessons"** (recommended) | The plugin's built-in multi-school feature | Reuses attendance, fees, invoices, notices, materials, timetable; formal counts stay separate (per school); separate invoice/admission numbering | A learner in both programmes gets two student records; the BSA extension links them by shared `user_id`/`parent_user_id`. Needs a test that the account page handles a user with records in two schools. |
| B. `student_type = "Extra Lessons"` in the main school | Built-in field | Simplest | Mixes extra-lessons learners into formal classes and counts: **rejected** |
| C. Custom BSA tables | Extension | Full control | Rebuilds attendance and fees that Weblizar already has: duplication |

## 9. Custom plugin requirements (bsa-school-management)

No equivalent BSA plugin exists on the server (verified). Initial scope, all justified above:
1. Security guard for the invoice IDOR (§7.1), with tests.
2. Version guard on `WLSM_VERSION`.
3. Audit log of result and payment create/edit/delete (hooks on the plugin's `admin-ajax` actions), or integration with the activity-log plugin.
4. Result approval workflow (teacher enters → head approves/publishes) if Q3 confirms it.
5. BSA dashboard (formal vs extra-lessons counts, attendance, fees) from real data.
6. BSA-branded report-card print template.
7. Hardening: disable XML-RPC, security headers, `DISALLOW_FILE_EDIT` via the plugin.

A separate `bsa-portal` Blocksy child theme handles presentation (brand tokens, login page, page templates).

## 10. Additional plugin recommendations

All data from api.wordpress.org on 2026-10-10. Nothing is installed yet.

| Plugin | Purpose | Why BSA needs it | Duplicates | Cost | Tested to | Last updated | Recommendation |
|---|---|---|---|---|---|---|---|
| UpdraftPlus 1.26.8 | Scheduled backups to off-site storage (Google Drive, etc.) | No backups exist; cPanel backups disabled | — | Free (Premium optional) | WP 7.1.3 | 2026-09-21 | **Essential** |
| FluentSMTP 2.4.1 | Authenticated email delivery | Plugin notifications and password resets need reliable mail | Weblizar's own SMTP settings (a single global SMTP is cleaner) | Free | 7.1.3 | 2026-09-27 | **Essential before email goes live** |
| Simple History 5.35.0 | Activity log | Audit of admin, user and settings changes | `wlsm_logs` is minimal | Free | 7.1.3 | 2026-10-07 | **Recommended** (lighter than WP Activity Log 5.6.7) |
| Limit Login Attempts Reloaded 3.3.10 | Brute-force protection | No login protection | — | Free | 7.1.3 | 2026-09-23 | **Recommended** |
| Two Factor 0.17.0 | 2FA for staff/admin | Accounts holding minors' data | — | Free | 7.1.3 | 2026-09-28 | **Recommended for admin/head/bursar** |
| Wordfence 9.0.2 | Firewall and malware scan | One-off scan of the suspect plugin; ongoing WAF on shared hosting | Overlaps login limiting | Free | 7.1.3 | 2026-09-30 | Optional (choose this **or** LLAR) |
| Caching plugin | — | LiteSpeed server; logged-in portal traffic barely benefits | — | — | — | — | **Not needed now** |
| PDF generator | — | Plugin print views + browser "Save as PDF" are enough | — | — | — | — | **Not needed** |
| Blocksy Companion | Already installed | — | — | — | — | — | Keep |

## 11. Questions requiring decisions

See the grouped questions in the session reply. They are tracked in `docs/decisions-log.md`.

## 12. Recommended MVP

1. Safety baseline: backup, licensed plugin copy, hardening, IDOR guard.
2. Configure the school: identity, Form 1–4 (+6th if confirmed), sessions/terms, sections, subjects, staff roles.
3. Branded portal: child theme, login, account page (learner/parent), noticeboard.
4. Attendance and fees (offline payments) for formal classes.
5. Exams, marks and a branded report card using approved rules.
6. Extra-lessons school (option A) with registration, attendance and fees.
7. BSA dashboard.

## 13. Implementation roadmap

| Phase | Content | Blocked by |
|---|---|---|
| 1. Safety baseline | Backup plugin + first off-site backup; replace nulled plugin; hardening; IDOR guard | Approval to install; Weblizar licence |
| 2. Core extension + child theme | Repo scaffolding, version guard, brand tokens, login page | — |
| 3. School configuration | School record, forms, sessions, sections, subjects, roles | Q1, Q2 |
| 4. Academics & reports | Exams, grading, approval workflow, report card | Q3, Q4 |
| 5. Attendance | Configure and test | — |
| 6. Finance | Fees, invoices, offline payments, audit | Q6 |
| 7. Extra lessons | Second school, linking, reports | Q5 |
| 8. Parent/learner portal | Pages, navigation, permission tests | — |
| 9. QA & hardening | Permission/IDOR tests, a11y, mobile, backup-restore test | — |

## 14. Acceptance criteria: Phase 1 (safety baseline)

- [ ] A complete backup (files + DB) exists **off-site**, and a restore has been tested on a throwaway copy or verified with the backup tool's restore preview.
- [ ] The plugin is replaced by a vendor-supplied, licensed copy; `is_valid()` is no longer short-circuited; WLSM tables and settings are intact.
- [ ] XML-RPC disabled; HSTS, X-Frame-Options/`frame-ancestors`, X-Content-Type-Options and Referrer-Policy headers present on portal responses.
- [ ] `DISALLOW_FILE_EDIT` effective; login rate limiting active; 2FA on the admin account.
- [ ] Invoice IDOR guard: with synthetic data, a parent requesting another learner's invoice ID gets 403/404; their own child's invoice still loads. Same for the student route.
- [ ] Site timezone set to `Africa/Harare`.
- [ ] All code committed to this repo; no business logic in the Novamira sandbox.
