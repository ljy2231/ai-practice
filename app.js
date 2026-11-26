(() => {
  'use strict';
  const $ = id => document.getElementById(id);
  const main = $('main'), work = $('workspace'), dialog = $('login-dialog');
  const state = { ready:false, user:null, csrf:'', view:'home', paper:null, index:0, page:1, pages:1, items:[], source:'submit', pending:null, busy:false };
  const modeTitle = p => p.mode==='practice'?'练习模式':'模拟考试';
  const advanceSeconds=3;
  const practiceActive=()=>state.view==='exam' && state.paper?.mode==='practice';
  const firstUnanswered=p=>p.answers.findIndex(a=>a==null);
  const reachable=(p,i,first=firstUnanswered(p))=>Number.isInteger(i) && i>=0 && i<p.questions.length && (p.answers[i]!=null || i===first);
  let advanceTimer=null;
  function cancelAdvance() {
    clearTimeout(advanceTimer); advanceTimer=null;
    $('advance-countdown')?.remove();
  }
  const readingScroll = () => matchMedia('(prefers-reduced-motion: reduce)').matches ? 'instant' : 'smooth';
  function revealPracticeFeedback() {
    const anchor=work.querySelector('.feedback-anchor');
    // 锚点不参与弹出动画，按最终高度定位；只滚动一次，不锁定用户的阅读位置。
    const {top,bottom,height}=anchor.getBoundingClientRect(), vh=document.documentElement.clientHeight;
    const card=anchor.closest('.question').getBoundingClientRect();
    if (card.bottom>vh-12 || top<12 || bottom>vh-12) {
      anchor.scrollIntoView({block:height>vh-24?'start':'center',behavior:readingScroll()});
    }
  }
  function jump(index) {
    const practice=practiceActive();
    if (practice && !reachable(state.paper,index)) return notice('请按顺序完成当前未答题，已答题可随时回看。');
    cancelAdvance(); state.index=index; renderQuestion();
    $('question-title').focus({preventScroll:true});
    if (practice) {
      const card=work.querySelector('.question'), top=card.getBoundingClientRect().top;
      if (top<12 || top>document.documentElement.clientHeight/2) card.scrollIntoView({block:'start',behavior:readingScroll()});
    } else window.scrollTo(0,0);
  }
  const make = (tag, cls, text) => {
    const el = document.createElement(tag);
    if (cls) el.className = cls;
    if (text !== undefined) el.textContent = text;
    return el;
  };
  const button = (text, action, cls='secondary', value) => {
    const b = make('button', cls, text); b.type = 'button'; b.dataset.do = action;
    if (value !== undefined) b.dataset.value = value;
    return b;
  };
  const actionRow = (...children) => { const row = make('div','actions'); row.append(...children); return row; };
  const label = i => String.fromCharCode(65 + i);
  const timeText = t => {
    const d = new Date(t); return Number.isNaN(+d) ? '时间未知' : new Intl.DateTimeFormat('zh-CN',{year:'numeric',month:'2-digit',day:'2-digit',hour:'2-digit',minute:'2-digit',second:'2-digit',hourCycle:'h23',timeZone:'Asia/Shanghai'}).format(d)+'（北京时间）';
  };
  const percent = (value,total) => `${total ? +(100*value/total).toFixed(1) : 0}%`;
  const attempted = p => p.answers.reduce((n,a) => n + Number(a != null),0);
  const verdict = (p,i) => p.answers[i] == null ? 'miss' : p.correct[i] === p.answers[i] ? 'right' : 'wrong';
  const names = { right:'答对', wrong:'答错', miss:'未答' };
  const firstMistake = p => p.questions.findIndex((_,i) => verdict(p,i) !== 'right');
  function notice(message, retry=false) {
    const n = $('notice'); n.replaceChildren(); n.hidden = !message;
    if (message) { n.append(make('span','',message)); if (retry) n.append(button('重新连接','connect','text-link')); n.scrollIntoView({block:'nearest'}); }
  }
  async function api(action, body, params={}) {
    const url = new URL('api.php',location.href); url.search = new URLSearchParams({action,...params});
    const options = {method:body===undefined ? 'GET' : 'POST', credentials:'same-origin', cache:'no-store', headers:{'Accept':'application/json'}};
    if (body!==undefined) { options.headers['Content-Type']='application/json'; options.headers['X-CSRF-Token']=state.csrf; options.body=JSON.stringify(body); }
    let r;
    try { r = await fetch(url,options); } catch { throw new Error('无法连接到服务器，请检查网络后重试。'); }
    let data; try { data = await r.json(); } catch { throw new Error('服务暂未返回有效数据，请稍后重试。'); }
    if (!r.ok) { const err = new Error(data?.error || '操作未完成，请重试。'); err.status = r.status; throw err; }
    return data;
  }
  function session(info) {
    state.ready=true; state.user=info.user; state.csrf=info.csrf;
    $('bank-total').textContent=info.total; $('bank-pick').textContent=info.pick;
    $('practice-home').disabled=false; $('start-home').disabled=false; $('history-home').disabled=false;
    const box=$('account'); box.replaceChildren();
    if (state.user) { const name=make('span','account-label',state.user); name.title=state.user; box.append(name,button('退出登录','logout','mini')); }
    else box.append(button('登录 / 注册','login','mini'));
  }
  function unload(e) { e.preventDefault(); e.returnValue=''; }
  function view(v) {
    cancelAdvance(); state.mapOpen=false;
    window.removeEventListener('beforeunload',unload);
    if (v==='exam') window.addEventListener('beforeunload',unload);
    state.view=v; $('home').hidden=v!=='home'; work.hidden=v==='home';
    if (v==='home') { state.paper=null; work.replaceChildren(); }
    if (v==='exam'||v==='review') renderQuestion();
    if (v==='result') renderResult();
    if (v==='history') renderHistory();
    main.focus({preventScroll:true}); window.scrollTo(0,0);
  }
  function head(code,title,sub,actions) {
    const wrap=make('div','section-head'), group=make('div'); group.append(make('div','kicker',code),make('h1','',title));
    if (sub) group.append(make('p','muted',sub)); wrap.append(group); if (actions) wrap.append(actions); return wrap;
  }
  function showLogin(pending=null) {
    state.pending=pending; $('login-error').hidden=true;
    $('login-title').textContent=pending==='practice'||pending==='exam'?'请先登录再答题':'登录练习室';
    dialog.showModal(); $('username').focus();
  }
  dialog.addEventListener('close',()=>{ $('password').value=''; state.pending=null; });
  dialog.addEventListener('cancel',e=>{ if (state.busy) e.preventDefault(); });
  const leave = () => state.view!=='exam' || window.confirm('本套练习尚未提交，离开将放弃当前作答。确定离开吗？');
  const practiceDialog=$('practice-choice');
  let exitTarget=null;
  practiceDialog.addEventListener('cancel',e=>{ if (state.busy) e.preventDefault(); });
  function offerPracticeChoice(title,options) {
    $('practice-choice-title').textContent=title;
    $('practice-choice-actions').replaceChildren(...options.map(([text,action,cls])=>button(text,action,cls)));
    practiceDialog.showModal();
  }
  function confirmPracticeExit(target) {
    exitTarget=target;
    offerPracticeChoice('是否保存当前答题记录到云端？',[
      ['保存并退出','save-exit','primary'],['不保存并退出','leave-practice','secondary'],['继续答题','cancel-practice','text-link']
    ]);
  }
  async function exitPractice(save) {
    const p=state.paper, target=exitTarget;
    if (!practiceActive() || !target) return;
    if (save) {
      try {
        const result=await api('saveDraft',{id:p.id,answers:p.answers,index:state.index});
        if (!result?.saved) throw new Error('服务器未确认保存，请重试。');
      } catch (error) {
        // 本次写入未确认，保留原题和作答；不要走通用会话失效时返回首页的逻辑。
        notice(`保存失败，仍停留在当前题目。${error.message}`); return;
      }
    }
    if (target==='logout') session(await api('logout',{}));
    exitTarget=null; view('home');
  }
  function enterPaper(data,resume=false) {
    if (!data?.questions?.length) throw new Error('本次没有抽到题目，请重试。');
    if (resume && (!Array.isArray(data.answers) || data.answers.length!==data.questions.length)) throw new Error('草稿数据不完整，无法继续作答。');
    const p={...data,answers:resume?data.answers:Array(data.questions.length).fill(null)};
    state.index=resume ? (reachable(p,data.index)?data.index:Math.max(0,firstUnanswered(p))) : 0;
    state.paper=p; view('exam');
  }
  async function start(mode='practice') {
    if (!state.user) return showLogin(mode);
    const active=practiceActive();
    if (state.view==='exam' && !window.confirm('重新开始将清空本套作答，确定继续吗？')) return;
    if (mode==='practice' && state.user && !active) {
      const {draft}=await api('draft');
      if (draft) {
        offerPracticeChoice(`你上次答了 ${draft.answered} / ${draft.total} 题，是否继续答题？`,[
          ['继续答题','resume-practice','primary'],['重新开始','restart-practice','secondary'],['取消','cancel-practice','text-link']
        ]); return;
      }
    }
    const body={mode};
    if (mode==='practice' && state.user && active) body.discardDraft=true;
    enterPaper(await api('start',body));
  }
  async function submit() {
    const p=state.paper, n=p.questions.length-attempted(p);
    if (p.mode==='practice' && n) return notice('练习模式需按顺序完成全部题目后才能交卷。');
    const status=n ? `还有 ${n} 题未答，未答计入错题率。` : '你已答完全部题目。';
    if (window.confirm(`${status}确认交卷并保存到当前账号？`)) {
      state.paper=await api('submit',{id:p.id,answers:p.answers}); state.source='submit'; view('result');
    }
  }
  async function historyPage(page) {
    if (!state.user) return showLogin('history');
    if (!leave()) return;
    const data=await api('history',undefined,{page}); state.items=data.items; state.page=data.page||1; state.pages=Math.max(1,data.pages||1);
    state.paper=null; view('history');
  }
  async function detail(id) { state.paper=await api('detail',undefined,{id}); state.source='history'; state.index=0; view('result'); }
  function renderQuestion() {
    const p=state.paper, review=state.view==='review', i=state.index, len=p.questions.length, q=p.questions[i];
    const practice=p.mode==='practice', guided=practice&&!review, first=guided?firstUnanswered(p):-1;
    const revealed=review || (practice && p.answers[i]!=null);
    const submitButton=cls=>{
      const b=button('提交试卷','submit',cls); b.disabled=guided && first!==-1;
      if (b.disabled) b.title='完成全部练习题后才能交卷'; return b;
    };
    const nav=review ? actionRow(button('返回成绩','result','mini')) : actionRow(button('练习首页','home','mini'),button(practice?'重练全套':'重新抽题','start','mini',p.mode));
    const header=head(review?'PAPER REVIEW':practice?'GUIDED PRACTICE':'MOCK EXAM',review?'逐题复盘':modeTitle(p),review?timeText(p.time):practice?`请按顺序答题，已答题可回看。答对 ${advanceSeconds} 秒后自动翻题，答错请看解析后手动继续。完成全部题目后可交卷。`:'单选题选定后自动进入下一题。交卷前可返回修改答案。',nav);
    const typeBar=make('div','type-bar'); typeBar.setAttribute('aria-label','按题型跳转');
    for(const [type,name] of [['single','单选题'],['tf','判断题']]) {
      const indices=p.questions.flatMap((item,j)=>item.type===type?[j]:[]);
      if (!indices.length) continue;
      const dest=(guided ? indices.find(j=>j===first) ?? indices.find(j=>p.answers[j]!=null) : indices.find(j=>p.answers[j]==null)) ?? indices[0];
      const tab=button(`${name} · ${indices.length}`,'jump','mini',dest);
      tab.disabled=guided && !reachable(p,dest,first); if (tab.disabled) tab.title='请先按顺序完成前面的题目';
      tab.setAttribute('aria-pressed',String(type===q.type)); typeBar.append(tab);
    }
    const layout=make('div','exam-grid'), panel=make('article','panel question'), rail=make('aside','rail');
    const meta=make('div','q-meta'); meta.append(make('span','badge',q.type==='tf'?'判断题':'单选题'),make('span','q-no',`第 ${i+1} / ${len} 题`));
    const title=make('h2','q-title',q.text); title.id='question-title'; title.tabIndex=-1;
    const options=make('div','options'); options.setAttribute('role','group'); options.setAttribute('aria-labelledby',title.id);
    q.options.forEach((text,k)=>{
      const selected=p.answers[i]===k, correct=revealed && p.correct[i]===k;
      const el=revealed ? make('div','option') : button('','answer','option',k);
      if (!revealed) el.setAttribute('aria-pressed',String(selected));
      if (correct) el.classList.add('is-correct'); else if (selected) el.classList.add(revealed?'is-wrong':'selected');
      el.append(make('span','letter',label(k)),make('span','option-text',text));
      if (revealed && (selected||correct)) el.append(make('small','opt-note',selected&&correct?'你的答案 · 正解':correct?'正确答案':'你的答案'));
      options.append(el);
    });
    panel.append(meta,title,options);
    if (revealed) {
      const v=verdict(p,i), feedback=make('div',`feedback ${v}`); feedback.setAttribute('role','status');
      feedback.append(make('strong','',v==='right'?'回答正确':v==='wrong'?'回答错误':'未作答'),make('p','',`正确答案：${label(p.correct[i])}. ${q.options[p.correct[i]]}`));
      if (p.explanations?.[i]) feedback.append(make('p','explanation',`解析：${p.explanations[i]}`));
      if (!review && i===len-1) feedback.append(make('p','',practice?'已到最后一题，可交卷或回看已答题。':'已到最后一题，可提交或用答题卡查漏。'));
      if (practice && !review) {
        const anchor=make('div','feedback-anchor'); anchor.append(feedback); panel.append(anchor);
      } else panel.append(feedback);
    }
    const prev=button('← 上一题','jump','secondary',i-1); prev.disabled=i===0 || (guided && !reachable(p,i-1,first));
    const next=i+1<len ? button('下一题 →','jump','primary',i+1) : review ? button('返回成绩','result','primary') : submitButton('primary');
    if (i+1<len) {
      next.id='next-question'; next.disabled=guided && (p.answers[i]==null || !reachable(p,i+1,first));
      if (next.disabled) next.title='请先回答当前未答题';
    }
    panel.append(actionRow(prev,next));
    const summary=make('section','panel progress-card');
    summary.append(make('div','kicker',review?'REVIEW':'PROGRESS'),make('h3','',review?'复盘进度':'作答进度'));
    const progress=make('p','progress-count'); progress.id='count'; const bar=make('progress'); bar.max=len; bar.id='progress-bar'; bar.setAttribute('aria-label',review?'当前复盘题号':'已答题数');
    summary.append(progress,bar); if (!review) summary.append(submitButton('secondary fill'));
    const card=make('details','panel map-card'), toggle=make('summary','map-toggle','答题卡'); card.append(toggle);
    card.open=Boolean(state.mapOpen);
    card.addEventListener('toggle',()=>{if (card.isConnected) state.mapOpen=card.open;});
    const legend=make('div','legend'); (review?['right','wrong','miss']:practice?['right','wrong','empty']:['done','empty']).forEach(c=>legend.append(make('span',c,names[c]||({done:'已答',empty:'未答'})[c])));
    card.append(legend); const lists=make('div','map-scroll');
    for(const [type,name] of [['single','单选题'],['tf','判断题']]) {
      const grid=make('div','map'); p.questions.forEach((item,j)=>{
        if (item.type!==type) return;
        const status=review || (practice && p.answers[j]!=null)?verdict(p,j):p.answers[j]!=null?'done':'empty', b=button(j+1,'jump',`cell ${status}`,j);
        b.disabled=guided && !reachable(p,j,first); if (b.disabled) b.title='请先按顺序完成前面的题目';
        if (j===i) b.setAttribute('aria-current','true'); b.setAttribute('aria-label',`第 ${j+1} 题，${names[status]||(status==='done'?'已答':'未答')}`); grid.append(b);
      }); if(grid.childElementCount) lists.append(make('h4','',name),grid);
    }
    card.append(lists); rail.append(summary,card); layout.append(panel,rail); work.replaceChildren(header,typeBar,layout); updateCount();
  }
  function updateCount() {
    const p=state.paper, review=state.view==='review', n=review?state.index+1:attempted(p);
    $('count').textContent=`${review?'当前':'已答'} ${n} / ${p.questions.length} 题`; $('progress-bar').value=n;
  }
  function answer(k) {
    const p=state.paper, i=state.index;
    if (p.mode==='practice' && i!==firstUnanswered(p)) return;
    p.answers[i]=k;
    if (p.mode==='practice') {
      renderQuestion();
      work.querySelector('.feedback-anchor').classList.add('just-answered');
      revealPracticeFeedback();
      if (p.correct[i]===k && i+1<p.questions.length) {
        const countdown=make('span'); countdown.id='advance-countdown'; $('next-question').append(countdown);
        const deadline=performance.now()+advanceSeconds*1000;
        const tick=()=>{
          if(state.view!=='exam' || state.paper!==p || state.index!==i || !countdown.isConnected || document.querySelector('dialog[open]')) return cancelAdvance();
          const left=deadline-performance.now();
          if(left<=0) return jump(i+1);
          const seconds=Math.ceil(left/1000); countdown.textContent=`（${seconds} 秒）`;
          advanceTimer=setTimeout(tick,Math.max(1,Math.ceil(left-(seconds-1)*1000)));
        };
        tick();
      }
      return;
    }
    if (p.mode==='exam' && p.questions[i].type==='single' && i+1<p.questions.length) return jump(i+1);
    work.querySelectorAll('.option').forEach((b,j)=>{ b.classList.toggle('selected',j===k); b.setAttribute('aria-pressed',String(j===k)); });
    const cell=work.querySelector('[aria-current="true"]'); cell.classList.replace('empty','done');cell.setAttribute('aria-label',`第 ${i+1} 题，已答`); updateCount();
  }
  function renderResult() {
    const p=state.paper, first=firstMistake(p), card=make('section','panel score-card');
    const score=make('div','score-lead'); score.append(make('span','kicker','ACCURACY'),make('strong','score',percent(p.right,p.total)),make('span','','正确率'));
    const facts=make('dl','score-facts'); [[p.total,'总题数',''],[p.right,'答对','right'],[p.wrong,'答错','wrong'],[p.miss,'未答','miss']].forEach(([v,t,cls])=>{const div=make('div',cls);div.append(make('dt','',t),make('dd','',v));facts.append(div);}); card.append(score,facts);
    const review=make('article','panel result-actions'); review.append(make('h2','','试卷复盘'),make('p','muted',`错题率（含未答）：${percent(p.wrong+p.miss,p.total)}。${first<0?'本套全部答对。':'可直接跳到第一道答错或未答的题目。'}`));
    const actions=actionRow(button('逐题复盘','review','primary',0)); if(first>=0) actions.append(button('定位首错','review','secondary',first)); review.append(actions);
    work.replaceChildren(head(state.source==='history'?'ARCHIVED RESULT':'SESSION RESULT',state.source==='history'?'历史成绩':'练习成绩',`${modeTitle(p)} · ${timeText(p.time)} · 已保存到云端`,actionRow(button('练习首页','home','mini'))),card,review,actionRow(button(state.source==='history'?'返回记录列表':'查看历史','history','secondary',state.source==='history'?state.page:1),button(p.mode==='practice'?'重练全套':'重新抽题','start','secondary',p.mode)));
  }
  function renderHistory() {
    const list=make('div','history-list');
    if (!state.items.length) { const empty=make('div','panel empty-state'); empty.append(make('h2','','还没有练习记录'),make('p','muted','完成并提交一套练习后，成绩会出现在这里。'),button('开始练习','start','primary')); list.append(empty); }
    state.items.forEach(item=>{
      const card=make('article','panel history-row'), left=make('div'); left.append(make('h2','history-time',timeText(item.time)),make('p','muted',`${modeTitle(item)} · 共 ${item.total} 题 · 答对 ${item.right} · 答错 ${item.wrong} · 未答 ${item.miss}`));
      const r=make('div','history-score'); r.append(make('strong','',percent(item.right,item.total)),make('small','','正确率')); card.append(left,r,button('查看详情','detail','secondary',item.id)); list.append(card);
    });
    const prev=button('上一页','history','secondary',state.page-1), next=button('下一页','history','secondary',state.page+1);prev.disabled=state.page<=1; next.disabled=state.page>=state.pages;
    const pager=actionRow(prev,make('span','muted',`${state.page} / ${state.pages} 页`),next);pager.classList.add('pager');
    work.replaceChildren(head('PRACTICE LOG','我的练习记录','查看已提交的练习，选择一条记录进入复盘。',actionRow(button('练习首页','home','mini'))),list); if (state.items.length) work.append(pager);
  }
  async function run(fn) {
    if (state.busy) return; state.busy=true; document.body.classList.add('busy'); main.setAttribute('aria-busy','true'); $('activity').textContent='处理中…'; notice('');
    try { await fn(); }
    catch(error) {
      if (error.status===401 && state.user) {
        state.user=null; state.ready=false; view('home'); resetConnection(); try { session(await api('me')); } catch { /* 可手动重新连接 */ }
      } else if (error.status===403 && state.ready) {
        // 仅刷新会话，不自动重放失败的写操作。
        try { const info=await api('me'); if(info.user!==state.user) view('home'); session(info); } catch { /* 保留原操作报错 */ }
      }
      if (dialog.open) { $('login-error').textContent=error.message; $('login-error').hidden=false; }
      else notice(error.message,!state.ready);
    } finally {state.busy=false;document.body.classList.remove('busy');main.removeAttribute('aria-busy');$('activity').textContent='';}
  }
  function resetConnection() { $('practice-home').disabled=true; $('start-home').disabled=true; $('history-home').disabled=true; $('account').replaceChildren(make('span','account-label','尚未连接')); }
  document.addEventListener('click',event=>{
    const b=event.target.closest('button[data-do]'); if (!b || b.disabled || state.busy) return;
    const a=b.dataset.do,v=b.dataset.value;
    cancelAdvance();
    if(a==='close-login') return dialog.close();
    if(a==='login') return showLogin();
    if(a==='answer' && state.view==='exam') return answer(Number(v));
    if(a==='jump') { if(b.classList.contains('cell')) state.mapOpen=false; return jump(Number(v)); }
    if(a==='review') { state.index=Number(v);view('review');return; }
    if(a==='result') return view('result');
    if(a==='home' || a==='logout') {
      if (practiceActive()) return confirmPracticeExit(a);
      if (a==='home') {if(leave()){notice('');view('home');} return;}
    }
    if (b.closest('#practice-choice')) {
      practiceDialog.close();
      if (a==='cancel-practice') return;
      if (a==='save-exit' || a==='leave-practice') return void run(()=>exitPractice(a==='save-exit'));
      if (a==='resume-practice') return void run(async()=>enterPaper(await api('resumeDraft',{}),true));
      if (a==='restart-practice') return void run(async()=>enterPaper(await api('start',{mode:'practice',discardDraft:true})));
    }
    void run(async()=>{
      if(a==='connect') session(await api('me'));
      if(a==='start') await start(v || 'practice');
      if(a==='submit') await submit();
      if(a==='history') await historyPage(Number(v)||1);
      if(a==='detail') await detail(v);
      if(a==='logout' && window.confirm(state.view==='exam'?'当前作答尚未提交，退出将放弃本套练习。确定退出登录吗？':'确定退出当前账号吗？')) { session(await api('logout',{})); view('home'); }
    });
  });
  $('login-form').addEventListener('submit',event=>{
    event.preventDefault(); void run(async()=>{
      const username=$('username').value, password=$('password').value;
      const pending=state.pending; session(await api('login',{username,password})); dialog.close(); $('login-form').reset();
      if(pending==='practice'||pending==='exam') await start(pending);
      if(pending==='history') await historyPage(1);
    });
  });
  resetConnection(); void run(async()=>session(await api('me')));
})();
