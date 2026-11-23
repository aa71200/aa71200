#!/usr/bin/env bash
set -euo pipefail

docroot="$1"
server_dir="$2"
php_version="$(php -r 'echo PHP_MAJOR_VERSION, ".", PHP_MINOR_VERSION;')"
mkdir -p "$server_dir"

if ! command -v "php-fpm$php_version" >/dev/null || ! command -v nginx >/dev/null; then
  if ! sudo apt-get install --yes --no-install-recommends "php$php_version-fpm" nginx; then
    sudo apt-get update
    sudo apt-get install --yes --no-install-recommends "php$php_version-fpm" nginx
  fi
fi

cat > "$server_dir/php-fpm.conf" <<EOF
[global]
error_log = $server_dir/php-fpm.log
daemonize = no

[checkout]
listen = 127.0.0.1:8079
pm = static
pm.max_children = 2
catch_workers_output = yes
php_admin_flag[opcache.enable] = off
EOF

cat > "$server_dir/nginx.conf" <<EOF
daemon off;
pid $server_dir/nginx.pid;
error_log $server_dir/nginx.log;

events {}
http {
    include /etc/nginx/mime.types;
    access_log off;
    client_body_temp_path $server_dir/client;
    proxy_temp_path $server_dir/proxy;
    fastcgi_temp_path $server_dir/fastcgi;
    uwsgi_temp_path $server_dir/uwsgi;
    scgi_temp_path $server_dir/scgi;

    server {
        listen 127.0.0.1:8077;
        root $docroot;
        index index.php;

        location / {
            try_files \$uri \$uri/ /index.php?\$args;
        }
        location ~ \.php\$ {
            try_files \$uri =404;
            include /etc/nginx/fastcgi_params;
            fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
            fastcgi_pass 127.0.0.1:8079;
        }
    }
}
EOF

# Use the exact PHP version and installed extensions selected for this matrix cell.
"php-fpm$php_version" --fpm-config "$server_dir/php-fpm.conf" > "$server_dir/php-fpm-output.log" 2>&1 &
php_server="$!"
nginx -p "$server_dir" -c "$server_dir/nginx.conf" > "$server_dir/nginx-output.log" 2>&1 &
http_server="$!"
trap 'kill "$http_server" "$php_server" 2>/dev/null || true' EXIT

if ! (
  curl --fail --silent --show-error --retry-connrefused --retry 5 http://127.0.0.1:8077/ > /dev/null
  npm test --prefix "$3"
); then
  for log in "$server_dir"/*.log; do
    printf '\n--- %s ---\n' "$(basename "$log")"
    tail -n 35 "$log"
  done
  exit 1
fi
