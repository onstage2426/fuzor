#!/usr/bin/env bash
#
# Builds one SQLite release as libsqlite3.so.0 so the tests can run against it:
#
#   .github/scripts/build-sqlite.sh 3.46.0 /tmp/sqlite    # or: latest
#   LD_LIBRARY_PATH=/tmp/sqlite/lib vendor/bin/phpunit
#
# Works where PHP's pdo_sqlite loads the shared system library (the usual packaging);
# `SELECT sqlite_version()` tells which one a PHP process got.

set -euo pipefail

version=${1:?usage: build-sqlite.sh <version|latest> <prefix>}
prefix=${2:?usage: build-sqlite.sh <version|latest> <prefix>}
site=https://www.sqlite.org
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT

if [ "$version" = latest ]; then
    # The download page lists the current release as a relative path.
    path=$(curl -fsSL "$site/download.html" | grep -oE '[0-9]{4}/sqlite-autoconf-[0-9]+\.tar\.gz' | head -n 1)
    [ -n "$path" ] || { echo "No release found on $site/download.html" >&2; exit 1; }
    curl -fsSL "$site/$path" -o "$work/sqlite.tar.gz"
else
    IFS=. read -r major minor patch <<< "$version"
    file=$(printf 'sqlite-autoconf-%d%02d%02d00.tar.gz' "$major" "$minor" "${patch:-0}")
    # Releases are filed under the year they came out in.
    for year in $(seq 2024 "$(date +%Y)"); do
        if curl -fsSL "$site/$year/$file" -o "$work/sqlite.tar.gz"; then
            break
        fi
        rm -f "$work/sqlite.tar.gz"
    done
    [ -f "$work/sqlite.tar.gz" ] || { echo "SQLite $version not found on $site" >&2; exit 1; }
fi

tar -xzf "$work/sqlite.tar.gz" -C "$work" --strip-components=1
mkdir -p "$prefix/lib"
# The amalgamation, with the options PHP's drivers expect a packaged library to have.
gcc -O2 -fPIC -shared -Wl,-soname,libsqlite3.so.0 \
    -DSQLITE_ENABLE_COLUMN_METADATA \
    -DSQLITE_ENABLE_MATH_FUNCTIONS \
    -DSQLITE_ENABLE_UNLOCK_NOTIFY \
    -DSQLITE_ENABLE_FTS5 \
    -DSQLITE_ENABLE_RTREE \
    -DSQLITE_THREADSAFE=1 \
    "$work/sqlite3.c" -o "$prefix/lib/libsqlite3.so.0" -lm -ldl -lpthread

echo "Built SQLite $version in $prefix/lib"
