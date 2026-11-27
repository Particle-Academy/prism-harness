"""Whether a published particle-academy/prism release satisfies composer.json.

Used by the `core-floor` job in .github/workflows/tests.yml:

    python3 tools/core_floor.py '<constraint>' <packagist p2 json>

exits 0 when a published stable release satisfies the constraint, and 1 when
none does or when the constraint is not one this check can read. A check
that cannot read the constraint FAILS: a skipped check must never read as a
passed one.

    python3 tools/core_floor.py --self-test

runs the cases at the bottom of this file.
"""

import json
import re
import sys


def satisfying(constraint, versions):
    """The published versions that satisfy `constraint`, or None when the
    constraint cannot be read."""
    # ^0.N: any 0.N.P. ^0.N.M: 0.N.P with P >= M (a caret on 0.N allows
    # patch releases only). Stable releases only; anything else is unread.
    match = re.fullmatch(r"\^0\.(0|[1-9]\d*)(?:\.(0|[1-9]\d*))?", constraint)
    if match is None:
        return None
    minor = int(match.group(1))
    floor = int(match.group(2)) if match.group(2) is not None else 0
    found = []
    for version in versions:
        release = re.fullmatch(r"v?0\.(\d+)\.(\d+)", version)
        if release is not None and int(release.group(1)) == minor and int(release.group(2)) >= floor:
            found.append(version)
    return found


def main(argv):
    if argv[1:] == ["--self-test"]:
        return self_test()
    constraint, path = argv[1], argv[2]
    with open(path, encoding="utf-8") as handle:
        versions = [v["version"] for v in json.load(handle)["packages"]["particle-academy/prism"]]
    found = satisfying(constraint, versions)
    if found is None:
        print(f"::error::Cannot check the core constraint {constraint!r}: this job only reads ^0.N and ^0.N.M.")
        return 1
    if not found:
        print(f"::error::particle-academy/prism {constraint} is not on Packagist yet, so the tests did NOT run (skipped, not passed). Re-run this workflow once it is published.")
        return 1
    print(f"particle-academy/prism {constraint}: {', '.join(found)}")
    return 0


# (constraint, published versions, expected: True = a release satisfies it,
# False = none does, None = the constraint cannot be read)
CASES = [
    ("^0.155", ["v0.155.0"], True),
    ("^0.155", ["v0.154.3"], False),
    ("^0.155", ["v0.156.0"], False),
    ("^0.155.1", ["v0.155.0"], False),
    ("^0.155.1", ["v0.155.0", "v0.155.1"], True),
    ("^0.155.1", ["v0.155.2"], True),
    ("^0.155.1", ["v0.156.0"], False),
    ("^0.155.1", ["v0.155.1-beta1"], False),
    ("^0.155.1", ["dev-main"], False),
    ("^0.155.10", ["v0.155.9"], False),
    ("^0.155.10", ["v0.155.10"], True),
    ("^1.2", ["v1.2.0"], None),
    ("~0.155", ["v0.155.0"], None),
    (">=0.155", ["v0.155.0"], None),
    ("^0.155.1.2", ["v0.155.1"], None),
    ("^0.155.1 || ^0.156", ["v0.155.1"], None),
    ("", ["v0.155.0"], None),
    # ^0.0.M is exactly 0.0.M, as in Composer.
    ("^0.0.3", ["v0.0.3"], True),
    ("^0.0.3", ["v0.0.4"], False),
    ("^0.0.3", ["v0.0.2"], False),
    # Only ASCII digits are digits.
    ("^0.١٥٥", ["v0.155.0"], None),
    ("^0.155.١", ["v0.155.1"], None),
    ("^0.155", ["v0.١٥٥.0"], False),
    ("^0.155", ["v0.155.١"], False),
]


def self_test():
    failed = 0
    for constraint, versions, expected in CASES:
        found = satisfying(constraint, versions)
        got = None if found is None else bool(found)
        if got is not expected:
            failed += 1
            print(f"FAIL {constraint!r} over {versions}: expected {expected}, got {got}")
    print(f"{len(CASES) - failed} of {len(CASES)} core-floor cases pass")
    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main(sys.argv))
