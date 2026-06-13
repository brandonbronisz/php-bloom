#!/usr/bin/env bash

set -euo pipefail

source "$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" >/dev/null 2>&1 && pwd)/common.sh"

check_extension_project

remove_path() {
    local path="$1"
    local display_path="${path#${PROJECT_ROOT}/}"

    if [[ -e "${path}" || -L "${path}" ]]; then
        info "Removing ${display_path}"
        rm -rf -- "${path}"
    fi
}

remove_paths() {
    local path

    for path in "$@"; do
        remove_path "${path}"
    done
}

remove_find_matches() {
    local path

    while IFS= read -r -d '' path; do
        remove_path "${path}"
    done < <(
        find "${PROJECT_ROOT}" \
            \( \
                -path "${PROJECT_ROOT}/.agents" -o \
                -path "${PROJECT_ROOT}/.codex" -o \
                -path "${PROJECT_ROOT}/.git" -o \
                -path "${PROJECT_ROOT}/.idea" -o \
                -path "${PROJECT_ROOT}/node_modules" -o \
                -path "${PROJECT_ROOT}/vendor" \
            \) -prune -o \
            \( -type d \( -name ".deps" -o -name ".libs" \) -prune -print0 \) -o \
            \( -type f \( \
                -name "*.a" -o \
                -name "*.dep" -o \
                -name "*.gcda" -o \
                -name "*.gcno" -o \
                -name "*.la" -o \
                -name "*.lo" -o \
                -name "*.o" -o \
                -name "*.so" \
            \) -print0 \)
    )
}

if [[ -f "${PROJECT_ROOT}/Makefile" ]] && command -v "${MAKE}" >/dev/null 2>&1; then
    info "Running make clean"
    if ! "${MAKE}" clean; then
        warn "make clean failed; continuing with artifact cleanup"
    fi
elif [[ -f "${PROJECT_ROOT}/Makefile" ]]; then
    warn "Makefile found, but ${MAKE} is not available; skipping make clean"
else
    warn "Makefile not found; skipping make clean"
fi

shopt -s nullglob

remove_paths \
    "${PROJECT_ROOT}/Makefile" \
    "${PROJECT_ROOT}/Makefile.fragments" \
    "${PROJECT_ROOT}/Makefile.objects" \
    "${PROJECT_ROOT}/acinclude.m4" \
    "${PROJECT_ROOT}/aclocal.m4" \
    "${PROJECT_ROOT}/autom4te.cache" \
    "${PROJECT_ROOT}/build" \
    "${PROJECT_ROOT}/config.h" \
    "${PROJECT_ROOT}/config.h.in" \
    "${PROJECT_ROOT}/config.h.in~" \
    "${PROJECT_ROOT}/config.log" \
    "${PROJECT_ROOT}/config.nice" \
    "${PROJECT_ROOT}/config.status" \
    "${PROJECT_ROOT}/configure" \
    "${PROJECT_ROOT}/configure~" \
    "${PROJECT_ROOT}/configure.ac" \
    "${PROJECT_ROOT}/include" \
    "${PROJECT_ROOT}/libtool" \
    "${PROJECT_ROOT}/libs" \
    "${PROJECT_ROOT}/modules" \
    "${PROJECT_ROOT}/run-tests.php" \
    "${PROJECT_ROOT}/tmp-php.ini" \
    "${PROJECT_ROOT}"/cmake-build-* \
    "${PROJECT_ROOT}"/tests/*.clean.php \
    "${PROJECT_ROOT}"/tests/*.diff \
    "${PROJECT_ROOT}"/tests/*.exp \
    "${PROJECT_ROOT}"/tests/*.log \
    "${PROJECT_ROOT}"/tests/*.mem \
    "${PROJECT_ROOT}"/tests/*.out \
    "${PROJECT_ROOT}"/tests/*.php \
    "${PROJECT_ROOT}"/tests/*.sh

remove_find_matches

shopt -u nullglob
