# Use PHP 8.2 with Apache
FROM php:8.2-apache

# Install required system packages
RUN apt-get update && apt-get install -y \
    libpng-dev \
    libjpeg-dev \
    libwebp-dev \
    libfreetype6-dev \
    libonig-dev \
    libzip-dev \
    unzip \
    git \
    curl \
    libpq-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install gd mbstring zip pdo pdo_mysql pdo_pgsql

# Enable Apache rewrite module
RUN a2enmod rewrite

# Install Composer (dependency manager for PHP)
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www/html

# Copy all application files to container
COPY . .

# Install PHP dependencies (PhpWord, PdfParser, etc.)
RUN composer install --no-dev --optimize-autoloader

# Set file permissions
RUN chown -R www-data:www-data /var/www/html

# Add this if not already present
RUN mkdir -p /tmp && chmod 777 /tmp

# Create a directory for PHP sessions
RUN mkdir -p /var/lib/php/sessions \
 && chmod -R 777 /var/lib/php/sessions



EXPOSE 80
