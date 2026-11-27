#!/bin/sh
# Tests for property-ref.sh, both directions: every bad ref must be refused
# (exit 1, nothing on stdout), every good one accepted unchanged.
set -eu
here="$(cd "$(dirname "$0")" && pwd)"
script="$here/property-ref.sh"
pass=0
fail=0

expect() {
    want=$1
    value=$2
    if out=$(sh "$script" "$value" main 2>/dev/null); then got=0; else got=1; fi
    if [ "$want" = ok ] && [ "$got" = 0 ] && [ "$out" = "$value" ]; then
        pass=$((pass + 1))
    elif [ "$want" = refused ] && [ "$got" = 1 ] && [ -z "$out" ]; then
        pass=$((pass + 1))
    else
        fail=$((fail + 1))
        echo "FAIL: '$value' should be $want (exit $got, stdout '$out')" >&2
    fi
}

for good in main release/0.26.x release/0.27.x release/1.0 release/next_line release/a-b; do
    expect ok "$good"
done
for bad in '' ' main' 'main ' Main master develop feature/x refs/heads/main refs/heads/release/0.26.x release/ release \
    release/0.26.x/extra release/.hidden release/-x release/a..b 'release/0.26 x' 'release/0.26.x;rm' 'release/*' \
    v0.26.9 refs/tags/v0.26.9 5f449ecce685d69759dcdc4f88270bac52a22d24 5f449ec refs/pull/1/head '-x' origin/main release/ü; do
    expect refused "$bad"
done
# An unknown default branch, no argument, and three arguments are refused too.
if sh "$script" main '' >/dev/null 2>&1; then fail=$((fail + 1)); echo "FAIL: empty default accepted" >&2; else pass=$((pass + 1)); fi
if sh "$script" >/dev/null 2>&1; then fail=$((fail + 1)); echo "FAIL: no argument accepted" >&2; else pass=$((pass + 1)); fi
if sh "$script" main main x >/dev/null 2>&1; then fail=$((fail + 1)); echo "FAIL: three arguments accepted" >&2; else pass=$((pass + 1)); fi

echo "property-ref: $pass passed, $fail failed"
[ "$fail" -eq 0 ]
