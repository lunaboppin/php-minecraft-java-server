FROM php:8.5.12-cli-trixie@sha256:635a4b998025ab269194639a5549c6fdcd9b4f88ec8cd761b092de8653d40663
RUN docker-php-ext-install -j2 pcntl \
    && groupadd --gid 10001 minecraft \
    && useradd --uid 10001 --gid 10001 --no-create-home --home-dir /app minecraft
COPY deploy/docker/php-memory.ini /usr/local/etc/php/conf.d/zz-memory-limit.ini
COPY deploy/docker/php-opcache.ini /usr/local/etc/php/conf.d/zz-opcache-jit.ini
WORKDIR /app
COPY --chown=10001:10001 . /app
RUN mkdir -p /app/data && chown 10001:10001 /app/data \
    && php -r 'require "src/bootstrap.php"; foreach (["pdo_sqlite","zlib","iconv","pcntl","openssl","curl"] as $extension) { if (!extension_loaded($extension)) exit(1); }'
USER 10001:10001
ENV SERVER_PORT=25676 CONFIG_FILE=/app/data/server.json PHP_MEMORY_LIMIT=2048M
VOLUME ["/app/data"]
EXPOSE 25676/tcp
STOPSIGNAL SIGTERM
HEALTHCHECK --interval=30s --timeout=3s --start-period=30s --retries=3 CMD ["php", "deploy/docker/health.php"]
ENTRYPOINT ["/bin/sh", "deploy/docker/entrypoint.sh"]
CMD ["server"]
