# Test credentials — preview environment only

## Pre-seeded verified ambassador (for dashboard testing)
- Email: `preview-verified@example.com`
- Password: `previewpass1234`
- Referral code: `PREVIEW1`
- Login at: `/login`

## Fake provider verification driver values (for activation testing at `/activate`)
- Provider username `test_active` + password `letmein` → ✅ eligible (activation succeeds)
- Provider username `test_inactive` + password `letmein` → ❌ subscription inactive
- Provider username `test_error` + any password → ❌ simulated provider outage
- Any other username → ❌ not found
- `test_active` + wrong password → ❌ wrong credentials

## New AIO Rewards account (for activation flow)
- Any new email (not already used)
- Password ≥ 12 characters
- Passwords must match
- Consent checkbox required

## Filament admin panel
- URL: `/admin/login`
- No admin user pre-seeded; create via `php artisan aio:make-super-admin`
- Mandatory TOTP MFA on first login

## Preview base URL
- `https://saas-architect-15.preview.emergentagent.com`

Notes:
- `MAIL_MAILER=log` → welcome & verification emails written to
  `storage/logs/laravel-*.log` (not sent). Grep the log for
  the verification link to complete the email-verify step manually.
- `PROVIDER_VERIFICATION_DRIVER=fake` in `.env`; production would set
  `aio_iptv_v1` and real API credentials.
