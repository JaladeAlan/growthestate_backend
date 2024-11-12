<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Authentication Defaults
    |--------------------------------------------------------------------------
    |
    | This option controls the default authentication "guard" and password
    | reset options for your application. You may change these defaults as
    | required, but they're a great starting point for most applications.
    |
    */

    'defaults' => [
        'guard' => 'web',
        'passwords' => 'users',
    ],

    /*
    |--------------------------------------------------------------------------
    | Authentication Guards
    |--------------------------------------------------------------------------
    |
    | Here you may define every authentication guard for your application.
    | Of course, a great default configuration has been defined for you
    | here which uses session storage and the Eloquent user provider.
    |
    | All authentication drivers have a user provider. This defines how
    | the users are actually retrieved out of your database or other
    | storage mechanisms used by this application.
    |
    | Supported: "session", "token", "basic", "jwt"
    |
    */

    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'users',
        ],

        'api' => [
            'driver' => 'jwt', // Use JWT for API authentication
            'provider' => 'users',
            'hash' => false,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | User Providers
    |--------------------------------------------------------------------------
    |
    | All authentication drivers have a user provider. This defines how
    | the users are actually retrieved out of your database or other
    | storage mechanisms used by this application.
    |
    | If you have multiple user tables or models you can configure multiple
    | sources which represents each model / table. These sources may then
    | be assigned to any extra authentication guards you have defined.
    |
    | Supported: "database", "eloquent"
    |
    */

    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model' => App\Models\User::class,
        ],
        // 'users' => [
        //     'driver' => 'database',
        //     'table' => 'users',
        // ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Resetting Passwords
    |--------------------------------------------------------------------------
    |
    | Here you may define the settings for password reset notifications.
    | You may configure multiple password reset configurations if you
    | have more than one user table or model in the application.
    |
    | The expiration time for the reset token is set to 60 minutes by default.
    | You can change this value as needed.
    |
    */

    'passwords' => [
        'users' => [
            'provider' => 'users',
            'table' => 'password_resets',
            'expire' => 60,
            'throttle' => 60,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | JWT Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may define the settings for JWT configuration.
    |
    */

    'jwt' => [
        'secret' => env('JWT_SECRET'), // Set your JWT secret key in .env
        'ttl' => 60, // Time to live for tokens (in minutes)
        'refresh_ttl' => 20160, // Refresh token time to live (in minutes)
    ],

];
