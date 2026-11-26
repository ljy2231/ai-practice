FROM php:8.4-apache
RUN --network=none php -r 'if (!extension_loaded("pdo_sqlite") || !in_array("argon2id", password_algos(), true)) exit(1);'
WORKDIR /web
COPY docker/site.conf /etc/apache2/sites-available/000-default.conf
COPY --chmod=0755 docker/entrypoint.sh /usr/local/bin/practice-entrypoint
RUN --network=none install -d -m 0755 /web/public
COPY --chmod=0644 404.html admin.css admin.php admin-panel.php api.php app.js bank.php explain-a.php explain-b.php favicon.svg index.php storage.php style.css /web/public/
ENV AI_PRACTICE_DATA_DIR=/web/data
EXPOSE 80
ENTRYPOINT ["/usr/local/bin/practice-entrypoint"]
CMD ["apache2-foreground"]
