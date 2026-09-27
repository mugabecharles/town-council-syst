#!/bin/bash
set -e

echo "=========================================="
echo " TCMS - Town Council Management System"
echo " Starting up..."
echo "=========================================="

# ── Render assigns $PORT dynamically (usually 10000) ──────────────
LISTEN_PORT="${PORT:-10000}"
echo "Configuring Apache to listen on port $LISTEN_PORT ..."

# Update Apache ports.conf to use dynamic PORT
cat > /etc/apache2/ports.conf <<EOF
Listen ${LISTEN_PORT}
EOF

# Update VirtualHost to use dynamic PORT
cat > /etc/apache2/sites-available/000-default.conf <<EOF
<VirtualHost *:${LISTEN_PORT}>
    DocumentRoot /var/www/html
    ServerName localhost

    <Directory /var/www/html>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    <FilesMatch "\.(sql|env|log|bak)$">
        Require all denied
    </FilesMatch>

    ErrorLog \${APACHE_LOG_DIR}/error.log
    CustomLog \${APACHE_LOG_DIR}/access.log combined
</VirtualHost>
EOF

echo "Apache configured for port $LISTEN_PORT"

# ── SSL flag ──────────────────────────────────────────────────────
SSL_FLAG=""
if [ "$DB_SSL" = "true" ]; then
  SSL_FLAG="true"
  echo "SSL mode: enabled (Aiven/managed database)"
fi

DB_PORT_VAL="${DB_PORT:-3306}"

# ── Wait for MySQL ─────────────────────────────────────────────────
if [ -n "$DB_HOST" ] && [ -n "$DB_USER" ]; then
  echo "Waiting for MySQL at $DB_HOST:$DB_PORT_VAL ..."
  MAX_TRIES=20
  COUNT=0
  until php -r "
    try {
      \$ssl  = '${SSL_FLAG}' === 'true';
      \$opts = [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION];
      if (\$ssl) \$opts[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
      new PDO('mysql:host=${DB_HOST};port=${DB_PORT_VAL};charset=utf8mb4','${DB_USER}','${DB_PASS}',\$opts);
      echo 'ok';
    } catch(Exception \$e) { exit(1); }
  " 2>/dev/null; do
    COUNT=$((COUNT+1))
    if [ $COUNT -ge $MAX_TRIES ]; then
      echo "WARNING: MySQL not reachable after $MAX_TRIES tries — starting anyway."
      break
    fi
    echo "  Attempt $COUNT/$MAX_TRIES — retrying in 3s..."
    sleep 3
  done
  echo "MySQL check done."
fi

# ── Auto-install schema on first boot ─────────────────────────────
if [ -n "$DB_HOST" ] && [ -n "$DB_USER" ]; then
  TABLE_EXISTS=$(php -r "
    try {
      \$ssl  = '${SSL_FLAG}' === 'true';
      \$opts = [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION];
      if (\$ssl) \$opts[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
      \$pdo = new PDO('mysql:host=${DB_HOST};port=${DB_PORT_VAL};dbname=${DB_NAME};charset=utf8mb4','${DB_USER}','${DB_PASS}',\$opts);
      \$r = \$pdo->query(\"SHOW TABLES LIKE 'users'\");
      echo \$r->rowCount() > 0 ? 'yes' : 'no';
    } catch(Exception \$e) { echo 'no'; }
  " 2>/dev/null)

  if [ "$TABLE_EXISTS" = "no" ]; then
    echo "Running database schema setup..."
    php -r "
      try {
        \$ssl  = '${SSL_FLAG}' === 'true';
        \$opts = [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION];
        if (\$ssl) \$opts[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
        \$pdo = new PDO('mysql:host=${DB_HOST};port=${DB_PORT_VAL};charset=utf8mb4','${DB_USER}','${DB_PASS}',\$opts);
        try { \$pdo->exec('CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'); } catch(Exception \$e) {}
        \$pdo->exec('USE \`${DB_NAME}\`');
        \$sql = file_get_contents('/var/www/html/config/tcms_schema.sql');
        \$ok = 0;
        foreach (array_filter(array_map('trim', explode(';', \$sql))) as \$s) {
          try { \$pdo->exec(\$s); \$ok++; } catch(Exception \$e) {}
        }
        echo \"Schema done: \$ok statements.\n\";
      } catch(Exception \$e) { echo 'Schema error: '.\$e->getMessage().\"\\n\"; }
    "
    echo ""
    echo "=== Default login: admin / Admin@2026 ==="
  else
    echo "Schema already installed — skipping."
  fi
fi

# ── Auto-set APP_URL from Render external URL ──────────────────────
if [ -n "$RENDER_EXTERNAL_URL" ] && [ -z "$APP_URL" ]; then
  export APP_URL="$RENDER_EXTERNAL_URL"
  echo "APP_URL set to: $APP_URL"
fi

echo "Starting Apache on port $LISTEN_PORT ..."
exec "$@"
