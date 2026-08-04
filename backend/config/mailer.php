<?php
return [
    'host'       => getenv('SMTP_HOST') ?: 'smtp.gmail.com',
    'port'       => getenv('SMTP_PORT') ?: 587,
    'encryption' => getenv('SMTP_ENCRYPTION') ?: 'tls',          
    'username'   => getenv('SMTP_USERNAME') ?: 'elysenda69@gmail.com',
    'password'   => getenv('SMTP_PASSWORD') ?: 'omcm gjbg eyqt hutc', 
    'from_email' => getenv('SMTP_FROM_EMAIL') ?: 'elysenda69@gmail.com',
    'from_name'  => getenv('SMTP_FROM_NAME') ?: 'GarageManager',
    'smtp_skip_cert_verify' => getenv('SMTP_SKIP_CERT_VERIFY') === 'true' ? true : true,
];
