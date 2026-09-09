<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Ajax upload fields (ajax_upload / ajax_multi_upload)
    |--------------------------------------------------------------------------
    |
    | Files are uploaded immediately to the "ajax-upload" route and only the
    | stored path is submitted with the form. Fields may pick a disk and a
    | sub-folder, but only from the whitelist below; the folder is always
    | placed under "base_path" so a field can never write outside of it.
    |
    */

    'disks' => ['public'],

    'default_disk' => 'public',

    'base_path' => 'uploads',

    // Largest single file, in kilobytes. Fields can lower this with max_size.
    'max_size_kb' => 10240,

];
