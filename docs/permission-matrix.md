# Permission Matrix

Who can do what on the portal. Source of truth: Weblizar roles (`wlsm_roles`), set by
`scripts/05-staff-roles.php`, plus the BSA plugin's own checks. Verified by
`tests/integration/admission-and-portal.php` (2026-10-10, 60/60).

## Accounts

| Account | Created by | Signs in to | WordPress role |
|---|---|---|---|
| Administrator (owner) | — | wp-admin | administrator (two-factor on) |
| Teacher | School office, in School Management → Staff, role "Teacher" | wp-admin (School Management only) | none beyond Weblizar staff |
| Bursar | School office, role "Bursar" | wp-admin (School Management only) | none beyond Weblizar staff |
| Learner | School office, at admission (login details on the student record) | My Account page | standard user, no staff rights |
| Parent | School office, at admission (parent login on the student record) | My Account page | standard user, no staff rights |

Learners and parents are sent to **My Account** after signing in, do not see the
WordPress toolbar, and are redirected away from wp-admin (`BSA_Portal`).

## Staff permissions (Weblizar)

| Area | Administrator | Teacher | Bursar |
|---|---|---|---|
| Class timetable | Manage | View | — |
| Attendance | Manage | Manage (own assigned class) | — |
| Learner leave requests | Manage | Manage (own assigned class) | — |
| Study materials, homework, lessons | Manage | Manage (own assigned class) | — |
| Exams, marks, results | Manage | **Not yet** (waits for grading rules) | — |
| Fee types | Manage | — | Manage |
| Invoices | Create, edit, delete | — | Create and edit; **cannot delete** |
| Payments | Record, delete | — | Record (through invoices); **cannot delete** |
| Income and expenses | Manage | — | Manage |
| Finance statistics | View | — | View |
| Admissions and learner records | Manage | — | — |
| Admission documents (birth certificate, Grade 7 results) | View, edit, download | — | — |
| Staff, roles, settings | Manage | — | — |

Weblizar keys: Teacher = `view_timetable`, `manage_attendance`, `manage_student_leaves`,
`manage_study_materials`, `manage_homework`, `manage_lessons`, `assigned_class`.
Bursar = `manage_fees`, `manage_invoices`, `edit_invoices`, `manage_income`,
`manage_expenses`, `stats_payments`, `stats_amount_fees_structure`, `stats_income`,
`stats_expense`.

**Admission documents** are open to administrators and to any staff member given
`manage_admissions` or `manage_students` (for example, a future "Office" role). Staff of
another school are refused.

**Important:** Weblizar copies a role's permissions onto each staff member when they are
created. Editing a role later does **not** change staff who already have it; update
those staff members individually (School Management → Staff → Edit).

## Learner and parent access (My Account)

| Item | Learner | Parent |
|---|---|---|
| Own details, class, stream | Yes | Each linked child |
| Fee invoices, fee structure, payment history | Own | Each linked child |
| Attendance | Own | Each linked child |
| Homework, study materials | Own class | — |
| Noticeboard, events, class timetable | Yes | Noticeboard, timetable |
| Exam results | Own, once published | Each linked child, once published |
| Leave request | Own | — |
| Admission documents | **No** | **No** |
| Any other learner's data | **No** (BSA access guard) | **No** (BSA access guard) |

Switched off for everyone: Live Classes, Books Issued (modules not used).
