#!/usr/bin/env bash

set -euo pipefail

source "$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" >/dev/null 2>&1 && pwd)/common.sh"

need_cmd "${PHP_BIN}"

stub_file="${1:-${EXTENSION_NAME}.stub.php}"
[[ -f "${stub_file}" ]] || err "stub file not found: ${stub_file}"

gen_stubs="${GEN_STUBS:-}"

if [[ -z "${gen_stubs}" ]]; then
    candidates=(
        "${PROJECT_ROOT}/build/gen_stub.php"
        "${PROJECT_ROOT}/../php-src/build/gen_stub.php"
        "${PROJECT_ROOT}/../../php-src/build/gen_stub.php"
        "${HOME:-}/src/php-src/build/gen_stub.php"
        "${HOME:-}/code/php-src/build/gen_stub.php"
        "${HOME:-}/Code/php-src/build/gen_stub.php"
    )

    for candidate in "${candidates[@]}"; do
        if [[ -f "${candidate}" ]]; then
            gen_stubs="${candidate}"
            break
        fi
    done
fi

[[ -f "${gen_stubs}" ]] || err "could not find gen_stub.php; set GEN_STUBS=/path/to/php-src/build/gen_stub.php"

info "Generating arginfo from ${stub_file}"
"${PHP_BIN}" "${gen_stubs}" "${stub_file}"
