# 理论练习室

计算机程序设计员（四级 / 中级）理论知识练习网站。在线使用：https://ai.002231.xyz/

- 练习模式：300 道题按顺序作答，提供逐题解析；答对后 3 秒自动进入下一题。
- 模拟考试：随机抽取 100 题，交卷后统一判分。
- 游客可直接练习；登录后可保存练习进度、跨设备续练及查看已提交的历史试卷。

## Docker Compose 部署

```sh
git clone https://github.com/ljy2231/ai-practice.git
cd ai-practice
docker compose up -d --build
```

- 容器内 `/web/public` 是网站根目录；`/web/data` 存放数据库、练习会话和独立的管理会话，默认持久化到宿主机 `/web/data`。备份或迁移时备份数据目录，网址和站点源码不写入其中。
- 默认只监听宿主机 `127.0.0.1:8080`。将自己的 HTTPS 反向代理指向此地址；本站登录 Cookie 需要 HTTPS。用 `WEB_PORT` 更改本地端口，或用 `WEB_DATA_DIR` 指定宿主机上的另一个数据目录（容器内仍是 `/web/data`）。
- 现有 SQLite 如需迁移，先停止旧站的写入，再备份数据库。将备份放到新的数据目录，确保运行 PHP 的用户能读取和写入数据目录及数据库（容器内为 `www-data`，UID/GID `33:33`）。不要把数据库、备份或 Git 元数据放进 Web 根目录。

## 管理后台

入口为 `/admin.php`，管理员用户名为 `admin`。它和练习站用户是两套账号；普通注册不会赋予管理权限。后台可查看统计、管理用户与练习/模拟记录、导出 CSV 和查看操作日志。

首次设置密码（或忘记密码时重置）请从可信服务器输入，不要把密码写进仓库。以下用 Bash 的无回显输入，选择至少 12 个字符的随机临时密码：

```bash
read -rs -p '请输入管理员临时密码: ' ADMIN_PASSWORD; printf '\n'
printf '%s\n' "$ADMIN_PASSWORD" | docker compose exec -T --user www-data web php /web/public/admin.php init
unset ADMIN_PASSWORD
```

登录后先修改临时密码，再进入管理功能。没有配置时，网页无法注册管理员。临时密码和正式密码均仅以 Argon2id 哈希保存在数据库中。

## 手动部署（PHP / 1Panel）

使用 PHP 8（PDO SQLite、Argon2id）和 HTTPS。建议把网站源码放到 `/web/public`，设置 FPM 环境变量 `AI_PRACTICE_DATA_DIR=/web/data`，将数据目录及其 `sessions/`、`admin-sessions/` 子目录交给 PHP 运行用户读写。如果未设置该变量，沿用旧版本的站点同级 `private/` 目录。必要时同步修改 `open_basedir`。手动初始化管理员时，在相同环境下以 PHP 运行用户执行 `php admin.php init`，同样通过标准输入传入密码。

本仓库仅包含网站源码、题库和解析，不含线上账号、练习记录或会话数据。
