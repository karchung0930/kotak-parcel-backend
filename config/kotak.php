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
    | Pricing
    |--------------------------------------------------------------------------
    |
    | Money is stored in sen (RM 1.00 = 100 sen) and weight in grams. The
    | chargeable weight is the greater of the actual and volumetric weight,
    | where volumetric kg = length x width x height (cm) / divisor.
    |
    */

    'volumetric_divisor' => 5000,

    'base_price_sen' => 800,

    'per_kg_sen' => 200,

    /*
    |--------------------------------------------------------------------------
    | Parcel Limits
    |--------------------------------------------------------------------------
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
    | to the sender. Orders that are never dropped off expire after a while.
    |
    */

    'max_failed_attempts' => 3,

    'unclaimed_order_days' => 14,

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
