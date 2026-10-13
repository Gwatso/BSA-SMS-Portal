# Requirements and Decisions Log

Keeps project context across Claude Code sessions. Newest entries first.

## Decisions

| Date | Decision | By | Consequence |
|---|---|---|---|
| 2026-10-10 | Weblizar plugin licence: owner uses a "WordPress IT" licence; **no Weblizar purchase**. Current copy stays. | Owner | Audit finding §7.2 accepted by owner. No vendor updates; the BSA version check warns if the plugin changes. |
| 2026-10-10 | Approved plugins: UpdraftPlus, FluentSMTP, Simple History, Limit Login Attempts Reloaded, Two Factor. | Owner | Installed 2026-10-10. Wordfence not installed (it was an either/or). |
| 2026-10-10 | Off-site backups to Google Drive account **info.afriflarenet@gmail.com**. | Owner | Needs the owner to authorise in UpdraftPlus (see `backup-and-recovery.md`). |
| 2026-10-10 | Formal school = **Form 1–4 only**. A' Level classes belong to the extra-lessons programme, not the formal school. | Owner | Lower/Upper Sixth are not created as formal classes. |
| 2026-10-10 | **Extra lessons deferred.** | Owner | Phase 7 on hold. Q5 answered later. |
| 2026-10-10 | Fees: **USD 250 per term**, paid by **bank transfer or cash at school**. | Owner | Offline payments recorded by staff. No online gateways. |
| 2026-10-10 | Communication: **email and WhatsApp** now; **SMS later**. | Owner | SMS settings stay off. |
| 2026-10-10 | Admission documents collected: **birth certificate** and **Grade 7 results**. Blood group **not** collected. | Owner | Built as the Admission Documents screen (plugin 0.2.0). Religion collected (owner, below). |
| 2026-10-10 | Slogan: **"Passion for Excellence"**. | Owner | Use everywhere on the portal; not "Excellence Through Science". |
| 2026-10-10 | Report card: owner will supply a **template letter**; **grade bands** come with it. | Owner | Grading and report cards wait for the template. |
| 2026-10-10 | Subjects: take them from the **public website**. | Owner | O' Level list (13) used for Forms 1–4 (see below). |
| 2026-10-10 | Branding via a **Blocksy child theme** (`bsa-portal`). | Owner | Activated. Brand direction captured from the public website into `docs/DESIGN.md` and saved/activated as the Novamira design `bsa-portal`. |
| 2026-10-10 | Brand fonts **self-hosted** (Playfair Display, Plus Jakarta Sans, Latin subset); no Google Fonts requests. Blocksy palette set to the brand colours; crest added to the Media Library as logo; square site icon generated from it; tagline set to "Passion for Excellence". | Claude (within approved branding) | Site title "BSA School Management System" left unchanged (owner may rename in Settings → General). |
| 2026-10-10 | **Forms 1–2 take 10 subjects** (ZJC list on the website): Mathematics, English Language, Principles of Accounting, History, Geography, Chemistry, Physics, Biology, Shona Language, Computer Science. Combined Science, Business Enterprise Skills and Heritage Studies start at Form 3. | Owner (count) + website (list) | Applied. Forms 3–4 keep all 13. |
| 2026-10-10 | **Forms 3 and 4 have two streams: Sciences and Commercials** (Weblizar sections). Learners are promoted into the matching Form 4 stream. | Owner | Applied (Form 3 in step 2, Form 4 in step 3). Weblizar subjects belong to the form; stream subjects are chosen per learner at admission. |
| 2026-10-10 | School year **January–December** confirmed. | Owner | Sessions already Jan–Dec. |
| 2026-10-10 | **Religion is collected**; caste and blood group are not. Transport field off (module out of scope). | Owner (religion, blood group) + Claude (caste, transport) | Applied in Weblizar registration settings, which drive both the admin admission form and the public form. |
| 2026-10-10 | Portal email sent **from info@bindurascienceacademy.co.zw** ("Bindura Science Academy") through FluentSMTP using the server's PHP mail. No mailbox password stored. Weblizar's carrier set to wp_mail. | Owner (address) + Claude (method) | Test email to info@ logged as sent. SPF valid. **DKIM not published** in the domain's DNS (owner action). Switch to authenticated SMTP later if deliverability is poor. |
| 2026-10-10 | Fees modelled as **three one-time fee types "Term 1/2/3 Fees", USD 250 each, per form**, active on admission. Invoices are issued per term by the bursar. | Claude (implementation) | Weblizar has no "term" period; its "quadrimester" option is ignored in totals and auto-invoicing, and a single one-time fee would show USD 250/year instead of 750. |
| 2026-10-10 | Sessions "2026" and "2027", January–December; one section "A" per form. | Claude (Q1b default) | Change in wp-admin (School Management → Sessions / Sections) if BSA uses streams or another calendar. |
| 2026-10-10 | All 13 website subjects assigned to every form, type "Theory", no subject codes. | Claude (Q2b default) | Remove subjects per form or add ZIMSEC codes when confirmed. |
| 2026-10-10 | Online learner/staff self-registration through Weblizar blocked (staff-only) until online admissions are wanted. | Claude (security default) | Re-enable through the `bsa_sm_staff_only_ajax_actions` filter. |
| 2026-10-10 | Work directly on the production portal; commit and push every change. | Owner | Snapshot before each risky change. |
| 2026-10-10 | AIOS, Burst Statistics and WP-Optimize were installed together with UpdraftPlus. | Owner (confirmed) | Kept. AIOS and Limit Login Attempts Reloaded overlap (login lockout): recommend keeping one. WP-Optimize page cache must stay off for logged-in users and the My Account page. |
| 2026-10-10 | Staff roles **Teacher** (own assigned class: attendance, leave, study materials, homework, lessons; timetable view) and **Bursar** (fee types, invoices create/edit, income, expenses, finance statistics; **no delete**). Marks/exams for teachers wait for the grading rules, because Weblizar's single `manage_exams` permission also creates exams and publishes results. | Owner (roles) + Claude (least-privilege split) | `scripts/05-staff-roles.php`; `docs/permission-matrix.md`. Role edits do not reach existing staff (Weblizar copies permissions at creation). |
| 2026-10-10 | Birth certificate and Grade 7 results kept in a **BSA table and a private upload folder**, not in Weblizar's custom fields: number, primary school, Grade 7 year and units, plus scans (PDF/JPG/PNG, 5 MB). Only administrators and staff with admissions or students rights can see them; learners and parents cannot. | Claude (implementation of owner decision) | Weblizar custom fields cannot hold files privately and show on printouts. No vendor files edited. |
| 2026-10-10 | Portal pages: **Home** (front page) and **My Account** (Weblizar's account shortcode, which shows the sign-in form when signed out). Learners and parents sign in there and never see wp-admin; staff use wp-admin. Header menu: Home, My Account, School website. | Claude (within owner's request) | `scripts/06-portal-pages.php`. Site title still "BSA School Management System" (owner may rename). |
| 2026-10-10 | Weblizar dashboard items **Live Classes** and **Books Issued** switched off (modules not used). | Claude | Re-enable in School Management → Settings → Dashboard. |
| 2026-10-13 | Login lockout: **Limit Login Attempts Reloaded** kept; All-In-One Security removed. | Owner | Verified 2026-10-13: AIOS folder, tables and firewall files gone; LLAR active with defaults (4 tries, 20-minute lockout, 24 h after 4 lockouts, email notice). Two AIOS cron events and two AIOS options remain (inert). |
| 2026-10-13 | The second school administrator (Weblizar "admin" staff role) is **not** a WordPress administrator; he manages the school through School Management only. The owner's account is the only WordPress administrator. | Owner | Weblizar refuses to link staff records to WordPress administrators, so this is also what makes his School Management login work. |
| 2026-10-13 | Staff logins are created from the email on the Weblizar staff record; staff set their own password through WordPress's password-setup email (no passwords are chosen or shared by others). | Owner (approved) + Claude | First email sent to the Form 1 A class teacher. |
| 2026-10-13 | The bursar uses the `bursar` login with a staff record named "Bursar" until the person is known; a personal login is preferred so the activity log shows who recorded each payment. | Claude (owner did not name the bursar) | Rename the staff record and login when known. |
| 2026-10-13 | Learner BSA001's parents are **sample data** for now (names marked "(sample)", login `bsa001.parent` with a non-deliverable email). | Owner | Replace with the real parents before the account is used; the owner sets the parent's password in Users → Edit. |
| 2026-10-13 | **Invoices are termly; some parents pay monthly.** Every unpaid invoice accepts part-payments; a monthly payment is recorded as a part-payment against the term invoice. No discounts. | Owner | Enforced by plugin 0.3.0. |
| 2026-10-13 | Payments accepted: **Cash** and **Bank Transfer** (bank reference required). Amount must not exceed the invoice balance; an overpayment is recorded as the balance and the rest handled outside the invoice. | Owner (methods) + Claude (reference, amount rule) | Weblizar's own form offers six methods and checks no amounts. |
| 2026-10-13 | Bank details for invoices: **to follow from the school administrator**. Weblizar's "Bank Transfer" option for parents (shows the details and lets parents submit proof of payment) stays off until then. | Owner | Pending owner action. |
| 2026-10-13 | No real term invoices issued by Claude; the system was built and tested with sample data only. Learner BSA001's three invoices (created by Weblizar at admission) were only renumbered and opened to part-payments. | Claude (owner did not ask for invoices to be issued) | Issue term invoices from School Management → Fees → Invoices. |
| 2026-10-10 | Weblizar tables stay the system of record; BSA code lives in `bsa-school-management` and the `bsa-portal` child theme, never in vendor files. | Claude | — |
| 2026-10-10 | Library, transport, hostel modules unused. | Claude | — |

## Verified facts to reuse

- Brand: navy `#1B3674`, gold `#CEA378`, crest red `#A71B32`, primary-light `#376DBE`; Playfair Display (headings) + Plus Jakarta Sans (body); logo URL in audit §3.
- Contact: 3159 Aerodrome Medium, Bindura, Mashonaland Central · info@bindurascienceacademy.co.zw · +263 77 260 9503 / +263 77 807 0422 / +263 78 365 5488. EMIS 4770. Founded 2022.
- Subjects published on the website (O' Level, 13): Mathematics, English Language, Combined Science, History, Geography, Principles of Accounting, Chemistry, Physics, Biology, Shona Language, Computer Science, Business Enterprise Skills, Heritage Studies.
- Hosting: shared cPanel account; account-level backups unavailable.

## Open questions

| # | Question | Default if unanswered | Blocks |
|---|---|---|---|
| Q3 | Assessment components/weights, grade bands, who approves results | Comes with report template | Grading, results |
| Q4 | Report-card template | Owner to send | Report cards |
| Q5 | Extra lessons design | Deferred | Phase 7 |

## Completed owner actions

| Date | Action |
|---|---|
| 2026-10-10 | Google Drive authorised in UpdraftPlus (account owner "Afriflarenet Pty Ltd") |
| 2026-10-10 | Two Factor enabled on the administrator account |
| 2026-10-10 | Test email "BSA portal: test email" received in the info@ inbox |
| 2026-10-10 | Confirmed Sciences/Commercials streams continue into Form 4 |
| 2026-10-10 | Confirmed AIOS, Burst Statistics and WP-Optimize came with UpdraftPlus |
| 2026-10-13 | Chose Limit Login Attempts Reloaded; removed AIOS |
| 2026-10-13 | Created the first accounts (administrator, teacher, bursar login, one learner); review findings given to the owner |
| 2026-10-13 | Approved the account fixes (head no longer WordPress admin, logins linked, sample parent) |

## Pending owner actions

| Action | Where |
|---|---|
| Publish the DKIM record for bindurascienceacademy.co.zw at the domain's DNS provider (value: cPanel → Email Deliverability) | DNS provider |
| Name the bursar (rename the "Bursar" staff record and give them a personal login) | School Management → Staff |
| Send the bank details (bank, account name, account number, branch) to show on invoices | — |
| 2026 term dates / fee due dates | — |
| Decide whether new admissions should still create all three term invoices at once (Weblizar does this for fee types marked "active on admission") | School Management → Fees |
| Replace BSA001's sample parents with the real parents and set the parent login's password | School Management → Students; Users → Edit |
