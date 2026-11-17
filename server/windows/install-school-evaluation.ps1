#Requires -RunAsAdministrator
[CmdletBinding()]
param(
 [string]$XamppRoot='C:\xampp',
 [string]$InstallPath='C:\xampp\htdocs\index',
 [string]$DatabaseName='evaluation',
 [string]$DatabaseUser='root',
 [string]$DatabaseHost='127.0.0.1',
 [int]$DatabasePort=3306,
 [switch]$SkipDatabaseImport
)
$ErrorActionPreference='Stop'
$RepoZip='https://github.com/sanadrasmicheljohncarpina-ai/school-/archive/refs/heads/main.zip'
$Work=Join-Path $env:TEMP ('school-install-'+[guid]::NewGuid().ToString('N'))
function Step([string]$s){Write-Host $s -ForegroundColor Cyan}
try {
 if($DatabaseName -notmatch '^[A-Za-z0-9_]+$'){throw 'DatabaseName may contain only letters, numbers, and underscores.'}
 Step 'Checking XAMPP'
 $php=Join-Path $XamppRoot 'php\php.exe'; $mysql=Join-Path $XamppRoot 'mysql\bin\mysql.exe'; $htdocs=Join-Path $XamppRoot 'htdocs'
 foreach($p in @($php,$mysql,$htdocs)){if(!(Test-Path $p)){throw "Required XAMPP path not found: $p"}}
 Write-Host ('PHP '+((& $php -r 'echo PHP_VERSION;').Trim())+' detected.')
 Step 'Downloading GitHub source'
 New-Item -ItemType Directory -Path $Work -Force|Out-Null
 $zip=Join-Path $Work 'source.zip'; Invoke-WebRequest -Uri $RepoZip -OutFile $zip
 Expand-Archive -LiteralPath $zip -DestinationPath $Work -Force
 $source=Get-ChildItem $Work -Directory|Where-Object{Test-Path (Join-Path $_.FullName 'index.php')}|Select-Object -First 1
 if(!$source){throw 'Downloaded archive does not contain index.php.'}
 Step 'Installing files'
 if(Test-Path $InstallPath){$backup="$InstallPath.backup-$(Get-Date -Format yyyyMMdd-HHmmss)";Move-Item $InstallPath $backup;Write-Host "Existing installation backed up to $backup"}
 New-Item -ItemType Directory -Path $InstallPath -Force|Out-Null
 Copy-Item (Join-Path $source.FullName '*') $InstallPath -Recurse -Force
 Step 'Writing local database settings'
 $envFile=Join-Path $InstallPath '.env.php'
 if(!(Test-Path $envFile)){
  $sec=Read-Host 'MySQL password for application database user (blank for XAMPP root)' -AsSecureString
  $ptr=[Runtime.InteropServices.Marshal]::SecureStringToBSTR($sec)
  try{$pass=[Runtime.InteropServices.Marshal]::PtrToStringBSTR($ptr)}finally{[Runtime.InteropServices.Marshal]::ZeroFreeBSTR($ptr)}
  $pass=$pass.Replace('\','\\').Replace("'","\'")
  $envContent=@"
<?php
return [
 'APP_ENV'=>'local',
 'APP_URL'=>'http://localhost/index',
 'APP_DOMAIN'=>'localhost',
 'APP_TIMEZONE'=>'Asia/Manila',
 'DB_HOST'=>'$DatabaseHost',
 'DB_PORT'=>$DatabasePort,
 'DB_NAME'=>'$DatabaseName',
 'DB_USER'=>'$DatabaseUser',
 'DB_PASS'=>'$pass',
];
"@
  [System.IO.File]::WriteAllText($envFile, $envContent, [System.Text.UTF8Encoding]::new($false))
 }
 $configPath=Join-Path $InstallPath 'shared\app_config.php'
 if(!(Test-Path $configPath)){
  $config=@'
<?php
function app_config(): array {
 static $cfg=null; if($cfg!==null)return $cfg;
 $defaults=['APP_ENV'=>'local','APP_URL'=>'http://localhost/index','APP_DOMAIN'=>'localhost','APP_TIMEZONE'=>'Asia/Manila','DB_HOST'=>'127.0.0.1','DB_PORT'=>3306,'DB_NAME'=>'evaluation','DB_USER'=>'root','DB_PASS'=>''];
 foreach($defaults as $k=>$v){$e=getenv($k);if($e!==false)$defaults[$k]=$e;}
 $runtimeFile=dirname(__DIR__).'/.env.php';
 $runtime=is_file($runtimeFile)?require $runtimeFile:[];
 if(!is_array($runtime))$runtime=[];
 $cfg=array_merge($defaults,$runtime);
 date_default_timezone_set($cfg['APP_TIMEZONE']?:'Asia/Manila');
 return $cfg;
}
function app_is_production(): bool { return strtolower((string)(app_config()['APP_ENV']??'local'))==='production'; }
'@
  [System.IO.File]::WriteAllText($configPath, $config, [System.Text.UTF8Encoding]::new($false))
 }
 Step 'Database setup'
 if(!$SkipDatabaseImport){
  $sql=Join-Path $InstallPath 'evaluation.sql'
  if(!(Test-Path $sql)){Write-Warning 'No evaluation.sql is included in the current repository snapshot.';$sql=Read-Host 'Full path to your trusted evaluation.sql dump (Enter to skip)'}
  if($sql -and (Test-Path $sql)){
   $sec=Read-Host 'MySQL administrator password for database import' -AsSecureString
   $ptr=[Runtime.InteropServices.Marshal]::SecureStringToBSTR($sec)
   try{$dbpass=[Runtime.InteropServices.Marshal]::PtrToStringBSTR($ptr)}finally{[Runtime.InteropServices.Marshal]::ZeroFreeBSTR($ptr)}
   $env:MYSQL_PWD=$dbpass
   try{
    & $mysql -h $DatabaseHost -P $DatabasePort -u $DatabaseUser -e "CREATE DATABASE IF NOT EXISTS $DatabaseName CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
    if($LASTEXITCODE -ne 0){throw 'Could not create database; verify MySQL is running and credentials are correct.'}
    Get-Content -LiteralPath $sql -Raw|& $mysql -h $DatabaseHost -P $DatabasePort -u $DatabaseUser $DatabaseName
    if($LASTEXITCODE -ne 0){throw 'SQL import failed; inspect the MySQL error output.'}
   }finally{Remove-Item Env:\MYSQL_PWD -ErrorAction SilentlyContinue}
  }else{Write-Warning 'Database import skipped. Restore a valid schema before using the application.'}
 }
 Step 'Checking PHP extensions'
 $modules=& $php -m
 foreach($ext in @('mysqli','mbstring','openssl','fileinfo')){if($modules -notcontains $ext){Write-Warning "Enable PHP extension $ext in php.ini and restart Apache."}}
 Step 'Done'
 Write-Host "Application: $InstallPath"
 Write-Host 'Start Apache and MySQL in XAMPP Control Panel.'
 Write-Host 'Open http://localhost/index/'
 Write-Host 'Never expose MySQL port 3306 to the public internet.'
} catch {Write-Error $_;exit 1}
finally {if(Test-Path $Work){Remove-Item $Work -Recurse -Force -ErrorAction SilentlyContinue}}
