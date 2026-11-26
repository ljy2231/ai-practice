#!/bin/sh
set -eu
umask 077
root=${AI_PRACTICE_DATA_DIR:-/web/data}
install -d -m 0700 -o www-data -g www-data "$root" "$root/sessions" "$root/admin-sessions"
exec docker-php-entrypoint "$@"
