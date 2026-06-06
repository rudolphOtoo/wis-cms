<?php

return [
    /*
    | Church-wide settings. Editable here; can be promoted to a database-backed
    | Settings screen later without changing callers.
    */

    'name' => env('CHURCH_NAME', 'Wesleyan International Society'),

    'birthday' => [
        // Master switch for the automated daily birthday greeting.
        'enabled' => env('BIRTHDAY_GREETINGS_ENABLED', true),

        // Channel: 'sms', 'email', or 'both'.
        'channel' => env('BIRTHDAY_GREETINGS_CHANNEL', 'sms'),

        // {first_name} and {church} are substituted at send time.
        'message' => env(
            'BIRTHDAY_GREETINGS_MESSAGE',
            'Happy birthday, {first_name}! The {church} family celebrates with you today and prays God\'s richest blessings over your new year. 🎉'
        ),

        'subject' => 'Happy Birthday!',
    ],
];
