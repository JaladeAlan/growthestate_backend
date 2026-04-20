<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Trusted Proxies
    |--------------------------------------------------------------------------
    |
    | Set to '*' on Render (and other PaaS/load-balanced platforms) so that
    | Laravel reads the real client IP from X-Forwarded-For rather than
    | seeing the load balancer's internal IP on every request.
    |
    | If you ever need to restrict this, replace '*' with an array of your
    | known proxy IP ranges, e.g. ['10.0.0.0/8', '172.16.0.0/12'].
    |
    */

    'proxies' => env('TRUSTED_PROXIES', '*'),

    /*
    |--------------------------------------------------------------------------
    | Trusted Proxy Headers
    |--------------------------------------------------------------------------
    |
    | Which forwarded headers to trust. The combination below covers Render,
    | AWS ELB, Cloudflare, and most other reverse proxies.
    |
    */

    'headers' => \Illuminate\Http\Request::HEADER_X_FORWARDED_FOR |
                 \Illuminate\Http\Request::HEADER_X_FORWARDED_HOST |
                 \Illuminate\Http\Request::HEADER_X_FORWARDED_PORT |
                 \Illuminate\Http\Request::HEADER_X_FORWARDED_PROTO |
                 \Illuminate\Http\Request::HEADER_X_FORWARDED_AWS_ELB,

];