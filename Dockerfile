FROM php:8.2-cli

# Install PostgreSQL extensions
RUN apt-get update && apt-get install -y libpq-dev \
    && docker-php-ext-install pdo pdo_pgsql pgsql

# Copy application files
COPY . /app
WORKDIR /app

# Run PHP's built-in web server, binding to the PORT provided by Railway
CMD php -S 0.0.0.0:$PORT
