FROM php:8.2-apache

# Instalare pachete de sistem și biblioteci necesare pentru GD și MongoDB
RUN apt-get update && apt-get install -y \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libssl-dev \
    pkg-config \
    unzip \
    git \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) gd fileinfo \
    && pecl install mongodb-1.21.0 \
    && docker-php-ext-enable mongodb gd fileinfo \
    && rm -rf /var/lib/apt/lists/*

# Activare mod_rewrite și mod_headers Apache
RUN a2enmod rewrite headers

# Configurare DocumentRoot către /public
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
RUN sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# Copiere Composer din imaginea oficială
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

EXPOSE 80
CMD ["apache2-foreground"]
