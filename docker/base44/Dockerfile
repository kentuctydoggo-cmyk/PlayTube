FROM php:7.4-apache

# Debian bullseye (the base of php:7.4-apache) is past end of life, so its packages
# are served by archive.debian.org instead of the live mirrors.
RUN printf 'deb http://archive.debian.org/debian bullseye main\ndeb http://archive.debian.org/debian-security bullseye-security main\n' \
        > /etc/apt/sources.list \
    && printf 'Acquire::Check-Valid-Until "false";\n' > /etc/apt/apt.conf.d/99base44-archive

# PHP extensions required by PlayTube (see readme.md "Requirements"):
# MySQLi, GD, mbstring, calendar, cURL, ZipArchive, exif.
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        curl \
        libfreetype6-dev \
        libjpeg62-turbo-dev \
        libpng-dev \
        libzip-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" mysqli gd zip calendar exif \
    && rm -rf /var/lib/apt/lists/*

# PlayTube routes every pretty URL through .htaccess rewrite rules.
RUN a2enmod rewrite headers expires \
    && sed -ri 's/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf \
    && printf 'ServerName localhost\n' > /etc/apache2/conf-available/base44.conf \
    && a2enconf base44

COPY docker/base44/entrypoint.sh /usr/local/bin/base44-entrypoint
RUN chmod +x /usr/local/bin/base44-entrypoint

WORKDIR /var/www/html

ENTRYPOINT ["base44-entrypoint"]
CMD ["apache2-foreground"]
