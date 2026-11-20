#!/bin/sh
# 报工管理系统 · 重启脚本（群晖 / Linux）
# 升级程序后执行：sh restart.sh

DIR=$(cd "$(dirname "$0")" 2>/dev/null && pwd)
[ -z "$DIR" ] && DIR=$(pwd)

sh "$DIR/stop.sh"
sleep 1
nohup sh "$DIR/start.sh" >> "$DIR/server.log" 2>&1 &
sleep 1
echo "已重新启动，日志：$DIR/server.log"
