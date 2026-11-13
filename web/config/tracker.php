<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Office schedule (Bangladesh time)
    |--------------------------------------------------------------------------
    | Tracking can only be started inside one of these windows. The desktop
    | client enforces this as well; the API checks it again so a modified
    | client cannot bypass office rules.
    */
    'timezone' => 'Asia/Dhaka',

    'windows' => [
        ['start' => '09:00', 'end' => '13:15'],
        ['start' => '14:15', 'end' => '18:30'],
    ],

    'idle_threshold_minutes' => 5,

    'screenshot_interval_minutes' => 10,

    'breaks' => [
        'asor' => [
            'label' => 'Asor Namaz',
            'minutes' => 20,
        ],
        'maghrib' => [
            'label' => 'Maghrib Namaz',
            'minutes' => 15,
        ],
        'toilet' => [
            'label' => 'Toilet Break',
            'minutes' => 15,
        ],
    ],
];
