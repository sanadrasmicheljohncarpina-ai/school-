#Requires -RunAsAdministrator
[CmdletBinding()]
param(
    [string]$XamppRoot = 'C:\\xampp',
    [string]$AppPath = 'C:\\xampp\\htdocs\\index',
    [switch]$OpenFirewall,
    [switch]$RestartApache
)

$ErrorActionPreference = 'Stop'

function Write-Step([string]$Message) {
    Write-Host ('[school-evaluation] ' + $Message) -ForegroundColor Cyan
}

function Ensure-TextLine {
    param(
        [string]$Path,
        [string]$Line
    )

    $content = Get-Content -Raw -LiteralPath $Path
    if ($content -notmatch [regex]::Escape($Line)) {
        Add-Content -LiteralPath $Path -Value (([Environment]::NewLine) + $Line + ([Environment]::NewLine))
    }
}

Write-Step 'Checking paths...'

$ApacheExe = Join-Path $XamppRoot 'apache\\bin\\httpd.exe'
$HttpdConf = Join-Path $XamppRoot 'apache\\conf\\httpd.conf'
$VhostsConf = Join-Path $XamppRoot 'apache\\conf\\extra\\httpd-vhosts.conf'
$HostsFile = Join-Path $env:SystemRoot 'System32\\drivers\\etc\\hosts'

foreach ($path in @($ApacheExe, $HttpdConf, $VhostsConf, $AppPath)) {
    if (-not (Test-Path -LiteralPath $path)) {
        throw ('Required path not found: ' + $path)
    }
}

$backupDir = Join-Path $XamppRoot 'apache\\conf\\backup-before-school-evaluation'
New-Item -ItemType Directory -Force -Path $backupDir | Out-Null

$stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$httpdBackup = Join-Path $backupDir ('httpd.conf.' + $stamp + '.bak')
$vhostsBackup = Join-Path $backupDir ('httpd-vhosts.conf.' + $stamp + '.bak')

Copy-Item -LiteralPath $HttpdConf -Destination $httpdBackup -Force
Copy-Item -LiteralPath $VhostsConf -Destination $vhostsBackup -Force

Write-Step 'Enabling Apache virtual-host configuration...'

$httpd = Get-Content -Raw -LiteralPath $HttpdConf
$includePattern = '(?m)^\\s*#\\s*Include\\s+conf/extra/httpd-vhosts\\.conf\\s*$'

if ($httpd -match $includePattern) {
    $httpd = [regex]::Replace($httpd, $includePattern, 'Include conf/extra/httpd-vhosts.conf', 1)
    Set-Content -LiteralPath $HttpdConf -Value $httpd -Encoding UTF8
}
elseif ($httpd -notmatch '(?m)^\\s*Include\\s+conf/extra/httpd-vhosts\\.conf\\s*$') {
    Add-Content -LiteralPath $HttpdConf -Value (([Environment]::NewLine) + 'Include conf/extra/httpd-vhosts.conf' + ([Environment]::NewLine))
}

Write-Step 'Adding school-evaluation.com virtual host...'

$vhosts = Get-Content -Raw -LiteralPath $VhostsConf

if ($vhosts -notmatch '(?i)ServerName\\s+school-evaluation\\.com\\s*$') {
    $appPathForApache = $AppPath -replace '\\\\','/'
    $block = @'

# Evaluation System - school-evaluation.com
<VirtualHost *:80>
    ServerName school-evaluation.com
    ServerAlias www.school-evaluation.com

    DocumentRoot "APP_PATH_PLACEHOLDER"

    <Directory "APP_PATH_PLACEHOLDER">
        AllowOverride All
        Options FollowSymLinks
        Require all granted
    </Directory>

    ErrorLog "logs/school-evaluation-error.log"
    CustomLog "logs/school-evaluation-access.log" common
</VirtualHost>
'@
    $block = $block.Replace('APP_PATH_PLACEHOLDER', $appPathForApache)
    Add-Content -LiteralPath $VhostsConf -Value $block
}

Write-Step 'Adding local Hosts-file entries...'

Ensure-TextLine -Path $HostsFile -Line '127.0.0.1 school-evaluation.com'
Ensure-TextLine -Path $HostsFile -Line '127.0.0.1 www.school-evaluation.com'

Write-Step 'Validating Apache configuration...'

$test = & cmd.exe /c ""$ApacheExe" -t 2>&1"
$apacheTestExitCode = $LASTEXITCODE
Write-Host ($test | Out-String).Trim()
if ($apacheTestExitCode -ne 0) {
    throw ('Apache configuration validation failed. Backups are available in: ' + $backupDir)
}

if ($OpenFirewall) {
    Write-Step 'Opening TCP/80 in Windows Firewall (HTTP only)...'

    $ruleName = 'XAMPP Apache HTTP - school-evaluation.com'
    $existing = Get-NetFirewallRule -DisplayName $ruleName -ErrorAction SilentlyContinue

    if (-not $existing) {
        New-NetFirewallRule -DisplayName $ruleName -Direction Inbound -Protocol TCP -LocalPort 80 -Action Allow -Profile Domain,Private,Public -Description 'Allow HTTP traffic to Apache for school-evaluation.com' | Out-Null
    }
}

if ($RestartApache) {
    Write-Step 'Restarting Apache...'

    $apacheService = Get-Service -Name 'Apache2.4' -ErrorAction SilentlyContinue

    if ($apacheService) {
        Restart-Service -Name 'Apache2.4' -Force
        Write-Host 'Apache2.4 Windows service restarted.'
    }
    else {
        Write-Warning 'Apache is not installed as a Windows service on this XAMPP installation.'
        Write-Host 'Use the XAMPP Control Panel to Stop then Start Apache.'
    }
}

Write-Host ''
Write-Host 'Server preparation completed.' -ForegroundColor Green
Write-Host ('Application path: ' + $AppPath)
Write-Host 'Test locally on this PC: http://school-evaluation.com/'
Write-Host ('Apache config backup: ' + $backupDir)
Write-Host ''
Write-Host 'Next networking steps: DNS -> router port forwarding -> HTTPS/SSL.'
Write-Host 'Do not expose MySQL port 3306 to the internet.'