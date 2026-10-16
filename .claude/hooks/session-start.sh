#!/bin/bash
# Prepares a Claude Code cloud session for this repository: the PHP CLI with the
# extensions the plugin and its local checks use (zip, dom/SimpleXML, mbstring), and
# the zip tool used by bin/build.sh and bin/build-theme.sh. Idempotent: installs only
# what is missing. Integration tests run on the live site through Novamira, so nothing
# else is needed locally.
set -euo pipefail

if [ "${CLAUDE_CODE_REMOTE:-}" != "true" ]; then
  exit 0
fi

packages=()
if ! command -v php >/dev/null 2>&1; then
  packages+=(php-cli php-zip php-xml php-mbstring)
else
  modules=$(php -m | tr '[:upper:]' '[:lower:]')
  grep -qx zip <<<"$modules" || packages+=(php-zip)
  { grep -qx dom <<<"$modules" && grep -qx simplexml <<<"$modules"; } || packages+=(php-xml)
  grep -qx mbstring <<<"$modules" || packages+=(php-mbstring)
fi
command -v zip >/dev/null 2>&1 || packages+=(zip)

if [ ${#packages[@]} -gt 0 ]; then
  echo "Installing: ${packages[*]}"
  export DEBIAN_FRONTEND=noninteractive
  apt-get update -qq
  apt-get install -y -qq "${packages[@]}"
fi

php -v | head -n 1
echo "PHP extensions: $(php -m | grep -iE '^(zip|dom|simplexml|mbstring)$' | tr '\n' ' ')"
