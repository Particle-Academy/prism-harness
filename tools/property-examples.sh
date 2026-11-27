#!/bin/sh
# Validate the property test's example count (G-392 QC nightly).
#
#   sh tools/property-examples.sh <value>
#
# Prints the count and exits 0 only for a whole number from 1 to 100000,
# written in plain decimal digits with no leading zero. Anything else (empty,
# 0, negative, fractional, a sign, spaces, hex, exponent, or larger than the
# cap) exits 1 with a message on stderr. There is no default: a value that
# was given and is not valid fails the job rather than running a different
# number of examples than the one asked for.
set -eu

if [ "$#" -ne 1 ]; then
    echo "property-examples: give exactly one value" >&2
    exit 1
fi
value=$1
case "$value" in
    '' | *[!0-9]* | 0*)
        echo "property-examples: '$value' is not a whole number >= 1" >&2
        exit 1
        ;;
esac
# At most six digits, then the cap: a count no run could finish is refused.
if [ "${#value}" -gt 6 ] || [ "$value" -gt 100000 ]; then
    echo "property-examples: '$value' is larger than 100000" >&2
    exit 1
fi
echo "$value"
