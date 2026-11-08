# Online Deployment — school-evaluation.com

This repository is the production source for the PHP/MySQL Evaluation System.

## What this changes

The application remains usable from XAMPP for development, but it is now prepared for a real PHP/MySQL hosting environment.

Production URL:

**https://school-evaluation.com**

The application uses **Asia/Manila** for evaluation scheduling through the existing shared scheduling service.

## 1. Register/activate the domain and hosting

The domain name must be registered with a domain registrar and pointed to a PHP-capable hosting account or VPS. GitHub itself does not execute this PHP application or host its MySQL database.

At the registrar, point school-evaluation.com to the server provided by the hosting company, normally with an A record. Configure www only when you intend to serve that hostname.

Enable an SSL/TLS certificate for school-evaluation.com and use the HTTPS URL in production.

## 2. Create the production database

Create a MySQL/MariaDB database and a dedicated database user in the hosting control panel.

Do not use the XAMPP root account on production.

Import:

evaluation.sql

into the new production database.

## 3. Upload the application

Upload the contents of this repository to the domain's document root, commonly something like public_html/.

Do not upload the .git directory as part of a normal shared-hosting deployment.

The domain document root should contain:

index.php

and the folders:

admin/, dean/, principal/, faculty/, student/, shared/, cron/

## 4. Create the production runtime configuration

Copy:

.env.example.php

to:

.env.php

in the project root.

Set the real hosting values:

    return [
        'APP_ENV'      => 'production',
        'APP_URL'      => 'https://school-evaluation.com',
        'APP_DOMAIN'   => 'school-evaluation.com',
        'APP_TIMEZONE' => 'Asia/Manila',

        'DB_HOST' => 'your_database_host',
        'DB_PORT' => 3306,
        'DB_NAME' => 'your_database_name',
        'DB_USER' => 'your_database_user',
        'DB_PASS' => 'your_database_password',
    ];

.env.php is ignored by Git and blocked from direct web access by the repository .htaccess.

## 5. Database connections

The Admin, Dean, Principal, Faculty/Staff, and Student database entry points now read their database settings from the shared runtime configuration.

Local XAMPP defaults remain:

- host: localhost
- user: root
- password: empty
- database: evaluation
- port: 3306

For production, the hosting configuration overrides those values.

## 6. Email / password recovery

The production SMTP sender should also be configured outside the source code. Supply the SMTP host, port, username, password/app password, and sender name.

Do not commit real SMTP credentials.

## 7. Evaluation scheduling

The existing schedule service uses:

**Asia/Manila**

and treats the evaluation window as:

[start, end)

That means:

- exact start time = submissions open
- exact closing time = submissions blocked
- Force Open = always open
- Force Closed = always closed

Run the existing scheduler every minute on the production host when you want the database period state synchronized even when no user is browsing:

    php /path/to/public_html/cron/sync_evaluation_schedule.php

On cPanel, use Cron Jobs and configure it to run every minute.

## 8. File permissions

A typical shared-hosting setup is:

- directories: 755
- PHP/CSS/JS files: 644

The upload directory must be writable by PHP when the application saves profile images or other allowed uploads.

## 9. First production smoke test

After DNS, SSL, hosting, and database setup, test:

https://school-evaluation.com/

Then verify each portal:

- EA / Admin login
- Dean login
- Principal login
- Faculty login
- Staff login
- Student login

Also test account registration/approval, scheduled opening/closing, evaluation submission, reports, notifications, uploads, and password recovery.

## 10. Important separation

Keep development and production data separate.

Development:
XAMPP → local MySQL → test accounts/data

Production:
school-evaluation.com → hosting MySQL → real school data

Do not use the public GitHub repository as the production database.
