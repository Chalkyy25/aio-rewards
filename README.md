# AIO Rewards

Referral and rewards platform for AIO Media (IPTV / VPN / streaming resale).

- Laravel 12 · PHP 8.4 · MySQL 8 · Redis 7+ · Horizon · Filament v5 · Livewire v4
- Standard portable Laravel project — no proprietary runtime dependencies

See **[PROJECT.md](PROJECT.md)** for the living project specification,
approved scope, corrections, architecture decisions, and phased roadmap.

## Requirements

- PHP **8.4** with extensions: `mbstring`, `xml`, `curl`, `zip`, `bcmath`,
  `intl`, `gd`, `mysql`, `redis`, `tokenizer`
- Composer 2.x
- **MySQL 8** (MariaDB 10.6+ works as a wire-compatible fallback)
- **Redis 7+**
- Node 20+ (only needed once a Vite pipeline is introduced in Phase 3+)

## Local quick-start

### 1. Install services

Debian / Ubuntu:
```bash
sudo apt update
sudo apt install mysql-server-8.0 redis-server
sudo systemctl enable --now mysql redis-server
```

macOS (Homebrew):
```bash
brew install mysql redis
brew services start mysql
brew services start redis
```

### 2. Create the database

```bash
mysql -uroot -p <<'SQL'
CREATE DATABASE aio_rewards CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE aio_rewards_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'aio_rewards'@'127.0.0.1' IDENTIFIED BY 'CHANGE_ME';
GRANT ALL PRIVILEGES ON aio_rewards.* TO 'aio_rewards'@'127.0.0.1';
GRANT ALL PRIVILEGES ON aio_rewards_test.* TO 'aio_rewards'@'127.0.0.1';
FLUSH PRIVILEGES;
SQL
```

### 3. Bootstrap the app

```bash
composer install
cp .env.example .env
php artisan key:generate
# edit .env — set DB_* and REDIS_* for your machine
php artisan migrate --seed
php artisan aio:make-super-admin        # interactive; see below
php artisan serve                       # http://127.0.0.1:8000
```

In a second terminal (only needed when queued work is exercised — the
`sync` queue driver is used in tests so most local dev works without it):

```bash
php artisan horizon
```

### 4. First admin login

Open http://127.0.0.1:8000/admin/login.
After login you will be redirected to `/admin/multi-factor-authentication/set-up`.
Scan the QR code with a TOTP app (Google Authenticator, 1Password, Aegis, etc.),
enter the six-digit code, and **save the recovery codes** — MFA is mandatory.

## `php artisan aio:make-super-admin`

Interactive command that creates or updates the Super Admin. The password is
entered hidden with confirmation, is not logged anywhere, and only the Argon2
hash is stored. Re-running the command with an existing email updates the
name and password without duplicating the user. Fails clearly if roles have
not been seeded.

## Quality gates

```bash
composer lint           # apply code style (Laravel Pint)
composer lint:check     # verify code style
composer analyse        # static analysis (Larastan level 6)
composer test           # PHPUnit against phpunit.xml (SQLite in-memory by default)

# authoritative compatibility target (requires a running MySQL/MariaDB):
php artisan test --configuration=phpunit.mysql.xml

composer check          # lint:check + analyse + test
```

## Production deployment

See **[PROJECT.md §9](PROJECT.md#9-production-requirements)** for the full
deployment checklist.

Short form:

```bash
git clone <your-repo> /var/www/aio-rewards && cd /var/www/aio-rewards
composer install --no-dev --optimize-autoloader
cp .env.example .env && php artisan key:generate
# fill in production secrets in .env (Stripe, provider API, DB, mail)
php artisan migrate --force
php artisan db:seed --class=RolesAndPermissionsSeeder --force
php artisan config:cache route:cache view:cache
php artisan aio:make-super-admin        # once, per deploy
# supervise Horizon with systemd/Supervisor:
php artisan horizon:terminate           # signals a clean supervisor restart
```

Point Nginx at `public/` with PHP-FPM 8.4. Restrict `/horizon` and `/admin`
in front of TLS.

## Project structure (Phase 0)

```
app/
├── Console/Commands/     # MakeSuperAdminCommand
├── Enums/                # Role enum
├── Filament/             # (populated from Phase 1 onwards)
├── Jobs/                 # HorizonHealthProbeJob
├── Models/               # User, AuditLog
├── Providers/            # AdminPanelProvider, HorizonServiceProvider
└── Support/Audit/        # AuditLogger

resources/views/
├── layouts/              # public.blade.php, ambassador.blade.php
├── public/               # welcome.blade.php
└── ambassador/           # dashboard.blade.php

database/
├── migrations/           # users mfa cols, activity cols, audit_logs, permission tables
└── seeders/              # RolesAndPermissionsSeeder

tests/
├── Feature/Audit/        # AuditLogTest
├── Feature/Auth/         # RolesFoundationTest
├── Feature/Console/      # MakeSuperAdminCommandTest
└── Feature/              # WelcomePageTest
```

## GitHub export

This project has no proprietary Emergent dependencies. Push the repo to any
Git host and clone it to any host that meets the requirements above. In the
Emergent environment specifically, use the built-in **Save to Github** action
in the chat input to publish the code.
