<?php

return [

    'defaults' => [
        'guard'     => 'api',
        'passwords' => 'users',
    ],

    'guards' => [
        'web' => [
            'driver'   => 'session',
            'provider' => 'users',
        ],

        'api' => [
            'driver'   => 'jwt',
            'provider' => 'jwt_users',
            'hash'     => false,
        ],
    ],

    'providers' => [
        // Standard web provider (used by Sanctum / session auth)
        'users' => [
            'driver' => 'eloquent',
            'model'  => App\Models\User::class,
        ],

        // Custom provider for the JWT guard — looks up users by `uid`
        // instead of `id`, because getJWTIdentifier() returns uid.
        'jwt_users' => [
            'driver' => 'jwt-eloquent',  
            'model'  => App\Models\User::class,
        ],
    ],

    'passwords' => [
        'users' => [
            'provider' => 'users',
            'table'    => 'password_resets',
            'expire'   => 60,
            'throttle' => 60,
        ],
    ],

];