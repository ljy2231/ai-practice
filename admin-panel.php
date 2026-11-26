<?php
if (!defined('ADMIN_PANEL_AUTHORIZED')) { http_response_code(404); exit; }

const ADMIN_PANEL_PAGE_SIZE = 25;

class AdminPanelInputException extends RuntimeException {}

function adminPanelQuery(PDO $db, string $sql, array $params = []): PDOStatement {
    $query = $db->prepare($sql);
    foreach (array_values($params) as $index => $value) {
        $query->bindValue($index + 1, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $query->execute();
    return $query;
}

function adminPanelUrl(string $view, array $params = []): string {
    return 'admin.php?' . http_build_query(['view' => $view] + $params, '', '&', PHP_QUERY_RFC3986);
}

function adminPanelField(array $input, string $field): string {
    $value = $input[$field] ?? '';
    if (!is_string($value)) throw new AdminPanelInputException('请求参数格式无效。', 400);
    return $value;
}

function adminPanelInt($value, int $minimum = 0): ?int {
    if (!is_string($value) || !preg_match('/\A[0-9]+\z/', $value)) return null;
    $value = ltrim($value, '0');
    $parsed = filter_var($value === '' ? '0' : $value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $minimum]]);
    return $parsed === false ? null : $parsed;
}

function adminPanelAttemptId($value): ?string {
    return is_string($value) && preg_match('/\A[a-f0-9]{32}\z/', $value) ? $value : null;
}

function adminPanelSearch(array $input): string {
    $term = adminPanelField($input, 'q');
    if (strlen($term) > 512 || strpos($term, "\0") !== false || !preg_match('//u', $term)) {
        throw new AdminPanelInputException('搜索内容无效或过长。', 400);
    }
    return $term;
}

function adminPanelAudit(PDO $db, string $action, string $target, array $detail): void {
    adminPanelQuery($db, 'INSERT INTO admin_log(created_at, action, target, detail) VALUES (?, ?, ?, ?)', [
        time(), $action, $target, json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR),
    ]);
}

function adminPanelTransaction(PDO $db, callable $callback): string {
    // 在读取旧值前获取写锁，确保检查、写入及审计都针对同一份数据。
    $db->exec('BEGIN IMMEDIATE');
    try {
        $result = $callback();
        $db->exec('COMMIT');
        return $result;
    } catch (Throwable $error) {
        try { $db->exec('ROLLBACK'); } catch (Throwable $rollbackError) {
            error_log('ai-practice admin panel: rollback failed');
        }
        throw $error;
    }
}

function adminPanelForm(string $action, array $fields = [], string $class = 'actions'): void {
    echo '<form method="post" action="admin.php" class="', h($class), '">';
    foreach (['csrf' => adminCsrf(), 'panel_action' => $action] + $fields as $name => $value) {
        echo '<input type="hidden" name="', h($name), '" value="', h($value), '">';
    }
}

function adminPanelConfirm(string $text): void {
    echo '<label><input type="checkbox" name="confirm" value="yes" required> ', h($text), '</label>';
}

function adminPanelRequireConfirm(array $input): void {
    if (($input['confirm'] ?? null) !== 'yes') {
        throw new AdminPanelInputException('请先勾选对应操作的确认框。');
    }
}

function adminPanelGetUser(PDO $db, int $id) {
    return adminPanelQuery($db, 'SELECT id, username, created_at, banned FROM users WHERE id = ?', [$id])->fetch(PDO::FETCH_ASSOC);
}

function adminPanelGetRecord(PDO $db, string $id) {
    return adminPanelQuery($db, 'SELECT a.id, a.user_id, u.username, a.mode, a.finished,
        a.right_n, a.wrong_n, a.miss_n, a.total, a.paper, a.answers
        FROM attempts a JOIN users u ON u.id = a.user_id WHERE a.id = ?', [$id])->fetch(PDO::FETCH_ASSOC);
}

function adminPanelScore(array $row): array {
    return [(int)$row['right_n'], (int)$row['wrong_n'], (int)$row['miss_n'], (int)$row['total']];
}

// 仅使用交卷时保留的试卷快照，不读取或依赖现行题库。
function adminPanelSnapshot(array $row): array {
    try {
        $paper = json_decode($row['paper'], false, 16, JSON_THROW_ON_ERROR);
        $answers = json_decode($row['answers'], false, 8, JSON_THROW_ON_ERROR);
    } catch (JsonException $error) {
        throw new AdminPanelInputException('试卷或答题明细格式异常。');
    }
    if (!is_array($paper) || !$paper || !is_array($answers) || count($paper) !== count($answers)) {
        throw new AdminPanelInputException('试卷与作答明细不完整。');
    }

    $right = $wrong = $miss = 0;
    foreach ($paper as $index => $question) {
        if (!is_array($question) || count($question) < 3 || !is_string($question[0])) {
            throw new AdminPanelInputException('试卷含无效题目。');
        }
        $options = $question[1] ?? ['正确', '错误'];
        if (!is_array($options) || !$options) throw new AdminPanelInputException('试卷含无效选项。');
        foreach ($options as $option) {
            if (!is_string($option)) throw new AdminPanelInputException('试卷含无效选项。');
        }
        $correct = $question[2];
        $choice = $answers[$index];
        if (!is_int($correct) || !array_key_exists($correct, $options)) {
            throw new AdminPanelInputException('试卷含无效的标准答案。');
        }
        if ($choice === null) {
            $miss++;
        } elseif (!is_int($choice) || !array_key_exists($choice, $options)) {
            throw new AdminPanelInputException('作答明细含无效答案。');
        } elseif ($choice === $correct) {
            $right++;
        } else {
            $wrong++;
        }
    }
    return [$paper, $answers, [$right, $wrong, $miss, count($paper)]];
}

function adminPanelChangeUser(PDO $db, string $action, int $id, array $input): string {
    return adminPanelTransaction($db, static function () use ($db, $action, $id, $input): string {
        $user = adminPanelGetUser($db, $id);
        if (!$user) throw new AdminPanelInputException('找不到该用户。');
        $target = 'user:' . $id;
        $detail = ['用户名' => $user['username']];
        switch ($action) {
            case 'rename_user':
                // 与站点登录规则保持一致：非空、无 NUL；不裁剪、不折叠大小写。
                $name = adminPanelField($input, 'username');
                if ($name === '' || strpos($name, "\0") !== false || !preg_match('//u', $name)) {
                    throw new AdminPanelInputException('用户名必须是非空文本，且不能包含空字节。');
                }
                if ($name === $user['username']) return '用户名未变更。';
                if (adminPanelQuery($db, 'SELECT 1 FROM users WHERE username = ? AND id <> ?', [$name, $id])->fetchColumn()) {
                    throw new AdminPanelInputException('该用户名已被其他账号占用。');
                }
                adminPanelQuery($db, 'UPDATE users SET username = ?, session_version = session_version + 1 WHERE id = ?', [$name, $id]);
                adminPanelAudit($db, $action, $target, $detail + ['新用户名' => $name]);
                return '用户名已修改。';
            case 'ban_user':
            case 'unban_user':
                $banned = $action === 'ban_user' ? 1 : 0;
                if ($banned === (int)$user['banned']) return '账号状态未变更。';
                adminPanelQuery($db, 'UPDATE users SET banned = ?, session_version = session_version + 1 WHERE id = ?', [$banned, $id]);
                adminPanelAudit($db, $action, $target, $detail);
                return $banned ? '账号已封禁。' : '账号已解封。';
            case 'clear_user_records':
            case 'delete_user':
                adminPanelRequireConfirm($input);
                $records = adminPanelQuery($db, 'DELETE FROM attempts WHERE user_id = ?', [$id])->rowCount();
                $detail['移除成绩数'] = $records;
                if ($action === 'delete_user') {
                    $detail['移除草稿数'] = adminPanelQuery($db, 'DELETE FROM practice_drafts WHERE user_id = ?', [$id])->rowCount();
                    adminPanelQuery($db, 'DELETE FROM users WHERE id = ?', [$id]);
                }
                adminPanelAudit($db, $action, $target, $detail);
                return $action === 'delete_user' ? '账号、成绩及草稿已删除。' : '成绩已清空，练习草稿未删除。';
            default:
                throw new AdminPanelInputException('操作不存在。', 400);
        }
    });
}

function adminPanelChangeRecord(PDO $db, string $action, string $id, array $input): string {
    return adminPanelTransaction($db, static function () use ($db, $action, $id, $input): string {
        $row = adminPanelGetRecord($db, $id);
        if (!$row) throw new AdminPanelInputException('找不到该成绩记录。');
        $target = 'attempt:' . $id;
        $previous = adminPanelScore($row);
        $detail = ['用户编号' => (int)$row['user_id'], '用户名' => $row['username'], '原成绩' => adminPanelDescribeScore($previous)];
        switch ($action) {
            case 'delete_record':
                adminPanelRequireConfirm($input);
                adminPanelQuery($db, 'DELETE FROM attempts WHERE id = ?', [$id]);
                adminPanelAudit($db, $action, $target, $detail);
                return '成绩记录已删除。';
            case 'update_score':
                $right = adminPanelInt($input['right_n'] ?? null);
                $wrong = adminPanelInt($input['wrong_n'] ?? null);
                $miss = adminPanelInt($input['miss_n'] ?? null);
                $total = $previous[3];
                // 分步相减避免任意大输入相加时溢出。
                if ($right === null || $wrong === null || $miss === null || $right > $total
                    || $wrong > $total - $right || $miss !== $total - $right - $wrong) {
                    throw new AdminPanelInputException('答对、答错和未答必须是非负整数，且三项合计须等于总题数。');
                }
                $score = [$right, $wrong, $miss, $total];
                break;
            case 'recalculate_score':
                [, , $score] = adminPanelSnapshot($row);
                break;
            default:
                throw new AdminPanelInputException('操作不存在。', 400);
        }
        if ($action === 'update_score' && $score === $previous) return '成绩未变更。';
        adminPanelQuery($db, 'UPDATE attempts SET right_n = ?, wrong_n = ?, miss_n = ?, total = ? WHERE id = ?', [...$score, $id]);
        adminPanelAudit($db, $action, $target, $detail + ['新成绩' => adminPanelDescribeScore($score)]);
        return $action === 'recalculate_score' ? '已按作答明细重算成绩。' : '成绩已手动修正，作答明细未修改。';
    });
}

function adminPanelDescribeScore(array $score): string {
    return sprintf('答对 %d · 答错 %d · 未答 %d · 共 %d 题', ...$score);
}

function adminPanelDate($value): string {
    return gmdate('Y-m-d H:i:s', (int)$value) . ' UTC';
}

function adminPanelMode(string $mode): string {
    return ['practice' => '练习', 'exam' => '模拟考试'][$mode] ?? '未知模式';
}

function adminPanelRecordFilters(array $input): array {
    $term = adminPanelSearch($input);
    $mode = adminPanelField($input, 'mode');
    $user = adminPanelField($input, 'user_id');
    if ($mode !== '' && !in_array($mode, ['practice', 'exam'], true)) throw new AdminPanelInputException('记录模式无效。', 400);
    $id = $user === '' ? null : adminPanelInt($user, 1);
    if ($user !== '' && $id === null) throw new AdminPanelInputException('用户编号无效。', 400);
    $conditions = [];
    $params = [];
    if ($term !== '') {
        $conditions[] = '(instr(lower(u.username), lower(?)) > 0 OR instr(lower(a.id), lower(?)) > 0)';
        array_push($params, $term, $term);
    }
    if ($mode !== '') { $conditions[] = 'a.mode = ?'; $params[] = $mode; }
    if ($id !== null) { $conditions[] = 'a.user_id = ?'; $params[] = $id; }
    return [$conditions ? ' WHERE ' . implode(' AND ', $conditions) : '', $params,
        array_filter(['q' => $term, 'mode' => $mode, 'user_id' => $id], static fn($value) => $value !== '' && $value !== null)];
}

function adminPanelRecordListSql(): string {
    return 'SELECT a.id, a.user_id, u.username, a.mode, a.finished, a.right_n, a.wrong_n, a.miss_n, a.total';
}

function adminPanelRecordFromSql(): string {
    return ' FROM attempts a JOIN users u ON u.id = a.user_id';
}

function adminPanelCsvField(string $value): string {
    // fputcsv 处理分隔符与引号；前缀阻止表格把文本当作公式，包括前置空白及全角符号。
    $danger = preg_match('/\A(?:[\p{Z}\p{Cc}\p{Cf}]*[=+\-@＝＋－＠]|[\p{Z}\p{Cf}]*[\t\r\n])/u', $value);
    return $danger === 0 ? $value : "'" . $value;
}

function adminPanelExport(PDO $db, array $filter): void {
    [$where, $params, $fields] = $filter;
    adminPanelAudit($db, 'export_records', 'attempts', ['筛选条件' => $fields ?: '全部成绩']);
    $query = adminPanelQuery($db, adminPanelRecordListSql() . adminPanelRecordFromSql() . $where . ' ORDER BY a.finished DESC, a.id DESC', $params);
    $output = fopen('php://output', 'wb');
    if ($output === false) throw new RuntimeException('CSV stream unavailable');
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="practice-records.csv"');
    header('Cache-Control: no-store, private');
    header('X-Content-Type-Options: nosniff');
    fwrite($output, "\xEF\xBB\xBF");
    fputcsv($output, ['记录编号', '用户编号', '用户名', '模式', '交卷时间（UTC）', '答对', '答错', '未答', '总题数'], ',', '"', '');
    while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($output, [adminPanelCsvField($row['id']), (int)$row['user_id'], adminPanelCsvField($row['username']),
            adminPanelMode($row['mode']), gmdate('Y-m-d H:i:s', (int)$row['finished']), ...adminPanelScore($row)], ',', '"', '');
    }
    fclose($output);
}

function adminPanelPage(PDO $db, string $from, array $params): array {
    $page = adminPanelInt($_GET['page'] ?? '1', 1);
    if ($page === null) throw new AdminPanelInputException('页码无效。', 400);
    $count = (int)adminPanelQuery($db, 'SELECT count(*)' . $from, $params)->fetchColumn();
    $pages = max(1, intdiv($count, ADMIN_PANEL_PAGE_SIZE) + ($count % ADMIN_PANEL_PAGE_SIZE !== 0 ? 1 : 0));
    return [min($page, $pages), $pages, $count];
}

function adminPanelPageNav(string $view, array $params, array $pageInfo): void {
    [$page, $pages, $count] = $pageInfo;
    echo '<nav class="actions" aria-label="分页"><span class="muted">共 ', $count, ' 条 · 第 ', $page, ' / ', $pages, ' 页</span> ';
    if ($page > 1) echo '<a class="btn" href="', h(adminPanelUrl($view, $params + ['page' => $page - 1])), '">上一页</a> ';
    if ($page < $pages) echo '<a class="btn" href="', h(adminPanelUrl($view, $params + ['page' => $page + 1])), '">下一页</a>';
    echo '</nav>';
}

function adminPanelRecordTable(array $rows): void {
    if (!$rows) { echo '<p class="muted">没有匹配的成绩记录。</p>'; return; }
    echo '<div class="table-wrap"><table><thead><tr><th>用户</th><th>模式</th><th>交卷时间</th><th>答对</th><th>答错</th><th>未答</th><th>总题数</th><th>操作</th></tr></thead><tbody>';
    foreach ($rows as $row) {
        echo '<tr><td><a href="', h(adminPanelUrl('user', ['id' => $row['user_id']])), '">', h($row['username']), '</a></td><td>',
            h(adminPanelMode($row['mode'])), '</td><td>', h(adminPanelDate($row['finished'])), '</td>';
        foreach (adminPanelScore($row) as $score) echo '<td>', $score, '</td>';
        echo '<td><a class="btn" href="', h(adminPanelUrl('record', ['id' => $row['id']])), '">查看成绩</a></td></tr>';
    }
    echo '</tbody></table></div>';
}

function adminPanelDashboard(PDO $db): void {
    $stats = $db->query("SELECT (SELECT count(*) FROM users) AS users,
        (SELECT count(*) FROM users WHERE banned = 1) AS banned,
        (SELECT count(*) FROM practice_drafts) AS drafts,
        count(*) AS attempts,
        count(CASE WHEN mode = 'practice' THEN 1 END) AS practice,
        count(CASE WHEN mode = 'exam' THEN 1 END) AS exam FROM attempts")->fetch(PDO::FETCH_ASSOC);
    $recent = adminPanelQuery($db, adminPanelRecordListSql() . adminPanelRecordFromSql() . ' ORDER BY a.finished DESC, a.id DESC LIMIT 10')->fetchAll(PDO::FETCH_ASSOC);
    adminHeader('统计看板');
    echo '<h1>统计看板</h1><div class="grid">';
    foreach (['users' => ['注册用户', 'users', []], 'banned' => ['封禁用户', 'users', ['banned' => 1]],
        'attempts' => ['全部成绩', 'records', []], 'practice' => ['练习成绩', 'records', ['mode' => 'practice']],
        'exam' => ['模拟考试成绩', 'records', ['mode' => 'exam']], 'drafts' => ['未交卷练习草稿', null, []]] as $key => [$label, $view, $params]) {
        echo '<section class="panel"><h2>', h($label), '</h2><p><strong>', (int)$stats[$key], '</strong></p>';
        if ($view !== null) echo '<a href="', h(adminPanelUrl($view, $params)), '">查看', h($label), '</a>';
        echo '</section>';
    }
    echo '</div><section class="panel"><h2>最近交卷</h2>';
    adminPanelRecordTable($recent);
    echo '</section>';
    adminFooter();
}

function adminPanelUsers(PDO $db): void {
    $term = adminPanelSearch($_GET);
    $banned = adminPanelField($_GET, 'banned');
    if (!in_array($banned, ['', '0', '1'], true)) throw new AdminPanelInputException('账号状态筛选无效。', 400);
    $conditions = [];
    $params = [];
    if ($term !== '') {
        $conditions[] = '(instr(lower(u.username), lower(?)) > 0 OR CAST(u.id AS TEXT) = ?)';
        array_push($params, $term, $term);
    }
    if ($banned !== '') { $conditions[] = 'u.banned = ?'; $params[] = (int)$banned; }
    $from = ' FROM users u' . ($conditions ? ' WHERE ' . implode(' AND ', $conditions) : '');
    $page = adminPanelPage($db, $from, $params);
    $rows = adminPanelQuery($db, 'SELECT u.id, u.username, u.created_at, u.banned,
        (SELECT count(*) FROM attempts a WHERE a.user_id = u.id) AS attempts' . $from .
        ' ORDER BY u.created_at DESC, u.id DESC LIMIT ? OFFSET ?', [...$params, ADMIN_PANEL_PAGE_SIZE, ($page[0] - 1) * ADMIN_PANEL_PAGE_SIZE])->fetchAll(PDO::FETCH_ASSOC);
    adminHeader('用户管理');
    echo '<h1>用户管理</h1><section class="panel"><form method="get" action="admin.php" class="actions">
        <input type="hidden" name="view" value="users"><label>用户名或用户编号 <input type="search" name="q" value="', h($term), '"></label>
        <label>账号状态 <select name="banned"><option value="">全部</option><option value="0"', $banned === '0' ? ' selected' : '', '>正常</option><option value="1"',
        $banned === '1' ? ' selected' : '', '>已封禁</option></select></label><button class="btn" type="submit">搜索</button> <a class="btn" href="', h(adminPanelUrl('users')), '">重置</a></form>';
    if (!$rows) echo '<p class="muted">没有匹配的用户。</p>';
    else {
        echo '<div class="table-wrap"><table><thead><tr><th>编号</th><th>用户名</th><th>注册时间</th><th>状态</th><th>成绩数</th><th>操作</th></tr></thead><tbody>';
        foreach ($rows as $user) {
            echo '<tr><td>', (int)$user['id'], '</td><td>', h($user['username']), '</td><td>', h(adminPanelDate($user['created_at'])), '</td><td>',
                $user['banned'] ? '已封禁' : '正常', '</td><td>', (int)$user['attempts'], '</td><td><a class="btn" href="',
                h(adminPanelUrl('user', ['id' => $user['id']])), '">管理用户</a></td></tr>';
        }
        echo '</tbody></table></div>';
    }
    adminPanelPageNav('users', array_filter(['q' => $term, 'banned' => $banned], static fn($value) => $value !== ''), $page);
    echo '</section>';
    adminFooter();
}

function adminPanelUser(PDO $db): void {
    $id = adminPanelInt($_GET['id'] ?? null, 1);
    if ($id === null) throw new AdminPanelInputException('用户编号无效。', 400);
    $user = adminPanelGetUser($db, $id);
    if (!$user) throw new AdminPanelInputException('找不到该用户。', 404);
    $records = (int)adminPanelQuery($db, 'SELECT count(*) FROM attempts WHERE user_id = ?', [$id])->fetchColumn();
    $draft = adminPanelQuery($db, 'SELECT answered, total FROM practice_drafts WHERE user_id = ?', [$id])->fetch(PDO::FETCH_ASSOC);
    $fields = ['user_id' => $id];
    adminHeader('管理用户');
    echo '<h1>管理用户</h1><section class="panel"><h2>', h($user['username']), '</h2><p>编号：', $id,
        ' · 注册时间：', h(adminPanelDate($user['created_at'])), ' · 账号状态：', $user['banned'] ? '已封禁' : '正常',
        '</p><p>练习草稿：', $draft ? ('已答 ' . (int)$draft['answered'] . ' / ' . (int)$draft['total'] . ' 题') : '无',
        '</p><div class="actions"><a class="btn" href="', h(adminPanelUrl('users')), '">返回用户列表</a> <a class="btn" href="',
        h(adminPanelUrl('records', $fields)), '">查看该用户成绩（', $records, ' 条）</a></div></section>';

    echo '<div class="grid"><section class="panel"><h2>修改用户名</h2><p class="muted">改名不改变密码或成绩。登录需使用新用户名；旧用户名可按站点规则重新注册。</p>';
    adminPanelForm('rename_user', $fields);
    echo '<label>新用户名 <input type="text" name="username" autocomplete="off" value="', h($user['username']), '" required></label>
        <button type="submit" class="btn">保存用户名</button></form></section><section class="panel"><h2>账号状态</h2>
        <p class="muted">封禁会阻止该账号登录和继续使用登录后功能，不删除成绩或草稿，也不改变游客练习功能。</p>';
    adminPanelForm($user['banned'] ? 'unban_user' : 'ban_user', $fields);
    echo $user['banned'] ? '<button type="submit" class="btn">解封账号</button>' : '<button type="submit" class="btn danger">封禁账号</button>';
    echo '</form></section></div><div class="grid">
        <section class="panel"><h2>清空成绩</h2><p class="muted">永久删除该用户的所有练习与模拟考试成绩，保留账号和练习草稿。</p>';
    adminPanelForm('clear_user_records', $fields);
    adminPanelConfirm('确认永久清空该用户的所有成绩');
    echo '<button type="submit" class="btn danger">清空成绩</button></form></section>
        <section class="panel"><h2>删除账号</h2><p class="muted">永久删除账号、所有成绩和练习草稿。删除后，同名可重新注册，但不会找回数据。</p>';
    adminPanelForm('delete_user', $fields);
    adminPanelConfirm('确认永久删除该账号及其成绩、草稿');
    echo '<button type="submit" class="btn danger">删除账号</button></form></section></div>';
    adminFooter();
}

function adminPanelRecords(PDO $db): void {
    [$where, $params, $fields] = adminPanelRecordFilters($_GET);
    $from = adminPanelRecordFromSql() . $where;
    $page = adminPanelPage($db, $from, $params);
    $rows = adminPanelQuery($db, adminPanelRecordListSql() . $from . ' ORDER BY a.finished DESC, a.id DESC LIMIT ? OFFSET ?',
        [...$params, ADMIN_PANEL_PAGE_SIZE, ($page[0] - 1) * ADMIN_PANEL_PAGE_SIZE])->fetchAll(PDO::FETCH_ASSOC);
    $mode = $fields['mode'] ?? '';
    adminHeader('成绩记录');
    echo '<h1>成绩记录</h1><section class="panel"><form method="get" action="admin.php" class="actions">
        <input type="hidden" name="view" value="records"><label>用户名或记录编号 <input type="search" name="q" value="', h($fields['q'] ?? ''), '"></label>
        <label>用户编号 <input type="number" name="user_id" min="1" value="', h($fields['user_id'] ?? ''), '"></label>
        <label>模式 <select name="mode"><option value="">全部</option><option value="practice"', $mode === 'practice' ? ' selected' : '', '>练习</option>
        <option value="exam"', $mode === 'exam' ? ' selected' : '', '>模拟考试</option></select></label>
        <button class="btn" type="submit">搜索</button> <a class="btn" href="', h(adminPanelUrl('records')), '">重置</a></form>';
    adminPanelForm('export_records', $fields);
    echo '<button class="btn" type="submit">导出 CSV</button> <span class="muted">导出当前筛选下的所有成绩，不限于当前页。</span></form>';
    adminPanelRecordTable($rows);
    adminPanelPageNav('records', $fields, $page);
    echo '</section>';
    adminFooter();
}

function adminPanelQuestions(array $paper, array $answers): void {
    echo '<ol>';
    foreach ($paper as $index => $question) {
        $choice = $answers[$index];
        $correct = $question[2];
        $options = $question[1] ?? ['正确', '错误'];
        echo '<li><p><strong>', h($question[0]), '</strong></p><ul>';
        foreach ($options as $number => $option) {
            echo '<li>', h($option), ($number === $choice ? '（作答）' : ''), ($number === $correct ? '（正确选项）' : ''), '</li>';
        }
        echo '</ul><p>本题：', $choice === null ? '未答' : ($choice === $correct ? '答对' : '答错'), '</p>';
        if (isset($question[3]) && is_string($question[3])) echo '<p class="muted">解析：', h($question[3]), '</p>';
        echo '</li>';
    }
    echo '</ol>';
}

function adminPanelRecord(PDO $db): void {
    $id = adminPanelAttemptId($_GET['id'] ?? null);
    if ($id === null) throw new AdminPanelInputException('记录编号无效。', 400);
    $row = adminPanelGetRecord($db, $id);
    if (!$row) throw new AdminPanelInputException('找不到该成绩记录。', 404);
    $stored = adminPanelScore($row);
    $snapshot = null;
    $snapshotError = '';
    try { $snapshot = adminPanelSnapshot($row); }
    catch (AdminPanelInputException $error) { $snapshotError = $error->getMessage(); }

    $fields = ['attempt_id' => $id];
    adminHeader('成绩详情');
    echo '<h1>成绩详情</h1><section class="panel"><h2>交卷信息</h2><p>记录编号：', h($id), '</p><p>用户：<a href="',
        h(adminPanelUrl('user', ['id' => $row['user_id']])), '">', h($row['username']), '</a> · 模式：', h(adminPanelMode($row['mode'])),
        ' · 交卷时间：', h(adminPanelDate($row['finished'])), '</p><p>当前成绩：', h(adminPanelDescribeScore($stored)), '</p>';
    if ($snapshot) {
        echo '<p>明细计算：', h(adminPanelDescribeScore($snapshot[2])), $snapshot[2] === $stored ? '' : '（与当前成绩不一致）', '</p>';
    }
    echo '<a class="btn" href="', h(adminPanelUrl('records')), '">返回成绩列表</a></section>
        <div class="grid"><section class="panel"><h2>手动修正成绩</h2><p class="muted">答对、答错与未答之和须等于当前总题数；不会更改作答明细。</p>';
    adminPanelForm('update_score', $fields);
    foreach ([['right_n', '答对'], ['wrong_n', '答错'], ['miss_n', '未答']] as $index => [$name, $label]) {
        echo '<label>', h($label), ' <input type="number" name="', h($name), '" min="0" max="', $stored[3], '" step="1" value="', $stored[$index], '" required></label> ';
    }
    echo '<button class="btn" type="submit">保存成绩</button></form></section><section class="panel"><h2>按明细重算</h2>
        <p class="muted">用交卷时保存的试卷与作答重算答对、答错、未答和总题数，覆盖当前成绩。</p>';
    if ($snapshot) {
        adminPanelForm('recalculate_score', $fields);
        echo '<button type="submit" class="btn">重算成绩</button></form>';
    } else echo '<p class="muted">', h($snapshotError), ' 无法重算。</p>';
    echo '</section><section class="panel"><h2>删除成绩</h2><p class="muted">永久删除这一条记录及其作答明细，不影响其他成绩。</p>';
    adminPanelForm('delete_record', $fields);
    adminPanelConfirm('确认永久删除这一条成绩及明细');
    echo '<button type="submit" class="btn danger">删除记录</button></form></section></div><section class="panel"><h2>逐题明细</h2>';
    if ($snapshot) adminPanelQuestions($snapshot[0], $snapshot[1]);
    else echo '<p class="muted">', h($snapshotError), '</p>';
    echo '</section>';
    adminFooter();
}

function adminPanelLog(PDO $db): void {
    $term = adminPanelSearch($_GET);
    $from = ' FROM admin_log';
    $params = [];
    if ($term !== '') {
        $from .= ' WHERE instr(lower(action), lower(?)) > 0 OR instr(lower(target), lower(?)) > 0 OR instr(lower(detail), lower(?)) > 0';
        $params = [$term, $term, $term];
    }
    $page = adminPanelPage($db, $from, $params);
    $rows = adminPanelQuery($db, 'SELECT id, created_at, action, target, detail' . $from . ' ORDER BY created_at DESC, id DESC LIMIT ? OFFSET ?',
        [...$params, ADMIN_PANEL_PAGE_SIZE, ($page[0] - 1) * ADMIN_PANEL_PAGE_SIZE])->fetchAll(PDO::FETCH_ASSOC);
    $labels = ['rename_user' => '修改用户名', 'ban_user' => '封禁用户', 'unban_user' => '解封用户', 'clear_user_records' => '清空成绩',
        'delete_user' => '删除用户', 'update_score' => '手动修正成绩', 'recalculate_score' => '按明细重算',
        'delete_record' => '删除成绩', 'export_records' => '导出成绩 CSV', 'admin_init' => '设置管理员临时密码',
        'admin_password' => '修改管理员密码'];
    adminHeader('审计日志');
    echo '<h1>审计日志</h1><section class="panel"><form class="actions" method="get" action="admin.php"><input type="hidden" name="view" value="log">
        <label>操作、目标或详情 <input type="search" name="q" value="', h($term), '"></label><button type="submit" class="btn">搜索</button>
        <a class="btn" href="', h(adminPanelUrl('log')), '">重置</a></form>';
    if (!$rows) echo '<p class="muted">没有匹配的审计记录。</p>';
    else {
        echo '<div class="table-wrap"><table><thead><tr><th>编号</th><th>时间</th><th>操作</th><th>目标</th><th>详情</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            echo '<tr><td>', (int)$row['id'], '</td><td>', h(adminPanelDate($row['created_at'])), '</td><td>', h($labels[$row['action']] ?? $row['action']),
                '</td><td>', h($row['target']), '</td><td>', h($row['detail']), '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
    adminPanelPageNav('log', $term !== '' ? ['q' => $term] : [], $page);
    echo '</section>';
    adminFooter();
}

function adminPanelError(int $status, string $message): void {
    http_response_code($status);
    adminHeader('无法完成请求');
    echo '<h1>无法完成请求</h1><section class="panel"><p>', h($message), '</p><a class="btn" href="', h(adminPanelUrl('dashboard')), '">返回统计看板</a></section>';
    adminFooter();
}

function adminPanelPost(): void {
    $url = adminPanelUrl('dashboard');
    try {
        $action = adminPanelField($_POST, 'panel_action');
        if ($action === 'export_records') {
            [$where, $params, $fields] = adminPanelRecordFilters($_POST);
            $url = adminPanelUrl('records', $fields);
            adminPanelExport(adminDb(), [$where, $params, $fields]);
            return;
        }
        if (in_array($action, ['rename_user', 'ban_user', 'unban_user', 'clear_user_records', 'delete_user'], true)) {
            $url = adminPanelUrl('users');
            $id = adminPanelInt($_POST['user_id'] ?? null, 1);
            if ($id === null) throw new AdminPanelInputException('用户编号无效。');
            $url = adminPanelUrl('user', ['id' => $id]);
            $message = adminPanelChangeUser(adminDb(), $action, $id, $_POST);
            if ($action === 'delete_user') $url = adminPanelUrl('users');
        } elseif (in_array($action, ['update_score', 'recalculate_score', 'delete_record'], true)) {
            $url = adminPanelUrl('records');
            $id = adminPanelAttemptId($_POST['attempt_id'] ?? null);
            if ($id === null) throw new AdminPanelInputException('记录编号无效。');
            $url = adminPanelUrl('record', ['id' => $id]);
            $message = adminPanelChangeRecord(adminDb(), $action, $id, $_POST);
            if ($action === 'delete_record') $url = adminPanelUrl('records');
        } else {
            adminPanelError(400, '操作不存在。');
            return;
        }
        adminFlash($message);
    } catch (AdminPanelInputException $error) {
        adminFlash($error->getMessage(), true);
    } catch (Throwable $error) {
        error_log('ai-practice admin panel: action failed (' . get_class($error) . ')');
        if (headers_sent()) return;
        adminFlash('操作未完成，请稍后重试。', true);
    }
    adminRedirect($url);
}

function adminPanelRun(): void {
    $method = $_SERVER['REQUEST_METHOD'] ?? '';
    if ($method === 'POST') { adminPanelPost(); return; }
    if ($method !== 'GET' || array_key_exists('panel_action', $_GET)) {
        header('Allow: ' . ($method === 'GET' ? 'POST' : 'GET, POST'));
        adminPanelError(405, '请求方法不正确。写操作必须使用 POST。');
        return;
    }
    $views = ['dashboard' => 'adminPanelDashboard', 'users' => 'adminPanelUsers', 'user' => 'adminPanelUser',
        'records' => 'adminPanelRecords', 'record' => 'adminPanelRecord', 'log' => 'adminPanelLog'];
    try {
        $view = adminPanelField($_GET, 'view') ?: 'dashboard';
        if (!isset($views[$view])) throw new AdminPanelInputException('页面不存在。', 404);
        $views[$view](adminDb());
    } catch (AdminPanelInputException $error) {
        adminPanelError($error->getCode() === 404 ? 404 : 400, $error->getMessage());
    } catch (Throwable $error) {
        error_log('ai-practice admin panel: page failed (' . get_class($error) . ')');
        if (!headers_sent()) adminPanelError(503, '暂时无法读取管理数据，请稍后重试。');
    }
}

adminPanelRun();
