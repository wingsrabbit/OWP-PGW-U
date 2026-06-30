#!/usr/bin/env sh
set -eu

PHP_BIN="${PHP_BIN:-php}"

for file in $(find modules includes -type f -name '*.php' | sort); do
  "$PHP_BIN" -l "$file"
done
