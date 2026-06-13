#!/usr/bin/env bash

set -euo pipefail

source "$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" >/dev/null 2>&1 && pwd)/common.sh"

check_extension_project
check_php_tools
print_context

info "Running phpize"
"${PHPIZE}"

configure_args=("${CONFIGURE_ENABLE_ARG}")

if [[ -n "${CONFIGURE_ARGS:-}" ]]; then
    # Intentionally split CONFIGURE_ARGS so callers can pass multiple flags.
    extra_configure_args=(${CONFIGURE_ARGS})
    configure_args+=("${extra_configure_args[@]}")
fi

info "Running ./configure ${configure_args[*]}"
./configure "${configure_args[@]}"
