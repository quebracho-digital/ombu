#!/usr/bin/env bash
# A buyer agent's round in the mall: read /market.json, buy every service
# Ombú sells itself, on the network the site charges (Arc mainnet in production).
# Needs x402_pay next to this repo, with its scripts/.env (BUYER_PRIVATE_KEY);
# ENV=.env.mainnet for a mainnet buyer.
# Run: scripts/buy-ours.sh [https://ombu.ddev.site]
set -euo pipefail
SITE=${1:-https://ombu.ddev.site}
BUY="$(dirname "$0")/../../x402_pay/scripts"
export NODE_EXTRA_CA_CERTS="${NODE_EXTRA_CA_CERTS:-$(mkcert -CAROOT)/rootCA.pem}"

# Sample input per service, by listing title.
input() {
  case "$1" in
    Ping*) echo '{}' ;;
    Web*) echo '{"url":"https://example.com"}' ;;
    Fact*) echo '{"claim":"The Paraná River flows into the Río de la Plata."}' ;;
    Terms*) echo '{"text":"Your subscription renews automatically every year. Any dispute goes to binding arbitration and you waive class actions. We may share your personal data with advertising partners."}' ;;
  esac
}

curl -sk "$SITE/market.json?search=Quebracho&limit=100" | python3 -c '
import json, sys
for i in json.load(sys.stdin)["items"]:
    if i["metadata"]["market"]["source"] == "ours" and i["accepts"]:
        print(i["resource"] + "\t" + i["metadata"]["description"])' |
while IFS=$'\t' read -r url title; do
  echo "=== $title"
  (cd "$BUY" && node --env-file="${ENV:-.env}" buy.mjs "$url" 0.05 "$(input "$title")" | grep -E "^(402|paid|tx|seller|timing)")
done
