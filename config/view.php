<?php

return [

    /*
    |--------------------------------------------------------------------------
    | View Storage Paths
    |--------------------------------------------------------------------------
    |
    | resources/views/personal is an optional override layer: when it exists,
    | a view there (for example partials/footer.blade.php or layouts/app.blade.php)
    | is used instead of the one with the same name in resources/views. The
    | public release does not ship that directory. The check runs when the
    | config loads, so run `php artisan config:clear` (or config:cache again)
    | after adding or removing it on a server that caches config.
    |
    */

    'paths' => array_values(array_filter([
        is_dir(resource_path('views/personal')) ? resource_path('views/personal') : null,
        resource_path('views'),
    ])),

    /*
    |--------------------------------------------------------------------------
    | Compiled View Path
    |--------------------------------------------------------------------------
    */

    'compiled' => env(
        'VIEW_COMPILED_PATH',
        realpath(storage_path('framework/views'))
    ),

];
