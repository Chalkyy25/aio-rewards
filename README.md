# AIO Rewards

Referral and rewards platform for AIO Media (IPTV / VPN / streaming resale).

- Laravel 12 · PHP 8.4 · MySQL 8 · Redis · Horizon · Filament v5 · Livewire v4
- Standard portable Laravel project — no proprietary runtime dependencies

See **[PROJECT.md](PROJECT.md)** for the living project specification, approved
scope, corrections, architecture decisions, and phased roadmap.

## Requirements

- PHP **8.4** with extensions: `mbstring`, `xml`, `curl`, `zip`, `bcmath`,
  `intl`, `gd`, `mysql` (or `sqlite`) and `redis`
- Composer 2.x
- MySQL 8 and Redis (for production / integration testing)
- Node 20+ (only when Phase 3+ adds a Vite pipeline)

## Local quick-start

```bash
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite            # local dev only
php artisan migrate --seed
php artisan test
php artisan serve
```

Admin panel is at `/admin`. The first admin user is created by the seeder in
Phase 1 — Phase 0 seeds roles only.

## Quality gates

```bash
composer lint          # apply code style (Laravel Pint)
composer lint:check    # verify code style
composer analyse       # static analysis (Larastan level 6)
composer test          # PHPUnit
composer check         # lint:check + analyse + test
```

## Project structure (Phase 0)

```
app/
├── Enums/                # Role enum
├── Filament/             # (populated from Phase 1 onwards)
├── Models/               # User, AuditLog
├── Providers/            # AdminPanelProvider, HorizonServiceProvider
└── Support/Audit/        # AuditLogger

resources/views/
├── layouts/              # public.blade.php, ambassador.blade.php
├── public/               # welcome.blade.php
└── ambassador/           # dashboard.blade.php

database/
├── migrations/           # users mfa cols, audit_logs, permission tables
└── seeders/              # RolesAndPermissionsSeeder

tests/
├── Feature/Audit/        # AuditLogTest
├── Feature/Auth/         # RolesFoundationTest
└── Feature/              # WelcomePageTest
```
