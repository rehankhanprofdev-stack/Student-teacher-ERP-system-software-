<?php
// Database and app settings. Override with environment variables in production.
return [
    'db_host'   => getenv('DB_HOST') ?: '127.0.0.1',
    'db_port'   => getenv('DB_PORT') ?: '3306',
    'db_name'   => getenv('DB_NAME') ?: 'campusos',
    'db_user'   => getenv('DB_USER') ?: 'root',
    'db_pass'   => getenv('DB_PASS') ?: '',
    'app_name'  => 'Maulana Azad College Portal',
    'college'   => getenv('COLLEGE_NAME') ?: 'Maulana Azad College',
    'pass_mark' => 40,     // minimum % per subject to pass
    'min_att'   => 75,     // minimum attendance % before a warning is shown
    'demo_mode' => getenv('DEMO_MODE') !== '0', // shows demo logins on the sign-in page; set DEMO_MODE=0 in production
];
