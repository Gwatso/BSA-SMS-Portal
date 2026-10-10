# Requirements and Decisions Log

Keeps project context across Claude Code sessions. Newest entries first.

## Decisions

| Date | Decision | By | Reason / consequence |
|---|---|---|---|
| 2026-10-10 | **Work directly on the production portal** `portal.bindurascienceacademy.co.zw`; no staging site. Commit and push every change to this repo. | Owner | Overrides master prompt §3. Mitigations: back up before any write; the site has no learner data yet; all code is kept in git. |
| 2026-10-10 | Phase 0 audit done read-only. See `docs/audit-report.md`. | Claude | — |
| 2026-10-10 | Weblizar tables stay the system of record; BSA code goes in a separate plugin and a Blocksy child theme, never in vendor files. | Claude (architecture default) | Master prompt §10. |
| 2026-10-10 | Library, transport and hostel modules stay unused. | Claude (scope default) | Master prompt §2. |

## Verified facts to reuse

- Brand: navy `#1B3674`, gold `#CEA378`, crest red `#A71B32`, primary-light `#376DBE`; Playfair Display (headings) + Plus Jakarta Sans (body); logo URL in audit §3.
- Contact: 3159 Aerodrome Medium, Bindura, Mashonaland Central · info@bindurascienceacademy.co.zw · +263 77 260 9503 / +263 77 807 0422 / +263 78 365 5488. EMIS 4770. Founded 2022.
- Hosting: shared cPanel account; account-level backups unavailable.

## Open questions (first batch, 2026-10-10)

| # | Topic | Question | Recommended default | Blocks |
|---|---|---|---|---|
| Q1 | Academic structure | Forms 1–4 only, or also Lower/Upper Sixth (website advertises A' Level, Jan 2027 intake)? Calendar Jan–Dec with 3 terms? Streams per form (e.g. 1A/1B, Science/Commercial)? | Forms 1–4 + Lower/Upper 6th; one session per calendar year; 3 terms; one section per form until told otherwise | School configuration |
| Q2 | Subjects | Approved subject list per form (website shows O' Level 13 / A' Level 8 / ZJC)? | Use website lists as a draft for you to confirm | Subject setup |
| Q3 | Assessment | Components and weights (coursework, tests, end-of-term exam), grade bands, pass mark; who approves results before learners/parents see them? | Teacher enters → Head approves → publish | Grading, results |
| Q4 | Report card | Existing template to reproduce? Which comments and signatures? | Logo, learner details, subject marks/grades, class-teacher + Head comments, attendance summary | Report cards |
| Q5 | Extra lessons | Approve option A (separate Weblizar school "BSA Extra Lessons")? Organised by subject, form, group or tutor? Cambridge included? Logins for participants/parents? | Option A; groups by subject + level; parent/learner logins optional | Extra-lessons module |
| Q6 | Finance & messaging | Currencies (USD only, or ZiG too)? Payment methods (cash, bank, EcoCash, swipe)? Fees per term per form? Which mailbox for outgoing email; any SMS provider? | USD; offline payments recorded by bursar; fees per term per form; SMTP via school mailbox; SMS later | Fees, notifications |
| Q7 | Data minimisation | May we hide religion, caste, blood group, ID-proof uploads? Who is responsible for data-protection compliance? | Hide them; collect medical/emergency info only if the school asks | Admission forms |
| Q8 | Tagline | "Passion for Excellence" (crest/motto) or "Excellence Through Science" (website title) for the portal? | "Passion for Excellence" | Branding text only |

## Pending approvals

| Item | Status |
|---|---|
| Install UpdraftPlus + choose off-site storage (e.g. Google Drive) | Awaiting owner |
| Install FluentSMTP, Simple History, Limit Login Attempts Reloaded, Two Factor | Awaiting owner |
| Buy Weblizar School Management licence and replace the nulled copy | Awaiting owner |
| Hardening (disable XML-RPC, security headers, DISALLOW_FILE_EDIT, timezone Africa/Harare) | Awaiting owner (low risk) |
