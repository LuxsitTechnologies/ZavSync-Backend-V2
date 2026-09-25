<?php

return [
    'frontend_url' => env('FRONTEND_URL', 'http://localhost:5173'),
    'document_max_kilobytes' => (int) env('DOCUMENT_MAX_KILOBYTES', 10_240),
    'document_mimes' => ['pdf', 'png', 'jpg', 'jpeg', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'txt'],
    'critical_notification_types' => ['security.login', 'security.password_changed', 'security.membership_suspended'],
];
