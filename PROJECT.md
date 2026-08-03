# AIO Rewards — Project Specification

Living project document. Updated at the end of every phase and whenever a
correction is applied to the approved specification.

---

## 1. Approved MVP Scope

AIO Rewards is a **referral and rewards platform** for AIO Media (an IPTV /
VPN / streaming subscription reseller). Version 1 does **not** replace the
upstream provider panel — that panel remains the fulfilment system.

### In scope for v1
- Existing AIO Media customers activate an ambassador account by proving they
  own an active line on the upstream provider (see §3.1).
- Ambassadors receive a unique referral link and share it.
- Referral clicks, registrations, purchases, approvals and rewards are
  tracked automatically.
- Purchases go through **Stripe Checkout** (one-time, annual packages in v1).
- A purchase creates a **pending referral conversion**, linked to the referring
  ambassador via signed attribution cookie + Stripe metadata.
- Admin fulfils the purchase manually in the upstream provider panel and marks
  it fulfilled in Filament.
- After a configurable waiting window with no refund / chargeback, the
  referral becomes **approved** and counts toward the ambassador's milestone.
- Rewards are configurable per rule (repeatable cycles or one-time lifetime
  milestones); admin approves and marks paid; payouts are recorded manually.

### Explicitly out of scope for v1
- IPTV customer management portal / provider credentials portal
- Auto-provisioning, subscription lifecycle sync, provider webhooks, provider
  health dashboards
- Stripe Billing recurring subscriptions and Stripe Customer Portal
- Multi-tenancy
- Support Agent role (permissioning designed to allow it later)
- Livewire Volt (Blade + standard Livewire are used instead)

---

## 2. Architecture Decisions

| # | Decision | Rationale |
|---|---|---|
| A1 | **Modular monolith on a single Linux VPS** | Small, focused v1; scale later without a rewrite. |
| A2 | **Laravel 12 + PHP 8.4** | Long-term support, modern language features. |
| A3 | **Filament v5** admin panel at `/admin` | Rich admin UX without hand-rolling. |
| A4 | **Blade + Livewire v4** for public + ambassador surfaces | No Volt dependency; portable. |
| A5 | **MySQL 8** primary DB, **Redis** for cache / queue / session | Standard, well-supported. |
| A6 | **Horizon** supervises the queue workers | Real value: dashboard, retries, metrics. |
| A7 | **spatie/laravel-permission** for roles | Battle-tested; supports future Support role. |
| A8 | **Thin HTTP → Services → Events → Jobs** pattern | Testable, replaceable seams. |
| A9 | **Immutable append-only history** for allocations and reward transitions | Auditability without full double-entry ledger. |
| A10 | **Secrets live in server environment only** | Never in DB or Filament settings (see §7). |

---

## 3. Approved Corrections

The following corrections are part of the approved specification and MUST be
respected by all subsequent phases. They amend the earlier design.

### 3.1 Secure ambassador activation (Correction 1)

Provider username plus a user-supplied email is **not** sufficient proof of
account ownership. Activation collects:
- Existing IPTV/provider **username**
- Existing IPTV/provider **password**
- **Email** for the new AIO Rewards account
- **New AIO Rewards password** (and confirmation)
- **Consent** checkbox

`CustomerVerificationContract` verifies the provided provider username +
password and confirms the subscription is active.

The provider password is used **only** for the outbound verification request.
It is **never** stored, logged, cached, queued, or included in audit records,
exceptions, or database fields. It is passed as a `#[\SensitiveParameter]` and
scrubbed from stack traces.

After verification, the local AIO Rewards account is created using the *new*
AIO Rewards password (Argon2id via `bcrypt`/Laravel's hasher).

The verification contract is designed to be adaptable so the provider can
later support verification via registered email or one-time token without
breaking the contract shape.

### 3.2 Referral allocation history (Correction 2)

`ReferralAllocation` **preserves history** but supports release:

- Fields (conceptual): `allocated_at`, `released_at` (nullable), `release_reason` (nullable).
- Allocation rows are never deleted.
- A referral is *actively* allocated only when `released_at IS NULL`.
- A referral may have multiple historical released allocations but **at most
  one active allocation at a time**.
- When a pending/available/claimed reward is voided because one underlying
  referral was refunded:
  - Release the active allocations belonging to unaffected sibling referrals
    (`released_at = now`, `release_reason = 'sibling_referral_voided'`).
  - Sibling referrals become eligible for future reward cycles.
  - The refunded referral is marked `voided` and cannot be reused.
  - All allocation and reward-transaction history is preserved.
- Uniqueness is enforced under the ambassador row lock inside the service
  layer: a partial-unique index on `referral_id WHERE released_at IS NULL` is
  emulated via a `SELECT ... FOR UPDATE` guard plus a defensive check inside
  the milestone/void services. (MySQL 8 lacks true partial unique indexes; a
  generated-column workaround will be reviewed in Phase 6.)

### 3.3 Reward cycle identifier (Correction 3)

`Reward` gains a `cycle_number` integer, unique per
`(ambassador_profile_id, reward_rule_id, cycle_number)`.

- For `one_time_lifetime` rules, `cycle_number` is always `1`.
- For `repeatable_cycle` rules, cycle numbers increase sequentially (1, 2,
  3, ...).
- Milestone evaluation calculates the next cycle number under the ambassador
  row lock.
- This unique constraint is part of the idempotency protection against
  duplicate rewards. Uniqueness is enforced by a plain multi-column unique
  index, not a mode-conditional constraint.

### 3.4 Rejected reward handling (Correction 4)

Admin rejection supports **two explicit outcomes**:

**A. Reject and release allocations** — for incorrect payout details,
accidental claims, or administrative corrections.
- Active allocations are released.
- Referrals return to the unallocated pool.
- Immutable history preserved.

**B. Reject and consume cycle** — for fraud, abuse, or programme violations.
- Allocations remain consumed.
- Reason + acting administrator recorded.

Both actions require a reason and write to `RewardTransaction` **and**
`AuditLog`.

---

## 4. Phased Roadmap

| Phase | Status | Description |
|---|---|---|
| 0 | ✅ **Complete** | Foundations — Laravel skeleton, Filament, Horizon, roles, MFA foundation, audit log, layouts, tests, tooling. |
| 1 | Pending | Identity & Activation (secure verification, Ambassador role, referral code generation). |
| 2 | Pending | Referral Tracking (`/r/{code}`, signed cookie, click log, attribution). |
| 3 | Pending | Packages & Stripe Checkout (one-time payments, webhook signature, idempotency). |
| 4 | Pending | Referral Conversions & Fulfilment (pending → approved after window). |
| 5 | Pending | Refunds & Chargebacks (void unallocated referral; sibling release wiring). |
| 6 | Pending | Rewards Engine (cycle_number, ReferralAllocation history, MilestoneEvaluator). |
| 7 | Pending | Notifications (mail + in-app). |
| 8 | Pending | System Health & Hardening (health page, replay, headers, backups). |
| 9 | Pending | Launch Readiness (Stripe live keys via `.env`, runbook). |

---

## 5. Database Decisions

- **Primary DB:** MySQL 8 (production). SQLite (`:memory:` for automated tests,
  `database/database.sqlite` for local dev).
- **UUIDs / ULIDs:** ULIDs where sortable identifiers are useful
  (`AuditLog` row IDs). Users retain incremental `bigint` primary keys.
- **Timestamps:** UTC everywhere; `datetime(6)` where sub-second ordering
  matters (Phase 6 onward).
- **Encrypted-at-rest columns:** `users.app_authentication_secret`,
  `users.app_authentication_recovery_codes` (Filament v5 TOTP). Encryption is
  application-level via Eloquent `encrypted` / `encrypted:array` casts,
  keyed by `APP_KEY`.
- **Append-only tables (planned):** `audit_logs` (this phase), `reward_transactions`,
  `referral_allocations` (Phase 6). Application never issues `UPDATE` or
  `DELETE`; a Phase 8 hardening ticket will remove those privileges from
  the runtime DB user.
- **Partial-unique constraints:** MySQL does not support partial unique
  indexes; where needed, the constraint is enforced via row locks + service
  discipline (see §3.2 and §3.3).

---

## 6. Security Decisions

- **Authentication:** Laravel built-in guard (`web`), Argon2id password
  hashing (`bcrypt` in `.env` maps to Laravel's hasher; hashing algorithm
  configurable in `config/hashing.php`).
- **Mandatory admin 2FA:** Filament v5 App (TOTP) MFA is registered as
  **required** for the admin panel. Users must complete enrolment on first
  login. Recovery codes supported.
- **Panel access:** `User::canAccessPanel()` requires `is_active = true` and
  one of `support`, `admin`, or `super_admin` roles.
- **Roles:** Enumerated in `App\Enums\Role`. Seeded idempotently by
  `RolesAndPermissionsSeeder`.
- **Horizon dashboard:** Super Admin only (`viewHorizon` gate + `Horizon::auth`
  callback).
- **Secrets:** Stripe secret key, Stripe webhook secret, and provider API
  credentials **live in the server environment only**. They are never read
  from or written to the database. Filament's System Health page (Phase 8)
  reports only presence booleans and derived mode / connectivity — never
  values, never masked values, no reveal UI.
- **Sensitive parameters:** The provider password on the activation form is
  passed with `#[\SensitiveParameter]` so it is scrubbed from stack traces and
  logs (implemented in Phase 1).
- **Audit log:** Central `App\Support\Audit\AuditLogger::record()` entry
  point. No secrets are ever passed to it; enforced by code review.
- **Session:** Signed, encrypted cookies. Secure + HttpOnly + SameSite=Lax in
  production (`SESSION_SECURE_COOKIE=true`).

---

## 7. Configuration & Secrets Policy

- **`.env` / server environment:** all secrets, all environment-bound values.
  `.env.example` in the repo declares placeholders only.
- **`config/*.php`:** code-level defaults, read from `env()`. Committed.
- **`settings` table (Phase 8):** non-secret operational knobs (approval
  window, attribution window, default reward rule, rate limits, fraud
  thresholds). Filament-editable by Super Admin. Every write appends an
  `AuditLog` entry.
- **Rotation:** Secret rotation is a deployment-time operation: edit `.env`,
  `php artisan config:cache`, `php artisan horizon:terminate`. There is no
  in-application secret UI.

---

## 8. Testing Status

Phase 0 test suite (all green):

| Suite | Tests | Result |
|---|---|---|
| `Tests\Unit\ExampleTest` | 1 | pass |
| `Tests\Feature\WelcomePageTest` | 1 | pass |
| `Tests\Feature\Auth\RolesFoundationTest` | 5 | pass |
| `Tests\Feature\Audit\AuditLogTest` | 2 | pass |
| **Total** | **9 (16 assertions)** | **PASS** |

Static analysis: **PHPStan (Larastan) level 6** — 0 errors on `app/`, `config/`,
`database/seeders/`, `routes/`.

Code style: **Laravel Pint** with `pint.json` preset — 0 issues on 39 files.

---

## 9. Deployment Notes

**Target:** one standard Linux VPS.

Required services:
- Nginx + PHP-FPM 8.4
- MySQL 8
- Redis (cache / queue / session)
- Horizon (systemd or Supervisor)
- Certbot for TLS
- Nightly encrypted MySQL dump to off-VPS storage

Not required for v1: Docker, Kubernetes, Vapor, read replicas,
Prometheus/Grafana, multi-region.

The project is a standard portable Laravel 12 codebase. It does **not**
introduce any proprietary Emergent runtime dependencies. It can be cloned
from GitHub and run on any host that meets the requirements above using:

```
composer install --no-dev --optimize-autoloader
cp .env.example .env       # then edit .env
php artisan key:generate
php artisan migrate --force
php artisan db:seed --class=RolesAndPermissionsSeeder --force
php artisan config:cache route:cache view:cache
php artisan horizon &      # (behind supervisor in production)
```

See `README.md` for the full local development quick-start.
