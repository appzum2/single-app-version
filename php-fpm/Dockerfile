FROM phpdockerio/php7-fpm:latest

# Install selected extensions and other stuff
RUN apt-get update \
    && apt-get -y --no-install-recommends install  php7.0-mysql php7.0-redis php7.0-sqlite3 php7.0-bz2 php7.0-gd php7.0-igbinary php7.0-mbstring \
    && apt-get clean; rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/* /usr/share/doc/*

RUN apt-get update && \
    apt-get install -y \
        zlib1g-dev \
        zip \
        unzip \
        pngquant \
