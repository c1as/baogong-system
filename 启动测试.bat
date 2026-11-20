@echo off
chcp 65001 >nul
cd /d "%~dp0"
where node >nul 2>nul
if errorlevel 1 (
  echo.
  echo 未检测到 Node.js。请先安装 Node.js 18 或更高版本：https://nodejs.org/
  echo 安装后再双击本文件。
  echo.
  pause
  exit /b 1
)
set "PORT=8099"
set "DATA_DIR=%~dp0data"
if not exist "%DATA_DIR%" mkdir "%DATA_DIR%"
echo 正在启动报工管理系统……浏览器会自动打开 http://127.0.0.1:8099
start "" "http://127.0.0.1:8099"
node server.js
pause
