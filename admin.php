<?php
declare(strict_types=1);

ini_set('display_errors', '0');
umask(0077);
define('AI_PRACTICE_BOOT', true);
require __DIR__.'/storage.php';

function h($value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function adminDb(): PDO { return db(); }
function adminCsrf(): string { return $_SESSION['csrf']; }
function adminRedirect(string $url = 'admin.php'): void { header('Location: '.$url, true, 303); exit; }
function adminFlash(string $text, bool $error = false): void { $_SESSION['flash'] = [$text, $error]; }
function adminHeader(string $title): void {
    $flash = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);
    echo '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>'.h($title).' · 理论练习室后台</title><link rel="stylesheet" href="admin.css?v=1"><link rel="icon" href="favicon.svg"></head><body><header class="top"><div class="wrap top-inner"><a class="brand" href="admin.php">理论练习室 <small>管理后台</small></a><a class="btn ghost" href="index.php">返回练习站</a></div></header><main class="wrap">';
    if (isset($_SESSION['admin_tag'])) {
        echo '<nav class="nav" aria-label="后台导航">';
        $pages = ['dashboard'=>'概览','users'=>'用户管理','records'=>'答题记录','log'=>'操作日志'];
        $view = $_GET['view'] ?? 'dashboard';
        foreach ($pages as $key=>$label) {
            $current = $view === $key || ($view === 'user' && $key === 'users') || ($view === 'record' && $key === 'records');
            echo '<a href="admin.php?view='.$key.'"'.($current?' aria-current="page"':'').'>'.$label.'</a>';
        }
        echo '<a href="admin.php?view=token">更换 TOKEN</a><form method="post" action="admin.php"><input type="hidden" name="csrf" value="'.h(adminCsrf()).'"><button class="btn ghost" name="auth_action" value="logout">退出</button></form></nav>';
    }
    if ($flash) echo '<div role="status" class="flash'.($flash[1]?' error':'').'">'.h($flash[0]).'</div>';
}
function adminFooter(): void { echo '</main><footer class="wrap">理论练习室 · @LJY2231</footer></body></html>'; }
function adminTokenValid($token): bool { return is_string($token) && $token !== '' && strlen($token) <= 1024 && strpbrk($token, "\0\r\n") === false; }
function adminHash(string $token): string { return password_hash($token, PASSWORD_ARGON2ID, ['memory_cost'=>19456,'time_cost'=>2,'threads'=>1]); }

// 仅服务器 CLI 能初始化或重置。公开初始值只在显式选择时启用，并要求首次更换。
if (PHP_SAPI === 'cli') {
    if (($argv[1] ?? '') !== 'init' || !in_array($argv[2] ?? '', ['', '--default'], true) || count($argv)>3) {
        fwrite(STDERR, "Usage: php admin.php init < token-file | php admin.php init --default\n"); exit(2);
    }
    $default = ($argv[2] ?? '') === '--default';
    $input = $default ? 'admin' : fgets(STDIN, 1027);
    $token = $input === false ? '' : rtrim($input, "\r\n");
    if (!adminTokenValid($token)) { fwrite(STDERR, "请输入非空的单行 TOKEN（最多 1024 字节）。\n"); exit(2); }
    $temporary = $token === 'admin' ? 1 : 0;
    $db = adminDb(); $db->beginTransaction();
    query('INSERT INTO admin_account(id,password_hash,temporary) VALUES (1,?,?) ON CONFLICT(id) DO UPDATE SET password_hash=excluded.password_hash,temporary=excluded.temporary', [adminHash($token),$temporary]);
    query('INSERT INTO admin_log(created_at,action,target,detail) VALUES (?,?,?,?)', [time(),'admin_init','admin','通过服务器命令设置登录 TOKEN']);
    $db->commit(); echo $temporary ? "已设置初始 TOKEN；登录后需更换。\n" : "后台登录 TOKEN 已设置。\n"; exit;
}

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'self'; script-src 'none'; style-src 'self'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'; object-src 'none'");

try {
    if (!in_array($_SERVER['REQUEST_METHOD'], ['GET','POST'], true)) { header('Allow: GET, POST'); http_response_code(405); exit; }
    ini_set('session.use_strict_mode', '1'); ini_set('session.use_only_cookies', '1');
    ini_set('session.gc_maxlifetime', '7200');
    // 新名称避免浏览器继续复用旧版本的管理 Cookie。
    session_name('__Host-AIPRACTICEADMIN');
    if (session_save_path(sessionDir('admin-sessions')) === false) throw new RuntimeException('admin sessions unavailable');
    session_set_cookie_params(['path'=>'/','domain'=>'','secure'=>true,'httponly'=>true,'samesite'=>'Lax']);
    if (!session_start()) throw new RuntimeException('admin sessions unavailable');
    $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    $post = $_SERVER['REQUEST_METHOD'] === 'POST';
    $action = $_POST['auth_action'] ?? '';
    $account = query('SELECT password_hash, temporary FROM admin_account WHERE id=1')->fetch();
    $now = time();
    $authorized = $account && isset($_SESSION['admin_tag'],$_SESSION['admin_since'],$_SESSION['admin_seen']) &&
        $now - $_SESSION['admin_since'] <= 28800 && $now - $_SESSION['admin_seen'] <= 1800 &&
        hash_equals($_SESSION['admin_tag'], hash('sha256',$account['password_hash']));
    if (!$authorized) unset($_SESSION['admin_tag'],$_SESSION['admin_since'],$_SESSION['admin_seen']);
    // 登录表单过期时用 GET 重新生成表单，不重放请求；其他后台操作继续拒绝无效凭证。
    if ($post && (!is_string($_POST['csrf'] ?? null) || !hash_equals(adminCsrf(), $_POST['csrf']))) {
        if ($action === 'login') adminRedirect('admin.php?retry=1');
        http_response_code(403); echo '操作凭证已失效，请刷新页面后重试。'; exit;
    }
    if (!$authorized) {
        if ($post && $action !== 'login') { http_response_code(401); echo '请先使用 TOKEN 登录后台。'; exit; }
        if ($post) {
            $token = $_POST['token'] ?? null;
            $ip = 'admin:'.hash('sha256',$_SERVER['REMOTE_ADDR'] ?? 'local');
            query('DELETE FROM auth_limits WHERE until_ts <= ?', [$now]);
            query('INSERT INTO auth_limits(bucket,hits,until_ts) VALUES (?,1,?) ON CONFLICT(bucket) DO UPDATE SET hits=hits+1 WHERE hits<8', [$ip,$now+900]);
            $allowed = (int)adminDb()->query('SELECT changes()')->fetchColumn();
            if (!$allowed) { http_response_code(429); adminFlash('尝试次数过多，请稍后再登录。', true); }
            elseif (!$account || !adminTokenValid($token) || !password_verify($token,$account['password_hash'])) {
                http_response_code(401); adminFlash('TOKEN 不正确。', true);
            } else {
                query('DELETE FROM auth_limits WHERE bucket=?', [$ip]);
                if (!session_regenerate_id(true)) throw new RuntimeException('admin session rotation failed');
                $_SESSION = ['csrf'=>bin2hex(random_bytes(32)), 'admin_tag'=>hash('sha256',$account['password_hash']),
                    'admin_since'=>$now,'admin_seen'=>$now];
                adminRedirect();
            }
        }
        if (($_GET['retry'] ?? null) === '1' && !isset($_SESSION['flash'])) {
            adminFlash('登录表单已更新，请重新输入 TOKEN。若反复出现，请检查浏览器是否允许本站 Cookie。', true);
        }
        adminHeader('管理员登录');
        echo '<section class="panel signin"><p class="eyebrow">ADMIN ACCESS</p><h1>管理员登录</h1>';
        if (!$account) echo '<p class="muted">后台尚未初始化，请在服务器上设置 TOKEN。</p>';
        else echo '<form method="post" action="admin.php"><input type="hidden" name="csrf" value="'.h(adminCsrf()).'"><label>TOKEN<input type="password" name="token" autocomplete="current-password" maxlength="1024" required></label><button class="btn primary" name="auth_action" value="login">登录后台</button></form>';
        echo '</section>'; adminFooter(); exit;
    }
    $_SESSION['admin_seen'] = $now;
    if ($post && $action === 'logout') {
        if (!session_regenerate_id(true)) throw new RuntimeException('admin session rotation failed');
        $_SESSION = []; adminRedirect();
    }
    if ($post && $action === 'token') {
        $old = $_POST['current'] ?? null; $new = $_POST['token'] ?? null;
        if (!adminTokenValid($old) || !password_verify($old,$account['password_hash'])) adminFlash('当前 TOKEN 不正确。',true);
        elseif (!adminTokenValid($new) || strlen($new)<12 || $new !== ($_POST['confirm'] ?? null) || $new === $old) adminFlash('请填写至少 12 字节、不同于当前值的新 TOKEN，并确保两次输入一致。',true);
        else {
            $hash = adminHash($new); $db = adminDb(); $db->beginTransaction();
            $changed = query('UPDATE admin_account SET password_hash=?,temporary=0 WHERE id=1 AND password_hash=?', [$hash,$account['password_hash']])->rowCount();
            if ($changed) query('INSERT INTO admin_log(created_at,action,target,detail) VALUES (?,?,?,?)', [$now,'admin_password','admin','已更换后台登录 TOKEN']);
            $db->commit();
            if (!$changed) { adminFlash('TOKEN 已变更，请重新登录。',true); adminRedirect(); }
            if (!session_regenerate_id(true)) throw new RuntimeException('admin session rotation failed');
            $_SESSION['admin_tag']=hash('sha256',$hash); $_SESSION['csrf']=bin2hex(random_bytes(32));
            adminFlash('TOKEN 已更新。'); adminRedirect();
        }
        adminRedirect('admin.php?view=token');
    }
    if (!empty($account['temporary']) && $post) { http_response_code(403); echo '请先更换初始 TOKEN。'; exit; }
    if (!empty($account['temporary']) || in_array($_GET['view'] ?? '', ['token','password'], true)) {
        adminHeader('更换 TOKEN');
        echo '<section class="panel signin"><h1>更换 TOKEN</h1><p class="muted">'.(!empty($account['temporary'])?'首次登录请先更换初始 TOKEN。':'更换后，其他浏览器需要重新登录。').'</p><form method="post" action="admin.php"><input type="hidden" name="csrf" value="'.h(adminCsrf()).'"><label>当前 TOKEN<input type="password" name="current" autocomplete="current-password" maxlength="1024" required></label><label>新 TOKEN（至少 12 字节）<input type="password" name="token" autocomplete="new-password" maxlength="1024" required></label><label>确认新 TOKEN<input type="password" name="confirm" autocomplete="new-password" required></label><button class="btn primary" name="auth_action" value="token">更新 TOKEN</button></form></section>';
        adminFooter(); exit;
    }
    define('ADMIN_PANEL_AUTHORIZED', true);
    require __DIR__.'/admin-panel.php';
} catch (Throwable $error) {
    error_log('ai-practice-admin: '.$error->getMessage());
    if (!headers_sent()) http_response_code(503);
    echo '管理后台暂时不可用，请稍后重试。';
}
