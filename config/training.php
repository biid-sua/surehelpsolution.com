<?php

return [

    /*
    | Agent University (spec §20B, D42). Lesson files and course covers are private and served
    | through an access-checked route. Large videos are better linked (YouTube / Vimeo, unlisted).
    */
    'disk' => env('TRAINING_DISK', 'local'),

    // Largest lesson file accepted, in megabytes. PHP's upload_max_filesize and post_max_size must allow it.
    'max_upload_mb' => (int) env('TRAINING_MAX_UPLOAD_MB', 50),

];
