<?php

declare(strict_types=1);

namespace Prism\Harness\Bench;

use RuntimeException;

/** The `start` line cannot be run as given; the run ends `refused` before any model call. */
final class InvalidStart extends RuntimeException {}
