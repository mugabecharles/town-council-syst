# ── TCMS Production Dockerfile ────────────────────────────────────
# PHP 8.2 + Apache — compatible with Render.com dynamic PORT
FROM php:8.2-apache

# ── System dependencies ───────────────────────────────────────────
RUN apt-get update && apt-get install -y \
        libpng-dev \
        libjpeg-dev \
        libfreetype6-dev \
        libzip-dev \
        zip \
        unzip \
        curl \
        libonig-dev \
        libxml2-dev \
    && rm -rf /var/lib/apt/lists/*

# ── PHP extensions ────────────────────────────────────────────────
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install \
        pdo \
        pdo_mysql \
        mysqli \
        gd \
        zip \
        mbstring \
        xml \
        opcache

# ── Apache modules ────────────────────────────────────────────────
RUN a2enmod rewrite headers

# Allow .htaccess overrides globally
RUN sed -i 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf

# ── PHP production settings ───────────────────────────────────────
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && echo "upload_max_filesize = 10M"          >> "$PHP_INI_DIR/conf.d/tcms.ini" \
    && echo "post_max_size = 12M"                >> "$PHP_INI_DIR/conf.d/tcms.ini" \
    && echo "max_execution_time = 60"            >> "$PHP_INI_DIR/conf.d/tcms.ini" \
    && echo "memory_limit = 256M"               >> "$PHP_INI_DIR/conf.d/tcms.ini" \
    && echo "session.cookie_httponly = 1"        >> "$PHP_INI_DIR/conf.d/tcms.ini" \
    && echo "session.cookie_samesite = Strict"   >> "$PHP_INI_DIR/conf.d/tcms.ini" \
    && echo "expose_php = Off"                   >> "$PHP_INI_DIR/conf.d/tcms.ini" \
    && echo "display_errors = Off"               >> "$PHP_INI_DIR/conf.d/tcms.ini" \
    && echo "log_errors = On"                    >> "$PHP_INI_DIR/conf.d/tcms.ini" \
    && echo "opcache.enable = 1"                 >> "$PHP_INI_DIR/conf.d/tcms.ini" \
    && echo "opcache.memory_consumption = 128"   >> "$PHP_INI_DIR/conf.d/tcms.ini"

# ── Copy application ──────────────────────────────────────────────
WORKDIR /var/www/html
COPY . .

# ── Upload directories & permissions ─────────────────────────────
RUN mkdir -p uploads/documents uploads/receipts uploads/vouchers uploads/reports \
    && chown -R www-data:www-data /var/www/html \
    && find /var/www/html -type f -name "*.php" -exec chmod 644 {} \; \
    && find /var/www/html -type d -exec chmod 755 {} \; \
    && chmod +x /var/www/html/docker-entrypoint.sh

# ── Startup script ────────────────────────────────────────────────
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

# Render assigns a dynamic $PORT — Apache must listen on it
# We set it at container start in the entrypoint
EXPOSE 10000

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["apache2-foreground"]
