#!/usr/bin/env bash

set -euo pipefail

source "$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" >/dev/null 2>&1 && pwd)/common.sh"

check_extension_project
check_php_tools

if [[ ! -f "${PROJECT_ROOT}/Makefile" || ! -f "${MODULE_PATH}" ]]; then
    info "Build output missing; building first"
    "${SCRIPT_DIR}/build.sh"
fi

if (($# > 0)); then
    info "Running selected PHPT tests: $*"
    NO_INTERACTION=1 REPORT_EXIT_STATUS=1 TESTS="$*" "${MAKE}" test
else
    info "Running all PHPT tests"
    NO_INTERACTION=1 REPORT_EXIT_STATUS=1 "${MAKE}" test
fi
