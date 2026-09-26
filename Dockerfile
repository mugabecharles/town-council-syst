# ── TCMS Production Dockerfile ────────────────────────────────────
# PHP 8.2 + Apache on Debian Bookworm (slim)
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

# ── Apache configuration ──────────────────────────────────────────
RUN a2enmod rewrite headers

# Enable .htaccess overrides
RUN sed -i 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf

# ── PHP production settings ───────────────────────────────────────
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

# Tune PHP for production
RUN echo "upload_max_filesize = 10M"     >> "$PHP_INI_DIR/conf.d/tcms.ini" \
    && echo "post_max_size = 12M"         >> "$PHP_INI_DIR/conf.d/tcms.ini" \
    && echo "max_execution_time = 60"     >> "$PHP_INI_DIR/conf.d/tcms.ini" \
    && echo "memory_limit = 256M"         >> "$PHP_INI_DIR/conf.d/tcms.ini" \
    && echo "session.cookie_httponly = 1" >> "$PHP_INI_DIR/conf.d/tcms.ini" \
    && echo "session.cookie_samesite = Strict" >> "$PHP_INI_DIR/conf.d/tcms.ini" \
    && echo "expose_php = Off"            >> "$PHP_INI_DIR/conf.d/tcms.ini" \
    && echo "display_errors = Off"        >> "$PHP_INI_DIR/conf.d/tcms.ini" \
    && echo "log_errors = On"             >> "$PHP_INI_DIR/conf.d/tcms.ini" \
    && echo "opcache.enable = 1"          >> "$PHP_INI_DIR/conf.d/tcms.ini" \
    && echo "opcache.memory_consumption = 128" >> "$PHP_INI_DIR/conf.d/tcms.ini"

# ── Apache VirtualHost ────────────────────────────────────────────
RUN echo '<VirtualHost *:80>\n\
    DocumentRoot /var/www/html\n\
    ServerName localhost\n\
    \n\
    <Directory /var/www/html>\n\
        Options -Indexes +FollowSymLinks\n\
        AllowOverride All\n\
        Require all granted\n\
    </Directory>\n\
    \n\
    # Block access to sensitive files\n\
    <FilesMatch "\.(sql|env|log|bak)$">\n\
        Require all denied\n\
    </FilesMatch>\n\
    \n\
    ErrorLog ${APACHE_LOG_DIR}/error.log\n\
    CustomLog ${APACHE_LOG_DIR}/access.log combined\n\
</VirtualHost>' > /etc/apache2/sites-available/000-default.conf

# ── Copy application ──────────────────────────────────────────────
WORKDIR /var/www/html
COPY . .

# ── Create upload directories and set permissions ─────────────────
RUN mkdir -p uploads/documents uploads/receipts uploads/vouchers uploads/reports \
    && chown -R www-data:www-data uploads/ \
    && chmod -R 755 uploads/ \
    && chown -R www-data:www-data /var/www/html \
    && find /var/www/html -type f -name "*.php" -exec chmod 644 {} \; \
    && find /var/www/html -type d -exec chmod 755 {} \;

# ── Remove setup file (security) ──────────────────────────────────
# Uncomment after first deployment and DB is initialized:
# RUN rm -f setup.php

# ── Startup script ────────────────────────────────────────────────
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

EXPOSE 80

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["apache2-foreground"]
