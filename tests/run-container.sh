#!/usr/bin/env bash
#
# Runs the full winter-thread test matrix inside containers for a list of PHP
# versions. Each version: builds the image, then runs the default suite
# (base + working) followed by the container-only groups (--group container).
#
# Usage:
#   tests/run-container.sh                # default versions: 8.4 8.5
#   tests/run-container.sh 8.4            # single version
#   tests/run-container.sh 8.4 8.5 8.6    # custom list
#
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
COMPOSE_FILE="${ROOT_DIR}/tests/docker/docker-compose.test.yml"

VERSIONS=("$@")
if [ "${#VERSIONS[@]}" -eq 0 ]; then
    VERSIONS=(8.4 8.5)
fi

FAILED=()
for V in "${VERSIONS[@]}"; do
    echo "==================================================================="
    echo "  PHP ${V} — build image, run default + container groups"
    echo "==================================================================="
    if PHP_VERSION="${V}" docker compose -f "${COMPOSE_FILE}" build \
        && PHP_VERSION="${V}" docker compose -f "${COMPOSE_FILE}" run --rm wt-test; then
        echo "PHP ${V}: PASS"
    else
        echo "PHP ${V}: FAIL"
        FAILED+=("${V}")
    fi
    PHP_VERSION="${V}" docker compose -f "${COMPOSE_FILE}" down -v --remove-orphans >/dev/null 2>&1 || true
done

if [ "${#FAILED[@]}" -ne 0 ]; then
    echo "FAILED versions: ${FAILED[*]}"
    exit 1
fi
echo "All requested PHP versions passed."
