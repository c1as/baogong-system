#!/bin/sh
# 报工管理系统 · 停止脚本（群晖 / Linux）

DIR=$(cd "$(dirname "$0")" 2>/dev/null && pwd)
[ -z "$DIR" ] && DIR=$(pwd)
PIDFILE="$DIR/server.pid"

PID=$(cat "$PIDFILE" 2>/dev/null)
if [ -n "$PID" ] && [ ! -d "/proc/$PID" ]; then
  PID=""
fi

# 优先用 pgrep 找到本目录的 server.js
if [ -z "$PID" ] && command -v pgrep >/dev/null 2>&1; then
  PID=$(pgrep -f "$DIR/server.js" 2>/dev/null | head -n 1)
fi

# 再退回 ps
if [ -z "$PID" ]; then
  PID=$(ps w 2>/dev/null | grep "$DIR/server.js" | grep -v grep | awk '{print $1}' | head -n 1)
fi

if [ -z "$PID" ]; then
  echo "报工系统当前没有在运行"
  rm -f "$PIDFILE"
  exit 0
fi

# 确认这个 PID 确实是本目录的 server.js，避免误杀
CMD=$(tr '\0' ' ' < "/proc/$PID/cmdline" 2>/dev/null)
case "$CMD" in
  *"$DIR/server.js"*) ;;
  *)
    echo "PID $PID 不是报工系统，已忽略"
    rm -f "$PIDFILE"
    exit 0
    ;;
esac

kill "$PID" 2>/dev/null
i=0
while [ $i -lt 10 ] && [ -d "/proc/$PID" ]; do
  sleep 1
  i=$((i + 1))
done
if [ -d "/proc/$PID" ]; then
  kill -9 "$PID" 2>/dev/null
fi

rm -f "$PIDFILE"
echo "报工系统已停止（PID $PID）"
