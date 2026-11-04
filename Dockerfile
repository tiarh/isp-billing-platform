# Base images are pinned by digest so a silent upstream bump cannot change the
# build (or invalidate the layer cache) unnoticed. Bump monthly by PR.
# PHP_IMAGE: php:8.4-apache index digest = PHP 8.4.27 (image created 2026-10-21),
#   read from the Docker Hub registry config (PHP_VERSION env). 8.4.27 fixes
#   CVE-2026-109618 / CVE-2026-109619. This is the base prod already runs;
#   the older digest 901b0dbc... was 8.4.26.
# COMPOSER_IMAGE: composer:2 index digest as of 2026-10-22 (same as the latest prod build).
ARG PHP_IMAGE=php:8.4-apache@sha256:56e7dab56ae21ec10aae951f93cbe7069c1ca68a714dd22ca78724268c78f5de
ARG COMPOSER_IMAGE=composer:2@sha256:da624c2c705d54e2f5a9bb77a5dc6c7d091711433ebefd7035672e84c2010fee

FROM ${COMPOSER_IMAGE} AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-scripts --ignore-platform-req=ext-sockets
COPY . .
RUN composer dump-autoload --optimize

FROM ${PHP_IMAGE}

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public

# Tesseract's bundled OpenMP spins all cores and stalls for 40s+ on
# high-resolution photos. One thread keeps OCR fast and deterministic.
ENV OMP_THREAD_LIMIT=1

RUN printf '%s\n' \
        'Acquire::Retries "3";' \
        'Acquire::ForceIPv4 "true";' \
        > /etc/apt/apt.conf.d/99-skynet-build-network \
    && find /etc/apt -type f \( -name '*.list' -o -name '*.sources' \) \
        -exec sed -ri \
            -e 's!http://deb\.debian\.org!https://deb.debian.org!g' \
            -e 's!http://security\.debian\.org!https://security.debian.org!g' \
            {} + \
    && apt-get update \
    && apt-get install -y --no-install-recommends \
        default-mysql-client \
        git \
        imagemagick \
        iputils-ping \
        libfreetype6-dev \
        libicu-dev \
        libjpeg62-turbo-dev \
        libonig-dev \
        libpng-dev \
        libwebp-dev \
        libzip-dev \
        openssh-client \
        python3-pexpect \
        snmp \
        socat \
        tesseract-ocr \
        tesseract-ocr-ind \
        unzip \
        wireguard-tools \
        zip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install bcmath exif gd intl opcache pcntl pdo_mysql sockets zip \
    && pecl install https://pecl.php.net/get/redis-6.3.0.tgz \
    && docker-php-ext-enable redis \
    && a2enmod rewrite headers \
    && sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf \
    && echo 'Header always set X-Frame-Options "SAMEORIGIN"' >> /etc/apache2/conf-available/security-headers.conf \
    && echo 'Header always set X-Content-Type-Options "nosniff"' >> /etc/apache2/conf-available/security-headers.conf \
    && echo 'Header always set X-XSS-Protection "1; mode=block"' >> /etc/apache2/conf-available/security-headers.conf \
    && echo 'Header always set Referrer-Policy "strict-origin-when-cross-origin"' >> /etc/apache2/conf-available/security-headers.conf \
    && echo 'Header always set Permissions-Policy "camera=(), microphone=(), geolocation=(self)"' >> /etc/apache2/conf-available/security-headers.conf \
    && echo 'Header always unset X-Powered-By' >> /etc/apache2/conf-available/security-headers.conf \
    && echo 'ServerTokens Prod' >> /etc/apache2/conf-available/security-headers.conf \
    && echo 'ServerSignature Off' >> /etc/apache2/conf-available/security-headers.conf \
    && sed -i 's/^ServerTokens .*/ServerTokens Prod/' /etc/apache2/apache2.conf \
    && sed -i 's/^ServerSignature .*/ServerSignature Off/' /etc/apache2/apache2.conf \
    && echo 'ServerTokens Prod' >> /etc/apache2/apache2.conf \
    && echo 'ServerSignature Off' >> /etc/apache2/apache2.conf \
    && echo 'expose_php = 0' > /usr/local/etc/php/conf.d/99-security.ini \
    && a2enconf security-headers \
    && rm -f /etc/apt/apt.conf.d/99-skynet-build-network \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

COPY --from=vendor /app ./
COPY public/build ./public/build
COPY docker/app-entrypoint.sh /usr/local/bin/skynet-entrypoint
# OPcache + realpath cache (see docker/php-opcache.ini). The base image
# compiles OPcache but leaves it disabled; `docker-php-ext-install opcache`
# above enables the extension, this file tunes it.
COPY docker/php-opcache.ini /usr/local/etc/php/conf.d/10-opcache.ini
# Upload limits (see docker/php-uploads.ini); PHP defaults are 2M/8M.
COPY docker/php-uploads.ini /usr/local/etc/php/conf.d/20-uploads.ini

# Ownership of public/ (and the image's storage, bootstrap/cache) is fixed here,
# at build time. skynet-entrypoint never chowns public/ at boot.
RUN chmod +x /usr/local/bin/skynet-entrypoint \
    && mkdir -p storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache public

ENTRYPOINT ["skynet-entrypoint"]
CMD ["apache2-foreground"]
