#!/usr/bin/env bash

set -e -o pipefail
set -x

function on_script_exit () {
    docker-compose down -v --remove-orphans
}

function main () {
    trap on_script_exit EXIT

    docker-compose build

    docker-compose up --abort-on-container-exit
}

main "$@"
