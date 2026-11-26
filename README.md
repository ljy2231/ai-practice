# 理论练习室

计算机程序设计员（四级 / 中级）理论知识练习网站：https://ai.002231.xyz/

- 练习模式：300 题按顺序作答，提供逐题解析和云端续练。
- 模拟考试：随机抽取 100 题，交卷后统一判分。
- 管理后台：用户、成绩、答题明细、CSV 和操作日志。

## 直接复制部署

把整个站点目录复制到新服务器（例如 `/web`），将网站根目录直接指向它。服务器需要 PHP 8（PDO SQLite、Argon2id）和 HTTPS。不需要 Docker、不需要更改程序中的路径。

数据库和会话都在站点自己的 `data/` 中。搬站时一起复制 `data/`；为避免遗漏写入，请先暂停旧站 PHP 请求。全新安装不带数据库，PHP 会自动创建，确保 PHP 运行用户可以写入 `data/`。若 1Panel 自动生成了 `.user.ini`，确认 `open_basedir` 含新站点目录，不要从旧机器复制绝对路径配置。

**上线前禁止 HTTP 访问 `data/`**，这里包含账号、成绩和会话。Apache 已附带目录规则（须允许 `.htaccess` 生效）；Nginx/OpenResty 则在站点 `server` 中加入：

```nginx
location = /data { return 404; }
location ^~ /data/ { return 404; }
```

上线后访问 `/data/practice.sqlite`，应返回 403 或 404。

## 管理后台

入口：`/admin.php`，账号：`admin`。复制了原数据库就可以直接用原密码。全新部署时，以 PHP 运行用户在站点目录执行以下命令，通过标准输入设置至少 12 个字符的临时密码（首次登录需要修改）：

```bash
read -rs -p '管理员临时密码: ' ADMIN_PASSWORD; printf '\n'
printf '%s\n' "$ADMIN_PASSWORD" | php admin.php init
unset ADMIN_PASSWORD
```

公开仓库不含任何线上数据库、账号或会话。
