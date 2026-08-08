<?php

$profile = env('DIOCESE_PROFILE', 'wis');

// A missing/invalid profile must never take the app down: fall back to the
// default WIS profile. This is evaluated at config-cache time (and again
// whenever the config is rebuilt), so the guard runs exactly when it needs
// to — a broken DIOCESE_PROFILE still boots, defaulting to 'wis'.
if (! is_file(__DIR__."/profiles/{$profile}.php")) {
    $profile = 'wis';
}

return array_merge(
    ['key' => $profile],
    require __DIR__."/profiles/{$profile}.php",
);
