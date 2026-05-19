#!/bin/sh
set -e

# Render every *.tmpl in /etc/traefik to its *.tmpl-less name
find /etc/traefik -name '*.tmpl' | while read -r tmpl; do
    out="${tmpl%.tmpl}"
    envsubst < "$tmpl" > "$out"
done

exec traefik "$@"
