#!/bin/sh
# CoPAI Platform
# https://copai.community
#
# Developed by Murbit GmbH as part of the Erasmus+ project:
#
# Community of Practice AI
# Project No.: 2023-2-AT01-KA210-VET-000169864
#
# Funded by the European Union. Views and opinions expressed are however
# those of the author(s) only and do not necessarily reflect those of the
# European Union or the European Education and Culture Executive Agency (EACEA).
# Neither the European Union nor EACEA can be held responsible for them.
#
# Copyright (c) 2025 Murbit GmbH
#
# Licensed under the MIT License.
# See LICENSE file for details.

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
