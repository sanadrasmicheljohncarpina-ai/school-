<?php
// Retired legacy evaluation form. The authenticated student workflow now
// lives in student/student_dashboard.php.
http_response_code(410);
header('Content-Type: text/plain; charset=utf-8');
exit("This legacy evaluation page has been retired. Please use the current student portal.\n");
