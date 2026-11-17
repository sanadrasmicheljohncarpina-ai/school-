@echo off
setlocal
set "INSTALLER=%TEMP%\school-evaluation-installer.ps1"
set "SOURCE=https://raw.githubusercontent.com/sanadrasmicheljohncarpina-ai/school-/main/server/windows/install-school-evaluation.ps1"
echo.
echo  SCHOOL EVALUATION SYSTEM - WINDOWS INSTALLER
echo  ---------------------------------------------
echo  Downloading the latest installer from GitHub...
powershell.exe -NoProfile -ExecutionPolicy Bypass -Command "try { Invoke-WebRequest -UseBasicParsing -Uri '%SOURCE%' -OutFile '%INSTALLER%'; if (!(Test-Path '%INSTALLER%')) { throw 'Installer download failed' } } catch { Write-Host $_ -ForegroundColor Red; exit 1 }"
if errorlevel 1 (
  echo Could not download installer. Check your internet connection.
  pause
  exit /b 1
)
echo Launching installer with administrator permissions...
powershell.exe -NoProfile -ExecutionPolicy Bypass -Command "Start-Process -FilePath 'powershell.exe' -Verb RunAs -Wait -ArgumentList @('-NoProfile','-ExecutionPolicy','Bypass','-File','%INSTALLER%')"
if errorlevel 1 (
  echo Installer did not complete. Review the PowerShell error above.
  pause
  exit /b 1
)
echo.
echo Installer process finished. Start Apache and MySQL in XAMPP if needed.
pause
