# AIO Rewards — PRD (Concise)

_Living product doc. Full architecture lives in `/app/PROJECT.md`. This file
tracks scope, delivery status and prioritised backlog._

## Original problem statement

AIO Rewards is a scalable referral & rewards SaaS platform for **AIO Media**
(existing IPTV / streaming reseller). V1 focuses strictly on referral tracking,
Stripe checkout for one-time package purchases, milestone rewards for
ambassadors, and a Filament v5 admin panel. It intentionally excludes
multi-tenancy, subscription life-cycle sync and automated upstream
provisioning in the MVP.

## Users
- **Ambassador** — an existing AIO Media customer who verifies against the
  upstream provider and receives a unique referral link.
- **Buyer / Referred visitor** — anyone who lands via `/r/{code}` and buys a
  package through Stripe Checkout.
- **Admin / Super Admin** — Filament panel operators. Fulfil orders manually,
  approve referrals, configure rewards.

## Core requirements
1. Ambassador identity via **fake / upstream provider driver** (P0 ✅).
2. `/r/{code}` first-touch attribution with signed, encrypted cookies (P0 ✅).
3. Public **package catalogue** + **Stripe Checkout** (`mode: payment`) (P0 ✅).
4. Buyer details capture (name, email, preferred username, delivery
   channel, T&Cs) with attribution snapshot on the purchase (P0 ✅).
5. Signed **Stripe webhook** with idempotent event log and Horizon job
   processor (P0 ✅).
6. Filament v5 admin — Ambassadors, Referral Clicks, Packages (CRUD),
   Purchases (read + fulfil action) (P0 ✅).
7. Referral **conversions & fulfilment** workflow (P1, next).
8. **Refund / chargeback** handling + ambassador flagging (P1, partial —
   chargeback flag already lands on `AmbassadorProfile`).
9. **Rewards engine**: milestone rules (repeatable + lifetime), admin
   approval, manual payout recording (P1).
10. **Notifications** (email + WhatsApp CTA links) (P2).
11. **Hardening**: rate limits, audit logs, MFA, headers (P2).
12. **Launch readiness**: seed data cleanup, deploy runbook (P2).

## Delivery status (2026-02)

Completed in this build:
- Phase 0 — Foundations (Laravel 12, Filament v5, MariaDB drop-in, Redis,
  Horizon, roles, MFA scaffolding, audit logs, Super Admin CLI).
- Phase 1 — Identity & Activation (provider driver, activation, email verify,
  login/logout/reset, dashboard, AmbassadorResource).
- Phase 2 — Referral Tracking (`/r/{code}`, `ReferralClick`, attribution
  cookie, rate limits, dashboard stats, read-only Filament resource).
- Phase 3 — Packages, Buyer Details & Stripe Checkout (packages catalog,
  checkout flow, Stripe session creation, signed webhook, Horizon-backed
  event processor, Filament Package/Purchase resources, verified-user
  auto-redirect from verification prompt).

Tests: **64 passing** feature/unit tests (19 new for Phase 3 checkout +
webhook + processor idempotency).

## Backlog (prioritised)

### P1 — Next up
- Phase 4: **Conversions & Fulfilment.** Create a `ReferralConversion` record
  when a paid `Purchase` has an ambassador snapshot; admin fulfils the
  upstream provisioning manually and marks the conversion approved after
  the refund-window elapses.
- Phase 5: **Refunds & Chargebacks.** Reverse an approved conversion,
  claw back reward if applicable, flag ambassador for repeat abuse.
- Phase 6: **Rewards Engine.** Rule table (`min_conversions`, `reward_type`,
  `reward_value`, `repeatable`), admin approve → mark paid, payouts log.

### P2 — Post-core
- Phase 7: Notifications — email + WhatsApp deep-link CTAs.
- Phase 8: System Health & Hardening — headers, CSP, rate limits on all
  auth surfaces, Horizon health probe alarms.
- Phase 9: Launch Readiness — seed strip, deploy runbook, backup plan.

### Post-MVP / Future
- Support Agent role.
- Stripe Billing (recurring subs) and Customer Portal.
- Multi-tenancy.
- Stripe Connect payouts to ambassadors.
- Mobile API.

## Key architecture

DDD folders under `app/Domain/{Ambassadors,Billing,Provider,Referrals,Rewards}`.
See `PROJECT.md` for the full decisions table (A1–A10).

## Environment quirks

- Preview pod occasionally recycles PHP/MariaDB/Redis binaries; the
  self-healing `.emergent/bootstrap-runtime.sh` + Supervisor conf restore
  everything on boot.
- MariaDB 10.11 is used as a MySQL 8 drop-in in preview only. Production
  target remains MySQL 8.
- Laravel is served via Supervisor on port 3000 to align with Emergent
  ingress. Do **not** run `php artisan serve` manually.

## Test credentials

See `/app/memory/test_credentials.md` (kept up to date).
