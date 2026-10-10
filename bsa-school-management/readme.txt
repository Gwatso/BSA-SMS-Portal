=== Bindura Science Academy - School Management Extension ===
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPL-2.0-or-later

BSA-specific security hardening and extensions for the Weblizar School Management plugin.

== Description ==

* Access guard: blocks anonymous access to Weblizar endpoints that expose learner
  invoices, results, admit cards and certificates, or that create accounts; enforces
  learner/parent ownership on invoice endpoints (AJAX and REST).
* Hardening: disables XML-RPC and the file editor, sends security headers, hides the
  REST users list from anyone who cannot list users.
* Compatibility notice when Weblizar is missing or at an unreviewed version.

The plugin stores no data and adds no database tables.

Filter `bsa_sm_staff_only_ajax_actions` changes which Weblizar AJAX actions are
staff-only (e.g. to re-enable online admissions).

== Changelog ==

= 0.1.0 =
* Initial release: access guard, hardening, compatibility notice.
