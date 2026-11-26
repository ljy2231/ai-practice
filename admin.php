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
        if (empty($_SESSION['admin_temporary'])) {
            $pages = ['dashboard'=>'概览','users'=>'用户管理','records'=>'答题记录','log'=>'操作日志'];
            $view = $_GET['view'] ?? 'dashboard';
            foreach ($pages as $key=>$label) {
                $current = $view === $key || ($view === 'user' && $key === 'users') || ($view === 'record' && $key === 'records');
                echo '<a href="admin.php?view='.$key.'"'.($current?' aria-current="page"':'').'>'.$label.'</a>';
            }
        }
        echo '<a href="admin.php?view=password">修改密码</a><form method="post" action="admin.php"><input type="hidden" name="csrf" value="'.h(adminCsrf()).'"><button class="btn ghost" name="auth_action" value="logout">退出</button></form></nav>';
    }
    if ($flash) echo '<div role="status" class="flash'.($flash[1]?' error':'').'">'.h($flash[0]).'</div>';
}
function adminFooter(): void { echo '</main><footer class="wrap">理论练习室 · @LJY2231</footer></body></html>'; }
function adminPasswordValid(string $password): bool { return strlen($password) <= 1024 && preg_match('/\A.{12,}\z/us', $password) === 1; }
function adminHash(string $password): string { return password_hash($password, PASSWORD_ARGON2ID, ['memory_cost'=>19456,'time_cost'=>2,'threads'=>1]); }

// 仅 CLI 可以配置初始密码。明文从 stdin 读取，不进入命令行参数、日志或仓库。
if (PHP_SAPI === 'cli') {
    if (($argv[1] ?? '') !== 'init') { fwrite(STDERR, "Usage: php admin.php init < password-file\n"); exit(2); }
    $input = fgets(STDIN, 1027); $password = $input === false ? '' : rtrim($input, "\r\n");
    if (!adminPasswordValid($password)) { fwrite(STDERR, "管理员密码至少 12 个字符，且最多 1024 字节。\n"); exit(2); }
    $db = adminDb(); $db->beginTransaction();
    query('INSERT INTO admin_account(id,password_hash,temporary) VALUES (1,?,1) ON CONFLICT(id) DO UPDATE SET password_hash=excluded.password_hash,temporary=1', [adminHash($password)]);
    query('INSERT INTO admin_log(created_at,action,target,detail) VALUES (?,?,?,?)', [time(),'admin_init','admin','通过服务器命令设置临时密码']);
    $db->commit(); echo "管理员 admin 的临时密码已设置；首次登录须修改。\n"; exit;
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
    session_name('AIPRACTICEADMIN');
    if (session_save_path(sessionDir('admin-sessions')) === false) throw new RuntimeException('admin sessions unavailable');
    session_set_cookie_params(['path'=>'/admin.php','secure'=>true,'httponly'=>true,'samesite'=>'Strict']);
    if (!session_start()) throw new RuntimeException('admin sessions unavailable');
    $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    $post = $_SERVER['REQUEST_METHOD'] === 'POST';
    $action = $_POST['auth_action'] ?? '';
    $account = query('SELECT password_hash, temporary FROM admin_account WHERE id=1')->fetch();
    $now = time();
    $authorized = $account && isset($_SESSION['admin_tag'],$_SESSION['admin_since'],$_SESSION['admin_seen']) &&
        $now - $_SESSION['admin_since'] <= 28800 && $now - $_SESSION['admin_seen'] <= 1800 &&
        hash_equals($_SESSION['admin_tag'], hash('sha256',$account['password_hash']));
    if (!$authorized) unset($_SESSION['admin_tag'],$_SESSION['admin_temporary'],$_SESSION['admin_since'],$_SESSION['admin_seen']);
    if ($post && (!is_string($_POST['csrf'] ?? null) || !hash_equals(adminCsrf(), $_POST['csrf']))) { http_response_code(403); echo '操作凭证已失效，请刷新页面后重试。'; exit; }
    if (!$authorized) {
        if ($post && $action !== 'login') { http_response_code(401); echo '请先登录管理员账号。'; exit; }
        if ($post) {
            $password = $_POST['password'] ?? null; $name = $_POST['username'] ?? null;
            $ip = 'admin:'.hash('sha256',$_SERVER['REMOTE_ADDR'] ?? 'local');
            query('DELETE FROM auth_limits WHERE until_ts <= ?', [$now]);
            query('INSERT INTO auth_limits(bucket,hits,until_ts) VALUES (?,1,?) ON CONFLICT(bucket) DO UPDATE SET hits=hits+1 WHERE hits<8', [$ip,$now+900]);
            $allowed = (int)adminDb()->query('SELECT changes()')->fetchColumn();
            if (!$allowed) { http_response_code(429); adminFlash('尝试次数过多，请稍后再登录。', true); }
            elseif (!$account || !is_string($password) || strlen($password)>1024 || $name!=='admin' || !password_verify($password,$account['password_hash'])) {
                http_response_code(401); adminFlash('管理员账号或密码不正确。', true);
            } else {
                query('DELETE FROM auth_limits WHERE bucket=?', [$ip]);
                if (!session_regenerate_id(true)) throw new RuntimeException('admin session rotation failed');
                $_SESSION = ['csrf'=>bin2hex(random_bytes(32)), 'admin_tag'=>hash('sha256',$account['password_hash']),
                    'admin_since'=>$now,'admin_seen'=>$now,'admin_temporary'=>(bool)$account['temporary']];
                adminRedirect();
            }
        }
        adminHeader('管理员登录');
        echo '<section class="panel signin"><p class="eyebrow">ADMIN ACCESS</p><h1>管理员登录</h1>';
        if (!$account) echo '<p class="muted">管理员尚未初始化。请在服务器上设置临时密码。</p>';
        else echo '<p class="muted">使用独立的管理账号。学员账号不能登录此处。</p><form method="post" action="admin.php"><input type="hidden" name="csrf" value="'.h(adminCsrf()).'"><label>账号<input name="username" value="admin" autocomplete="username" required></label><label>密码<input type="password" name="password" autocomplete="current-password" maxlength="1024" required></label><button class="btn primary" name="auth_action" value="login">登录后台</button></form>';
        echo '</section>'; adminFooter(); exit;
    }
    $_SESSION['admin_seen'] = $now;
    if ($post && $action === 'logout') {
        if (!session_regenerate_id(true)) throw new RuntimeException('admin session rotation failed');
        $_SESSION = []; adminRedirect();
    }
    if ($post && $action === 'password') {
        $old = $_POST['current'] ?? null; $new = $_POST['password'] ?? null;
        if (!is_string($old) || strlen($old)>1024 || !password_verify($old,$account['password_hash'])) adminFlash('当前密码不正确。',true);
        elseif (!is_string($new) || !adminPasswordValid($new) || $new !== ($_POST['confirm'] ?? null) || $new === $old) adminFlash('请输入不同于当前密码的新密码（至少 12 个字符），并确保两次输入一致。',true);
        else {
            $hash = adminHash($new); $db = adminDb(); $db->beginTransaction();
            $changed = query('UPDATE admin_account SET password_hash=?,temporary=0 WHERE id=1 AND password_hash=?', [$hash,$account['password_hash']])->rowCount();
            if ($changed) query('INSERT INTO admin_log(created_at,action,target,detail) VALUES (?,?,?,?)', [$now,'admin_password','admin','已修改管理员密码']);
            $db->commit();
            if (!$changed) { adminFlash('管理员密码已经变更，请重新登录。',true); adminRedirect(); }
            if (!session_regenerate_id(true)) throw new RuntimeException('admin session rotation failed');
            $_SESSION['admin_tag']=hash('sha256',$hash); $_SESSION['admin_temporary']=false; $_SESSION['csrf']=bin2hex(random_bytes(32));
            adminFlash('管理员密码已更新。'); adminRedirect();
        }
        adminRedirect('admin.php?view=password');
    }
    if (!empty($account['temporary']) && $post) { http_response_code(403); echo '请先修改临时密码。'; exit; }
    if (!empty($account['temporary']) || ($_GET['view'] ?? '') === 'password') {
        adminHeader('修改管理员密码');
        echo '<section class="panel signin"><h1>修改管理员密码</h1><p class="muted">'.(!empty($account['temporary'])?'首次登录请先更换临时密码。':'修改后，其他浏览器需要重新登录。').'</p><form method="post" action="admin.php"><input type="hidden" name="csrf" value="'.h(adminCsrf()).'"><label>当前密码<input type="password" name="current" autocomplete="current-password" maxlength="1024" required></label><label>新密码（至少 12 个字符）<input type="password" name="password" autocomplete="new-password" minlength="12" maxlength="1024" required></label><label>确认新密码<input type="password" name="confirm" autocomplete="new-password" required></label><button class="btn primary" name="auth_action" value="password">更新密码</button></form></section>';
        adminFooter(); exit;
    }
    define('ADMIN_PANEL_AUTHORIZED', true);
    require __DIR__.'/admin-panel.php';
} catch (Throwable $error) {
    error_log('ai-practice-admin: '.$error->getMessage());
    if (!headers_sent()) http_response_code(503);
    echo '管理后台暂时不可用，请稍后重试。';
}
