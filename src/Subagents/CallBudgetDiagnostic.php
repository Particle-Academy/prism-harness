<?php

declare(strict_types=1);

namespace Prism\Harness\Subagents;

/** Content-free reasons that leave admission conservative rather than refusing it. */
enum CallBudgetDiagnostic: string
{
    case ProjectedImageFormatUnknown = 'projected_image_format_unknown';
}
