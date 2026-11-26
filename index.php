<?php
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'");
header('Referrer-Policy: same-origin');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-cache');
?>
<!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="theme-color" content="#f1f6ff">
  <meta name="description" content="计算机程序设计员（四级/中级）理论知识模拟练习，支持随机组卷、成绩统计与逐题复盘。">
  <title>计算机程序设计员 · 理论练习室</title>
  <link rel="icon" type="image/svg+xml" href="favicon.svg?v=blue-1">
  <link rel="stylesheet" href="style.css?v=practice-draft-3">
  <script src="app.js?v=login-required-1" defer></script>
</head>
<body>
<a class="skip" href="#main">跳到主要内容</a>
<header class="topbar"><div class="frame bar-inner">
  <div class="brand"><span class="brand-mark" aria-hidden="true">&lt;/&gt;</span><span>理论练习室</span></div>
  <div class="account" id="account"><span class="account-label">连接中…</span></div>
</div></header>
<div class="frame shell">
  <div id="notice" role="alert" hidden></div><div id="activity" class="sr-only" role="status"></div>
  <main id="main" tabindex="-1">
    <section id="home" aria-labelledby="home-title">
      <div class="hero">
        <div class="hero-copy"><div class="hero-tag"><i></i> 2026 年 11 月复习</div>
          <h1 id="home-title">计算机程序设计员</h1><p class="hero-sub">四级 / 中级 · 理论知识模拟练习</p>
          <div class="hero-facts"><div><strong id="bank-total">—</strong><span>题库总量</span></div><div><strong id="bank-pick">—</strong><span>模拟考试题量</span></div></div>
        </div>
        <svg class="hero-art" viewBox="0 0 215 165" aria-hidden="true" focusable="false"><rect width="215" height="165" fill="transparent"/><g class="stack-float" fill="none" stroke="currentColor" stroke-width="1.3">
          <g class="stack-layer"><path d="m18 106 83 45 94-52-82-45z" opacity=".38"/></g>
          <g class="stack-layer"><path d="m18 92 83 45 94-52-82-45z" opacity=".65"/></g>
          <g class="stack-layer"><path d="m18 78 83 45 94-52-82-45z" fill="#285596"/><path d="m70 67-16 6 14 9m64-27 14 9-16 6m-21-17-24 34" stroke="#d8f2ff" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/></g>
          <path d="M19 45v-9h9m155 87h9v-9" opacity=".55"/>
        </g></svg>
      </div>
      <div class="section-head"><div><div class="kicker">STUDY DESK</div><h2>练习工作台</h2><p class="muted">登录后可开始练习或模拟考试，交卷成绩会保存到当前账号。</p></div></div>
      <div class="home-grid">
        <article class="panel home-card"><span class="card-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M13 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V10zM13 3v7h7M8 14h8m-8 3h5"/></svg></span><h3>练习模式</h3><p>按顺序完成 300 题后交卷。答对 3 秒后进入下一题，答错看解析后手动翻题。中途退出时可保存进度。</p><button id="practice-home" data-do="start" data-value="practice" class="primary" disabled>开始练习 <b aria-hidden="true">→</b></button></article>
        <article class="panel home-card"><span class="card-icon blue" aria-hidden="true">100</span><h3>模拟考试</h3><p>随机抽取 100 道题，交卷前可修改答案，不提前显示正解。</p><button id="start-home" data-do="start" data-value="exam" class="secondary" disabled>开始考试 <b aria-hidden="true">→</b></button></article>
        <article class="panel home-card history-entry"><span class="card-icon blue" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M3 11a9 9 0 1 1 2 7m-2-5v-4m0 4h4m5-6v5l3 2"/></svg></span><h3>我的练习记录</h3><p>按时间查看已提交的试卷，重新查看作答与正解，定位需要巩固的题目。</p><button id="history-home" data-do="history" class="secondary" disabled>查看历史 <b aria-hidden="true">→</b></button></article>
      </div>
    </section>
    <section id="workspace" hidden></section>
    <noscript><p class="panel">请开启 JavaScript，以便登录、答题及查看练习记录。</p></noscript>
  </main>
  <footer><span>理论练习室 · <a href="https://github.com/ljy2231/ai-practice" target="_blank" rel="noopener noreferrer" aria-label="@LJY2231 的理论练习室 GitHub 仓库">@LJY2231</a></span><span>计算机程序设计员 · 四级 / 中级</span></footer>
</div>
<dialog id="practice-choice" aria-labelledby="practice-choice-title">
  <div class="dialog-top"><span class="kicker">练习进度</span><button type="button" class="close" data-do="cancel-practice" aria-label="取消操作">×</button></div>
  <h2 id="practice-choice-title"></h2><div class="choice-actions" id="practice-choice-actions"></div>
</dialog>
<dialog id="login-dialog" aria-labelledby="login-title"><form id="login-form">
  <div class="dialog-top"><span class="kicker">ACCOUNT</span><button type="button" class="close" data-do="close-login" aria-label="关闭登录窗口">×</button></div>
  <h2 id="login-title">登录练习室</h2><p class="muted" id="login-note">首次登录将自动创建账号，请记住用户名和密码。</p>
  <label for="username">用户名</label><input id="username" name="username" autocomplete="username" required placeholder="输入用户名">
  <label for="password">密码</label><input id="password" name="password" type="password" required autocomplete="current-password" placeholder="输入密码">
  <p id="login-error" class="form-error" role="alert" hidden></p><button type="submit" class="primary fill">登录 / 创建账号</button>
</form></dialog>
</body>
</html>
