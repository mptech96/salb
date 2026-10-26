#!/usr/bin/env bash
set -euo pipefail
umask 077
trap 'stty echo < /dev/tty 2>/dev/null || true' EXIT
tools=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
out=$(mktemp -d /tmp/sulb-rc-preflight.XXXXXXXX)
php "$tools/preflight.php" > "$out/preflight.json"
{
  for command_name in node chromium chromium-browser google-chrome php-fpm php-fpm8.3 php-fpm8.4; do
    if command -v "$command_name" >/dev/null; then
      if ! "$command_name" --version; then printf '%s version query unavailable\n' "$command_name"; fi
    fi
  done
  if command -v composer >/dev/null; then
    if ! COMPOSER_DISABLE_NETWORK=1 composer --no-plugins --no-scripts --version; then printf 'composer version unavailable\n'; fi
  fi
  if command -v systemctl >/dev/null; then
    systemctl show sulb-frontend.service -p LoadState -p ActiveState -p SubState || printf 'service state unavailable\n'
  fi
  if git -C /home/sulb-release rev-parse --is-inside-work-tree >/dev/null 2>&1; then
    printf 'Staging checkout revision (not proof of live code): '
    git -C /home/sulb-release rev-parse HEAD
  fi
} > "$out/runtime.txt" 2>/dev/null
tar -czf "$out/metadata.tar.gz" -C "$out" preflight.json runtime.txt
printf 'METADATA_FILE=%s/metadata.tar.gz\n' "$out"
printf 'NO-GO: evidence captured only; production compatibility and staging remain unapproved.\n'
exit 78
