<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Business context limits
    |--------------------------------------------------------------------------
    |
    | title_max / content_max bound a single entry and are configurable via
    | .env. total_max is the fixed budget for all active unscoped entries: it
    | MUST match agent/core.py::_format_business_context (max_chars=6000) so
    | the rendered block can never silently truncate. Per-entry caps need not
    | sum under it — the total validator is the single arbiter. A mismatch is
    | surfaced by a boot-time warning in AppServiceProvider.
    |
    */

    'title_max' => (int) env('BUSINESS_CONTEXT_TITLE_MAX', 200),

    'content_max' => (int) env('BUSINESS_CONTEXT_CONTENT_MAX', 5000),

    'total_max' => 6000,
];
