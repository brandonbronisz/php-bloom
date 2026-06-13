#!/usr/bin/env bash

set -euo pipefail

source "$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" >/dev/null 2>&1 && pwd)/common.sh"

check_extension_project
check_php_tools

if [[ ! -f "${PROJECT_ROOT}/Makefile" ]]; then
    info "Makefile not found; bootstrapping first"
    "${SCRIPT_DIR}/bootstrap.sh"
fi

info "Building ${EXTENSION_NAME}"
"${MAKE}" "$@"

if [[ -f "${MODULE_PATH}" ]]; then
    info "Built ${MODULE_PATH}"
else
    warn "Build completed, but ${MODULE_PATH} was not found"
fi
