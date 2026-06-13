#!/usr/bin/env bash

set -euo pipefail

source "$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" >/dev/null 2>&1 && pwd)/common.sh"

check_php_tools
print_context

if [[ -f "${PROJECT_ROOT}/config.m4" ]]; then
    info "Found config.m4"
else
    warn "config.m4 not found yet; create it before running bootstrap/build"
fi

include_flags="$("${PHP_CONFIG}" --includes)"
info "PHP include flags: ${include_flags}"

read -r -a parsed_include_flags <<< "${include_flags}"
php_h_path=""

for flag in "${parsed_include_flags[@]}"; do
    if [[ "${flag}" == -I* ]]; then
        include_dir="${flag#-I}"
        if [[ -f "${include_dir}/php.h" ]]; then
            php_h_path="${include_dir}/php.h"
            break
        fi
    fi
done

if [[ -n "${php_h_path}" ]]; then
    info "Found php.h: ${php_h_path}"
else
    err "php.h was not found in php-config --includes paths; check that PHP development headers are installed"
fi

if [[ -f "${MODULE_PATH}" ]]; then
    info "Built module exists: ${MODULE_PATH}"
    "${PHP_BIN}" -d "extension=${MODULE_PATH}" -m | grep -Fx "${EXTENSION_NAME}" >/dev/null \
        && info "PHP can load ${EXTENSION_NAME}" \
        || warn "Module exists, but PHP did not report ${EXTENSION_NAME} as loaded"
else
    warn "Built module does not exist yet; run scripts/build.sh"
fi
