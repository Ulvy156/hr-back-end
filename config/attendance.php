<?php

return [
    'work_start_time' => env('ATTENDANCE_WORK_START_TIME', '08:00:00'),
    'work_end_time' => env('ATTENDANCE_WORK_END_TIME', '17:00:00'),
    'break_start_time' => env('ATTENDANCE_BREAK_START_TIME', '12:00:00'),
    'break_end_time' => env('ATTENDANCE_BREAK_END_TIME', '13:00:00'),
    'employee_history_per_page' => 15,
    'management_history_per_page' => 20,
    'correction_requests_per_page' => 20,
    'audit_logs_per_page' => 20,
];
