<?php

return [
    'horizon_emails' => array_filter(array_map('trim', explode(',', env('HORIZON_ALLOWED_EMAILS', '')))),
];
