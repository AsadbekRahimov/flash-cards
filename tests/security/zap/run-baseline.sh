#!/usr/bin/env bash
# OWASP ZAP baseline (passive scan, no attacks) of the public targets of a staging: docs/STAGING_PHASE2_CHECKLIST.md 7.2.
# Runs the ZAP Docker image once per target, writes HTML + JSON + Markdown reports and fails (exit 1) on any High alert.
#
#   BASE_URL=https://staging.example.com tests/security/zap/run-baseline.sh      (or: make zap-baseline BASE_URL=...)
#
# Variables:
#   BASE_URL       required, no trailing slash
#   TARGETS        paths to scan, space separated (default: the list below)
#   ZAP_IMAGE      image with a pinned tag (default below); change the tag on purpose, not by "stable"
#   ZAP_OUT        directory for the reports (default tests/security/zap/reports, git-ignored)
#   ZAP_MINUTES    spider time per target, minutes (default 2)
#   STRICT_MEDIUM  1: exit 2 when Medium alerts exist (default: only listed for the manual review)
#
# Exit code: 0 - no High; 1 - High (or a FAIL rule of zap-baseline.conf); 2 - Medium with STRICT_MEDIUM=1; 3 - tool error.
set -u

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BASE_URL="${BASE_URL:-}"
ZAP_IMAGE="${ZAP_IMAGE:-ghcr.io/zaproxy/zaproxy:2.16.1}"
ZAP_OUT="${ZAP_OUT:-$HERE/reports}"
ZAP_MINUTES="${ZAP_MINUTES:-2}"
TARGETS="${TARGETS:-/ /offer /privacy /admin/login /org/login /billing/return /twa/}"

if [ -z "$BASE_URL" ]; then
  echo "BASE_URL is required, e.g. BASE_URL=https://staging.example.com" >&2
  exit 3
fi
BASE_URL="${BASE_URL%/}"

mkdir -p "$ZAP_OUT"
# The image runs as the user "zap" (uid 1000): it must be able to write into the mounted directory.
chmod a+rwx "$ZAP_OUT"
cp "$HERE/zap-baseline.conf" "$ZAP_OUT/zap-baseline.conf"

worst=0
reports=()

for target in $TARGETS; do
  slug="$(printf '%s' "$target" | tr -c 'A-Za-z0-9' '-' | sed 's/^-*//; s/-*$//')"
  slug="${slug:-root}"
  echo "=== ZAP baseline: $BASE_URL$target"

  docker run --rm -t -v "$ZAP_OUT:/zap/wrk:rw" "$ZAP_IMAGE" \
    zap-baseline.py -t "$BASE_URL$target" -c zap-baseline.conf -m "$ZAP_MINUTES" \
    -r "zap-$slug.html" -J "zap-$slug.json" -w "zap-$slug.md"
  code=$?

  # zap-baseline.py: 0 clean, 1 FAIL rule hit, 2 WARN only, 3 tool error.
  if [ "$code" -ge 3 ]; then
    echo "ZAP failed on $target (exit $code)" >&2
    exit 3
  fi
  [ "$code" -eq 1 ] && worst=1
  reports+=("$ZAP_OUT/zap-$slug.json")
done

python3 -I "$HERE/check_reports.py" "${reports[@]}"
check=$?

if [ "$worst" -eq 1 ] && [ "$check" -eq 0 ]; then
  echo "A FAIL rule of tests/security/zap/zap-baseline.conf was hit: see the reports in $ZAP_OUT" >&2
  exit 1
fi

echo "Reports: $ZAP_OUT (zap-*.html for reading, zap-*.json for the record)"
exit "$check"
