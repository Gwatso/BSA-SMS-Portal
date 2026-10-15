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
| 2026-10-14 | Report book, grade keys (ZJC 1–9 for Forms 1–2, O Level A–U for Forms 3–4) and class register supplied. | Owner | `docs/academics-spec.md`; blank register in `docs/reference/`. |
| 2026-10-14 | Subject pass = **50 % and above** (ZJC 1–6, O Level A–C). | Owner | |
| 2026-10-14 | Overall result **Pass = 5 or more subjects passed** (any subjects). | Owner | |
| 2026-10-14 | **Exam marks** decide position, subjects passed and result; term marks shown for information. | Owner | |
| 2026-10-14 | USD 250 per term **covers everything**; the register's Levy / G.P.F. / Other columns are not charged separately. | Owner | Fee setup unchanged. |
| 2026-10-14 | Position = rank by average exam mark over subjects recorded, within class and stream; ties share a position; fractional marks rounded half up before grading; Present and Late count as attended, Holiday and days without a register are not possible attendances. | Claude (defaults) | Change on request. |
| 2026-10-14 | Weblizar student types renamed **Day Scholar** / **Boarder** for the register; learners without a type count as day scholars. | Claude (from the register layout) | `scripts/08-student-types.php`. |
| 2026-10-14 | The class teacher's Pupil details page shows the birth certificate **number** (as the paper register does); the scans stay restricted to administrators. | Claude | |
| 2026-10-14 | Weekly register checks can be recorded by the owner and Weblizar "admin" staff (head / deputy / teacher in charge). | Claude | Matches "School Head/Deputy/T.I.C" on the register. |
| 2026-10-14 | Marks and report cards built as BSA screens (tables `bsa_marks`, `bsa_reports`), not Weblizar's exam module: Weblizar's single `manage_exams` right also creates exams and publishes results, and its result card does not follow the BSA report book. | Claude | Weblizar exams stay unused. |
| 2026-10-14 | Who enters marks: class teacher for all subjects of their class; subject teacher for subjects assigned in Weblizar, in every stream of that form; administrators everywhere. Marks locked for teachers once published. | Claude (defaults) | Change on request. |
| 2026-10-14 | Report cards are **published per class by an administrator** (owner or school administrator) and only then visible to parents and learners. | Claude (Q7 default) | Q7 still open. |
| 2026-10-14 | The report card has no coat-of-arms image (none supplied); the school crest is used. The ministry heading is text. | Claude | Add the image when the owner supplies it. |
| 2026-10-14 | Learners are **not locked out of their dashboard** while fees are unpaid. Weblizar's "Dashboard Disable?" lock-out is off on the front end (it would lock out every learner whose parents pay monthly, and it miscounted payments); the fee types keep the flag so admission still creates one invoice per term. | Claude (default; follows the owner's part-payment decision) | Filter `bsa_sm_fee_lockout` restores it if the school wants a lock-out. |
| 2026-10-14 | Weblizar's exam timetable, admit card, exam results and certificates are hidden from learners and parents; results come from BSA report cards. | Claude | `scripts/09-family-portal.php`; re-enable in School Management → Settings → Dashboard. |
| 2026-10-14 | "Pay Now" is hidden while no online or bank-transfer method is enabled; it reappears automatically when the bank-transfer option is switched on with the bank details. | Claude | |
| 2026-10-14 | A **Help** page (`/help/`) is the parent and learner guide, linked in the header menu; it says to ask the office for bank details until they are published. | Claude (Phase 8) | Update the page when bank details arrive. |
| 2026-10-15 | **Parents and learners do not share logins.** Each parent has their own login (own email), shared by all their children; learner logins are optional and use a school email address. | Owner asked; Claude recommended | Family Accounts screen (plugin 0.7.0) checks it. |
| 2026-10-15 | Caution: in Weblizar, deleting a **class section or a school year (session)** deletes every learner record in it (database cascade). Deleting a WordPress user only unlinks it from the learner. | Claude (verified in the database) | Never delete a session or section that has learners; snapshot first. |
| 2026-10-15 | Backups follow the UpdraftPlus settings as they stood (daily files and database, 14 kept), now actually scheduled daily at 02:00. | Claude (fix; settings unchanged) | If Google Drive space runs short, set files to weekly in UpdraftPlus (database stays daily). |
| 2026-10-15 | Author archives disabled (404); usernames are not published anywhere on the site. | Claude (Phase 9 finding) | Plugin 0.7.1. |
| 2026-10-15 | Home page hero uses the learners photo from the school banner, **blurred so faces are not recognisable**, under a navy overlay; motto and title as real text. | Owner (softened photo) | Theme 0.3.0. A sharp photo needs parental media consent first. |
| 2026-10-15 | Staff area: **branded wp-admin** (all WordPress signs removed for everyone but the owner; a home screen per role), not a separate staff portal. | Owner | Next build. Owner keeps full WordPress. |
| 2026-10-15 | Results dashboards built **into the portal**; learner data stays on the school's server (no AI service). | Owner | AI-written insights only after a school privacy decision. |
| 2026-10-15 | **Learners at risk** = any of: term average below 50 %; fewer than 5 subjects passed; average down 10+ marks on last term; attendance below 85 %. Reasons shown with each flag. **Staff only**: parents and learners see results, trends, attendance and fees, but no at-risk flag or class position. | Owner (signs, audience) + Claude (default numbers, adjustable) | Built after the staff-area branding. |
| 2026-10-15 | The families' Report Cards list **keeps the class position**, because it is printed on the report card itself. The Progress section and dashboards show no position or at-risk flag to families. | Owner | No change. |
| 2026-10-15 | **BSA Intranet at /intranet**, rebuilt inside the portal from the Lovable "BSA Intranet" prototype (which used fake sign-in and sample data). Staff land there after signing in. | Owner (name, features) + Claude (architecture) | Plugin 0.11.0. |
| 2026-10-15 | Intranet address **https://portal.bindurascienceacademy.co.zw/intranet** with no trailing slash (also /intranet/news, /intranet/item/12…); addresses ending in "/" redirect (301). | Owner | |
| 2026-10-15 | Intranet posting: **owner and school administrator only**. Notices can require acknowledgement; the school administrator sees read receipts. | Owner | |
| 2026-10-15 | Documents stay in **OneDrive and Google Drive**; the intranet links to them (https only). Browsing Drive/OneDrive inside the intranet needs app registrations with Google and Microsoft (owner action) and is a later step. | Owner (providers) + Claude | |
| 2026-10-15 | **Attendance to WhatsApp: one tap by a person**, not automatic. WhatsApp's official Groups API covers only small groups the business itself creates (verified business, per-message charges); posting into the existing BSA Staff group is only possible with unofficial tools that risk a ban. | Claude (owner asked for automatic posting) | Share button for school administrators: picture on phones, or text. |
| 2026-10-15 | Teams- and SharePoint-style **department spaces and discussions** come after the intranet core. | Claude | Next phase. |
| 2026-10-15 | **Data to bring in:** existing records are mixed (Excel files, class lists in Word, marks on paper). History to capture: **last year (2025) and this year (2026)** only. | Owner | Import comes before the board insights. |
| 2026-10-15 | **Parent emails collected later**, once the system has proved itself. Learners and parents are imported without parent logins for now (parent names and phones recorded). | Owner | Parent logins created later from the emails; no messages sent at import. |
| 2026-10-15 | Owner will send the files; some attached in the chat for Claude to format and load. | Owner | Each file goes through the import checks and a preview of problems before anything is saved. |
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
| Q6 | Games houses: names of the houses | Field left empty until named | Register |
| Q7 | Who approves results before parents can see them (principal only, or principal and form teacher) | Principal (school administrator) publishes | Report cards |
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
| 2026-10-14 | Entered 2026 Term 3 dates (8 September – 1 December) and recorded the first weekly register check (Form 1 A) |

## Pending owner actions

| Action | Where |
|---|---|
| Publish the DKIM record for bindurascienceacademy.co.zw at the domain's DNS provider (value: cPanel → Email Deliverability) | DNS provider |
| Name the bursar (rename the "Bursar" staff record and give them a personal login) | School Management → Staff |
| Send the bank details (bank, account name, account number, branch) to show on invoices | — |
| Enter the remaining term dates: 2026 Terms 1–2 (if past cards or registers are wanted) and 2027 Terms 1–3. 2026 Term 3 was entered on 2026-10-14. Term 3 report cards need the 2027 Term 1 date for "Next term begins on" | wp-admin → School Terms |
| Assign subject teachers to their subjects so they can enter marks | School Management → Staff → Edit → Subjects |
| Before handing out family accounts: make sure each parent and learner login has a working email address (needed for "Lost your password?"), and point families to the Help page | School Management → Students |
| Decide whether new admissions should still create all three term invoices at once (Weblizar does this for fee types marked "active on admission") | School Management → Fees |
| Replace BSA001's sample parents with the real parents and set the parent login's password | School Management → Students; Users → Edit |
