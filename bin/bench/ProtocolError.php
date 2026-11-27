<?php

declare(strict_types=1);

namespace Prism\Harness\Bench;

use RuntimeException;

/** A line broke `prism-bench-driver/1`; the run ends with `protocol_error`. */
final class ProtocolError extends RuntimeException {}
