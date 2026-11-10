FROM php:8.2-apache

# Configure Apache modules and Railway port.
RUN rm -f /etc/apache2/mods-enabled/mpm_*.load \
          /etc/apache2/mods-enabled/mpm_*.conf \
    && a2enmod mpm_prefork rewrite headers \
    && printf '\nServerName localhost\n' >> /etc/apache2/apache2.conf \
    && sed -i 's/Listen 80/Listen 8080/' /etc/apache2/ports.conf \
    && sed -i 's/:80>/:8080>/' /etc/apache2/sites-available/000-default.conf

WORKDIR /var/www/html
COPY . /var/www/html/

# Verify required front-end assets are present in the image.
RUN test -f /var/www/html/index.php \
    && test -f /var/www/html/css/styles.css \
    && test -f /var/www/html/js/app.js \
    && test -f /var/www/html/posters/0.png \
    && test -f /var/www/html/posters/1.png \
    && test -f /var/www/html/posters/2.png \
    && test -f /var/www/html/g/icon.png \
    && test -f /var/www/html/g/icons8-garena-96.png \
    && printf '%s\n' \
       '<Directory /var/www/html>' \
       '    Options -Indexes' \
       '    AllowOverride All' \
       '    Require all granted' \
       '</Directory>' \
       > /etc/apache2/conf-available/ffmaxtopup.conf \
    && a2enconf ffmaxtopup \
    && chown -R www-data:www-data /var/www/html

EXPOSE 8080

# Remove any conflicting MPM modules at container startup.
CMD ["sh", "-c", "rm -f /etc/apache2/mods-enabled/mpm_event.* /etc/apache2/mods-enabled/mpm_worker.* && a2enmod -q mpm_prefork && exec apache2-foreground"]
