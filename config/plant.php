<?php

return [

    // time in what expires the preference
    'expiration_minutes' => env('PAYMENT_EXPIRATION_MINUTES', 120),

    // indicates if validate if a domain has another quote in pending state
    'validate_pending_quotes' => env('VALIDATE_PENDING_QUOTES', false),

    // default 1 day
    'cash_expiration_minutes' => env('CASH_PAYMENT_EXPIRATION_MINUTES', 1440),

    // margin time to expand range of cash payments
    'margin_post_expiration_minutes' => env('MARGIN_POST_CASH_PAYMENT_TIME_MINUTES', 0),

    // indicates if ignoring vehicle type at getting quotes time
    'ignore_lines' => env('IGNORE_LINES', false),

];