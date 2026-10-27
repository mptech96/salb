#!/usr/bin/env bash
# Inspection only: no mysqld invocation, DB connection, service or cgroup mutation.
set +x
set -euo pipefail
printf 'MODE=NATIVE_STAGING_FEASIBILITY_ONLY\nDATABASE_CONNECTIONS=0\nMYSQLD_STARTED=false\nSERVICES_CHANGED=false\n'
printf 'MEM_AVAILABLE_KIB=%s\n' "$(awk '/^MemAvailable:/{print $2}' /proc/meminfo)"
printf 'HOME_FREE_KIB=%s\n' "$(df -Pk /home/mgye-api-sulb | awk 'NR==2{print $4}')"
printf 'CPU_COUNT=%s\n' "$(getconf _NPROCESSORS_ONLN)"
printf 'CGROUP_FS=%s\n' "$(stat -fc %T /sys/fs/cgroup)"
if [ -r /sys/fs/cgroup/cgroup.controllers ]; then
  printf 'CGROUP_CONTROLLERS=%s\n' "$(cat /sys/fs/cgroup/cgroup.controllers)"
else printf 'CGROUP_CONTROLLERS=UNAVAILABLE\n'; fi
for name in mysqld composer unshare setpriv prlimit; do
  if command -v "$name" >/dev/null; then printf 'COMMAND_%s=AVAILABLE\n' "$name"; else printf 'COMMAND_%s=UNAVAILABLE\n' "$name"; fi
done
if [ -r /sys/module/apparmor/parameters/enabled ]; then
  printf 'APPARMOR_ENABLED=%s\n' "$(cat /sys/module/apparmor/parameters/enabled)"
else printf 'APPARMOR_ENABLED=UNKNOWN\n'; fi
if [ -f /etc/apparmor.d/usr.sbin.mysqld ]; then printf 'MYSQLD_APPARMOR_PROFILE=PRESENT_REVIEW_REQUIRED\n'; else printf 'MYSQLD_APPARMOR_PROFILE=NOT_FOUND\n'; fi
# A child true process only. Namespace objects disappear immediately; no mounts,
# listeners, filesystem changes or production processes enter the namespace.
if command -v unshare >/dev/null && unshare --mount --net --pid --fork /bin/true 2>/dev/null; then
  printf 'EPHEMERAL_NAMESPACE_CAPABILITY=PASS\n'
else printf 'EPHEMERAL_NAMESPACE_CAPABILITY=BLOCKED\n'; fi
printf 'NATIVE_INSTANCE_APPROVED=false\nRELEASE_DECISION=NO-GO\n'
