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

FROM traefik:v3

# envsubst lives in the gettext package on alpine
RUN apk add --no-cache gettext

COPY entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

ENTRYPOINT ["/entrypoint.sh"]
