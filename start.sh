#!/bin/bash
set -e

APACHE_PORT="${PORT:-8080}"
echo "==> Starting Apache on port: $APACHE_PORT"

# Rewrite ports.conf
echo "Listen $APACHE_PORT" > /etc/apache2/ports.conf

# Rewrite the vhost
cat > /etc/apache2/sites-enabled/000-default.conf << VHOST
<VirtualHost *:${APACHE_PORT}>
    DocumentRoot /var/www/html
    DirectoryIndex login.html index.html index.php
    ErrorLog \${APACHE_LOG_DIR}/error.log
    CustomLog \${APACHE_LOG_DIR}/access.log combined

    <Directory /var/www/html>
        AllowOverride All
        Options -Indexes +FollowSymLinks
        Require all granted
    </Directory>

    # PWA: Service Worker must NOT be cached
    <Files "sw.js">
        Header set Cache-Control "no-cache, no-store, must-revalidate"
        Header set Pragma "no-cache"
        Header set Expires "0"
        Header set Service-Worker-Allowed "/"
    </Files>

    # MIME types for PWA
    AddType application/manifest+json .json
    AddType application/javascript .js
    AddType image/png .png
    AddType image/webp .webp
</VirtualHost>
VHOST

# Fix MPM conflict
a2dismod mpm_event 2>/dev/null || true
a2enmod mpm_prefork 2>/dev/null || true

# Enable required modules
a2enmod rewrite 2>/dev/null || true
a2enmod headers 2>/dev/null || true

echo "==> Apache config ready, starting..."
exec apache2-foreground
