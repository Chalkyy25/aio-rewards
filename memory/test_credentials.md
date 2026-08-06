# Test credentials — preview environment only

## Pre-seeded verified ambassador (for dashboard testing)
- Email: `preview-verified@example.com`
- Password: `previewpass1234`
- Referral code: `PREVIEW1`
- Login at: `/login`

## Fake provider verification driver values (for activation testing at `/activate`)
Removed — the fake verification driver is now restricted to automated tests
only (APP_ENV=testing). Manual activation in preview / staging / production
uses the real Xtream driver against the DNS configured in the admin panel
under `/admin/settings/provider-verification`. If the upstream is not
reachable, activation will surface the "temporarily unable to verify"
message and refuse to create the account.

## Fake referral link (Phase 2)
- `/r/PREVIEW1` — routes to the pre-seeded preview ambassador; sets `aior_ref` cookie; redirects to landing.
- Query string honoured: `?utm_source=whatsapp&utm_medium=social&utm_campaign=launch`
- Invalid or inactive code → 404 "Referral link unavailable" (no ambassador info leaked).

## New AIO Rewards account (for activation flow)
- Any new email (not already used)
- Password ≥ 12 characters
- Passwords must match
- Consent checkbox required

## Filament admin panel
- URL: `/admin/login`
- Preview Super Admin (seeded in this session):
  - Email: `preview-admin@example.com`
  - Password: `AdminPass1234!`
  - MFA: TOTP enforced on first login (an enrolment step happens automatically).
- Create additional admins via `php artisan aio:make-super-admin` (interactive).

## Packages (Phase 3 — Stripe checkout)
- `/packages` lists three preview packages: `IPTV 12 Months £60`, `IPTV + VPN 12 Months £85`, `VPN Only 12 Months £35`.
- Slugs used in checkout URLs: `iptv-12-months`, `iptv-vpn-12-months`, `vpn-only-12-months`.
- End-to-end Stripe checkout requires `STRIPE_SECRET` in `.env`. If unset, the pay button
  returns a friendly "Stripe is not configured on this environment" error.

## Preview base URL
- `https://saas-architect-15.preview.emergentagent.com`

## Customer order status page (Phase 4)
- Public URL: `/order/{customer_view_token}` where token is a 32-char opaque
  string generated when Stripe marks the checkout completed.
- While the order is in progress the page shows the timeline only. Once the
  admin marks the order **Completed** in Filament (`/admin/orders`), the
  page reveals the provisioned username, password, expiry date, setup
  instructions and any download links.

Notes:
- `MAIL_MAILER=log` → welcome & verification emails written to
  `storage/logs/laravel-*.log` (not sent). Grep the log for
  the verification link to complete the email-verify step manually.
- `PROVIDER_VERIFICATION_DRIVER=xtream` — the only runtime driver. The
  fake driver is compiled out of the config `drivers[]` map and can only
  be instantiated inside PHPUnit tests via explicit `$this->app->instance(...)`.
