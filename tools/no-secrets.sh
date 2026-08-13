#!/usr/bin/env bash
# Fails if anything that looks like a live credential is tracked in the repository.
#
# The library reads keys from the environment, so a key appearing in a tracked file is either a
# mistake or an example that will be copied as one. Run locally before committing; CI runs it too.
set -uo pipefail

patterns=(
  'sk-ant-[A-Za-z0-9_-]{20,}'      # Anthropic
  'gsk_[A-Za-z0-9]{40,}'           # Groq
  'AQ\.[A-Za-z0-9_-]{30,}'         # Google API key (AQ. form)
  'AIza[0-9A-Za-z_-]{35}'          # Google API key (AIza form)
  'xai-[A-Za-z0-9]{40,}'           # xAI
  'sk-[A-Za-z0-9]{40,}'            # OpenAI-style
  'ASIA[0-9A-Z]{16}'               # AWS temporary access key id
  'AKIA[0-9A-Z]{16}'               # AWS access key id
)

status=0
for pattern in "${patterns[@]}"; do
  if matches=$(git grep -nIE "$pattern" -- . ':!tools/no-secrets.sh' 2>/dev/null); then
    echo "Possible credential matching /${pattern}/:"
    echo "$matches"
    status=1
  fi
done

if [ "$status" -eq 0 ]; then
  echo "No tracked credentials found."
fi

exit "$status"
