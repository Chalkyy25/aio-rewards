#!/usr/bin/env bash
# AIO Rewards — pod-recycle self-heal for the Laravel runtime.
# Idempotent: PHP + composer + MariaDB datadir + DB user + migrations + seeders.
set -euo pipefail

log() { echo "[bootstrap] $*"; }

# 1. PHP + composer + native services
need_install=false
command -v php >/dev/null 2>&1 || need_install=true
command -v composer >/dev/null 2>&1 || need_install=true
[ -x /usr/sbin/mariadbd ] || need_install=true
[ -x /usr/bin/redis-server ] || need_install=true

if [ "$need_install" = "true" ]; then
    log "Installing PHP 8.4 + MariaDB + Redis (missing after pod recycle)."
    export DEBIAN_FRONTEND=noninteractive
    apt-get update -qq
    apt-get install -y -qq lsb-release apt-transport-https curl gnupg
    if [ ! -f /etc/apt/sources.list.d/sury-php.list ]; then
        curl -sSLo /etc/apt/trusted.gpg.d/sury-php.gpg https://packages.sury.org/php/apt.gpg
        echo "deb https://packages.sury.org/php/ bookworm main" > /etc/apt/sources.list.d/sury-php.list
        apt-get update -qq
    fi
    apt-get install -y -qq \
        php8.4-cli php8.4-common php8.4-mysql php8.4-redis \
        php8.4-mbstring php8.4-xml php8.4-curl php8.4-zip \
        php8.4-bcmath php8.4-intl php8.4-gd php8.4-sqlite3 \
        mariadb-server redis-server default-mysql-client
    if ! command -v composer >/dev/null 2>&1; then
        curl -sS https://getcomposer.org/installer \
            | php -- --install-dir=/usr/local/bin --filename=composer
    fi
fi

# 2. Runtime dirs
mkdir -p /run/mysqld /var/lib/redis /var/log/mysql /var/log/redis
chown -R mysql:mysql /run/mysqld /var/log/mysql /var/lib/mysql 2>/dev/null || true

# 3. Wait until MariaDB is reachable (supervisor may still be starting it).
for i in $(seq 1 20); do
    if mysqladmin -uroot --socket=/run/mysqld/mysqld.sock ping >/dev/null 2>&1; then
        break
    fi
    log "Waiting for MariaDB ($i/20)..."
    sleep 1
done

if ! mysqladmin -uroot --socket=/run/mysqld/mysqld.sock ping >/dev/null 2>&1; then
    log "MariaDB not reachable after 20s — starting a foreground instance for this bootstrap."
    /usr/sbin/mariadbd --user=mysql --datadir=/var/lib/mysql --socket=/run/mysqld/mysqld.sock --bind-address=127.0.0.1 --port=3306 &
    MDPID=$!
    for i in $(seq 1 20); do
        mysqladmin -uroot --socket=/run/mysqld/mysqld.sock ping >/dev/null 2>&1 && break
        sleep 1
    done
fi

# 4. DB + user (idempotent)
mysql -uroot --socket=/run/mysqld/mysqld.sock <<'SQL' 2>/dev/null || true
CREATE DATABASE IF NOT EXISTS aio_rewards CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS aio_rewards_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'aio_rewards'@'localhost' IDENTIFIED BY 'aio_dev_password';
CREATE USER IF NOT EXISTS 'aio_rewards'@'127.0.0.1' IDENTIFIED BY 'aio_dev_password';
CREATE USER IF NOT EXISTS 'aio_rewards'@'%' IDENTIFIED BY 'aio_dev_password';
GRANT ALL PRIVILEGES ON aio_rewards.* TO 'aio_rewards'@'localhost';
GRANT ALL PRIVILEGES ON aio_rewards_test.* TO 'aio_rewards'@'localhost';
GRANT ALL PRIVILEGES ON aio_rewards.* TO 'aio_rewards'@'127.0.0.1';
GRANT ALL PRIVILEGES ON aio_rewards_test.* TO 'aio_rewards'@'127.0.0.1';
GRANT ALL PRIVILEGES ON aio_rewards.* TO 'aio_rewards'@'%';
GRANT ALL PRIVILEGES ON aio_rewards_test.* TO 'aio_rewards'@'%';
FLUSH PRIVILEGES;
SQL

# 5. vendor/
if [ ! -f /app/vendor/autoload.php ]; then
    log "vendor/ missing — running composer install."
    cd /app && composer install --no-interaction --no-progress --prefer-dist
fi

# 6. Migrations, roles seed, preview seed, Filament assets (all idempotent).
cd /app
php artisan migrate --force 2>&1 | tail -1 || true
php artisan db:seed --class=RolesAndPermissionsSeeder --force 2>&1 | tail -1 || true
php artisan db:seed --class=PreviewAmbassadorSeeder --force 2>&1 | tail -1 || true
php artisan filament:assets 2>/dev/null || true

log "Ready. php=$(php --version | head -1)"
exit 0
