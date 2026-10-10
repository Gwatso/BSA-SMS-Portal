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

### Not yet tested
- Restoring from backup (no second environment yet).
- Logged-in browser journeys (no learner/parent accounts or pages exist yet).
