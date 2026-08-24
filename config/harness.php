<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Default thread scope
    |--------------------------------------------------------------------------
    |
    | A thread is addressed by participant AND scope, so that one participant
    | can hold several unrelated conversations without them merging. This is
    | the scope used when a caller does not name one.
    |
    */

    'default_scope' => env('HARNESS_DEFAULT_SCOPE', 'default'),

];
