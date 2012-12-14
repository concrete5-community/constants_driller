@echo off
setlocal

set t=%ProgramFiles%\Inkscape
if exist "%t%\inkscape.exe" goto isf
set t=%ProgramFiles(x86)%\Inkscape
if exist "%t%\inkscape.exe" goto isf
set t=%ProgramW6432%\Inkscape
if exist "%t%\inkscape.exe" goto isf
echo Inkscape not found.
goto done
:isf
set Path=%Path%;%t%

php "%~dp0%create-zip.php" 

:done
endlocal

