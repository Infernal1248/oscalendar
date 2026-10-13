<?php

return [
    'secret' => env('NPD_API_SECRET'),
    // Fixed onboarding boundary, never evaluated as "now" at deployment.
    'payments_from' => '2026-10-13T10:32:56+00:00',
];
