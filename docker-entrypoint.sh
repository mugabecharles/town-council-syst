#!/bin/bash
set -e

echo "=========================================="
echo " TCMS - Town Council Management System"
echo " Starting up..."
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

# ── Set APP_URL from Render external URL ───────────────────────────
if [ -n "$RENDER_EXTERNAL_URL" ] && [ -z "$APP_URL" ]; then
    export APP_URL="$RENDER_EXTERNAL_URL"
    echo "APP_URL: $APP_URL"
fi

# ── Run DB schema setup in background (non-blocking) ───────────────
# This runs AFTER Apache starts so the health check passes immediately
(
    echo "[DB] Waiting 15s for Apache to start before DB setup..."
    sleep 15

    SSL_FLAG=""
    [ "$DB_SSL" = "true" ] && SSL_FLAG="true"
    DB_PORT_VAL="${DB_PORT:-3306}"

    if [ -z "$DB_HOST" ] || [ -z "$DB_USER" ]; then
        echo "[DB] No DB credentials — skipping schema setup."
        exit 0
    fi

    # Wait for MySQL with retries
    echo "[DB] Connecting to $DB_HOST:$DB_PORT_VAL ..."
    for i in $(seq 1 20); do
        CONNECTED=$(php -r "
            try {
                \$o=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION];
                if('${SSL_FLAG}'==='true') \$o[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT]=false;
                new PDO('mysql:host=${DB_HOST};port=${DB_PORT_VAL};charset=utf8mb4','${DB_USER}','${DB_PASS}',\$o);
                echo 'yes';
            } catch(Exception \$e){ echo 'no'; }
        " 2>/dev/null)
        if [ "$CONNECTED" = "yes" ]; then
            echo "[DB] Connected."
            break
        fi
        echo "[DB] Attempt $i/20 failed — retry in 5s..."
        sleep 5
    done

    if [ "$CONNECTED" != "yes" ]; then
        echo "[DB] Could not connect to MySQL. Schema setup skipped."
        exit 0
    fi

    # Check if schema already installed
    TABLE_EXISTS=$(php -r "
        try {
            \$o=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION];
            if('${SSL_FLAG}'==='true') \$o[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT]=false;
            \$p=new PDO('mysql:host=${DB_HOST};port=${DB_PORT_VAL};dbname=${DB_NAME};charset=utf8mb4','${DB_USER}','${DB_PASS}',\$o);
            \$r=\$p->query(\"SHOW TABLES LIKE 'users'\");
            echo \$r->rowCount()>0?'yes':'no';
        } catch(Exception \$e){ echo 'no'; }
    " 2>/dev/null)

    if [ "$TABLE_EXISTS" = "yes" ]; then
        echo "[DB] Schema already installed — skipping."
        exit 0
    fi

    echo "[DB] Installing schema..."
    php -r "
        try {
            \$o=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION];
            if('${SSL_FLAG}'==='true') \$o[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT]=false;
            \$p=new PDO('mysql:host=${DB_HOST};port=${DB_PORT_VAL};charset=utf8mb4','${DB_USER}','${DB_PASS}',\$o);
            try { \$p->exec('CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'); } catch(Exception \$e){}
            \$p->exec('USE \`${DB_NAME}\`');
            \$sql=file_get_contents('/var/www/html/config/tcms_schema.sql');
            \$n=0;
            foreach(array_filter(array_map('trim',explode(';',\$sql))) as \$s){
                try { \$p->exec(\$s); \$n++; } catch(Exception \$e){}
            }
            echo \"[DB] Schema installed: \$n statements OK.\n\";
        } catch(Exception \$e){ echo '[DB] Error: '.\$e->getMessage().\"\n\"; }
    "
    echo "[DB] Default login: admin / Admin@2026"
) &

echo "Starting Apache..."
exec "$@"
