@echo off
REM Double-click to start the VietFeed public demo (https://vietfeed.app). Copy this file to your Desktop.
REM Pass --update to pull and rebuild first, or --stop to stop the demo services.
title VietFeed demo
wsl.exe -d VietFeed --cd /home/dolakhoa/vietfeed-demo -- bash -lc "deploy/start-all.sh %*"
echo.
pause
