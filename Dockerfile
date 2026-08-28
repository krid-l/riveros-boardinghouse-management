FROM php:8.2-apache

# Install PostgreSQL extensions and dependencies
RUN apt-get update && apt-get install -y libpq-dev \
    && docker-php-ext-install pdo pdo_pgsql pgsql

# Enable Apache mod_rewrite for nice URLs if needed
RUN a2enmod rewrite

# Copy all application files to the Apache web root
COPY . /var/www/html/

# Ensure correct permissions
RUN chown -R www-data:www-data /var/www/html/

# Configure Apache to listen on the port Railway provides, or fallback to 80
RUN sed -i 's/80/${PORT:-80}/g' /etc/apache2/sites-available/000-default.conf /etc/apache2/ports.conf
EXPOSE 80
