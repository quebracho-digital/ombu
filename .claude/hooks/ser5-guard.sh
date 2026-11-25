#!/usr/bin/env bash
# PreToolUse(Bash): SER5 changes only through git pull, by scripts/deploy-ser5.sh.
# Leo, 2026-11-25: "el deploy se hace con git pull en lugar de scp".
cmd=$(python3 -c 'import json,sys; print(json.load(sys.stdin).get("tool_input",{}).get("command",""))')
case "$cmd" in
  *scripts/deploy-ser5.sh*) exit 0 ;;
esac
# Not about SER5: let it through.
printf '%s' "$cmd" | grep -Eq 'ser5|192\.168\.1\.66|quebrachos?\.digital|@quebracho' || exit 0
# Reading is fine (ssh ... ls, cat, logs, curl, drush status, git log...).
if printf '%s' "$cmd" | grep -Eq '(^|[[:space:];&|])(scp|rsync|sftp)[[:space:]]|docker[[:space:]]+cp|docker[[:space:]]+compose[[:space:]]+(up|build|restart|down|cp)|tee[[:space:]]|sed[[:space:]]+-i|>>?[[:space:]]*/|git[[:space:]]+(commit|checkout|reset|stash|clean|apply|merge|rebase)|composer[[:space:]]+(require|update|remove)|apache2ctl|a2en|drush[^|]*(cim|config:import|updb|updatedb|deploy|php:script|sset|state:set|cset|config:set|pmu|en )|chmod|chown|(^|[[:space:]])(mv|cp|rm)[[:space:]]'; then
  echo "Blocked: SER5 changes only go through commit, push and scripts/deploy-ser5.sh (git pull). No scp/rsync/docker cp/hand edits. Ask Leo for 'deploya', or if the change is not in git, commit it first." >&2
  exit 2
fi
exit 0
