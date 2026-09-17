#!/bin/sh
set -eu
cd "$(dirname "$0")/.."
plugin_dir=$(basename "$(pwd)")
npx --yes @wordpress/env@11.15.0 run tests-cli --env-cwd="wp-content/plugins/$plugin_dir" vendor/bin/phpunit -c phpunit-integration.xml.dist "$@"
