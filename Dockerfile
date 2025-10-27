# Use official PHP Apache image
FROM php:8.2-apache

# Install PostgreSQL client libraries and PHP extensions
RUN apt-get update && apt-get install -y libpq-dev \
    && docker-php-ext-install pdo pdo_pgsql pgsql

# Copy your PHP app to the web root
COPY . /var/www/html/

# Expose port 80
EXPOSE 80
