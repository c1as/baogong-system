#!/bin/sh
# 报工管理系统 · 启动脚本（群晖 / Linux，不需要 Docker）
# 用法：sh start.sh
# 配置写在同目录的 app.env 里（APP_PASSWORD、PORT 等）

DIR=$(cd "$(dirname "$0")" 2>/dev/null && pwd)
[ -z "$DIR" ] && DIR=$(pwd)
cd "$DIR" || exit 1

# 读取配置（没有 app.env 就用默认值）
# 先去掉 Windows 记事本可能留下的 \r，避免密码末尾多一个回车
if [ -f "$DIR/app.env" ]; then
  TMP_ENV="$DIR/.app.env.tmp"
  if tr -d '\r' < "$DIR/app.env" > "$TMP_ENV" 2>/dev/null; then
    . "$TMP_ENV"
  else
    . "$DIR/app.env"
  fi
  rm -f "$TMP_ENV"
fi

export TZ="${TZ:-Asia/Shanghai}"
export PORT="${PORT:-8099}"
export DATA_DIR="${DATA_DIR:-$DIR/data}"
export APP_PASSWORD="${APP_PASSWORD:-}"

# 自动寻找群晖套件版 Node.js
NODE=""
for c in "$(command -v node 2>/dev/null)" \
         /usr/local/bin/node \
         /var/packages/Node.js_v22/target/usr/local/bin/node \
         /var/packages/Node.js_v22/target/bin/node \
         /var/packages/Node.js_v21/target/usr/local/bin/node \
         /var/packages/Node.js_v20/target/usr/local/bin/node \
         /var/packages/Node.js_v20/target/bin/node \
         /var/packages/Node.js_v18/target/usr/local/bin/node \
         /volume1/@appstore/Node.js_v22/usr/local/bin/node \
         /volume1/@appstore/Node.js_v20/usr/local/bin/node \
         /volume1/@appstore/Node.js_v18/usr/local/bin/node; do
  if [ -n "$c" ] && [ -x "$c" ]; then
    NODE="$c"
    break
  fi
done

if [ -z "$NODE" ]; then
  echo "错误：没有找到 Node.js。请先在「套件中心」安装 Node.js v20 或 v22。"
  exit 1
fi

echo "$(date '+%Y-%m-%d %H:%M:%S') 启动报工系统 | node=$NODE | 端口=$PORT | 数据=$DATA_DIR"
echo $$ > "$DIR/server.pid"

exec "$NODE" server.js
