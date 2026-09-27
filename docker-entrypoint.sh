#!/bin/bash
set -e

echo "=========================================="
echo " TCMS - Town Council Management System"
echo " Starting up..."
echo "=========================================="

SSL_FLAG=""
if [ "$DB_SSL" = "true" ]; then
  SSL_FLAG="true"
  echo "SSL mode: enabled (Aiven/managed database)"
fi

PORT="${DB_PORT:-3306}"

# ── Wait for MySQL to be ready ─────────────────────────────────────
if [ -n "$DB_HOST" ] && [ -n "$DB_USER" ]; then
  echo "Waiting for MySQL at $DB_HOST:$PORT ..."
  MAX_TRIES=30
  COUNT=0
  until php -r "
    try {
      \$ssl  = '${SSL_FLAG}' === 'true';
      \$opts = [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION];
      if (\$ssl) \$opts[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
      \$pdo = new PDO('mysql:host=${DB_HOST};port=${PORT};charset=utf8mb4','${DB_USER}','${DB_PASS}',\$opts);
      echo 'Connected';
    } catch(Exception \$e) { exit(1); }
  " 2>/dev/null; do
    COUNT=$((COUNT+1))
    if [ $COUNT -ge $MAX_TRIES ]; then
      echo "WARNING: Could not connect to MySQL after $MAX_TRIES attempts. Continuing anyway..."
      break
    fi
    echo "  Attempt $COUNT/$MAX_TRIES — retrying in 3s..."
    sleep 3
  done
  echo "MySQL check done."
fi

# ── Auto-run schema on first boot ─────────────────────────────────
if [ -n "$DB_HOST" ] && [ -n "$DB_USER" ]; then
  TABLE_EXISTS=$(php -r "
    try {
      \$ssl  = '${SSL_FLAG}' === 'true';
      \$opts = [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION];
      if (\$ssl) \$opts[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
      \$pdo = new PDO('mysql:host=${DB_HOST};port=${PORT};dbname=${DB_NAME};charset=utf8mb4','${DB_USER}','${DB_PASS}',\$opts);
      \$stmt = \$pdo->query(\"SHOW TABLES LIKE 'users'\");
      echo \$stmt->rowCount() > 0 ? 'yes' : 'no';
    } catch(Exception \$e) { echo 'no'; }
  " 2>/dev/null)

  if [ "$TABLE_EXISTS" = "no" ]; then
    echo "Database tables not found — running schema setup..."
    php -r "
      try {
        \$ssl  = '${SSL_FLAG}' === 'true';
        \$opts = [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION];
        if (\$ssl) \$opts[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;

        // Connect without DB first to create it if needed
        \$pdo = new PDO('mysql:host=${DB_HOST};port=${PORT};charset=utf8mb4','${DB_USER}','${DB_PASS}',\$opts);
        try { \$pdo->exec('CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'); } catch(Exception \$e) {}
        \$pdo->exec('USE \`${DB_NAME}\`');

        \$sql        = file_get_contents('/var/www/html/config/tcms_schema.sql');
        \$statements = array_filter(array_map('trim', explode(';', \$sql)));
        \$ok = 0; \$skip = 0;
        foreach (\$statements as \$stmt) {
          if (empty(\$stmt)) continue;
          try { \$pdo->exec(\$stmt); \$ok++; }
          catch(Exception \$e) { \$skip++; }
        }
        echo \"Schema installed: \$ok statements OK, \$skip skipped.\";
      } catch(Exception \$e) {
        echo 'Schema error: ' . \$e->getMessage();
      }
    "
    echo ""
    echo "=========================================="
    echo " Default admin account:"
    echo " Username: admin"
    echo " Password: Admin@2026"
    echo " CHANGE PASSWORD after first login!"
    echo "=========================================="
  else
    echo "Database schema already installed — skipping."
  fi
fi

# ── Set APP_URL from Render external URL if not set ────────────────
if [ -n "$RENDER_EXTERNAL_URL" ] && [ -z "$APP_URL" ]; then
  export APP_URL="$RENDER_EXTERNAL_URL"
  echo "APP_URL auto-set to: $APP_URL"
fi

echo "Starting Apache..."
exec "$@"
