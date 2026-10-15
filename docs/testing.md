# Testing Log

Never record a test as passed unless it was run. Synthetic data only.

## 2026-10-10: Phase 1 safety baseline

### HTTP checks (server-side requests to the live portal, after activation)
| Check | Result |
|---|---|
| `/` returns 200, no critical error | PASS |
| `/wp-login.php` returns 200 | PASS |
| `/wp-admin/` redirects (302) when logged out | PASS |
| HSTS, X-Frame-Options, X-Content-Type-Options, Referrer-Policy on `/` and login | PASS |
| Same headers on the logged-out `/wp-admin/` 302 | NOT SENT: the redirect happens before `admin_init`; logged-in admin pages send them |
| `X-Pingback` header removed | PASS |
| XML-RPC: `wp.*` methods no longer offered (only built-in `system.*` remain) | PASS |
| `/wp-json/wp/v2/users` anonymously returns 404 | PASS |
| Anonymous AJAX `wlsm-p-get-students-with-pending-invoices`, `-get-student-pending-invoice`, `-get-exam-result`, `-get-certificate`, `-submit-staff-registration` return 403 | PASS |
| Anonymous AJAX `wlsm-p-get-school-classes` (harmless) still works | PASS |
| Blocks are recorded in Simple History | PASS (5 events) |
| UpdraftPlus backup completes; `wp-content/updraft/` returns 403 | PASS |

### Integration test: `tests/integration/access-guard.php` (20/20 PASS)
Synthetic learners A and B, parent of A, one unpaid invoice each, created inside a
transaction and rolled back.

| # | Check | Result |
|---|---|---|
| 1 | Fixtures created | PASS |
| 2–3 | Admin counts as staff; parent does not | PASS |
| 4–8 | Ownership: own child ok; other learner, mixed list, foreign record and empty request all fail closed; comma lists parsed | PASS |
| 9 | Learner reads own invoice via the real Weblizar REST handler (200) | PASS |
| 10 | Learner requesting another learner's invoice gets 404 | PASS |
| 11 | Parent reads own child's invoice (200) | PASS |
| 12 | Parent requesting another invoice through own child's route gets 404 | PASS |
| 13 | Anonymous caller rejected by the Weblizar route permission | PASS |
| 14–18 | AJAX: parent own invoice allowed; other invoice, bulk with a foreign invoice, and learner's other-student lookup all denied; admin allowed | PASS |
| 19 | Rollback left no synthetic rows | PASS |

Limitation: REST checks call the guard filter and the Weblizar handler methods
directly, not a full HTTP dispatch. Novamira's OAuth credential is scoped to its own
routes and was deliberately left in place.

### Phase 3 configuration read-back (database, after run)
| Check | Result |
|---|---|
| School label/phone/email updated | PASS |
| Classes = Form 1–4 only | PASS |
| 4 class-school links, each with default section "A" | PASS |
| Sessions 2026 and 2027 with Jan 1 – Dec 31 dates | PASS |
| 13 subjects per form, no duplicates | PASS (first attempt saved 0: handler needs `class_id[]`; fixed and re-run) |
| 12 fee rows (3 terms × 4 forms), USD 250, one-time | PASS |
| Front page still 200 | PASS |

### Phase 3 step 2 read-back
| Check | Result |
|---|---|
| Forms 1–2 = 10 subjects, Forms 3–4 = 13 | PASS |
| Form 3 sections = Sciences, Commercials | PASS |
| Registration settings: religion on; caste, blood group, transport off | PASS |
| Weblizar email carrier = wp_mail | PASS |
| FluentSMTP default connection = info@bindurascienceacademy.co.zw | PASS |
| `wp_mail` test to info@ returns true; FluentSMTP log status "sent" | PASS (owner confirmed receipt 2026-10-10) |
| SPF for bindurascienceacademy.co.zw | PASS (`v=spf1 +a +mx +ip4:45.86.220.2 ~all`) |
| DKIM for bindurascienceacademy.co.zw | FAIL: not published (DNS SERVFAIL); owner action |

Note: the script's first run reported two false "FAIL" lines because those Weblizar
handlers close one buffer too many; the read-back confirmed success. The helper now
opens a spare buffer layer.

### Phase 3 step 3 and backup verification
| Check | Result |
|---|---|
| Form 4 sections = Sciences, Commercials (handlers report success; read-back matches) | PASS |
| UpdraftPlus backup 17:49 sent to Google Drive; log "no errors in the uploads … backup succeeded" | PASS |
| Two Factor active for administrator (`Two_Factor_Core::is_user_using_two_factor(1)`) | PASS |

### Restore test (2026-10-10): PASS
Details in `backup-and-recovery.md`: Google Drive retrieval with matching checksums;
isolated restore with 118/118 tables and matching row counts; the copy boots with all
plugins; temporary resources deleted.

### Branding (2026-10-10)
| Check | Result |
|---|---|
| Child theme active (stylesheet bsa-portal, template blocksy) | PASS |
| Home and login 200, no critical error | PASS |
| brand.css, fonts.css, login.css served; no fonts.googleapis.com request | PASS |
| Font files on server match repo SHA-256 (4/4) | PASS |
| Rendered offline from the server's HTML/CSS at 1366×900 and 390×844: Playfair Display and Plus Jakarta Sans load; crest beside title; footer credit correct | PASS (`docs/screenshots/`) |
| Novamira design pre-flight on login HTML + theme CSS | PASS (ok: true; one warning, fallback system fonts in the font stacks, kept on purpose) |
| Contrast: navy on white 11.5:1, body text 11.4:1, muted 4.8:1, white on navy 11.5:1 | PASS (WCAG AA) |

Limitation: renders used the server's HTML and CSS without JavaScript (this session cannot
reach the portal directly). Logged-in screens were not rendered at the time (see below).

### Staff roles, admission documents, portal pages (2026-10-10)

#### Plugin 0.2.0 deployment checks
| Check | Result |
|---|---|
| Plugin boots on the server (WP-CLI load before activation) | PASS |
| Migration creates `bsa_admission_records` with a unique key per learner | PASS |
| Probe file in `uploads/bsa-private/` returns 403, content not leaked; folder listing 403 | PASS |
| Anonymous `/wp-json/wp/v2/media` returns 404 | PASS |
| Home and login 200 | PASS |

#### Integration test: `tests/integration/admission-and-portal.php` (60/60 PASS)
Synthetic teacher, bursar, office clerk (`manage_admissions`), clerk of a second test
school, learner, parent and an account without learners; one learner record in Form 3
Sciences. All inside a transaction, rolled back; files written to the private store
deleted afterwards (checked).

| Area | Checks | Result |
|---|---|---|
| Roles | Teacher and Bursar exist; teacher has attendance/homework/study materials/assigned class and no fees, admissions or students; bursar has fees/invoices/income/expenses, no `delete_*`, no admissions or students | PASS |
| Who may manage admission documents | Administrator and office clerk yes; teacher, bursar, other school's clerk, learner, parent, visitor no | PASS |
| Field validation | Good input normalised; bad certificate number, year 1990, 0 units, future year rejected; HTML tags stripped | PASS |
| Private file store | PDF accepted under a random 24-character name in the learner's folder; not world-readable; direct web request 403; PHP file refused; PHP renamed to `.pdf` refused; file over 5 MB refused; upload path requires a real HTTP upload | PASS |
| Records | Save and read back; update keeps one row per learner | PASS |
| Handlers | Download and save refused (403) for teacher, bursar, learner and parent even with their own valid nonce; bad nonce refused for permitted staff; unknown field 404; permitted staff reach the file; download links carry a nonce | PASS |
| Sign-in routing | Learner and parent count as family, staff/admin/no-learner accounts do not; learner and parent go to My Account (parent even when wp-admin was requested); teacher goes to the dashboard; staff keep a requested page; failed sign-in untouched; toolbar hidden for learner, unchanged for staff | PASS |
| Cleanup and PHP notices | No test users or files left; no notices or warnings | PASS |

Runs before the passing one, recorded because they touched production:
- Two runs were cut short with HTTP 403: inside a REST request `wp_die()` uses the JSON
  handler, which the test did not intercept, so the first expected refusal ended the
  request. The database rolled back (connection closed mid-transaction; verified: no
  test users, records or school). Each run left one 45-byte synthetic PDF in the
  private store; both were found and deleted. The test now intercepts every `wp_die`
  handler and deletes any file it wrote to the test learner's folder.
- The earlier fixture also failed (Form 1 is now linked to the school, so inserting
  that link again was a duplicate); the test now uses the Sciences section.
- Novamira rejects the response format after the expected refusals, so results are
  read from the `bsa_test_admission_and_portal` transient.

#### Access guard re-run (20/20 PASS)
`tests/integration/access-guard.php` had the same duplicate class-school fixture; it now
reuses the existing Form 1 link. Re-run: 20/20, no synthetic data left.

#### Portal pages
| Check | Result |
|---|---|
| Home page is the front page (template "Portal home"); My Account page holds `[school_management_account]`; both 200 | PASS |
| Header menu: Home, My Account, School website (desktop and mobile locations) | PASS |
| Dashboard items Live Classes and Books Issued off; others unchanged | PASS |
| Theme 0.2.1/0.2.2 files on the server match the repo (SHA-256) | PASS |
| Rendered offline at 1366 px and 390 px: home, My Account signed out, learner dashboard, parent dashboard with two children (synthetic, rolled back) | PASS after fixes (`docs/screenshots/`) |
| Fixed during review: sign-in card styles targeted the wrong form ID; home page overflowed on phones (long email address); collapsed account menu showed a sliver; learner details squeezed beside a floated link; Weblizar's phone rule forced 45 px padding with `!important` | Fixed in theme 0.2.1–0.2.2 |
| Novamira design pre-flight on home and My Account | PASS (ok: true). Warnings: fallback serif font (kept on purpose); "inter" matched inside an ordinary word; `#003388` is the `&#038;` entity in a link, not a colour |

Limitations: renders use the server's HTML and CSS without JavaScript; the signed-in
dashboards were rendered from Weblizar's shortcode output for synthetic accounts placed
into the page shell, so links in those renders point at the REST endpoint that produced
them. The greeting shows the account's login name (Weblizar behaviour).

### Account clean-up (2026-10-13, `scripts/07-accounts.php`)
Snapshot first: `/home/thwxghpf/bsa-backups/2026-10-13-pre-accounts/portal-db.sql` (560 KB, dump complete).

| Check | Result |
|---|---|
| Only one WordPress administrator left (the owner) | PASS |
| The second school administrator's login: subscriber in WordPress, linked to his Weblizar admin staff record; School Management manage students, invoices, settings, delete invoices | PASS |
| Bursar login linked to a new Bursar staff record: manage invoices, fees, edit invoices yes; delete invoices, delete payments, students, admissions, attendance no | PASS |
| Teacher login created and linked; class teacher of Form 1 A; attendance, homework, assigned class yes; fees, invoices, students, exams no | PASS |
| Teacher password-setup email: FluentSMTP log "sent" | PASS |
| Learner BSA001 has a separate (sample) parent login; parent counts as family and sees only BSA001 | PASS |
| All three staff count as staff for the BSA plugin, none as family | PASS |
| All-In-One Security leftovers (2 scheduled tasks, 2 options) removed | PASS |

The script's first self-check reported that the bursar and teacher had every permission.
Cause: Weblizar caches the first user's details for the whole request
(`wp_cache_get( 'wlsm_user_info' )`), so all checks reused the first account's admin rights. Real
sign-ins are separate requests and unaffected. Re-run with the cache cleared before each
check: 19/19 PASS. The script now clears it itself.

### Finance, plugin 0.3.0 (2026-10-13)

#### Integration test: `tests/integration/finance.php` (49/49 PASS)
Run against the **staged** copy (`/home/thwxghpf/bsa-deploy/stage-0.3.0`, byte-identical
to what went live) before activation. Synthetic Form 1 learner with three term invoices
sharing one number (as Weblizar's admission screen makes them); payments through
Weblizar's real payment handler as the bursar; all rolled back (invoice and receipt
counters checked unchanged afterwards).

| Area | Checks | Result |
|---|---|---|
| Report access | Administrator and bursar yes; teacher, learner, visitor no | PASS |
| Payment rules | Cash and bank transfer with reference accepted; transfer without reference, card, cheque, UPI, demand draft, empty refused; amount over balance refused | PASS |
| Invoice rules | Oldest invoice keeps its number, the others renumbered; no duplicates left in the school; part-payments on for unpaid invoices; second run changes nothing | PASS |
| Through Weblizar's handler | Card and reference-less transfer refused before Weblizar; USD 100 cash and USD 150 transfer recorded; Term 1 marked paid; USD 1 on the paid invoice, USD 251 on a USD 250 balance, zero, negative and an unknown invoice refused; USD 50 monthly part-payment recorded | PASS |
| Payments | 3 rows, all recorded by the bursar, unique receipt numbers, bank reference stored | PASS |
| Reports | Balance 750 / 300 / 450; class filter; cash-up for today lists the 3 payments (USD 300); method filter; recorded-by name; yesterday excludes today; bad filters sanitised | PASS |
| CSV | Formula-like cells neutralised | PASS |
| Audit | 3 payments and a payment deletion written to Simple History | PASS |
| Clean-up | No synthetic data left; no PHP notices | PASS |

Found while testing, and fixed before going live:
- **Weblizar does not check payment amounts at all.** Its validation only checks the
  method name; a USD 1 payment on a fully paid invoice was accepted. BSA now refuses
  zero, negative, non-numeric and over-balance amounts.
- Weblizar's payment handler runs its own `COMMIT`. Inside a test transaction this would
  have kept the synthetic data; the test turns the handler's COMMIT/ROLLBACK into no-ops
  (checked: nothing left behind).
- A negative class filter was read as a class ID; now treated as "all classes".

#### After going live
| Check | Result |
|---|---|
| Plugin 0.3.0 loaded; home, My Account, login 200; wp-admin 302 when signed out | PASS |
| Payment check, query watcher and end-of-request hooks registered | PASS |
| Learner BSA001's invoices renumbered 00001/00002/00003 and opened to part-payments; two "renumbered" entries in Simple History | PASS |
| Fee Reports menu: owner, school administrator, bursar yes; teacher, learner, parent no | PASS |

### School Terms and Class Register, plugin 0.4.0 (2026-10-14)

#### Integration test: `tests/integration/register.php` (53/53 PASS)
Run against the staged copy before activation. Term dates for 2026/2027, three synthetic
Form 1 A learners (day boy, day girl, boarder girl) and one register week, all rolled back
(checked: no learners, types, checks or term dates left). The checks table itself was
created first, outside the transaction (it is the plugin's real table).

| Area | Checks | Result |
|---|---|---|
| Rights | Owner and school administrator: all registers, can record checks; teacher: Form 1 A only, no checks; bursar: none | PASS |
| Term dates | Missing end, end before start, overlap, outside the school year and impossible dates refused; valid dates saved and read back; current term (holidays belong to the term just ended); next term start, including across years; weeks ending Friday (12 in a 13 Jan–2 Apr term) | PASS |
| Register week (worked by hand) | Mon p,p,a · Tue p,l,p · Wed h,h,h · Thu none · Fri p,a,p → totals 3,2,2; absent 1,0,0,0,1; actual 2,3,0,0,2; possible 3,3,0,0,3; weekly total 7; a subject-lesson register on Thursday ignored | PASS |
| Report-card attendance | 2 of 3 for the day girl; none without term dates | PASS |
| Summary | Day boy 3, day girl 2, boarder girl 2; 3 school days; average 2.3 | PASS |
| Weekly check | Teacher refused; Thursday and future weeks refused; one check per week | PASS |
| Screens | Teacher's four tabs render for Form 1 A only, no check button; school administrator gets check buttons; School Terms shows saved dates; no PHP notices | PASS |

#### After going live
| Check | Result |
|---|---|
| Plugin 0.4.0 loaded; home, My Account, login 200 | PASS |
| School Terms: owner and school administrator only; Class Register: all classes for them, Form 1 A for the teacher, none for the bursar | PASS |
| Student types now Day Scholar, Boarder | PASS |

### Marks and Report Cards, plugin 0.5.0 (2026-10-14)

#### Integration test: `tests/integration/reports.php` (56/56 PASS)
Run against the staged copy before going live. Term dates, five synthetic learners (four
in Form 3 Sciences, one in Commercials), a Physics assignment for the Form 1 A teacher,
marks, attendance, comments, publication and a parent login, all rolled back (checked:
nothing left; the owner's real Term 3 dates unchanged). The two new tables were created
first, outside the transaction.

| Area | Checks | Result |
|---|---|---|
| Grade keys | ZJC 75→1, 74→2, 74.5→1 … 39→9; O Level 70 A, 69.5 A, 69.4 B … 39 U; no mark → no grade | PASS |
| Rules | 50 and 49.5 pass, 49.4 fails; 5 passes Pass, 4 Fail; marks 0–100 with one decimal only; positions 90, 80, 80, 70 → 1, 2, 2, 4 | PASS |
| Rights | Administrators: every subject and class; class teacher: every Form 1 A subject; subject teacher: Physics in both Form 3 streams only after assignment; bursar and parent: none | PASS |
| Saving | Bad mark refuses the whole save; another stream's learner refused; unassigned subject refused; unchanged marks not re-saved | PASS |
| Results (worked by hand) | A 6 passed, Pass, 1st; B 5 passed (49.5 counts), Pass; D ties B for 2nd; C 3 passed, Fail, 4th | PASS |
| Card | Form 3 Sciences, Term 1, 2026; English term 68 B, exam 80 A, project 72.5, remark, initials; Physics initialled "WH"; attendance 2 of 3; next term 5 May 2026; fee USD 250; learner with no subject list gets all 13 | PASS |
| Comments | Form 1 A teacher refused on Form 3; administrator saves form teacher, conduct and principal comments | PASS |
| Publication | Teacher cannot publish; administrator publishes 4 cards; teacher then locked out of the mark, administrator can still correct; withdraw works | PASS |
| Family | Parent owns only their child | PASS |
| Print | Book fields, ministry heading, 15 learning-area rows, "Without Alteration/Erasure", grade-key page | PASS |
| Screens | Teacher asking for Chemistry gets Physics; published marks read-only; administrator sees class list, publish button and comments form; no PHP notices | PASS |

#### After going live
| Check | Result |
|---|---|
| Plugin 0.5.0 loaded; all 14 plugin files match the repo (SHA-256); tables `bsa_marks`, `bsa_reports` exist | PASS |
| Marks and Report Cards: owner and school administrator all 6 classes; Form 1 A teacher Form 1 A only; bursar and sample parent none | PASS |
| Home 200; My Account (`/account/`) 200 with the sign-in form; login 200 | PASS |
| A report-card link opened while signed out goes to sign-in (302) | PASS |
| Register test re-run against 0.5.0 | PASS (53/53) |

### Learner and parent portal, plugin 0.6.0 (2026-10-14)

#### Walk-through (before the changes)
Every learner page (16) and parent page (9) was fetched over HTTP signed in as a
synthetic learner (Form 3 Sciences, own login) and her parent (two children), with a
part-paid invoice, attendance, a notice and a published report card. Rows were committed
for the duration and deleted by ID afterwards (none left). Findings are in CHANGELOG
(fee lock-out with a wrong "Paid" figure, pay buttons, missing parent Fee Structure,
cut-off tables on phones, invisible notices, no settings link for parents).

#### Integration test: `tests/integration/family.php` (28/28 PASS)
Run against the staged copy, inside a rolled-back transaction, on cut-down copies of
Weblizar's account markup.

| Area | Checks | Result |
|---|---|---|
| Fee lock-out | Without BSA, Weblizar's query locks the dashboard and reports Paid 400 for a 100 payment; with BSA no lock-out invoices; Fee Invoices list unchanged; filter restores Weblizar's behaviour; other queries untouched | PASS |
| Report cards | Only published cards; results 6 of 7, Pass, 2nd; empty message; link to the card | PASS |
| Fee structure | Three terms and the year total | PASS |
| Learner page | Name and Account settings in the greeting; Report Cards after Attendance; pay buttons and checkboxes removed; cells labelled despite `</td>` headers and commented cells; report page replaces the content, marks the menu item active, keeps the markup balanced, lists only her card | PASS |
| Parent page | Report Cards per child; report and fee pages for own children; another family's child (by URL) gets nothing; another parent cannot open the child's page | PASS |
| Others | Staff output and other shortcodes unchanged; rollback; no PHP notices | PASS |

#### End to end: `tests/integration/family-http.php` (14/14 PASS)
After deployment, over HTTP with a synthetic family (rows deleted afterwards, checked):
all 31 pages 200; learner dashboard keeps its menu with a part-paid invoice; menu has
Report Cards and no exam items; name and settings link; balance 150 after a 100
payment, labelled cells, no Pay Now; report cards for learner and parent; none for the
sibling; sibling's Form 1 fee structure; nothing for another family's learner; Help page
in the menu; cleanup complete.

#### Visual check
Captured pages rendered at 1366 px and 390 px with the live theme CSS: Report Cards,
Fee Structure, Fee Invoices, Payment History and Attendance as cards on phones; learner
dashboard with notices visible; Help page.

### Family Accounts, plugin 0.7.0 (2026-10-15)

#### Integration test: `tests/integration/accounts.php` (19/19 PASS)
Run against the staged copy inside a rolled-back transaction: 11 synthetic learners and
7 logins, one per situation.

| Area | Checks | Result |
|---|---|---|
| Helpers | Missing, invalid and placeholder emails told from real ones; phone numbers in +263, 0 and bare forms match | PASS |
| Problems | Two children on one parent login: none; same phone on different parent logins: siblings on both; placeholder parent email; shared login (error); a child's login used as a sibling's parent login (error on both); no parent login (warning); administrator as parent (error); teacher as parent (note only); invalid learner email; no learner login is not a problem | PASS |
| Summary | 11 learners, 10 with a parent login, 7 families, 4 with own login, 4 to fix, 4 to check | PASS |
| Rights and screen | Owner and school administrator yes; teacher and bursar no; problems listed with edit links and the guide; clean learners only with "show every learner"; bursar refused | PASS |
| Rollback, notices | None left; no PHP notices | PASS |

A first run stopped with a 403: the bursar's refusal used WordPress's JSON error handler
(Novamira runs inside a REST request) and ended the request; the transaction was
discarded (checked: nothing left) and the test now intercepts every `wp_die` handler.
The test also showed that a learner cannot be linked to a deleted login (the database
sets the link to NULL), so the screen has no separate "deleted login" check.

#### After going live
| Check | Result |
|---|---|
| Plugin 0.7.0 loaded; files match the repo; home 200 | PASS |
| Screen: owner and school administrator only (teacher, bursar, parent, learner: no) | PASS |
| Live data 2026: 1 learner, 1 warning (sample parent's placeholder email) | As expected |

### Phase 9, QA and hardening (2026-10-15)
Report: `docs/qa-report-phase9.md`. All suites re-run on the live code: access-guard
20/20, admission-and-portal 60/60, finance 49/49, register 53/53, reports 56/56,
family 29/29 (one check added: notice badge alt text), family-http 14/14, accounts
19/19. Security and health sweep `tests/integration/security.php` 49/49 (one finding,
author enumeration, fixed in plugin 0.7.1 before the final run). Accessibility (axe-core,
WCAG 2.1 A/AA) on 9 pages and phone widths 390/320 px. Backup schedule fault found and
fixed; a backup was run and verified in Google Drive.

### School Dashboard, plugin 0.8.0 (2026-10-15)

#### Integration test: `tests/integration/dashboard.php` (37/37 PASS)
Run against the staged copy, then live, inside a rolled-back transaction. Real data may
already exist, so figures are checked as the change the synthetic data makes: four
learners in Form 3 (plus one who has left), invoices and payments, a Monday register,
Term 3 marks and a published report card, a subject-teacher assignment.

| Area | Checks | Result |
|---|---|---|
| Helpers | Money as USD with two decimals; meter width, cap at 100%, text escaped and written out, labelled for screen readers | PASS |
| Fees | Form 3 Sciences +3 learners, +850 invoiced (one discount), +400 paid, +450 outstanding, +2 owing; a learner who left not counted; school totals match; last 7 days counts today's payment, not one 10 days old | PASS |
| Register | 1 present, 1 late, 1 absent → 2 of 3 attended; subject registers ignored; unmarked class has no rate; last day taken found | PASS |
| Marks | Expected = each learner's own subjects (2 + 2 × 13); a mark of 0 counts, a term mark alone does not, a subject the learner does not take does not; one published; other terms untouched | PASS |
| Subject teachers | Assigning a subject removes it from its form's list; one entry per form | PASS |
| Needs attention | Nothing when all is in order; no term; next term start missing; unmarked register named by class (not on weekends or holidays); unpublished report cards after the term ends; subjects without a teacher per form; family account errors (action) and warnings (note) | PASS |
| Rights and screen | Owner and school administrator only (teacher, bursar, parent, learner, visitor: no); menu for the administrator, not the bursar; title, six figures, three tables, links; synthetic figures written out; under 3 s; bursar refused | PASS |
| Rollback, notices | Nothing left; terms and subject teachers as before; no PHP notices | PASS |

#### After going live
| Check | Result |
|---|---|
| Plugin 0.8.0 loaded; files match the repo | PASS |
| Over HTTP: owner and school administrator 200 with the screen; bursar and teacher 403 | PASS |
| Live data: 1 learner (Form 2 A); attention shows next term start missing, Form 2 A register not marked, 46 subjects without a teacher, 1 family account to check | As expected |
| Security sweep (`security.php`) | 49/49 |

#### Visual check
A sample with six classes rendered at 1280, 1700, 390 and 320 px: figures in rows of
three (six across on wide screens), bars above their figures, no sideways scrolling on
phones (tables scroll inside their card if needed). Bar colour #376DBE on #DCE6F5
(contrast 4.07:1 against the track, 5.13:1 against white).

### Not yet tested
- A real sign-in through the browser with a learner or parent account (none exist yet).
- File upload through the Admission Documents screen in a browser (the upload path is
  covered except PHP's own `is_uploaded_file`, which needs a real HTTP upload).
- Weblizar's payment form in a browser (the hidden payment methods are removed by a
  small script; the server refuses them regardless).
- Parent bank-transfer submissions (off until the bank details arrive).
- Printing a report card from a real browser (the HTML is checked; page breaks and the
  A4 fit need a look on paper).
- A real parent or learner signing in from their own phone (the pages are verified over
  HTTP and rendered in a headless browser; no real family account is in use yet).
