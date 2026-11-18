#!/usr/bin/env bash
#
# Fails when the committed vendor/ directory contains development packages.
#
# The plugin ships vendor/ from git. PHPStan and its helpers are require-dev
# packages: `composer install` puts them in vendor/ and rewrites the autoloader
# to load files that would be missing on a user's site. Run this before any
# Composer install in CI. Locally, undo an install with:
#
#     git restore vendor && git clean -fd vendor
#
# Usage: .github/scripts/check-vendor.sh [path-to-plugin-root]

set -euo pipefail

root="${1:-.}"
installed="$root/vendor/composer/installed.json"
lock="$root/composer.lock"
status=0

dev_packages="$(jq -r '."dev-package-names" // [] | join(", ")' "$installed")"
if [[ -n "$dev_packages" ]]; then
	echo "vendor/ contains dev packages: $dev_packages" >&2
	status=1
fi

expected="$(jq -r '.packages[] | "\(.name) \(.version)"' "$lock" | sort)"
actual="$(jq -r '.packages[] | "\(.name) \(.version)"' "$installed" | sort)"
if [[ "$expected" != "$actual" ]]; then
	echo "vendor/ does not match the production packages in composer.lock:" >&2
	diff <(echo "$expected") <(echo "$actual") >&2 || true
	status=1
fi

if (( status )); then
	echo "Restore it with: git restore vendor && git clean -fd vendor" >&2
	exit 1
fi

echo "vendor/ holds only the production packages from composer.lock."
