#!/bin/sh
# Tests for property-examples.sh, both directions: every bad value must be
# refused (exit 1, nothing on stdout), every good one accepted unchanged.
set -eu
here="$(cd "$(dirname "$0")" && pwd)"
script="$here/property-examples.sh"
pass=0
fail=0

expect() {
    want=$1
    value=$2
    if out=$(sh "$script" "$value" 2>/dev/null); then got=0; else got=1; fi
    if [ "$want" = ok ] && [ "$got" = 0 ] && [ "$out" = "$value" ]; then
        pass=$((pass + 1))
    elif [ "$want" = refused ] && [ "$got" = 1 ] && [ -z "$out" ]; then
        pass=$((pass + 1))
    else
        fail=$((fail + 1))
        echo "FAIL: '$value' should be $want (exit $got, stdout '$out')" >&2
    fi
}

for good in 1 2 150 2000 5000 100000; do
    expect ok "$good"
done
for bad in '' 0 00 007 -1 -5000 1.5 2000.0 1e3 0x10 abc ' 5' '5 ' '+5' 100001 999999 1000000 99999999999999999999; do
    expect refused "$bad"
done
# No argument, and two arguments, are refused too.
if sh "$script" >/dev/null 2>&1; then fail=$((fail + 1)); echo "FAIL: no argument accepted" >&2; else pass=$((pass + 1)); fi
if sh "$script" 1 2 >/dev/null 2>&1; then fail=$((fail + 1)); echo "FAIL: two arguments accepted" >&2; else pass=$((pass + 1)); fi

echo "property-examples: $pass passed, $fail failed"
[ "$fail" -eq 0 ]
