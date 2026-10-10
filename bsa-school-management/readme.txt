=== Bindura Science Academy - School Management Extension ===
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.2.0
License: GPL-2.0-or-later

BSA-specific security hardening and extensions for the Weblizar School Management plugin.

== Description ==

* Access guard: blocks anonymous access to Weblizar endpoints that expose learner
  invoices, results, admit cards and certificates, or that create accounts; enforces
  learner/parent ownership on invoice endpoints (AJAX and REST).
* Hardening: disables XML-RPC and the file editor, sends security headers, hides the
  REST users list from anyone who cannot list users.
* Compatibility notice when Weblizar is missing or at an unreviewed version.
* Admission Documents (wp-admin): birth certificate number and scan, Grade 7 primary
  school, year, units and results slip per learner. Scans are stored in
  uploads/bsa-private (web access denied) and served only to administrators and
  Weblizar staff with Manage Admissions / Manage Students.
* Portal routing: learners and parents land on the My Account page after sign-in, have
  no toolbar and are kept out of wp-admin; staff go to the dashboard.

Data: table {prefix}bsa_admission_records and files in uploads/bsa-private/. Neither is
removed when the plugin is deactivated or deleted.

Filter `bsa_sm_staff_only_ajax_actions` changes which Weblizar AJAX actions are
staff-only (e.g. to re-enable online admissions).

== Changelog ==

= 0.2.0 =
* Admission Documents module with private file store and authenticated downloads.
* Portal sign-in routing and wp-admin separation for learners and parents.
* Media REST endpoints hidden from users who cannot upload files.

= 0.1.0 =
* Initial release: access guard, hardening, compatibility notice.
