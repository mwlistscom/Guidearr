<?php
return [
    // Application version. Single source of truth is the VERSION file at the
    // project root — bump it on every change (especially before pushing to
    // GitHub). Shown on the admin Status page.
    'version' => trim(@file_get_contents(base_path('VERSION')) ?: '') ?: 'dev',

    'admin' => [
        // URL segment for the admin panel: 'admin' => /admin. Override with ADMIN_PATH
        // to a hard-to-guess value to reduce automated probing of /admin.
        'path' => env('ADMIN_PATH', 'admin'),
        'email' => env('ADMIN_EMAIL'),
        'password' => env('ADMIN_PASSWORD'),
    ],
    'registration_requires_approval' => env('REGISTRATION_REQUIRES_APPROVAL', false),
];
