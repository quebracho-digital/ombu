#!/usr/bin/env bash
# PreToolUse(Bash): the deploy of Ombú to SER5 is `git pull`, by
# scripts/deploy-ser5.sh. Leo, 2026-11-25: "el deploy se hace con git pull en
# lugar de scp". It blocks only that: writing into Ombú's place on SER5
# (/data/docker/ombu, the ombu-* containers). scp, rsync and the rest stay free
# for any other task.
input=$(cat)
if command -v jq >/dev/null; then
  cmd=$(printf '%s' "$input" | jq -r '.tool_input.command // empty')
else
  cmd=$(printf '%s' "$input" | python3 -c 'import json,sys; print(json.load(sys.stdin).get("tool_input",{}).get("command",""))')
fi
case "$cmd" in *scripts/deploy-ser5.sh*) exit 0 ;; esac
# Only what is about SER5 and Ombú's directory or containers.
printf '%s' "$cmd" | grep -Eq 'ser5|192\.168\.1\.66|@quebracho' || exit 0
printf '%s' "$cmd" | grep -Eq '/data/docker/ombu|ombu-(web|db|meilisearch)-1|docker[[:space:]]+compose' || exit 0
# Reading is fine; these write.
if printf '%s' "$cmd" | grep -Eq '(^|[[:space:];&|])(scp|rsync|sftp)[[:space:]]|docker[[:space:]]+cp|docker[[:space:]]+compose[[:space:]]+(up|build|restart|down|cp)|tee[[:space:]]|sed[[:space:]]+-i|>>?[[:space:]]*/|git[[:space:]]+(commit|checkout|reset|stash|clean|apply|merge|rebase)|composer[[:space:]]+(require|update|remove)|apache2ctl|drush[^|]*(cim|config:import|updb|updatedb|deploy|config:set|cset|pmu)|(^|[[:space:]])(mv|cp|rm)[[:space:]]'; then
  echo "Blocked: Ombú reaches SER5 only by commit, push and scripts/deploy-ser5.sh (git pull), never by copying files in. (scp/rsync to other places are fine.)" >&2
  exit 2
fi
exit 0
