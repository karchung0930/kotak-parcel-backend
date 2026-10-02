<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Server Side Rendering
    |--------------------------------------------------------------------------
    |
    | These options configures if and how Inertia uses Server Side Rendering
    | to pre-render each initial request made to your application's pages
    | so that server rendered HTML is delivered for the user's browser.
    |
    | See: https://inertiajs.com/server-side-rendering
    |
    */

    'ssr' => [
        'enabled' => true,
        'url' => 'http://127.0.0.1:13714',
        // 'bundle' => base_path('bootstrap/ssr/ssr.mjs'),

    ],

    /*
    |--------------------------------------------------------------------------
    | History
    |--------------------------------------------------------------------------
    |
    | Pages carry personal data (addresses, phone numbers), so the copies the
    | browser keeps in its history are encrypted. Signing out clears the key,
    | so the Back button cannot show them again. Encryption needs HTTPS (or
    | localhost); on plain HTTP the browser falls back to storing them as is.
    |
    */

    'history' => [

        'encrypt' => (bool) env('INERTIA_ENCRYPT_HISTORY', true),

    ],

    /*
    |--------------------------------------------------------------------------
    | Pages
    |--------------------------------------------------------------------------
    |
    | These options configure how Inertia discovers page components on the
    | filesystem. The paths and extensions are used to locate components
    | when rendering responses and during testing assertions.
    |
    | The Vue pages live in the frontend repository, checked out next to
    | this one. The feature tests use this path to check that every page a
    | controller renders exists.
    |
    */

    'pages' => [

        'paths' => [
            rtrim((string) env('FRONTEND_PATH', base_path('../kotak-parcel-frontend')), '/\\').'/resources/js/pages',
        ],

        'extensions' => [
            'js',
            'jsx',
            'svelte',
            'ts',
            'tsx',
            'vue',
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Testing
    |--------------------------------------------------------------------------
    |
    | The values described here are used to locate Inertia components on the
    | filesystem. For instance, when using `assertInertia`, the assertion
    | attempts to locate the component as a file relative to the paths.
    |
    */

    'testing' => [

        'ensure_pages_exist' => true,

    ],

];
