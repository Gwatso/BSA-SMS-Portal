=== Bindura Science Academy - School Management Extension ===
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.16.0
License: GPL-2.0-or-later

BSA-specific security hardening and extensions for the Weblizar School Management plugin.

== Description ==

* Access guard: blocks anonymous access to Weblizar endpoints that expose learner
  invoices, results, admit cards and certificates, or that create accounts; enforces
  learner/parent ownership on invoice endpoints (AJAX and REST).
* Hardening: disables XML-RPC and the file editor, sends security headers, hides the
  REST users list from anyone who cannot list users, and answers author archives
  (?author=1) with 404; no users sitemap, no author names in oEmbed data.
* Compatibility notice when Weblizar is missing or at an unreviewed version.
* Admission Documents (wp-admin): birth certificate number and scan, Grade 7 primary
  school, year, units and results slip per learner. Scans are stored in
  uploads/bsa-private (web access denied) and served only to administrators and
  Weblizar staff with Manage Admissions / Manage Students.
* Portal routing: learners and parents land on the My Account page after sign-in, have
  no toolbar and are kept out of wp-admin; staff go to the dashboard.
* Fees: payments are recorded as Cash or Bank Transfer only (a bank reference is
  required for transfers); every unpaid invoice accepts part-payments; duplicate invoice
  numbers (from Weblizar's admission screen) are renumbered; payments recorded or
  deleted and invoices deleted are logged in Simple History. "Fee Reports" screen for
  the bursar and administrators: balances by class and payments received (cash-up),
  with CSV export.
* School Terms (administrators): start and end dates of Terms 1–3 per year.
* Class Register (class teachers for their own class; head/deputy for all): the BSA
  paper register on screen from Weblizar's daily attendance — weekly Monday–Friday grid
  with number absent, actual and possible attendances; pupil details; the head's weekly
  check; term and year summary (Day/Boarder × Boy/Girl, average daily attendance).
* Marks: term mark, exam mark, project mark and remark per learner, subject and term.
  Administrators enter every subject; a class teacher enters every subject of their own
  class; a subject teacher enters the subjects assigned to them in Weblizar, in every
  stream of that form. Marks are 0–100 with one decimal; one bad mark refuses the whole
  save. Published marks are locked for teachers; administrators can still correct them.
* Report Cards (administrators for all classes; class teachers for their own class):
  results per class with subjects passed (50 % and above), Pass with 5 or more, and
  position by exam average (ties share a place). Form teacher's comment, conduct and
  principal's comment (principal's comment by administrators only). Administrators
  publish or withdraw a class's cards. Printable A4 card in the BSA report-book layout
  with the ZJC (Forms 1–2) or O Level (Forms 3–4) grade key, attendance from the
  register, next term's start date and fee.
* Learner and parent account area (BSA_Family, on top of Weblizar's account page):
  "Report Cards" in the learner menu and in each child's links for parents (published
  cards with subjects passed, result and position); a parent "Fee Structure" page
  (Weblizar links to one but has none); the user's name and an "Account settings" link
  in the greeting; no "Pay Now" while no online or bank-transfer method is enabled; table
  cells labelled so the theme shows them as cards on phones. Weblizar's fee lock-out of
  the learner dashboard (fee types marked "Dashboard Disable?") is switched off on the
  front end; filter `bsa_sm_fee_lockout` restores it.
* Family Accounts (administrators and staff with Manage Admissions or Manage Students):
  learners whose logins will not work as intended — no parent login, parent and learner
  on one login, a child's login used as a parent login, possible siblings (same parent
  phone) on different parent logins, missing or placeholder login emails, administrator
  or staff accounts used as parent logins — with links to fix them, and the office guide
  for setting up accounts at admission. Read-only.
* School Dashboard (owner and school administrator): one read-only screen with what
  needs attention (term dates, registers not marked, report cards not published,
  subjects without a teacher, family account problems), fees per class, today's
  register per class and marks entry for the current term.
* Staff area (everyone except the owner): wp-admin without WordPress branding, menus in
  BSA order with plain names (Learners, Academics, Accounts, Exams, School, Staff),
  unused modules (Library, Transport, Live Classes) hidden from the menu, BSA navy menu
  and toolbar with the crest. Home screen per role: class teachers see their class
  (learners, today's register, exam marks entered per subject, report cards
  published); subject teachers their subjects in each class; the bursar fees
  (collected, outstanding, recent payments, largest balances).
* Results (school administrators: all classes; class teachers: their class; subject
  teachers: their subjects): average, pass rate and grades by class and subject; learners
  at risk with the reasons (average below 50 %, fewer than 5 subjects passed, average
  down 10 marks or more on the previous term, attendance below 85 %; school
  administrators can change the numbers); class averages by term with trend lines and
  the biggest changes; one learner's marks across the year. Staff only.
* Families: a Progress section under Report Cards with the average, change, subjects
  passed and attendance for each published term and marks by subject (no position, no
  at-risk flag).
* BSA Intranet (/intranet, all staff; the owner and school administrators post): a
  sliding "top stories this week" hero; news, announcements, notices (urgency, "show
  until", optional acknowledgement with read receipts), achievements and events; a
  resources library linking to OneDrive, SharePoint and Google Drive/Docs/Sheets/Forms;
  calendar with term dates; search; "For you today" tasks from the school data; today's
  attendance by class with a trend line and one-tap sharing to WhatsApp (picture on
  phones, or text) for school administrators. Content is not public: no front-end URLs,
  REST or search outside the intranet.

Data: tables {prefix}bsa_admission_records, {prefix}bsa_register_checks,
{prefix}bsa_marks and {prefix}bsa_reports, option
bsa_terms, and files in uploads/bsa-private/. None of these is
removed when the plugin is deactivated or deleted.

Filter `bsa_sm_staff_only_ajax_actions` changes which Weblizar AJAX actions are
staff-only (e.g. to re-enable online admissions).

== Changelog ==

= 0.16.0 =
* WhatsApp messages (tap to send): report card ready, absent today and fee reminders
  (off until approved), personalised per family and opened in WhatsApp from the school's
  number; consent per family; every message logged; dashboard reminder for absences.

= 0.15.0 =
* Clean addresses: staff screens at /staff (BSA screens shortest, e.g. /staff/dashboard)
  and signing in at /sign-in, through an .htaccess block put in and checked by
  scripts/12-clean-urls.php; old /wp-admin and /wp-login.php addresses redirect.
* Parents and learners opening a staff screen go to My Account.

= 0.14.1 =
* Staff land on their own dashboard after signing in; "My Account" in the site menu is
  "My Dashboard" for staff.

= 0.14.0 =
* Grade 7 entry level against results now: six slip grades (1–9) on the admission record
  and in the import; term marks put on the slip's scale and compared subject by subject;
  Results → Grade 7 entry tab; fifth at-risk sign "Below Grade 7 level" (adjustable);
  Grade 7 table on the learner page. Staff only.

= 0.13.0 =
* Streams: Sciences and Commercials as subject choices inside one Form 3 and one Form 4
  class; Streams screen; marks sheets list only the learners taking each subject.
* Year-end rollover: move up, repeat, leave or complete Form 4 for every learner; stream
  choice into Form 3; logins and admission documents move to the new year; the year
  ended stays visible; parents keep past report cards; undo; make the next year current.

= 0.12.0 =
* Import data (owner and school administrator): learners and parents from Excel (.xlsx),
  Word (.docx), CSV or a pasted table; columns matched from headings; every row checked
  and previewed; all rows or none; undo; learner template. No logins or messages.

= 0.11.0 =
* BSA Intranet at /intranet for staff: top stories slider, news, announcements,
  notices with acknowledgement and read receipts, achievements, events, resources
  library (OneDrive / Google Drive links), calendar, search, tasks from the school data,
  attendance chart with WhatsApp sharing. Staff sign in to the intranet.

= 0.10.0 =
* Results screen: classes and subjects (average, pass rate, grades), learners at risk
  with reasons and adjustable rules, progression by term, one learner's year.
* Families: Progress section on Report Cards (published terms only).
* At-risk counts on the School Dashboard and the class teacher's Home.

= 0.9.0 =
* Branded staff area: no WordPress branding, menus or Dashboard for anyone but the owner;
  Weblizar menus renamed; unused modules hidden from the menu; BSA colours and crest.
* Home screens: My class, My subjects and Fees for teachers and the bursar; school
  administrators start on the School Dashboard. Staff sign-in lands there.

= 0.8.0 =
* School Dashboard for the owner and school administrator: needs-attention list, fees
  collected against invoiced per class, the day's register per class, marks entry and
  report cards published for the current term.

= 0.7.1 =
* No author archives, users sitemap or oEmbed author names (login names were visible
  through ?author=1).
* Alt text on Weblizar's "new" notice badge.

= 0.7.0 =
* Family Accounts check and office guide.

= 0.6.0 =
* Learner and parent pages: Report Cards, parent Fee Structure, account settings link,
  phone-friendly tables; no dashboard fee lock-out; no pay buttons without a payment method.

= 0.5.0 =
* Marks entry (BSA_Marks) and grading rules (BSA_Grades).
* Report Cards: results, positions, comments, publication, print, family view.

= 0.4.0 =
* School Terms and Class Register.
* Shared staff-rights helper (BSA_School).

= 0.3.0 =
* Fee rules (cash/bank transfer, bank reference, part-payments, unique invoice numbers).
* Payment and invoice audit trail in Simple History.
* Fee Reports: balances and payments received, CSV export.

= 0.2.0 =
* Admission Documents module with private file store and authenticated downloads.
* Portal sign-in routing and wp-admin separation for learners and parents.
* Media REST endpoints hidden from users who cannot upload files.

= 0.1.0 =
* Initial release: access guard, hardening, compatibility notice.
