# Windows/XAMPP production-server setup

This folder prepares a Windows PC running XAMPP to serve the existing Evaluation System from:

https://school-evaluation.com

The current project remains at C:\\xampp\\htdocs\\index by default.

## What the setup script does

setup-xampp-server.ps1:

1. Verifies the XAMPP and application paths.
2. Backs up Apache configuration files before changing them.
3. Enables Apache's virtual-host configuration include.
4. Creates a virtual host for school-evaluation.com and www.school-evaluation.com.
5. Adds local Windows Hosts-file entries so the domain can be tested on the server PC before public DNS is changed.
6. Validates Apache configuration with httpd.exe -t.
7. Optionally opens TCP/80 in Windows Firewall with -OpenFirewall.
8. Optionally restarts Apache with -RestartApache.

It does not expose MySQL/3306 to the internet.

## Run it on the server PC

Open PowerShell as Administrator, then run:

    Set-ExecutionPolicy -Scope Process Bypass
    cd C:\\path\\to\\project\\server\\windows
    .\\setup-xampp-server.ps1 -OpenFirewall

Then restart Apache in the XAMPP Control Panel if you did not use -RestartApache.

Browse on the server PC to:

    http://school-evaluation.com

You should see the same application served from:

    C:\\xampp\\htdocs\\index

## If XAMPP is installed somewhere else

Example:

    .\\setup-xampp-server.ps1 -XamppRoot 'D:\\xampp' -AppPath 'D:\\xampp\\htdocs\\index' -OpenFirewall

## Production networking still required

This script prepares the PC itself. Public access still requires:

- a public/reachable internet connection;
- router port forwarding for TCP 80 to this PC;
- TCP 443 forwarding after HTTPS is configured;
- DNS for school-evaluation.com pointing to the public IP;
- an SSL/TLS certificate for HTTPS;
- the PC and Apache to remain running.

Do not forward TCP 3306 to the internet.

## Recommended migration model

Keep the application source in GitHub and keep production data only on the server:

    GitHub repository
          |
          v
    C:\\xampp\\htdocs\\index
          |
          +--> Apache/PHP
          |
          +--> MySQL
          |
          +--> image/ and allowed uploads

The production .env.php stays outside GitHub.

## Before switching real school users to the server

Verify:

- admin/EA login
- Dean login
- Principal login
- Faculty login
- Staff login
- Student login
- registration and approval
- evaluation scheduling
- evaluation submission
- reports
- notifications
- uploads
- password recovery
- database backups
- HTTPS

The production database should not be populated from GitHub accidentally. Import the intended database backup into the dedicated MySQL database on the server and use a non-root production database account.