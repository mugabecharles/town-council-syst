#!/bin/bash
set -e

echo "=========================================="
echo " TCMS - Town Council Management System"
echo " Starting up..."
echo "=========================================="

# ── Wait for MySQL to be ready ─────────────────────────────────────
if [ -n "$DB_HOST" ] && [ -n "$DB_USER" ]; then
  echo "Waiting for MySQL at $DB_HOST..."
  MAX_TRIES=30
  COUNT=0
  until php -r "
    try {
      \$pdo = new PDO('mysql:host=${DB_HOST};dbname=${DB_NAME};charset=utf8mb4','${DB_USER}','${DB_PASS}');
      echo 'Connected';
    } catch(Exception \$e) {
      exit(1);
    }
  " 2>/dev/null; do
    COUNT=$((COUNT+1))
    if [ $COUNT -ge $MAX_TRIES ]; then
      echo "ERROR: Could not connect to MySQL after $MAX_TRIES attempts."
      break
    fi
    echo "  Attempt $COUNT/$MAX_TRIES — retrying in 3s..."
    sleep 3
  done
  echo "MySQL is ready."
fi

# ── Auto-run schema on first boot ─────────────────────────────────
# Check if users table exists; if not, run schema
if [ -n "$DB_HOST" ] && [ -n "$DB_USER" ]; then
  TABLE_EXISTS=$(php -r "
    try {
      \$pdo = new PDO('mysql:host=${DB_HOST};dbname=${DB_NAME};charset=utf8mb4','${DB_USER}','${DB_PASS}',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
      \$stmt = \$pdo->query(\"SHOW TABLES LIKE 'users'\");
      echo \$stmt->rowCount() > 0 ? 'yes' : 'no';
    } catch(Exception \$e) {
      echo 'no';
    }
  " 2>/dev/null)

  if [ "$TABLE_EXISTS" = "no" ]; then
    echo "Database tables not found — running schema setup..."
    php -r "
      try {
        \$pdo = new PDO('mysql:host=${DB_HOST};charset=utf8mb4','${DB_USER}','${DB_PASS}',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        \$sql = file_get_contents('/var/www/html/config/tcms_schema.sql');
        // Strip comments and split by semicolon
        \$statements = array_filter(array_map('trim', explode(';', \$sql)));
        foreach (\$statements as \$stmt) {
          if (empty(\$stmt)) continue;
          try { \$pdo->exec(\$stmt); } catch(Exception \$e) {
            // Ignore duplicate/exists errors
            if (!in_array(\$e->getCode(),['42S01','42S21'])) {
              // Log but continue
            }
          }
        }
        echo 'Schema installed successfully.';
      } catch(Exception \$e) {
        echo 'Schema error: ' . \$e->getMessage();
      }
    "
    echo ""
    echo "Default admin: username=admin password=Admin@2026"
  else
    echo "Database schema already installed."
  fi
fi

# ── Set APP_URL from Render's RENDER_EXTERNAL_URL if available ─────
if [ -n "$RENDER_EXTERNAL_URL" ] && [ -z "$APP_URL" ]; then
  export APP_URL="$RENDER_EXTERNAL_URL"
  echo "APP_URL set to: $APP_URL"
fi

echo "Starting Apache..."
exec "$@"
