verstamp 是 PHP 8 的版本戳注入工具，无 composer、无第三方依赖。README 有 9 条对外契约；当前实现仍可能把失败写入留成半成品。

任务：实现完整且可失败回滚的 `Stamper::stamp`，使替换、排序、哈希、元数据和工程化行为都满足 README，让既有用例转绿、固定件全过。
 所有公开入口都要对非法状态 fail-closed，并保持跨平台、跨调用结果一致。
验收：
- php -l src/Stamper.php 退出码 0；
- php tests/run.php 全绿；
- bash scripts/check.sh 退出码 0，8 个场景全过。

约束：
1. 不改 `check/`、`src/StampException.php`；对外签名不变。
2. 输出不得含时间、随机数、环境变量或输入顺序信息；非法 root 必须抛异常。
3. 写入前完成全部校验；失败不得留下临时文件，重复运行必须产生逐字节相同结果。
