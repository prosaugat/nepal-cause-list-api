FROM php:8.3-cli

# System deps for the PHP curl extension.
RUN apt-get update \
    && apt-get install -y --no-install-recommends libcurl4-openssl-dev unzip git \
    && docker-php-ext-install curl \
    && rm -rf /var/lib/apt/lists/*

# Composer.
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Install dependencies first (better layer caching).
COPY composer.json ./
RUN composer install --no-dev --no-interaction --prefer-dist --no-scripts || true

# App source.
COPY . .
RUN composer install --no-dev --no-interaction --prefer-dist

EXPOSE 8080

# The Supreme Court scraper talks to the Selenium container.
ENV SELENIUM_HUB=http://selenium:4444/wd/hub

CMD ["php", "-S", "0.0.0.0:8080", "-t", "public"]
