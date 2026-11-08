<?php
// Retired legacy report page. Current faculty and staff evaluation results
// are available from their authenticated dashboards.
http_response_code(410);
header('Content-Type: text/plain; charset=utf-8');
exit("This legacy report page has been retired. Please use the current faculty or staff portal.\n");
