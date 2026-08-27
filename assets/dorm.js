const dormState={inviteCode:(new URLSearchParams(location.search).get('dorm')||'').toUpperCase(),current:null,pollTimer:null,lastJoinedAttempt:''};

function dormInit(){
  if($('createDormBtn'))$('createDormBtn').onclick=createDorm;
  if($('dormBackBtn'))$('dormBackBtn').onclick=()=>showPage('result');
  if($('dormCopyBtn'))$('dormCopyBtn').onclick=copyDormInvite;
  if($('dormShareBtn'))$('dormShareBtn').onclick=shareDormInvite;
  if($('dormRefreshBtn'))$('dormRefreshBtn').onclick=()=>loadDormStatus(dormState.current?.invite_code||dormState.inviteCode,true);
  if($('dormDoorplateBtn'))$('dormDoorplateBtn').onclick=generateDormDoorplate;
  if($('viewDormBtn'))$('viewDormBtn').onclick=()=>openDorm(dormState.current?.invite_code||dormState.inviteCode);
  window.addEventListener('type-me:result',()=>{if(dormState.inviteCode)joinInvitedDorm().catch(()=>{})});

  if(dormState.inviteCode){
    const banner=$('dormInviteBanner');
    if(banner){banner.hidden=false;$('dormInviteCode').textContent=dormState.inviteCode;}
    if($('startBtn'))$('startBtn').textContent='加入宿舍组合，开始测试 →';
    if($('startBtn2'))$('startBtn2').textContent='加入组合，开始测试';
    track('dorm_invite_view',{dorm_code:dormState.inviteCode});
    loadDormStatus(dormState.inviteCode,false).then(d=>{
      if($('dormInviteProgress'))$('dormInviteProgress').textContent=`当前 ${d.member_count}/4 已完成，完成测试后你会自动加入。`;
    }).catch(()=>{});
  }

  setInterval(()=>{
    if(!dormState.inviteCode||!state.result)return;
    const attempt=String(state.result.attempt_id||state.attemptId||'');
    if(!attempt||attempt===dormState.lastJoinedAttempt)return;
    dormState.lastJoinedAttempt=attempt;
    joinInvitedDorm().catch(()=>{dormState.lastJoinedAttempt=''});
  },500);
}

async function createDorm(){
  if(!state.result){toast('先完成 12 题，再创建宿舍');return}
  const btn=$('createDormBtn'),old=btn.textContent;btn.disabled=true;btn.textContent='正在创建宿舍…';
  try{
    const d=await postJSON(`${API_BASE}/api/dorm/create.php`,{
      attempt_id:state.result.attempt_id||state.attemptId,
      primary_personality:state.result.primary.key,
      secondary_personality:state.result.secondary.key,
      name:$('dormNameInput')?.value.trim()||''
    });
    dormState.current=d.dorm;dormState.inviteCode=d.dorm.invite_code;
    localStorage.setItem('type_me_last_dorm',d.dorm.invite_code);
    renderDorm(d.dorm);showPage('dorm');startDormPolling();toast('宿舍已创建，拉 3 个室友来测');
  }catch(e){console.error('create dorm failed',e);toast('宿舍创建暂未完成，请稍后重试')}finally{btn.disabled=false;btn.textContent=old}
}

async function joinInvitedDorm(){
  if(!dormState.inviteCode||!state.result)return;
  const box=$('dormJoinStatus');if(box){box.hidden=false;box.textContent='正在加入宿舍组合…'}
  try{
    const d=await postJSON(`${API_BASE}/api/dorm/join.php`,{
      invite_code:dormState.inviteCode,
      attempt_id:state.result.attempt_id||state.attemptId,
      primary_personality:state.result.primary.key,
      secondary_personality:state.result.secondary.key
    });
    dormState.current=d.dorm;localStorage.setItem('type_me_last_dorm',d.dorm.invite_code);
    if(box){box.innerHTML=`<b>已加入 ${escapeDormHtml(d.dorm.name)}</b><br>${d.dorm.member_count}/4 位已完成。${d.dorm.status==='COMPLETE'?'宿舍报告已解锁。':'还差 '+(4-d.dorm.member_count)+' 位室友。'}`}
    if($('viewDormBtn'))$('viewDormBtn').hidden=false;
    toast(d.dorm.status==='COMPLETE'?'4/4 完成，宿舍报告已解锁':'已加入宿舍组合');
  }catch(e){console.error('join dorm failed',e);if(box){box.hidden=false;box.textContent='加入宿舍暂未完成，请稍后重试'}throw e}
}

async function openDorm(code){
  code=(code||localStorage.getItem('type_me_last_dorm')||'').toUpperCase();
  if(!code){toast('还没有宿舍挑战');return}
  try{const d=await loadDormStatus(code,true);renderDorm(d);showPage('dorm');startDormPolling()}catch(e){console.error('open dorm failed',e);toast('宿舍信息暂时无法打开，请稍后重试')}
}

async function loadDormStatus(code,render=true){
  if(!code)throw new Error('缺少宿舍邀请码');
  const r=await fetch(`${API_BASE}/api/dorm/status.php?code=${encodeURIComponent(code)}`,{cache:'no-store'});const j=await r.json().catch(()=>({}));
  if(!r.ok||j.code<0)throw new Error(j.message||'读取宿舍失败');
  dormState.current=j.dorm;dormState.inviteCode=j.dorm.invite_code;
  if(render)renderDorm(j.dorm);return j.dorm;
}

function renderDorm(dorm){
  if(!$('dormPage'))return;
  $('dormTitle').textContent=dorm.name;
  $('dormCode').textContent=`邀请码 ${dorm.invite_code}`;
  $('dormProgressText').textContent=`${dorm.member_count} / 4 已完成`;
  $('dormProgressFill').style.width=`${Math.min(100,dorm.member_count/4*100)}%`;
  const bySlot=new Map((dorm.members||[]).map(m=>[Number(m.slot_no),m]));
  $('dormMembers').innerHTML=[1,2,3,4].map(slot=>{
    const m=bySlot.get(slot);if(!m)return`<div class="dorm-member empty"><div class="dorm-slot">${slot}</div><div class="dorm-member-main"><b>等待室友加入</b><span>把邀请链接发给第 ${slot} 位室友</span></div></div>`;
    return`<div class="dorm-member"><div class="dorm-slot">${slot}</div><div class="dorm-member-main"><b>${escapeDormHtml(m.type)} · ${escapeDormHtml(m.name)}</b><span>${escapeDormHtml(m.role)}</span></div>${m.is_you?'<span class="dorm-you">你</span>':''}</div>`
  }).join('');
  $('dormInviteUrl').textContent=dorm.invite_url;
  $('dormLock').hidden=dorm.status==='COMPLETE';
  if(dorm.status!=='COMPLETE')$('dormLock').textContent=`还差 ${Math.max(0,4-dorm.member_count)} 位室友完成测试，宿舍精神状态报告将在 4/4 后自动解锁。`;
  $('dormReport').hidden=dorm.status!=='COMPLETE';
  $('dormDoorplateBtn').hidden=dorm.status!=='COMPLETE';
  if(dorm.report){
    $('dormReportTitle').textContent=dorm.report.title;
    $('dormReportCore').textContent=dorm.report.core;
    $('dormMetrics').innerHTML=(dorm.report.metrics||[]).map(m=>`<div class="dorm-metric"><div class="dorm-metric-top"><span>${escapeDormHtml(m.name)}</span><span>${Number(m.value)||0}%</span></div><div class="dorm-metric-bar"><span style="width:${Math.max(0,Math.min(100,Number(m.value)||0))}%"></span></div></div>`).join('');
    const roleMap=new Map((dorm.report.roles||[]).map(r=>[Number(r.slot_no),r.role]));
    $('dormRoles').innerHTML=(dorm.members||[]).map(m=>`<div class="dorm-role"><span>${escapeDormHtml(m.type)} · ${escapeDormHtml(m.name)}</span><b>${escapeDormHtml(roleMap.get(Number(m.slot_no))||m.role||'')}</b></div>`).join('');
  }
  $('dormPreviewWarning').hidden=!dorm.preview_ephemeral;
  track('dorm_view',{dorm_id:dorm.dorm_id,dorm_code:dorm.invite_code,dorm_member_count:dorm.member_count});
}

function startDormPolling(){
  if(dormState.pollTimer)clearInterval(dormState.pollTimer);
  if(!dormState.current||dormState.current.status==='COMPLETE')return;
  dormState.pollTimer=setInterval(async()=>{try{const d=await loadDormStatus(dormState.inviteCode,true);if(d.status==='COMPLETE'){clearInterval(dormState.pollTimer);dormState.pollTimer=null;toast('4/4 完成，宿舍报告已解锁')}}catch(e){}},7000)
}

async function copyDormInvite(){
  const url=dormState.current?.invite_url;if(!url)return;
  if(navigator.clipboard){await navigator.clipboard.writeText(url);toast('宿舍邀请链接已复制')}else prompt('复制宿舍邀请链接',url);
  track('dorm_share',{dorm_id:dormState.current.dorm_id,dorm_code:dormState.current.invite_code,dorm_member_count:dormState.current.member_count});
}

async function shareDormInvite(){
  const d=dormState.current;if(!d)return;
  try{if(navigator.share)await navigator.share({title:`TYPE ME｜${d.name} 宿舍组合`,text:'我们宿舍还差人，测测你是哪种大学生物种。',url:d.invite_url});else await copyDormInvite();track('dorm_share',{dorm_id:d.dorm_id,dorm_code:d.invite_code,dorm_member_count:d.member_count})}catch(e){}
}

async function generateDormDoorplate(){
  const d=dormState.current;if(!d||d.status!=='COMPLETE'){toast('4/4 完成后才能生成门牌');return}
  const btn=$('dormDoorplateBtn'),old=btn.textContent;btn.disabled=true;btn.textContent='正在生成宿舍门牌…';
  try{
    const r=await postJSON(`${API_BASE}/api/dorm/card.php`,{invite_code:d.invite_code});
    const oldWrap=$('dormCardWrap');if(oldWrap)oldWrap.remove();
    const wrap=document.createElement('div');wrap.id='dormCardWrap';wrap.className='dorm-card-preview';
    wrap.innerHTML=`<img src="${r.card_url}" alt="TYPE ME 宿舍组合门牌"><a id="saveDormCard" class="secondary-btn" style="display:block;text-align:center;text-decoration:none;margin-top:10px" href="${r.card_url}" download="type-me-dorm-${d.invite_code}.png">保存宿舍组合门牌</a>`;
    $('dormDoorplateBtn').insertAdjacentElement('afterend',wrap);$('saveDormCard').onclick=()=>track('dorm_doorplate_save',{dorm_id:d.dorm_id,dorm_code:d.invite_code,dorm_member_count:d.member_count});toast('宿舍组合门牌已生成');
  }catch(e){console.error('dorm doorplate failed',e);toast('门牌生成暂未完成，请稍后重试')}finally{btn.disabled=false;btn.textContent=old}
}

function escapeDormHtml(v){return String(v??'').replace(/[&<>'"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]))}

setTimeout(dormInit,0);
