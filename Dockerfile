# Use official PHP 8.2 image with Apache web server
FROM php:8.2-apache

# Install PDO MySQL & MySQLi extensions (required to connect to Aiven MySQL)
RUN docker-php-ext-install pdo pdo_mysql mysqli

# Enable Apache mod_rewrite for clean API routing
RUN a2enmod rewrite

# Suppress Apache ServerName warning
RUN echo "ServerName localhost" >> /etc/apache2/apache2.conf

# Copy all project files into Apache's web folder inside the container
COPY . /var/www/html/

# Point Apache's document root to the public folder
ENV APACHE_DOCUMENT_ROOT /var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf

# Expose port 80 for web traffic
EXPOSE 80