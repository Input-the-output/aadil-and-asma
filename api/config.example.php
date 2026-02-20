<?php
/**
 * Configuration — Copy to config.php and update values.
 * NEVER commit config.php to version control.
 */
return [
    'security' => [
        'token_secret'       => 'CHANGE_THIS_TO_A_RANDOM_STRING',
        'token_ttl'          => 600,
        'rate_limit_dir'     => __DIR__ . '/rate_limits/',
        'lookup_rpm'         => 10,
        'submit_rpm'         => 5,
        'allowed_origins'    => [
            'http://localhost',
            'https://localhost',
            'https://adilandasma.com',
            'https://www.adilandasma.com',
        ],
    ],
    'paths' => [
        'guests_json' => __DIR__ . '/../data/guests.json',
        'rsvps_json'  => __DIR__ . '/../data/rsvps.json',
    ],
];
