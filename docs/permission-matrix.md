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

**One login per person.** A parent never uses the child's login: the parent gets their
own login (their own email), and brothers and sisters are linked to that same parent
login ("Existing User" on the admission form). A learner login is optional; when given,
it uses a school address (e.g. `bsa002@bindurascienceacademy.co.zw`), which keeps the
parent's email free. The **Family Accounts** screen lists learners where this is not the
case.

**Staff area** (at /staff since plugin 0.15.0; /wp-admin addresses redirect there). Everyone except the owner sees a branded wp-admin with no WordPress
branding or WordPress menus; menus are named Learners, Academics, Accounts, Exams,
School and Staff; Library, Transport and Live Classes are hidden from the menu. Hiding a
menu does not change access: each screen still checks Weblizar and BSA rights. Staff
land on their home screen after signing in. Only the owner sees standard WordPress.

**Results and families.** Learners at risk and class positions on the Results screen are
for staff only. Parents and learners see, under Report Cards, a Progress section built
from published report cards (average, change, subjects passed, attendance, marks by
subject), without any at-risk flag. The Report Cards list itself still shows the
position printed on each report card.

Learners and parents are sent to **My Account** after signing in, do not see the
WordPress toolbar, and are redirected away from wp-admin (`BSA_Portal`).

## Staff permissions (Weblizar)

| Area | Administrator | Teacher | Bursar |
|---|---|---|---|
| Class timetable | Manage | View | — |
| Attendance | Manage | Manage (own assigned class) | — |
| Learner leave requests | Manage | Manage (own assigned class) | — |
| Study materials, homework, lessons | Manage | Manage (own assigned class) | — |
| Weblizar exams and results | Manage | — | — |
| Marks (BSA: term, exam, project, remark) | Every class and subject | Class teacher: every subject of own class. Subject teacher: subjects assigned in Weblizar, every stream of that form | — |
| Report Cards: results, comments, print | All classes | Class teacher: own class | — |
| Principal's comment | Yes | — | — |
| Publish / withdraw report cards | Yes (owner, school administrator) | — | — |
| Change marks or comments after publication | Yes | — (locked) | — |
| Fee types | Manage | — | Manage |
| Invoices | Create, edit, delete | — | Create and edit; **cannot delete** |
| Payments | Record, delete | — | Record (through invoices); **cannot delete** |
| Income and expenses | Manage | — | Manage |
| Finance statistics | View | — | View |
| Fee Reports (balances, payments received, CSV) | Yes | — | Yes |
| School Terms (term dates) | Yes | — | — |
| Class Register (weekly register, pupil details, summary) | All classes | Own class only | — |
| Record the weekly register check | Yes (head / deputy / T.I.C.) | — | — |
| Admissions and learner records | Manage | — | — |
| School Dashboard (fees, register, marks, needs attention) | Yes (owner, school administrator) | — | — |
| Results: classes, subjects, learners at risk, Grade 7 entry vs now, progression, one learner's year | All classes; can change the at-risk rules | Class teacher: own class. Subject teacher: their subjects (no learner list) | — |
| Streams: set learners' Sciences or Commercials in Forms 3–4 | Yes (owner, school administrator; a class teacher for their own class) | — | — |
| Streams: change which subjects belong to each stream | Yes (owner, school administrator) | — | — |
| Year-end rollover; undo it; make the next year current | Yes (owner, school administrator) | — | — |
| Import data: learners and parents from files; undo an import | Yes (owner, school administrator) | — | — |
| Intranet (/intranet): read, acknowledge notices | Yes | Yes | Yes |
| Intranet: post news, announcements, notices, achievements, events, resources; read receipts; share attendance to WhatsApp | Yes (owner, school administrator) | — | — |
| Home screen | School Dashboard is their home | My class (class teacher), My subjects (subject teacher) | Fees |
| Family Accounts check (logins of learners and parents) | Yes (also staff with Manage Admissions or Manage Students) | — | — |
| Admission documents (birth certificate, Grade 7 results) | View, edit, download | — | — |
| Staff, roles, settings | Manage | — | — |

Weblizar keys: Teacher = `view_timetable`, `manage_attendance`, `manage_student_leaves`,
`manage_study_materials`, `manage_homework`, `manage_lessons`, `assigned_class`.
Bursar = `manage_fees`, `manage_invoices`, `edit_invoices`, `manage_income`,
`manage_expenses`, `stats_payments`, `stats_amount_fees_structure`, `stats_income`,
`stats_expense`.

**Payments** (BSA rules, all staff): Cash or Bank Transfer only, bank reference required for
transfers, amount above zero and at most the invoice balance. **Fee Reports** are open to
administrators, Weblizar "admin" staff, and staff with `manage_invoices` or `stats_payments`.

**Subject teachers:** assign subjects in School Management → Staff → Edit → Subjects.
A subject teacher can then enter that subject's marks for the learners of the form who
take it (in Forms 3 and 4, only the Sciences or only the Commercials learners for a
stream subject). "Administrators" in the Marks and Report
Cards rows means the owner and Weblizar "admin" staff (the school administrator).

**Admission documents** are open to administrators and to any staff member given
`manage_admissions` or `manage_students` (for example, a future "Office" role). Staff of
another school are refused.

**Linking logins:** a staff record works only when it is linked to a WordPress login
(School Management → Staff → Edit → Login details). Weblizar refuses to link a WordPress
administrator, so staff logins stay ordinary WordPress users; the Weblizar role decides
what they can do. The owner's account is the only WordPress administrator.

**Important:** Weblizar copies a role's permissions onto each staff member when they are
created. Editing a role later does **not** change staff who already have it; update
those staff members individually (School Management → Staff → Edit).

## WhatsApp messages (plugin 0.16.0)

| Who | Send | Contacts and consent | Sent log | Settings |
|---|---|---|---|---|
| Owner, school administrator | All messages, all classes | All classes | All | Yes |
| Bursar | Fee reminders (once switched on) | No | Own | No |
| Class teacher | Report card ready, absent today: own class | Own class | Own | No |
| Others, families | No access | — | — | — |

Messages go only to parents whose consent is "Yes". Nothing is sent by the server: the
sender presses send in WhatsApp.

## Learner and parent access (My Account)

| Item | Learner | Parent |
|---|---|---|
| Own details, class, stream | Yes | Each linked child |
| Fee invoices, fee structure, payment history | Own | Each linked child |
| Attendance | Own | Each linked child |
| Homework, study materials | Own class | — |
| Noticeboard, events, class timetable | Yes | Noticeboard, timetable |
| Weblizar exam timetable, admit card, exam results, certificates | Switched off (BSA uses its own report cards) | Exam results switched off |
| Report cards (BSA), view and print | Own, once published (menu: Report Cards) | Each linked child, once published (child's links: Report Cards) |
| Fee structure | Own (Weblizar) | Each linked child (BSA page) |
| Account settings (email, password) | Yes | Yes |
| Pay online (Pay Now) | Hidden while no payment method is enabled | Same |
| Leave request | Own | — |
| Admission documents | **No** | **No** |
| Any other learner's data | **No** (BSA access guard) | **No** (BSA access guard) |

Switched off for everyone: Live Classes, Books Issued, Exam Time Table, Admit Card, Exam
Results, Certificates (modules not used). Guide for families: the Help page (`/help/`).

A parent who changes the `student_id` in a link to another family's learner gets nothing:
Weblizar stops the page, and the BSA pages check ownership themselves.
