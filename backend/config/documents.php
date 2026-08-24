<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Maximum upload size, in kilobytes
    |--------------------------------------------------------------------------
    |
    | 20 MB by default. Configurable because a scanner's output size is a
    | deployment fact rather than an application rule — an office scanning
    | certificates at 600 dpi produces larger files than one scanning at 200.
    |
    | This is the application's limit and not the only one: PHP's own
    | `upload_max_filesize` and `post_max_size`, and any reverse proxy in front
    | of it, each impose their own. Raising this alone does not raise those, and
    | a file that exceeds the server limit never reaches validation at all —
    | it fails before Laravel sees it, which is a deployment problem rather than
    | a validation error.
    |
    */

    'max_upload_kilobytes' => (int) env('DOCUMENTS_MAX_UPLOAD_KILOBYTES', 20480),

];
