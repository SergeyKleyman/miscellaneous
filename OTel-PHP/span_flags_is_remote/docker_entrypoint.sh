#!/usr/bin/env bash

set -e -o pipefail
set -x

echo "env"
env | sort
echo "php -v"
php -v
echo "php -m"
php -m

if [ -n "${APPLY_IS_REMOTE_FLAG_FIX}" ] && [ "${APPLY_IS_REMOTE_FLAG_FIX}" == "true" ] ; then
    diff ./vendor/open-telemetry/exporter-otlp/SpanConverter.php ./SpanConverter.ORIGINAL.php
    cp --force ./SpanConverter.FIXED.php ./vendor/open-telemetry/exporter-otlp/SpanConverter.php
fi

php -S 0.0.0.0:8080
