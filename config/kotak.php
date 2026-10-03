<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Business Timezone
    |--------------------------------------------------------------------------
    |
    | Timestamps are stored in UTC. This timezone decides what "today" means
    | for delivery schedules, since every branch operates in Malaysia.
    |
    */

    'timezone' => 'Asia/Kuala_Lumpur',

    /*
    |--------------------------------------------------------------------------
    | Parcel Limits
    |--------------------------------------------------------------------------
    |
    | Weight is stored in grams and money in sen (RM 1.00 = 100 sen). The
    | prices themselves are rate cards, which admins publish on the Rates
    | page (see App\Support\RateCards).
    |
    */

    'max_weight_g' => 30000,

    'max_dimension_cm' => 150,

    /*
    |--------------------------------------------------------------------------
    | Delivery Rules
    |--------------------------------------------------------------------------
    |
    | A parcel may be rescheduled after a failed delivery until it reaches
    | the maximum number of failed attempts, after which it must be returned
    | to the sender. Orders that are never dropped off expire after a while,
    | and the customer is reminded a few days before (0 = no reminder).
    |
    | These are the defaults: admins change them on the Site settings page, and
    | App\Support\Settings reads the saved values.
    |
    */

    'max_failed_attempts' => 3,

    'unclaimed_order_days' => 7,

    'drop_off_reminder_days_before' => 2,

    /*
    |--------------------------------------------------------------------------
    | Reverse Proxy
    |--------------------------------------------------------------------------
    |
    | Set TRUSTED_PROXIES=* when the site sits behind CloudFront, so rate
    | limits see the visitor's IP from X-Forwarded-For. Only that header is
    | trusted: CloudFront passes the viewer's other X-Forwarded-* headers on.
    |
    */

    'trusted_proxies' => env('TRUSTED_PROXIES'),

];
