#!/bin/sh
# Validate the branch the property nightly checks out (G-387 round 16).
#
#   sh tools/property-ref.sh <ref> <default-branch>
#
# Prints the ref and exits 0 only for the repository's default branch, by
# its exact name, or a release line: `release/` followed by one segment of
# letters, digits, `.`, `_` or `-` that does not start with `.` or `-` and
# holds no `..` (for example release/0.26.x). Anything else (empty, a SHA, a
# tag, `refs/...`, another branch, a path with more segments, spaces, a
# leading `-` an option parser could read) exits 1 with a message on stderr.
# There is no default: a ref that was given and is not valid fails the job
# rather than testing a different branch than the one asked for.
set -eu

if [ "$#" -ne 2 ]; then
    echo "property-ref: give exactly a ref and the default branch" >&2
    exit 1
fi
ref=$1
default=$2
if [ -z "$default" ]; then
    echo "property-ref: the default branch is unknown" >&2
    exit 1
fi
if [ "$ref" = "$default" ]; then
    echo "$ref"
    exit 0
fi
case "$ref" in
    release/*) line=${ref#release/} ;;
    *)
        echo "property-ref: '$ref' is neither the default branch nor a release/* line" >&2
        exit 1
        ;;
esac
case "$line" in
    '' | .* | -* | *..* | *[!A-Za-z0-9._-]*)
        echo "property-ref: '$ref' is not a release line name" >&2
        exit 1
        ;;
esac
echo "$ref"
