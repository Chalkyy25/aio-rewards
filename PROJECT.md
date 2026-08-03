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
AIO Rewards password (Argon2id via Laravel's hasher).

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
- Enforcement is done under the ambassador row lock inside the service layer.

### 3.3 Reward cycle identifier (Correction 3)

`Reward` gains a `cycle_number` integer, unique per
`(ambassador_profile_id, reward_rule_id, cycle_number)`.

- `one_time_lifetime` rules: `cycle_number` = 1.
- `repeatable_cycle` rules: sequential 1, 2, 3, …
- Enforced by a plain multi-column unique index (not mode-conditional).

### 3.4 Rejected reward handling (Correction 4)

**A. Reject and release allocations** — for admin correction; active
allocations are released, referrals return to the unallocated pool, history
preserved. **B. Reject and consume cycle** — for fraud/abuse; allocations
remain consumed. Both actions require a reason and are written to
`RewardTransaction` **and** `AuditLog`.

---

## 4. Phased Roadmap

| Phase | Status | Description |
|---|---|---|
| 0 | ✅ Complete + verified | Foundations, Filament v5 + MFA, Horizon, roles, audit log, Super Admin command. |
| 1 | ✅ Complete | Identity & Activation — secure verification, Ambassador role, referral code generation, welcome/verify email, public activation page, ambassador login/logout/reset, ambassador dashboard, Filament AmbassadorResource. |
| 2 | Pending | Referral Tracking (`/r/{code}`, signed cookie, click log, attribution). |
| 3 | Pending | Packages & Stripe Checkout. |
| 4 | Pending | Referral Conversions & Fulfilment. |
| 5 | Pending | Refunds & Chargebacks. |
| 6 | Pending | Rewards Engine. |
| 7 | Pending | Notifications. |
| 8 | Pending | System Health & Hardening. |
| 9 | Pending | Launch Readiness. |

---

## Phase 1 — Fake Provider Verification Rules

When `PROVIDER_VERIFICATION_DRIVER=fake` (default in local/preview `.env`),
the `FakeVerificationDriver` accepts the following test values:

| Provider username | Provider password | Outcome |
|---|---|---|
| `test_active` | `letmein` | ✅ eligible → activation succeeds |
| `test_inactive` | `letmein` | ❌ inactive → activation rejected |
| `test_error` | *(any)* | ❌ provider outage simulation |
| *(anything else)* | *(any)* | ❌ not_found |
| `test_active` | *(wrong)* | ❌ wrong_credentials |

Set `PROVIDER_VERIFICATION_DRIVER=aio_iptv_v1` in production `.env` and fill
in `PROVIDER_VERIFICATION_URL` + `PROVIDER_VERIFICATION_KEY` to use the real
driver.

**No production secrets are committed to this repository.**

---

## 5. Database Decisions

- **Primary DB:** MySQL 8 (production). MariaDB 10.6+ is wire-compatible for
  the `mysql` Laravel driver and is used as the CI/dev fallback where MySQL 8
  is impractical to install.
- **Test DB:** two PHPUnit configurations are shipped:
  - `phpunit.mysql.xml` — authoritative compatibility target.
  - `phpunit.sqlite.xml` — fast local dev / CI smoke.
- **Encrypted-at-rest columns:** `users.app_authentication_secret`,
  `users.app_authentication_recovery_codes` (Filament v5 TOTP).
- **Append-only tables:** `audit_logs` (this phase). Reward tables in Phase 6.
  Phase 8 hardening ticket will revoke `UPDATE` / `DELETE` on those tables
  for the runtime DB user.

---

## 6. Security Decisions

- **Authentication:** Laravel `web` guard, Argon2id via Laravel's hasher.
- **Mandatory admin 2FA:** Filament v5 App (TOTP) MFA registered with
  `isRequired: true`. Users must enrol on first login. Recovery codes
  supported (`HasAppAuthenticationRecovery` implemented on `User`).
- **Panel access:** `User::canAccessPanel()` requires `is_active = true` and
  one of `support`, `admin`, `super_admin` roles.
- **Horizon dashboard:** Super Admin only.
- **Super Admin bootstrap:** Interactive `php artisan aio:make-super-admin` —
  no default password in `.env`, never logs or persists the plaintext.
- **Secrets policy:** Stripe secret key, Stripe webhook secret and provider
  API credentials live **exclusively in the server environment**. They are
  never read from or written to the database. Filament's Phase 8 System
  Health page will report only presence booleans, derived mode, and
  connectivity — never values.
- **Sensitive parameters:** provider password (Phase 1 onward) is
  `#[\SensitiveParameter]`-tagged.
- **Audit log:** central `App\Support\Audit\AuditLogger::record()`. Never
  receives secrets.

---

## 7. Super Admin Bootstrap

```bash
php artisan aio:make-super-admin
```

Interactive prompts:
1. **Name**
2. **Email**
3. **Password** (hidden, min 12 chars) + **Confirm password** (hidden)

Behaviour:
- Fails with a clear message if `roles` / `users` tables are missing
  (run `php artisan migrate` first) or if the `super_admin` role is not
  seeded (run `php artisan db:seed --class=RolesAndPermissionsSeeder`).
- Creates or **updates** the user by email (idempotent — safe to re-run).
- Assigns the `super_admin` role if not already assigned.
- Auto-verifies the email.
- Sets `is_active = true`.
- The plaintext password is used only to compute the hash and is never
  logged, exceptioned, cached, queued, or persisted.

On the first `/admin/login` the user is redirected to the mandatory TOTP
enrolment page and must complete enrolment (and record recovery codes)
before entering the panel.

---

## 8. Local Installation

**System requirements:** Linux (or macOS/WSL2), PHP 8.4 CLI + FPM,
Composer 2.x, MySQL 8 (or MariaDB 10.6+), Redis 7+.

```bash
git clone <your-fork-url> aio-rewards
cd aio-rewards
composer install
cp .env.example .env
php artisan key:generate
```

Edit `.env` — set `DB_*` and `REDIS_*` to point at your local services.

```bash
php artisan migrate --seed
php artisan aio:make-super-admin        # interactive
php artisan horizon &                   # in a second terminal
php artisan serve                       # http://127.0.0.1:8000
```

The admin panel is at `http://127.0.0.1:8000/admin/login`. On first login,
the Super Admin is redirected to `/admin/multi-factor-authentication/set-up`
to enrol TOTP and save recovery codes before the panel is accessible.

---

## 9. Production Requirements

- Linux VPS (Debian 12 / Ubuntu 24.04 LTS or similar).
- Nginx + PHP-FPM 8.4 with extensions: `mbstring`, `xml`, `curl`, `zip`,
  `bcmath`, `intl`, `gd`, `mysql`, `redis`, `tokenizer`.
- MySQL 8.
- Redis 7+.
- Horizon under **systemd** or **Supervisor** (sample unit file to be added
  in Phase 8).
- Certbot for TLS.
- Nightly encrypted MySQL dump to off-VPS storage.

Deploy:

```bash
composer install --no-dev --optimize-autoloader
php artisan config:cache route:cache view:cache
php artisan migrate --force
php artisan db:seed --class=RolesAndPermissionsSeeder --force
php artisan aio:make-super-admin        # interactive; done once
php artisan horizon:terminate           # supervisor restarts it
```

Secret rotation: edit `.env`, then
`php artisan config:cache && php artisan horizon:terminate`.

---

## 10. Testing Status (Phase 0)

Two authoritative test runs, both green:

| Suite | Config | Tests | Result |
|---|---|---|---|
| Full suite (MySQL/MariaDB) | `phpunit.mysql.xml` | 15 (59 assertions) | ✅ PASS |
| Full suite (SQLite in-memory) | `phpunit.sqlite.xml` | 15 (59 assertions) | ✅ PASS |

Individual test files:
- `Tests\Unit\ExampleTest` (1)
- `Tests\Feature\WelcomePageTest` (1)
- `Tests\Feature\Auth\RolesFoundationTest` (5)
- `Tests\Feature\Audit\AuditLogTest` (2)
- `Tests\Feature\Console\MakeSuperAdminCommandTest` (6)

Static analysis: **PHPStan (Larastan) level 6** — 0 errors.
Code style: **Laravel Pint** — 0 issues on 40 files.

Production stack verification:
- ✅ MySQL wire-compat (MariaDB 10.11.18) — 7 migrations + seeder green.
- ✅ Redis 7.0.15 — PING + roundtrip confirmed.
- ✅ Horizon — starts, reports `INFO Horizon is running.`, dispatches and
  processes a `HorizonHealthProbeJob` end-to-end, terminates cleanly.

---

## 11. Deployment Notes

Verified against **MariaDB 10.11.18** during Phase 0 checks. The Debian 12
container used for verification does not ship Oracle MySQL 8 packages for
ARM64; MariaDB 10.11 was used as an officially-supported MySQL wire-compatible
substitute. Production **must** target MySQL 8 (schema uses only standard
`mysql` PDO features; no `SPATIAL`, no MySQL-specific JSON functions in
Phase 0). Phase 6 will re-verify against MySQL 8 before schema features that
diverge (partial-unique emulation, generated columns) land.

The project is a standard portable Laravel 12 codebase. It does **not**
introduce any proprietary Emergent runtime dependencies. It can be exported
to any Git host and deployed anywhere the requirements in §9 are met.
