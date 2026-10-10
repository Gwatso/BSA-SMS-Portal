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
| 2026-10-10 | Admission documents collected: **birth certificate** and **Grade 7 results**. Blood group **not** collected. | Owner | Admission form config in Phase 3. Religion/caste handling still to confirm (Q7b). |
| 2026-10-10 | Slogan: **"Passion for Excellence"**. | Owner | Use everywhere on the portal; not "Excellence Through Science". |
| 2026-10-10 | Report card: owner will supply a **template letter**; **grade bands** come with it. | Owner | Grading and report cards wait for the template. |
| 2026-10-10 | Subjects: take them from the **public website**. | Owner | O' Level list (13) used for Forms 1–4 (see below). |
| 2026-10-10 | **Forms 1–2 take 10 subjects** (ZJC list on the website): Mathematics, English Language, Principles of Accounting, History, Geography, Chemistry, Physics, Biology, Shona Language, Computer Science. Combined Science, Business Enterprise Skills and Heritage Studies start at Form 3. | Owner (count) + website (list) | Applied. Forms 3–4 keep all 13. |
| 2026-10-10 | **Form 3 has two streams: Sciences and Commercials** (Weblizar sections). | Owner | Applied. Weblizar subjects belong to the form; a learner's stream subjects are chosen per learner at admission. Form 4 streams: see Q1c. |
| 2026-10-10 | School year **January–December** confirmed. | Owner | Sessions already Jan–Dec. |
| 2026-10-10 | **Religion is collected**; caste and blood group are not. Transport field off (module out of scope). | Owner (religion, blood group) + Claude (caste, transport) | Applied in Weblizar registration settings, which drive both the admin admission form and the public form. |
| 2026-10-10 | Portal email sent **from info@bindurascienceacademy.co.zw** ("Bindura Science Academy") through FluentSMTP using the server's PHP mail. No mailbox password stored. Weblizar's carrier set to wp_mail. | Owner (address) + Claude (method) | Test email to info@ logged as sent. SPF valid. **DKIM not published** in the domain's DNS (owner action). Switch to authenticated SMTP later if deliverability is poor. |
| 2026-10-10 | Fees modelled as **three one-time fee types "Term 1/2/3 Fees", USD 250 each, per form**, active on admission. Invoices are issued per term by the bursar. | Claude (implementation) | Weblizar has no "term" period; its "quadrimester" option is ignored in totals and auto-invoicing, and a single one-time fee would show USD 250/year instead of 750. |
| 2026-10-10 | Sessions "2026" and "2027", January–December; one section "A" per form. | Claude (Q1b default) | Change in wp-admin (School Management → Sessions / Sections) if BSA uses streams or another calendar. |
| 2026-10-10 | All 13 website subjects assigned to every form, type "Theory", no subject codes. | Claude (Q2b default) | Remove subjects per form or add ZIMSEC codes when confirmed. |
| 2026-10-10 | Online learner/staff self-registration through Weblizar blocked (staff-only) until online admissions are wanted. | Claude (security default) | Re-enable through the `bsa_sm_staff_only_ajax_actions` filter. |
| 2026-10-10 | Work directly on the production portal; commit and push every change. | Owner | Snapshot before each risky change. |
| 2026-10-10 | Weblizar tables stay the system of record; BSA code lives in `bsa-school-management` (+ future child theme), never in vendor files. | Claude | — |
| 2026-10-10 | Library, transport, hostel modules unused. | Claude | — |

## Verified facts to reuse

- Brand: navy `#1B3674`, gold `#CEA378`, crest red `#A71B32`, primary-light `#376DBE`; Playfair Display (headings) + Plus Jakarta Sans (body); logo URL in audit §3.
- Contact: 3159 Aerodrome Medium, Bindura, Mashonaland Central · info@bindurascienceacademy.co.zw · +263 77 260 9503 / +263 77 807 0422 / +263 78 365 5488. EMIS 4770. Founded 2022.
- Subjects published on the website (O' Level, 13): Mathematics, English Language, Combined Science, History, Geography, Principles of Accounting, Chemistry, Physics, Biology, Shona Language, Computer Science, Business Enterprise Skills, Heritage Studies.
- Hosting: shared cPanel account; account-level backups unavailable.

## Open questions

| # | Question | Default if unanswered | Blocks |
|---|---|---|---|
| Q1c | Do Sciences/Commercials continue as streams in Form 4 (so Form 3 learners are promoted into matching Form 4 streams)? | Leave Form 4 as one section until confirmed | Promotions at end of year |
| Q3 | Assessment components/weights, grade bands, who approves results | Comes with report template | Grading, results |
| Q4 | Report-card template | Owner to send | Report cards |
| Q5 | Extra lessons design | Deferred | Phase 7 |

## Pending owner actions

| Action | Where |
|---|---|
| Authorise Google Drive in UpdraftPlus as info.afriflarenet@gmail.com | `backup-and-recovery.md` |
| Turn on Two Factor for the administrator account (authenticator app) | wp-admin → Users → Profile → Two-Factor Options |
| Confirm the test email "BSA portal: test email" arrived in the info@ inbox (check spam too) | Webmail |
| Publish the DKIM record for bindurascienceacademy.co.zw at the domain's DNS provider (value: cPanel → Email Deliverability) | DNS provider |
