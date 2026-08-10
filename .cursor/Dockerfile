# MoxDOP Cursor Cloud agent base image
# Do NOT COPY the application source — Cursor checks out the correct revision.
FROM ubuntu:24.04

ENV DEBIAN_FRONTEND=noninteractive \
    LANG=C.UTF-8 \
    LC_ALL=C.UTF-8

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates \
        curl \
        git \
        gnupg \
        sudo \
        unzip \
        zip \
        software-properties-common \
    && apt-get install -y --no-install-recommends \
        php8.3-cli \
        php8.3-bcmath \
        php8.3-curl \
        php8.3-intl \
        php8.3-mbstring \
        php8.3-sqlite3 \
        php8.3-xml \
        php8.3-zip \
        php8.3-mysql \
    && rm -rf /var/lib/apt/lists/*

# Composer 2
RUN curl -fsSL https://getcomposer.org/installer \
    | php -- --install-dir=/usr/local/bin --filename=composer \
    && composer --version

# Node.js 22 (matches current Cloud/CI lockfile usage)
RUN curl -fsSL https://deb.nodesource.com/setup_22.x | bash - \
    && apt-get install -y --no-install-recommends nodejs \
    && npm --version \
    && node --version \
    && rm -rf /var/lib/apt/lists/*

# Non-root ubuntu user expected by Cursor Cloud
RUN id -u ubuntu >/dev/null 2>&1 || useradd -m -s /bin/bash ubuntu \
    && usermod -aG sudo ubuntu \
    && echo 'ubuntu ALL=(ALL) NOPASSWD:ALL' > /etc/sudoers.d/ubuntu \
    && chmod 0440 /etc/sudoers.d/ubuntu

USER ubuntu
WORKDIR /home/ubuntu

CMD ["bash"]
