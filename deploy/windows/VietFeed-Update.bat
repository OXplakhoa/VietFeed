@echo off
REM Double-click to pull the latest demo code, rebuild and restart the VietFeed public demo.
title VietFeed demo update
wsl.exe -d VietFeed --cd /home/dolakhoa/vietfeed-demo -- bash -lc "deploy/start-all.sh --update"
echo.
pause
