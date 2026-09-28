#!/bin/bash
set -e

echo "=========================================="
echo " TCMS - Town Council Management System"
echo " All-in-one: PHP + Apache + MySQL"
echo "=========================================="

# ── Configure Apache port (Render assigns $PORT dynamically) ───────
LISTEN_PORT="${PORT:-10000}"
echo "Apache port: $LISTEN_PORT"

cat > /etc/apache2/ports.conf <<EOF
Listen ${LISTEN_PORT}
EOF

cat > /etc/apache2/sites-available/000-default.conf <<EOF
<VirtualHost *:${LISTEN_PORT}>
    DocumentRoot /var/www/html
    ServerName localhost

    <Directory /var/www/html>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    <FilesMatch "\.(sql|env|log|bak|sh|yaml|yml)$">
        Require all denied
    </FilesMatch>

    ErrorLog \${APACHE_LOG_DIR}/error.log
    CustomLog \${APACHE_LOG_DIR}/access.log combined
</VirtualHost>
EOF

echo "Apache configured on port $LISTEN_PORT"

# ── Set APP_URL ────────────────────────────────────────────────────
if [ -n "$RENDER_EXTERNAL_URL" ] && [ -z "$APP_URL" ]; then
    export APP_URL="$RENDER_EXTERNAL_URL"
fi
echo "APP_URL: ${APP_URL:-not set}"

# ── Initialize MySQL data directory if needed ──────────────────────
if [ ! -d "/var/lib/mysql/mysql" ]; then
    echo "Initializing MySQL data directory..."
    mysqld --initialize-insecure --user=mysql --datadir=/var/lib/mysql 2>&1 | tail -5
    echo "MySQL initialized."
fi

# ── Start MySQL in background ─────────────────────────────────────
echo "Starting MySQL..."
mysqld_safe --user=mysql --skip-networking=0 &
MYSQL_PID=$!

# Wait for MySQL to be ready
echo "Waiting for MySQL to start..."
for i in $(seq 1 30); do
    if mysqladmin ping --silent 2>/dev/null; then
        echo "MySQL is ready."
        break
    fi
    sleep 1
done

# ── Create database and user ───────────────────────────────────────
echo "Setting up TCMS database..."
mysql --user=root <<-EOSQL
    CREATE DATABASE IF NOT EXISTS tcms_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    CREATE USER IF NOT EXISTS 'tcms_user'@'localhost' IDENTIFIED BY 'TcmsPass2026!';
    GRANT ALL PRIVILEGES ON tcms_db.* TO 'tcms_user'@'localhost';
    FLUSH PRIVILEGES;
EOSQL

# ── Check if schema already installed ─────────────────────────────
TABLE_EXISTS=$(mysql --user=tcms_user --password=TcmsPass2026! tcms_db -sN -e "SHOW TABLES LIKE 'users';" 2>/dev/null)

if [ -z "$TABLE_EXISTS" ]; then
    echo "Installing TCMS schema..."
    mysql --user=tcms_user --password=TcmsPass2026! tcms_db < /var/www/html/config/tcms_schema.sql 2>&1 | tail -3
    echo "Schema installed."
    echo "Default login: admin / Admin@2026"
else
    echo "Schema already installed — skipping."
fi

# ── Override DB env vars to use local MySQL ────────────────────────
export DB_HOST=127.0.0.1
export DB_NAME=tcms_db
export DB_USER=tcms_user
export DB_PASS=TcmsPass2026!
export DB_PORT=3306
export DB_SSL=false

# Write to a PHP-readable env file so Apache picks it up
cat > /var/www/html/.env <<EOF
DB_HOST=127.0.0.1
DB_NAME=tcms_db
DB_USER=tcms_user
DB_PASS=TcmsPass2026!
DB_PORT=3306
DB_SSL=false
APP_ENV=production
APP_URL=${APP_URL:-https://town-council-syst.onrender.com}
COUNCIL_NAME=${COUNCIL_NAME:-Kira Town Council}
SESSION_TIMEOUT=${SESSION_TIMEOUT:-1800}
EOF

echo ".env written for Apache/PHP"

# ── Keep MySQL running and start Apache via supervisord ───────────
echo "Starting services via supervisord..."
exec "$@"
