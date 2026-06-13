#!/usr/bin/env bash

set -euo pipefail

source "$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" >/dev/null 2>&1 && pwd)/common.sh"

check_extension_project
check_php_tools

if [[ ! -f "${PROJECT_ROOT}/Makefile" || ! -f "${MODULE_PATH}" ]]; then
    info "Build output missing; building first"
    "${SCRIPT_DIR}/build.sh"
fi

[[ -f "${MODULE_PATH}" ]] || err "module not found: ${MODULE_PATH}"

if (($# > 0)); then
    exec "${PHP_BIN}" -d "extension=${MODULE_PATH}" "$@"
fi

exec "${PHP_BIN}" -d "extension=${MODULE_PATH}" --ri "${EXTENSION_NAME}"
