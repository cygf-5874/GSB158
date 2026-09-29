#!/usr/bin/env bash
# 固定验收入口的薄封装：切到仓库根，再把参数原样转给 check/check.php。
#
#   bash scripts/check.sh                 跑全部 8 个场景
#   bash scripts/check.sh -list           列出全部场景
#   bash scripts/check.sh --only stable   只跑一组
set -euo pipefail

cd "$(dirname "$0")/.."

exec php check/check.php "$@"