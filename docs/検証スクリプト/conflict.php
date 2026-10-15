<?php
require '/home/user/nm_attendance/src/functions.php';
require '/home/user/nm_attendance/src/dbconnect.php';
require '/home/user/nm_attendance/src/attendance.php';
echo json_encode(performAttendanceAction($pdo, '2025001', 'check_in', false, time()), JSON_UNESCAPED_UNICODE), "\n";
