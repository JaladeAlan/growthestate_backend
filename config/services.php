    <?php

    return [

        /*
        |--------------------------------------------------------------------------
        | Third Party Services
        |--------------------------------------------------------------------------
        |
        | This file is for storing the credentials for third party services such
        | as Mailgun, Postmark, AWS and more. This file provides the de facto
        | location for this type of information, allowing packages to have
        | a conventional file to locate the various service credentials.
        |
        */

        'postmark' => [
            'token' => env('POSTMARK_TOKEN'),
        ],

        'ses' => [
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
        ],

        'resend' => [
            'key' => env('RESEND_API_KEY'),
        ],

        'mailgun' => [
            'domain'   => env('MAILGUN_DOMAIN'),
            'secret'   => env('MAILGUN_SECRET'),
            'endpoint' => env('MAILGUN_ENDPOINT', 'api.eu.mailgun.net'),
        ],

        'mailtrap' => [
            'api_key' => env('MAILTRAP_API_KEY'),
        ],

        'mailersend' => [
            'api_key' => env('MAILERSEND_API_KEY'),
        ],

        'slack' => [
            'notifications' => [
                'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
                'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
            ],
        ],

        'paystack' => [
        'secret_key' => env('PAYSTACK_SECRET_KEY'),
        'public_key' => env('PAYSTACK_PUBLIC_KEY'),
        'webhook_secret' => env('PAYSTACK_WEBHOOK_SECRET'),
        'test_mode' => env('PAYSTACK_TEST_MODE', true), // Default to test mode
        ],

        'monnify' => [
        'api_key'       => env('MONNIFY_API_KEY'),
        'secret_key'    => env('MONNIFY_SECRET_KEY'),
        'contract_code' => env('MONNIFY_CONTRACT_CODE'),
        'base_url'      => env('MONNIFY_BASE_URL', 'https://sandbox.monnify.com'),
        ],

        'opay' => [
            'public_key'  => env('OPAY_PUBLIC_KEY'),
            'secret_key'  => env('OPAY_SECRET_KEY'),
            'merchant_id' => env('OPAY_MERCHANT_ID'),
            'sandbox'     => env('OPAY_SANDBOX', true),   // false in production
            'country'     => env('OPAY_COUNTRY', 'NG'),   // NG for Nigeria
            'currency'    => env('OPAY_CURRENCY', 'NGN'),
            'base_url'    => env('OPAY_BASE_URL', 'https://testapi.opaycheckout.com'),
        ],
        
        'openai' => ['key' => env('OPENAI_API_KEY')],

        'telegram' => [
            'bot_token' => env('TELEGRAM_BOT_TOKEN'),
            'chat_id'   => env('TELEGRAM_CHAT_ID'),
        ],
    ];
