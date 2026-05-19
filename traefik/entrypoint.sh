#!/bin/sh
set -e

# Templates are mounted read-only at /etc/traefik/templates.
# Render each *.tmpl into the corresponding writable dir:
#   /etc/traefik/templates/traefik.yml.tmpl       → /etc/traefik/traefik.yml
#   /etc/traefik/templates/dynamic/<name>.yml.tmpl → /etc/traefik/dynamic/<name>.yml

mkdir -p /etc/traefik/dynamic

find /etc/traefik/templates -name '*.tmpl' | while read -r tmpl; do
    rel="${tmpl#/etc/traefik/templates/}"
    out="/etc/traefik/${rel%.tmpl}"
    mkdir -p "$(dirname "$out")"
    envsubst < "$tmpl" > "$out"
done

exec traefik "$@"
