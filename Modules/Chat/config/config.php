<?php

return [
    'name' => 'Chat',

    // Firebase Realtime Database Configuration
    'firebase_url' => env('FIREBASE_DATABASE_URL', 'https://your-project.firebaseio.com'),
    'firebase_server_key' => env('FIREBASE_SERVER_KEY', ''),

    // Chat settings
    'max_file_size' => 10240, // 10MB in KB
    'allowed_file_types' => ['image/jpeg', 'image/png', 'image/gif', 'application/pdf', 'application/msword'],
];
