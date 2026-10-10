# Requirements and Decisions Log

Keeps project context across Claude Code sessions. Newest entries first.

## Decisions

| Date | Decision | Reason |
|---|---|---|
| 2026-10-10 | Novamira connector `novamira-portal-bindurasc` treated as **production**; not to be used for any remote operation. | Name matches the production portal; no evidence of staging. Master prompt §3. |

## Open items (blocking Phase 0)

1. Owner to create a staging copy of the portal (separate subdomain or host-level staging), on a host not indexed by search engines and behind a login or IP restriction.
2. Install Novamira on staging only and connect it as a separate, clearly named connector (e.g. `novamira-bsa-staging`).
3. Disable Novamira AI abilities on production, or confirm they are disabled.
4. Confirm a recent restorable backup of production and of staging (host, date, restore tested?).
5. Allow `bindurascienceacademy.co.zw` and `portal.bindurascienceacademy.co.zw` in this cloud environment's network settings, so the public site can be read for the branding inventory.
6. Decide how staging handles real learner data: synthetic data (recommended), or an anonymised copy.
