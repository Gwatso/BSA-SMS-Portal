# Changelog

## 2026-10-16: Clean addresses, no "wp-admin" in the address bar (plugin 0.15.0, production)

- **Staff screens are at /staff**: for example `/staff/dashboard` instead of
  `/wp-admin/admin.php?page=bsa-dashboard`, and `/staff/results?tab=risk` instead of
  `…?page=bsa-results&tab=risk`. Other staff screens keep their name after /staff
  (`/staff/admin.php?page=sm-staff-invoices`).
- **Signing in is at /sign-in** instead of `/wp-login.php`; signing out and "Lost your
  password?" use it too.
- **Old addresses still work**: bookmarks and typed /wp-admin or /wp-login.php addresses
  are sent to the new ones (page visits only; forms and background requests are left
  alone). People already signed in stay signed in.
- **How:** WordPress's own files do not move. A labelled block in the site's .htaccess
  maps the new addresses onto them, put in by `scripts/12-clean-urls.php` with a copy of
  the file kept first. The script only switches clean addresses on after checking
  /sign-in, /staff and the school administrator's /staff/dashboard over HTTP, and takes
  the block out again if a check fails. `off` / `remove` undo it.
- **Parents or learners who open a staff address** now go to My Account, not a
  "Sorry, you are not allowed" page.

## 2026-10-16: Staff land on their dashboard (plugin 0.14.1, production)

- After signing in, **Masango goes straight to the School Dashboard**, and teachers and
  the bursar go to their home screen. Before this they went to the intranet, which is
  still one click away. Learners and parents still go to My Account; the owner still
  goes to WordPress.
- For staff, the site menu's **"My Account" is "My Dashboard"** and leads to their
  dashboard. Staff who open the account page are sent there too, instead of seeing
  "There is no student or parent record".

## 2026-10-16: Grade 7 entry level against results now (plugin 0.14.0, production)

The owner's observation: some learners arrive with strong Grade 7 results, then struggle
from Form 1 Term 1, and the school wants to see it early.

- **Grade 7 subject grades** (ZIMSEC new-curriculum slip): English, Mathematics, Agric,
  Science & Tech, Shona, Social Science, Physical Edu & Arts, each 1–9.
  - Recorded on Admission Documents and in the learner import (columns such as
    "English", "G7 Maths", "Agric Science Tech"; template updated).
  - The total units are worked out from the six grades, and units that do not add up
    are refused.
  - New columns in the BSA admission table (approved by the owner; snapshot first).
- **Same scale:** term exam marks are converted with the slip's own key
  (1 = 85–100%, 2 = 77–84 … 9 = 0–19).
- **Compared subject by subject:**
  - English with English Language, Mathematics with Mathematics, Shona with Shona
    Language.
  - Agric, Science & Tech with the sciences.
  - Social Science with History, Geography and Heritage Studies.
  - Physical Edu & Arts has no exam subject to compare.
  - With only the total units on file, the level is units ÷ 6 against the overall
    average.
- **Results → Grade 7 entry** (staff only):
  - Per class and term: learners furthest below their Grade 7 level first, the level at
    entry and now, the change in words with an icon, and each subject "entry → now".
  - Tiles: with Grade 7 results, below, above, and the class on average.
  - Learners with no Grade 7 results on file are listed with a link to add them.
- **Fifth at-risk sign:** "Below Grade 7 level" when results are 2 grades or more
  below the entry level. The number can be changed with the other rules. The sign shows
  in Learners at risk, on the School Dashboard and on teacher home screens.
- **Learner page:** a Grade 7 entry table (school, year, units, each subject term by
  term, level at entry → now).
- Families do not see the comparison (owner).

## 2026-10-16: One class per form, streams and the year-end rollover (plugin 0.13.0, production)

**One Form 3 and one Form 4 class (owner).** BSA runs one class per form: one class
teacher and one register. Sciences and Commercials are subject choices inside Forms 3
and 4, not separate classes. Script 11 renamed "Form 3 Sciences" and "Form 4 Sciences"
to **Form 3 A** and **Form 4 A**. It deleted the two Commercials sections, which were
empty (nothing referred to them).

- **Streams screen** (wp-admin → Streams; school administrator for both forms, a class
  teacher for their own class):
  - It shows the subjects for everyone and for each stream.
  - Each learner's stream can be set as Sciences, Commercials or not set.
  - The school administrator can change which subjects belong to each stream.
  - Owner's lists:
    - **Everyone:** Mathematics, English Language, Principles of Accounting, History,
      Computer Science, Shona Language, Geography.
    - **Sciences:** Physics, Chemistry, Biology.
    - **Commercials:** Heritage Studies, Combined Science, Business Enterprise Skills.
- A learner's stream is kept as their Weblizar subjects. **Marks sheets** list only the
  learners taking each subject, and a Physics mark for a Commercials learner is
  refused. **Report cards** show each learner's own subjects. The **position** is
  within the whole class (owner). Learners without a stream take every subject and are
  listed under "Needs attention" on the School Dashboard.
- The **import** reads "Form 3 Sciences" as Form 3 A with the Sciences subjects. "Form 3"
  without a stream is accepted, with a note to set the stream later. A stream given for
  Form 1 or 2 is ignored, with a warning.

**Year-end rollover** (wp-admin → Year-end rollover; owner and school administrator):

- Every class of the year ending is listed with a choice for each learner:
  - Forms 1–3: move up to the next form (default), repeat, or leave the school.
  - Form 4: complete Form 4 (default) or repeat.
- Learners going into Form 3 choose Sciences or Commercials, or later. Form 3 learners
  keep their stream into Form 4.
- Learners already in the next year are shown and skipped. Moving up needs an admission
  number.
- Records are created as Weblizar's own promotion does it: same admission and roll
  number, a new enrollment number, a promotion link, and the learner and parent logins
  moved to the new record. The **admission documents** record moves too.
- **The year just ended stays visible:** its records stay active, so registers, marks,
  report cards and results remain. Parents keep last year's report cards through the
  promotion link.
- No invoices are created and no messages are sent.
- Confirmation tick, all or nothing, and **undo**. Undo keeps any new-year record that
  already has fees, marks or a register entry.
- **"Make 2027 the current year"** button for January. It switches School Management's
  current year for everyone, including staff who had picked a year themselves.

## 2026-10-15: Import data, learners and parents (plugin 0.12.0, production)

A new **Import data** screen in wp-admin (owner and school administrator) to bring in
last year's and this year's records from the files the school already has.

- **Sources:** Excel (.xlsx), Word (.docx, class lists read straight from their tables,
  including tables split over pages), CSV, or a table copied from Word or Excel and
  pasted in. Old .xls and .doc files: save as .xlsx/.docx, or copy and paste.
- **Three steps:** choose the school year and the list; match the columns (guessed from
  the headings, e.g. "Name", "D.O.B", "Contact number", "Father's name", "Grade 7
  units"; can be changed); check every row, then import. Nothing is saved before the
  last step.
- **Checks on every row:** errors (row skipped): no name, no or unknown class, a class
  needing its stream ("Form 3" → Sciences or Commercials), the same admission number or
  learner twice, an admission number already used that year. Things to check (imported):
  one name only, sex or birth date not understood, age outside 10–21, phone not a
  Zimbabwe mobile, no parent phone, boarder/day not understood, Grade 7 units outside
  4–36. Notes: linked to another year, new number to be given.
- **Tidying:** names in CAPITALS become Title Case; phones written 07XXXXXXXX (+263…
  accepted); dates in any common form (24/05/2012, 24 May 2012, Excel dates); classes
  as written on school lists ("Form One", "F3 Sci", "2A"); boarder/day as words or B/D.
  A **class for every row** can be chosen for a list of one class (a Word list titled
  "Form 2 A").
- **Linking years:** a learner without an admission number keeps the number from the
  other year when the same name (and birth date) is found; otherwise a new BSA number
  is given. Results and progression follow learners across years by this number.
- **Saved:** a Weblizar learner record per row (parents' names and phones, guardian in
  the note), Grade 7 units, primary school and birth certificate number in the admission
  record. All rows or none. **No logins and no messages** (parent emails come later,
  owner decision); no admission invoices.
- **Undo:** each import is listed and can be undone; learners with fees, marks or
  register entries already recorded are kept.
- **Template:** a CSV learner template (opens in Excel) whose headings are all matched
  automatically.
- **School year 2025** added (script 10) so last year's lists can be imported.
- Marks import follows when the marks files arrive (owner).

## 2026-10-15: BSA Intranet (plugin 0.11.0, production)

The staff hub planned in the Lovable "BSA Intranet" prototype, rebuilt inside the
portal at **https://portal.bindurascienceacademy.co.zw/intranet** (no trailing slash,
owner decision; addresses ending in "/" are redirected) with real sign-in, saved
content and the school's own data.

- **Who:** every staff account (teachers, bursar, school administrator, owner).
  Learners and parents are sent to My Account, visitors to sign in. Staff now land on
  the intranet after signing in; "My work" leads to their screens. Only the owner and
  the school administrator post (owner decision).
- **Top stories slider:** everything published in the last 7 days, items marked
  "feature" (30 days), urgent notices and events in the next 7 days. Slides every
  7 seconds with arrows, dots, swipe and a pause button; no automatic sliding for
  readers who prefer reduced motion.
- **Content** (posted in wp-admin → Intranet): News, Announcements, Notices (urgency,
  "show until", optional acknowledgement), Achievements, Events (date, end, time, place,
  type), Resources (link, category, department).
- **Notices with read receipts:** staff press "I have read this notice"; the school
  administrator sees "Acknowledged by N of M staff" and who has not; unread notices
  appear in each person's "For you today".
- **Resources library:** links to OneDrive, SharePoint, Google Drive/Docs/Sheets/Forms
  (recognised from the link, https only) by category; files stay where they are.
- **Calendar:** events plus term start and end dates.
- **For you today:** the School Dashboard's "Needs attention" for school
  administrators; for teachers today's register, marks still to enter, learners at
  risk; for the bursar learners owing.
- **Attendance today:** whole-school figure, each class as a bar with figures (and
  "Not marked"), trend over the last register days. The school administrator can
  share it to the BSA Staff WhatsApp group in one tap: a picture on phones, or the
  text. Automatic posting into an existing WhatsApp group is not offered by WhatsApp's
  official channels (its Groups API only covers small groups the business creates,
  with a verified business account and a charge per message); unofficial tools risk
  the number being banned.
- Snapshot `2026-10-15-pre-intranet` first. Tests: `intranet.php` 36/36 (staged),
  `intranet-http.php` after going live.

## 2026-10-15: Results dashboards (plugin 0.10.0, production)

New **Results** screen in the staff area (school administrators: every class; class
teachers: their own class; subject teachers: their subjects in each class). Exam marks,
same rules as Report Cards.

- **Overview**: learners with marks, average, pass rate (learners with all their exam
  marks in), learners at risk; each class with average, pass rate and at-risk count;
  the selected class's subjects with average, share passed and grades (ZJC 1–9 or
  O Level A–U).
- **Learners at risk** (staff only), with the reasons written out:
  average below 50 % (once 3 exam marks are in); fewer than 5 subjects passed, counting
  subjects still to be marked as passes; average down 10 marks or more on the previous
  term (Term 1 compares with the previous year's Term 3, matched on admission number);
  attendance below 85 % (once 10 register days are recorded). School administrators can
  change the four numbers on the screen.
- **Progression**: each class's average in Terms 1–3 with a small trend line (values
  written at each point), and the five most improved and five biggest drops in the
  selected class.
- **Learner**: one learner's exam mark and grade per subject in each term with a trend
  line, average, subjects passed, position and attendance per term, and the reasons if
  at risk.
- **Families**: a **Progress** section under Report Cards on My Account: average, change,
  subjects passed and attendance for each published term, a trend line, and marks by
  subject for the latest year. Published report cards only; no position, no at-risk
  flag (owner decision).
- At risk on the **School Dashboard** ("Needs attention") and on the class teacher's
  **Home**.
- Snapshot `2026-10-15-pre-results` first. Test `tests/integration/results.php` 40/40
  on the live code.

## 2026-10-15: Branded staff area and role home screens (plugin 0.9.0, production)

Owner decision: staff use a branded wp-admin; only the owner sees WordPress.

- **No WordPress for staff** (everyone except the owner's account): no WordPress logo
  menu, command palette, "Howdy", "Thank you for creating with WordPress", version
  number, help tabs, update notices, application passwords, colour-scheme picker or
  social-media profile fields; browser tabs read "<screen> ‹ BSA School Management
  System" (also on the sign-in page, for everyone). The WordPress Dashboard sends staff
  to their home screen.
- **Menus**: BSA screens first, then Weblizar's groups with plain names (Learners,
  Academics, Accounts, Exams, School, Staff; "SM" prefix dropped), "My profile" last.
  Library, Transport and Live Classes are hidden from the menu (not used; access rules
  unchanged; list adjustable with the `bsa_sm_hidden_staff_menus` filter).
- **Look**: BSA navy menu and toolbar, crest and "BSA Portal" at the top left (links to
  the home screen), brand font. Same toolbar on the portal pages for staff.
- **Home screens**: school administrators start on the School Dashboard; everyone else
  on **Home**:
  - class teacher: "My class" (learners, today's register, exam marks entered this term
    per subject with a link to enter them, report cards published);
  - subject teacher: "My subjects" (marks entered per subject and class);
  - bursar: fees (collected, outstanding, last 7 days and this term, by class, largest
    balances, recent payments; links to Fee invoices and Fee Reports).
- Staff sign-in lands on the home screen (a requested page is still honoured).
- The owner's wp-admin is unchanged.
- Snapshot `2026-10-15-pre-staff-area` first. Tests: `staff-area.php` 31/31 (staged and
  live), `staff-area-http.php` 29/29 (no "WordPress" visible on 12 staff screens).

## 2026-10-15: Home page hero (theme 0.3.0, production)

- The home page hero now has the school photo of learners in the computer room (from
  the school banner) as its background. **Blurred so no one can be recognised** (owner
  decision: learners are minors), under a navy overlay that keeps white text readable
  (overlay at least 0.78 opaque behind the text).
- "Passion for Excellence" above the title as real text; white sign-in button on the
  photo; the crest in a round white badge.
- Two image sizes (15 KB for desktop, 7 KB for phones), WebP.

## 2026-10-15: School Dashboard (plugin 0.8.0, production)

- **School Dashboard** at the top of wp-admin for the owner and the school administrator
  (teachers, bursar, parents and learners do not see it). Read-only, one screen:
  - **Needs attention**: no term covering today or the next term's start missing;
    registers not marked on a school day (by class); report cards not published once
    the term has ended; subjects with no subject teacher (per form); family account
    problems. Each with a link to the screen that fixes it, and an icon plus the word
    "Action" or "Note" (never colour alone).
  - Six figures: active learners and parent logins; fees collected this year against
    invoiced; outstanding and learners owing; received in the last 7 days and this
    term; attendance today (or on the last day the register was taken); exam marks
    entered this term.
  - Per class: fees collected against invoiced, the day's register (attended, absent,
    late; "Not marked" shown), exam marks entered against the subjects each learner
    takes and report cards published. Bars always have the figures written beside them.
- Same arithmetic as Fee Reports, the Class Register (present and late count as
  attended; subject registers ignored) and Report Cards.
- Snapshot `2026-10-15-pre-dashboard` first. Test `tests/integration/dashboard.php`
  37/37 on the staged copy and live; security sweep 49/49 after going live.

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
