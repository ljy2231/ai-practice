<?php
declare(strict_types=1);

// 本接口与静态页面同源；数据库、会话文件均不在 Web 根目录。
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
const PICK = 100;
const PAGE_SIZE = 10;
define('AI_PRACTICE_BOOT', true);
require __DIR__ . '/storage.php';

function respond(array $data, int $status = 200): void {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
}
function fail(string $text, int $status): void { respond(['error' => $text], $status); }
function bank(): array {
    static $bank;
    return $bank ?? ($bank = require __DIR__ . '/bank.php');
}
function identity(): array {
    return ['user' => $_SESSION['user'] ?? null, 'csrf' => $_SESSION['csrf'], 'total' => count(bank()), 'pick' => PICK];
}
function resetSession(array $data): void {
    if (!session_regenerate_id(true)) throw new RuntimeException('session rotation failed');
    $_SESSION = $data + ['csrf' => bin2hex(random_bytes(32))];
}
function readInput(int $limit): array {
    if (!hash_equals($_SESSION['csrf'], (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) fail('页面凭证已过期，请刷新页面后重试。', 403);
    if (strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0])) !== 'application/json') fail('请发送 JSON 数据。', 415);
    $raw = file_get_contents('php://input', false, null, 0, $limit + 1);
    if ($raw === false || strlen($raw) > $limit) fail('请求内容过长。', 413);
    $body = json_decode($raw, true, 8);
    if (!is_array($body)) fail('请求格式有误。', 400);
    return $body;
}
function login(array $data): void {
    $user = $data['username'] ?? null;
    $pass = $data['password'] ?? null;
    if (!is_string($user) || $user === '' || !is_string($pass) || $pass === '') fail('请填写用户名和密码。', 422);
    // SQLite NOCASE 在 NUL 处停止比较，避免多个用户名被当成同一账号。
    if (strpos($user, "\0") !== false) fail('用户名不能包含空字节。', 422);
    // 只信任服务端连接地址。已注册用户成功登录不会消耗失败配额。
    $ip = hash('sha256', $_SERVER['REMOTE_ADDR'] ?? 'local'); $now = time();
    query('DELETE FROM auth_limits WHERE until_ts <= ?', [$now]);
    query('INSERT INTO auth_limits(bucket,hits,until_ts) VALUES (?,1,?) ON CONFLICT(bucket) DO UPDATE SET hits=hits+1 WHERE hits < 60', [$ip, $now + 900]);
    if (!(int)db()->query('SELECT changes()')->fetchColumn()) fail('登录尝试过多，请稍后再试。', 429);
    $lookup = 'SELECT id, username, password_hash, banned, session_version FROM users WHERE username=?';
    $row = query($lookup, [$user])->fetch(); $created = false;
    if (!$row) {
        if (strcasecmp($user, 'admin') === 0) fail('管理员请使用独立后台入口。', 403);
        $digest = password_hash($pass, PASSWORD_ARGON2ID, ['memory_cost'=>19456, 'time_cost'=>2, 'threads'=>1]);
        $created = query('INSERT OR IGNORE INTO users(username,password_hash,created_at) VALUES (?,?,?)', [$user, $digest, $now])->rowCount() === 1;
        $row = query($lookup, [$user])->fetch();
    }
    // 旧 bcrypt 账号当初禁止超过 72 字节或含 NUL 的密码；不能让截断后相同的输入通过。
    $legacy = password_get_info($row['password_hash'])['algoName'] === 'bcrypt';
    if (!$created && (($legacy && (strlen($pass) > 72 || strpos($pass, "\0") !== false)) || !password_verify($pass, $row['password_hash']))) fail('用户名或密码不正确。', 401);
    if (!$created) query('UPDATE auth_limits SET hits=max(0,hits-1) WHERE bucket=?', [$ip]);
    if ((int)$row['banned'] !== 0) fail('该账号已停用。', 403);
    $next = ['uid' => (int)$row['id'], 'user' => $row['username'], 'account_tag' => hash('sha256', $row['password_hash']), 'account_version' => (int)$row['session_version']];
    resetSession($next);
    respond(identity());
}
function questions(array $paper): array {
    return array_map(static function ($q) {
        return ['text' => $q[0], 'options' => $q[1] ?? ['正确', '错误'], 'type' => $q[1] === null ? 'tf' : 'single'];
    }, $paper);
}
function activeAccount(int $uid): bool {
    $account = query('SELECT password_hash,banned,session_version FROM users WHERE id=? AND username=? COLLATE BINARY', [$uid, $_SESSION['user']])->fetch();
    return $account && !(int)$account['banned'] && (int)($_SESSION['account_version'] ?? 0) === (int)$account['session_version'] &&
        isset($_SESSION['account_tag']) && hash_equals($_SESSION['account_tag'], hash('sha256', $account['password_hash']));
}
function lockActiveAccount(int $uid): void {
    db()->exec('BEGIN IMMEDIATE');
    if (!activeAccount($uid)) {
        db()->exec('ROLLBACK'); resetSession([]); fail('当前登录已失效，请重新登录。', 401);
    }
}
function startPaper(array $body, int $uid): void {
    $mode = $body['mode'] ?? 'exam';
    if (!in_array($mode, ['exam', 'practice'], true)) fail('练习模式无效。', 422);
    $bank = bank(); $ids = array_keys($bank); $feedback = [];
    if ($mode === 'exam') {
        shuffle($ids); $ids = array_slice($ids, 0, PICK);
        // 随机抽样后按题型稳定分组，保持各组内的随机顺序。
        usort($ids, static fn($a, $b) => ($bank[$a][1] === null) <=> ($bank[$b][1] === null));
    } else {
        $notes = array_merge(require __DIR__.'/explain-a.php', require __DIR__.'/explain-b.php');
        if (count($notes) !== count($bank)) throw new RuntimeException('explanation count mismatch');
        $feedback = ['correct' => array_column($bank, 2), 'explanations' => $notes];
    }
    $paper = array_map(static fn($id) => $bank[$id], $ids);
    if ($mode === 'practice') foreach ($paper as $i => $q) $paper[$i][3] = $notes[$ids[$i]];
    $id = bin2hex(random_bytes(16));
    if ($mode === 'practice' && ($body['discardDraft'] ?? false) === true) {
        lockActiveAccount($uid);
        query('DELETE FROM practice_drafts WHERE user_id=?', [$uid]);
        db()->exec('COMMIT');
    }
    $_SESSION['active'] = ['id' => $id, 'paper' => $paper, 'mode' => $mode];
    respond(['id' => $id, 'mode' => $mode, 'questions' => questions($paper)] + $feedback);
}
function summary(array $row): array {
    return ['id' => $row['id'], 'mode' => $row['mode'], 'time' => gmdate('Y-m-d\TH:i:s\Z', (int)$row['finished']), 'right' => (int)$row['right_n'],
        'wrong' => (int)$row['wrong_n'], 'miss' => (int)$row['miss_n'], 'total' => (int)$row['total']];
}
function record(string $id, int $uid) {
    return query('SELECT * FROM attempts WHERE id=? AND user_id=?', [$id, $uid])->fetch();
}
function detail(array $row): array {
    $paper = json_decode($row['paper'], true, 16, JSON_THROW_ON_ERROR);
    return summary($row) + ['questions' => questions($paper), 'correct' => array_column($paper, 2), 'explanations' => array_column($paper, 3), 'answers' => json_decode($row['answers'], true, 8, JSON_THROW_ON_ERROR)];
}
function attemptId($value): string {
    if (!is_string($value) || !preg_match('/\A[a-f0-9]{32}\z/', $value)) fail('记录编号无效。', 400);
    return $value;
}
// 返回答对、未答数量。练习草稿只允许连续的已答前缀；考试允许任意顺序作答。
function checkAnswers(array $paper, $answers, bool $ordered): array {
    $total = count($paper);
    if (!is_array($answers) || array_keys($answers) !== range(0, $total - 1)) fail('答题数据不完整。', 422);
    $right = $miss = 0;
    foreach ($answers as $i => $choice) {
        if ($choice === null) { ++$miss; continue; }
        if ($ordered && $miss) fail('请按顺序作答，不能跳过未答题。', 422);
        if (!is_int($choice) || $choice < 0 || $choice >= count($paper[$i][1] ?? [0, 1])) fail('选项超出范围。', 422);
        if ($choice === $paper[$i][2]) ++$right;
    }
    return [$right, $miss];
}
function saveDraft(array $body, int $uid): void {
    $id = attemptId($body['id'] ?? null); $active = $_SESSION['active'] ?? [];
    if (($active['id'] ?? '') !== $id || ($active['mode'] ?? '') !== 'practice') fail('只能保存当前的练习模式进度。', 409);
    $paper = $active['paper']; $answers = $body['answers'] ?? null; $index = $body['index'] ?? null;
    [, $miss] = checkAnswers($paper, $answers, true);
    $total = count($paper); $answered = $total - $miss;
    if (!is_int($index) || $index < 0 || $index >= $total || $index > $answered) fail('当前题号无效。', 422);
    lockActiveAccount($uid);
    if (query('SELECT 1 FROM attempts WHERE id=? AND user_id=?', [$id,$uid])->fetchColumn()) {
        db()->exec('ROLLBACK'); fail('该练习已交卷，无法再保存进度。', 409);
    }
    query('INSERT INTO practice_drafts(user_id,paper_id,paper,answers,position,answered,total) VALUES (?,?,?,?,?,?,?)
        ON CONFLICT(user_id) DO UPDATE SET paper_id=excluded.paper_id,paper=excluded.paper,answers=excluded.answers,
        position=excluded.position,answered=excluded.answered,total=excluded.total',
        [$uid,$id,json_encode($paper, JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),json_encode($answers, JSON_THROW_ON_ERROR),$index,$answered,$total]);
    db()->exec('COMMIT'); respond(['saved'=>true]);
}
function resumeDraft(int $uid): void {
    $draft = query('SELECT * FROM practice_drafts WHERE user_id=?', [$uid])->fetch();
    if (!$draft) fail('没有可继续的练习，请重新开始。', 404);
    $paper = json_decode($draft['paper'], true, 16, JSON_THROW_ON_ERROR);
    $id = $draft['paper_id'];
    $_SESSION['active'] = ['id'=>$id,'paper'=>$paper,'mode'=>'practice'];
    respond(['id'=>$id,'mode'=>'practice','questions'=>questions($paper),'correct'=>array_column($paper,2),
        'explanations'=>array_column($paper,3),'answers'=>json_decode($draft['answers'],true,8,JSON_THROW_ON_ERROR),'index'=>(int)$draft['position']]);
}
function submitPaper(array $body, int $uid): void {
    $id = attemptId($body['id'] ?? null);
    // 重试同一次提交时直接返回已落库的结果，不重复保存或覆盖成绩。
    if ($prior = record($id, $uid)) respond(detail($prior));
    $active = $_SESSION['active'] ?? [];
    if (($active['id'] ?? '') !== $id) fail('这套练习已失效，请返回首页重新抽题。', 409);
    $paper = $active['paper']; $total = count($paper); $answers = $body['answers'] ?? null;
    $practice = ($active['mode'] ?? 'exam') === 'practice';
    [$right,$miss] = checkAnswers($paper,$answers,$practice);
    if ($practice && $miss) fail('练习模式需全部作答后才能交卷，中途可退出并保存进度。', 422);
    lockActiveAccount($uid);
    query('INSERT OR IGNORE INTO attempts(id,user_id,mode,finished,right_n,wrong_n,miss_n,total,paper,answers) VALUES (?,?,?,?,?,?,?,?,?,?)',
        [$id, $uid, $active['mode'] ?? 'exam', time(), $right, $total - $right - $miss, $miss, $total, json_encode($paper, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), json_encode($answers, JSON_THROW_ON_ERROR)]);
    if ($practice) query('DELETE FROM practice_drafts WHERE user_id=? AND paper_id=?', [$uid,$id]);
    $saved = record($id, $uid);
    db()->exec('COMMIT');
    unset($_SESSION['active']); respond(detail($saved));
}
function history(int $uid): void {
    $page = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
    if (!$page) fail('页码无效。', 400);
    $count = (int)query('SELECT count(*) FROM attempts WHERE user_id=?', [$uid])->fetchColumn();
    $pages = max(1, (int)ceil($count / PAGE_SIZE)); $page = min($page, $pages);
    $rows = query('SELECT id,mode,finished,right_n,wrong_n,miss_n,total FROM attempts WHERE user_id=? ORDER BY finished DESC,id DESC LIMIT ? OFFSET ?', [$uid, PAGE_SIZE, ($page - 1) * PAGE_SIZE])->fetchAll();
    respond(['items'=>array_map('summary', $rows), 'page'=>$page, 'pages'=>$pages]);
}

try {
    umask(0077);
    ini_set('session.use_strict_mode', '1'); ini_set('session.use_only_cookies', '1');
    ini_set('session.gc_maxlifetime', '86400');
    session_name('AIPRACTICE');
    if (session_save_path(sessionDir('sessions')) === false) throw new RuntimeException('private sessions unavailable');
    session_set_cookie_params(['path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax']);
    if (!session_start()) throw new RuntimeException('session storage unavailable');
    $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    $action = $_GET['action'] ?? '';
    $methods = ['me'=>'GET', 'login'=>'POST', 'logout'=>'POST', 'start'=>'POST', 'submit'=>'POST', 'history'=>'GET', 'detail'=>'GET', 'draft'=>'GET', 'saveDraft'=>'POST', 'resumeDraft'=>'POST'];
    if (!is_string($action) || !isset($methods[$action])) fail('接口不存在。', 404);
    if ($_SERVER['REQUEST_METHOD'] !== $methods[$action]) { header('Allow: ' . $methods[$action]); fail('请求方法不正确。', 405); }
    // 删除、改名、封禁均使既有会话失效；解封也不会让旧会话复活。
    if (isset($_SESSION['uid']) && !activeAccount($_SESSION['uid'])) resetSession([]);
    if ($action === 'me') respond(identity());
    if ($action === 'login') login(readInput(1048576));
    $uid = $_SESSION['uid'] ?? null;
    if (!$uid) fail('请先登录。', 401);
    $body = $methods[$action] === 'POST' ? readInput(8192) : [];
    switch ($action) {
        case 'start': startPaper($body, $uid); break;
        case 'logout': resetSession([]); respond(identity()); break;
        case 'submit': submitPaper($body, $uid); break;
        case 'history': history($uid); break;
        case 'draft':
            $row = query('SELECT answered,total,position FROM practice_drafts WHERE user_id=?', [$uid])->fetch();
            respond(['draft'=>$row ? ['answered'=>(int)$row['answered'],'total'=>(int)$row['total'],'index'=>(int)$row['position']] : null]); break;
        case 'saveDraft': saveDraft($body,$uid); break;
        case 'resumeDraft': resumeDraft($uid); break;
        case 'detail':
            $row = record(attemptId($_GET['id'] ?? null), $uid);
            if (!$row) fail('找不到这条练习记录。', 404);
            respond(detail($row));
    }
} catch (Throwable $e) {
    error_log('ai-practice: ' . $e->getMessage());
    fail('服务暂时不可用，请稍后重试。', 503);
}
