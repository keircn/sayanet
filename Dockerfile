# multi-stage build: node builder + php runtime
FROM node:22-alpine AS builder
WORKDIR /app
RUN corepack enable && corepack prepare pnpm@10 --activate
COPY package.json pnpm-lock.yaml pnpm-workspace.yaml* ./
RUN pnpm install --frozen-lockfile
COPY . .
RUN pnpm run build && pnpm run build:vite
RUN mkdir -p /app/build/_sayanet/public/cache /app/build/_sayanet/private/cache && chmod -R 777 /app/build/_sayanet/public/cache /app/build/_sayanet/private/cache

FROM php:8.3-apache
LABEL maintainer="keircn/sayanet"

# enable apache rewrite and headers
RUN a2enmod rewrite headers deflate expires

# install php extensions needed for thumbnails
RUN apt-get update && apt-get install -y \
    libfreetype6-dev libjpeg-dev libpng-dev libzip-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) gd exif \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# php upload limits for folder/zip uploads
RUN echo "upload_max_filesize=0\npost_max_size=0\nmax_file_uploads=200\nmax_execution_time=300\nmemory_limit=512M" > /usr/local/etc/php/conf.d/sayanet.ini

# copy built app
COPY --from=builder /app/build/_sayanet /var/www/html/_sayanet
# also copy any static public files that are not built? (images etc already in build via ghu copy)
# ensure _sayanet is readable
RUN chown -R www-data:www-data /var/www/html/_sayanet && chmod -R 755 /var/www/html/_sayanet

# apache config: allow .htaccess + fallback to sayanet
RUN sed -i 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf && \
    echo 'DirectoryIndex index.html index.php /_sayanet/public/index.php' > /var/www/html/.htaccess && \
    echo 'FallbackResource /_sayanet/public/index.php' >> /var/www/html/.htaccess && \
    mkdir -p /var/www/html/demo && chown www-data:www-data /var/www/html/.htaccess /var/www/html/demo

# healthcheck
HEALTHCHECK --interval=30s --timeout=3s CMD curl -f http://localhost/_sayanet/public/js/scripts.js || exit 1

EXPOSE 80
# run as non-root is handled by apache (www-data)
