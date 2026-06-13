#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" >/dev/null 2>&1 && pwd)"
PROJECT_ROOT="$(cd -- "${SCRIPT_DIR}/.." >/dev/null 2>&1 && pwd)"

EXTENSION_NAME="${EXTENSION_NAME:-bloom}"
PHP_BIN="${PHP_BIN:-php}"
PHP_CONFIG="${PHP_CONFIG:-php-config}"
PHPIZE="${PHPIZE:-phpize}"
MAKE="${MAKE:-make}"
CONFIGURE_ENABLE_ARG="${CONFIGURE_ENABLE_ARG:---enable-${EXTENSION_NAME}}"
MODULE_PATH="${PROJECT_ROOT}/modules/${EXTENSION_NAME}.so"

cd "${PROJECT_ROOT}"

err() {
    printf 'error: %s\n' "$*" >&2
    exit 1
}

info() {
    printf '==> %s\n' "$*" >&2
}

warn() {
    printf 'warning: %s\n' "$*" >&2
}

resolve_cmd() {
    command -v "$1" 2>/dev/null || printf '%s' "$1"
}

need_cmd() {
    command -v "$1" >/dev/null 2>&1 || err "missing command: $1"
}

check_extension_project() {
    [[ -f "${PROJECT_ROOT}/config.m4" ]] || err "config.m4 not found in ${PROJECT_ROOT}; run scripts from a PHP extension project root"
}

check_php_tools() {
    need_cmd "${PHP_BIN}"
    need_cmd "${PHP_CONFIG}"
    need_cmd "${PHPIZE}"
    need_cmd "${MAKE}"
}

print_context() {
    info "Project root: ${PROJECT_ROOT}"
    info "Extension: ${EXTENSION_NAME}"
    info "PHP binary: $(resolve_cmd "${PHP_BIN}")"
    info "php-config: $(resolve_cmd "${PHP_CONFIG}")"
    info "phpize: $(resolve_cmd "${PHPIZE}")"
    info "Module path: ${MODULE_PATH}"
    info "PHP version: $("${PHP_BIN}" -r 'echo PHP_VERSION;')"
    info "PHP version ID: $("${PHP_CONFIG}" --vernum)"
    info "Extension dir: $("${PHP_CONFIG}" --extension-dir)"
}
