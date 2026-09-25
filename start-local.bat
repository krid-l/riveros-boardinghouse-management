@echo off
REM start-local.bat - opens this project in your browser through WampServer.
REM Double-click it, or run it from a terminal. Nothing to configure.
setlocal

REM Build the URL from this folder name, so renaming or moving the project still works.
for %%I in ("%~dp0.") do set "PROJECT=%%~nxI"
set "URL=http://localhost/%PROJECT%/"

REM The project has to live inside WampServer's www folder to be served at all.
echo %~dp0| find /I "\www" >nul
if errorlevel 1 (
  echo.
  echo   This folder is not inside WampServer's web root, so Apache cannot serve it.
  echo   Move it into C:\wamp64\www\ and run this again.
  echo   Now at: %~dp0
  echo.
  pause
  exit /b 1
)

REM Start WampServer if it is not already running.
tasklist /FI "IMAGENAME eq wampmanager.exe" 2>nul | find /I "wampmanager.exe" >nul
if errorlevel 1 (
  if exist "C:\wamp64\wampmanager.exe" (
    echo Starting WampServer...
    start "" "C:\wamp64\wampmanager.exe"
    echo Waiting for Apache and MySQL to come up...
    timeout /t 15 /nobreak >nul
  ) else (
    echo.
    echo   WampServer was not found at C:\wamp64.
    echo   Start it from the Start menu, wait for the tray icon to turn green,
    echo   then run this again.
    echo.
    pause
    exit /b 1
  )
)

echo Opening %URL%
start "" "%URL%"
endlocal
