# Changelog

## 2026-10-15: Phase 9, QA and hardening (plugin 0.7.1, theme 0.2.6, production)

Full report: `docs/qa-report-phase9.md`.

- All eight test suites pass on the live code (300 checks); new security and health
  sweep `tests/integration/security.php` (49/49): visitor HTTP checks, each BSA screen
  as each of six roles over HTTP, configuration, error logs, updates, backups.
- **Fixed: the administrator's login name was public** (`/?author=1` redirected to
  `/author/<login>/`). Author archives now answer 404; no users sitemap; no author in
  oEmbed data.
- **Fixed: backups were only weekly.** Settings said daily, the schedule was still
  weekly, and UpdraftPlus skips its database-only run in that case. Rebuilt to daily at
  02:00 (Africa/Harare); a full backup ran and reached Google Drive.
- Accessibility (axe-core, WCAG 2.1 A/AA, 9 pages): the only issue, Weblizar's "new"
  notice badge without alt text, fixed. Phones at 390 and 320 px: no sideways
  scrolling; the school email on the learner dashboard now wraps.
- Snapshot `2026-10-15-pre-hardening` first.

## 2026-10-15: Family Accounts check (plugin 0.7.0, production)

- **Family Accounts** screen in wp-admin (owner, school administrator and any staff with
  Manage Admissions or Manage Students). For each active learner in a school year it
  flags logins that will not work as intended:
  - no parent login (a deleted login shows here too: the database unlinks it);
  - parent and learner on **one shared login**, or a child's login used as a parent
    login (a parent signing in with a child's account);
  - possible **siblings** (same parent phone number) linked to different parent logins;
  - login emails that are missing, invalid or placeholders ("Lost your password?"
    cannot reach them);
  - a site administrator account used as a parent login (staff accounts are noted).
  Summary counts, "Edit learner" links into School Management, and a short office
  guide for setting up accounts at admission. Read-only.
- First run on the live data: 1 learner (BSA001), 1 warning: the sample parent login's
  placeholder email (already an owner action).
- Snapshot `2026-10-15-pre-accounts` first. `tests/integration/accounts.php` (19/19).

## 2026-10-14: Learner and parent portal (plugin 0.6.0, theme 0.2.4–0.2.5, production)

Walk-through of every learner and parent page with a synthetic family found these
problems in Weblizar's account area, now fixed without editing vendor files:

- **Learners with any unpaid or part-paid invoice lost their dashboard menu.** Weblizar
  replaced the dashboard with a "pending fee invoices" screen whenever an invoice matched
  a fee type marked "Dashboard Disable?" (all BSA term fees are, so that admission creates
  one invoice per term). That screen also counted each payment once per form (a USD 100
  payment showed as USD 400 paid, balance −150). The lock-out is now off on the front
  end; invoices stay on the Fee Invoices page with the correct balance.
- **"Pay Now" / "Pay Selected" opened an empty payment box** (no online or bank-transfer
  method is enabled). They are hidden until one is.
- **Parents' "Fee Structure" link showed the dashboard again** (Weblizar has no parent page
  for it). BSA now shows the child's term fees and how to pay.
- **Tables were cut off on phones** (on Fee Invoices the Paid, Due and Status columns were
  out of sight). Each row is now a card on phones.
- **Dashboard notices were invisible** (since theme 0.2.x: Weblizar's ticker ran outside
  the shortened box). They are now a still list.
- **Parents had no link to their account settings** (change email or password). An
  "Account settings" link now sits next to Logout for parents and learners, and the
  greeting shows the user's name instead of the capitalised login.

New:

- **Report Cards** in the learner menu and in each child's links on the parent dashboard:
  published cards with term, class, subjects passed, result and position, and a link to
  open and print the card. Replaces the list that was appended below every account page.
- **Help** page (`/help/`, in the header menu): the guide for parents and learners
  (signing in, setting a password, what each page shows, fees and paying, report cards,
  contacts). Printable from the browser.
- Weblizar items BSA does not use switched off for learners and parents: Exam Time
  Table, Admit Card, Exam Results, Certificates (`scripts/09-family-portal.php`).
- Snapshot `2026-10-14-pre-family` first. Tests: `tests/integration/family.php` (28/28)
  and `tests/integration/family-http.php` (14/14, end to end over HTTP).

## 2026-10-14: Theme cover image (bsa-portal 0.2.3, production)

- The BSA Portal theme now has a cover in Appearance → Themes (`screenshot.png`,
  1200 × 900): school crest and name, the live home page in a browser frame, the sign-in
  page on a phone, and the portal's four areas (fees, register, report cards, learner
  and parent accounts) in brand colours. Source and steps in
  `docs/reference/theme-cover/`.

## 2026-10-14: Marks and Report Cards (plugin 0.5.0, production)

- **Grading rules** (`BSA_Grades`) from the BSA report book: ZJC key 1–9 for Forms 1–2,
  O Level key A–U for Forms 3–4; marks rounded half up; subject pass 50 % and above;
  result **Pass** with 5 or more subjects passed; position by average exam mark, ties
  share a place (1, 2, 2, 4).
- **Marks** screen: term mark, exam mark, project mark (0–100, one decimal) and remark per
  learner, subject and term. Administrators enter every subject; the class teacher
  every subject of their own class; a subject teacher only the subjects assigned to them
  in Weblizar (Staff → Edit → Subjects), in every stream of that form. One bad mark
  refuses the whole save and the typed values are kept on screen. Published marks are
  locked for teachers.
- **Report Cards** screen (administrators: all classes; class teacher: own class): class
  results (subjects recorded and passed, average, position, Pass/Fail), form teacher's
  comment, conduct, principal's comment (administrators only), **publish / withdraw**
  per class (administrators), and printing in the report-book layout (A4, 15 learning
  areas, subject teacher's initials, attendance from the Class Register, "Next term
  begins on" from School Terms, next term's fee, grade-key page).
- **Parents and learners** see their own published cards on the My Account page and can
  print them; unpublished or other learners' cards are refused.
- New tables `bsa_marks` and `bsa_reports`. Snapshot `2026-10-14-pre-marks` first.
- `tests/integration/reports.php` (56/56). The register test now leaves the owner's real
  term dates and register checks alone in its rollback check (53/53 again).

## 2026-10-14: School Terms and Class Register (plugin 0.4.0, production)

- **School Terms** screen (owner and school administrator): start and end dates of
  Terms 1–3 for 2026 and 2027, kept inside the school year; drives the register and,
  next, report-card attendance and "Next term begins on". **No dates set yet.**
- **Class Register**, built on Weblizar's daily attendance and laid out like the BSA paper
  register: weekly Monday–Friday grid (✓ / L / A / H) with each pupil's weekly total and
  Number absent, Actual attendance, Possible attendances and Weekly total of actual
  attendances; Pupil details (surname and first names in capitals, date of birth, birth
  certificate number, religion, boarder/day scholar, parent contacts); Register checks
  (the head/deputy records each week's check); Attendance summary per term and year
  (Day/Boarder × Boy/Girl, school days, average daily attendance); A4 landscape print.
  Class teachers see only their own class; the bursar sees none.
- Weblizar student types renamed **Day Scholar** and **Boarder** (unused "Other" removed;
  `scripts/08-student-types.php`).
- `docs/academics-spec.md` from the report book and register; `tests/integration/register.php` (53/53).

## 2026-10-13: Finance (plugin 0.3.0, production)

- Fee rules enforced on the server (Weblizar checks none of these):
  - payments are **Cash** or **Bank Transfer** only; a bank transfer needs the bank
    reference; other methods are also hidden from Weblizar's payment form;
  - the amount must be above zero and at most the invoice balance (Weblizar accepted
    zero, negative and overpayments);
  - every unpaid invoice accepts **part-payments** (parents who pay monthly);
  - **unique invoice numbers**: Weblizar's admission screen gives all of a new learner's
    fee invoices one number; duplicates are renumbered at the end of the request.
- Audit trail in Simple History: payments recorded or deleted, invoices deleted,
  invoices renumbered (record IDs and amounts, no learner names).
- **Fee Reports** screen (administrators, the school administrator, bursar): balances by
  class for the current session, and payments received for a date range with a
  cash-up summary by method and by staff member; CSV export (formula-safe).
- Existing invoices for learner BSA001 renumbered 00001/00002/00003 and opened to
  part-payments. Snapshot `2026-10-13-pre-finance` first.
- `tests/integration/finance.php` (49/49).

## 2026-10-13: Account clean-up (production)

- The second school administrator's login is no longer a WordPress administrator and is now linked to his Weblizar
  admin staff record; the owner's account is the only WordPress administrator.
- Class teacher (Form 1 A) given a login linked to the staff record; password-setup
  email sent.
- Bursar staff record (role Bursar) created for the `bursar` login.
- Learner BSA001: separate sample parent login and sample parent names; religion
  spelling corrected.
- Limit Login Attempts Reloaded kept; All-In-One Security removed by the owner and its
  leftover scheduled tasks and options deleted.
- `scripts/07-accounts.php`; snapshot `2026-10-13-pre-accounts` first.

## 2026-10-10: Staff roles, admission documents, portal pages (production)

- **Staff roles** in Weblizar (`scripts/05-staff-roles.php`): **Teacher** (own assigned
  class: attendance, leave requests, study materials, homework, lessons; timetable view;
  marks wait for the grading rules) and **Bursar** (fee types, invoices, income,
  expenses, finance statistics; no delete rights). See `docs/permission-matrix.md`.
- `bsa-school-management` **0.2.0**:
  - **Admission Documents** screen (School Management staff with admissions rights and
    administrators): birth certificate number and scan, primary school, Grade 7 year,
    units and results slip, per learner. Scans (PDF, JPG, PNG, up to 5 MB) are stored
    under random names in `uploads/bsa-private/` (web access denied) and downloaded only
    through a permission- and nonce-checked link. New table `bsa_admission_records`.
    Saves and downloads are logged in Simple History without personal data.
  - **Sign-in routing**: learners and parents go to My Account, have no toolbar and are
    kept out of wp-admin; staff go to the dashboard.
  - REST media listing hidden from users who cannot upload.
- `bsa-portal` theme **0.2.0–0.2.2**: Portal home template (sign-in call to action that
  adapts to the visitor, what the portal offers, school contacts); branded My Account
  area (centred sign-in card with help text, account navigation, learner dashboard,
  one card per child for parents, phone layouts).
- Pages (`scripts/06-portal-pages.php`): **Home** (front page), **My Account**; header
  menu Home / My Account / School website; Weblizar dashboard items Live Classes and
  Books Issued switched off.
- Tests: `tests/integration/admission-and-portal.php` (60/60); access-guard fixture
  updated for the configured classes (20/20).

## 2026-10-10: Branding (child theme)

- New Blocksy child theme `bsa-portal` 0.1.2, activated (`scripts/04-activate-branding.php`,
  snapshot `portal-db-pre-branding.sql` first):
  - Self-hosted Playfair Display (headings) and Plus Jakarta Sans (body); nothing loaded from Google.
  - Blocksy palette: navy #1B3674 (action), #132653 hover, #343A48 text, #1D212B headings,
    #E5E7EB borders, #F6F7F9 / #FAFBFC surfaces, #FFFFFF.
  - Header: crest beside the site name (56 px desktop, 44 px mobile).
  - Footer: "Copyright © {year} Bindura Science Academy" replaces Blocksy's theme credit.
  - Branded login screen: crest, motto, white card, navy button, error/notice colours.
- Official crest added to the Media Library (unmodified) as the site logo; 512 px square
  site icon generated from it; tagline "Passion for Excellence".
- Design direction `docs/DESIGN.md` saved and activated in Novamira (`bsa-portal`).
- `bin/build-theme.sh`; screenshots in `docs/screenshots/`.

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
