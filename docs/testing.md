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
| Head's login: subscriber in WordPress, linked to his Weblizar admin staff record; School Management manage students, invoices, settings, delete invoices | PASS |
| Bursar login linked to a new Bursar staff record: manage invoices, fees, edit invoices yes; delete invoices, delete payments, students, admissions, attendance no | PASS |
| Teacher login created and linked; class teacher of Form 1 A; attendance, homework, assigned class yes; fees, invoices, students, exams no | PASS |
| Teacher password-setup email: FluentSMTP log "sent" | PASS |
| Learner BSA001 has a separate (sample) parent login; parent counts as family and sees only BSA001 | PASS |
| All three staff count as staff for the BSA plugin, none as family | PASS |
| All-In-One Security leftovers (2 scheduled tasks, 2 options) removed | PASS |

The script's first self-check reported that the bursar and teacher had every permission.
Cause: Weblizar caches the first user's details for the whole request
(`wp_cache_get( 'wlsm_user_info' )`), so all checks reused the head's admin rights. Real
sign-ins are separate requests and unaffected. Re-run with the cache cleared before each
check: 19/19 PASS. The script now clears it itself.

### Not yet tested
- A real sign-in through the browser with a learner or parent account (none exist yet).
- File upload through the Admission Documents screen in a browser (the upload path is
  covered except PHP's own `is_uploaded_file`, which needs a real HTTP upload).
