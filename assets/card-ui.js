generateIdentityCard = async function(){
  const btn=$('generateCardBtn');
  const original=btn.textContent;
  btn.disabled=true;btn.textContent='正在生成人格身份证…';
  try{
    const d=await postJSON(`${API_BASE}/api/card/generate-v2.php`,{
      attempt_id:state.attemptId,
      session_id:state.sessionId,
      answers:state.answers
    });
    state.shareId=d.share_id||state.shareId;
    const wrap=document.createElement('div');
    wrap.id='generatedCardWrap';
    wrap.className='generated-card-panel';
    wrap.setAttribute('aria-live','polite');
    const filename=`type-me-${state.result?.primary?.key||'personality'}.png`;
    wrap.innerHTML=`
      <div class="generated-card-media">
        <img src="${d.card_url}" alt="TYPE ME ${state.result?.primary?.cn||''}人格身份证" loading="eager">
      </div>
      <div class="generated-card-content">
        <span class="generated-card-kicker">CAMPUS IDENTITY READY</span>
        <h3>你的人格身份证已生成</h3>
        <p>高清图片已生成，包含你的人格结果和专属分享二维码。</p>
        <div class="generated-card-actions">
          <a id="saveCardLink" class="primary-btn" href="${d.card_url}" download="${filename}">保存人格身份证</a>
          <button id="regenerateCardBtn" class="secondary-btn" type="button">重新生成</button>
        </div>
        <small>长按图片或点击保存按钮，即可分享给好友。</small>
      </div>`;
    const old=document.getElementById('generatedCardWrap');if(old)old.remove();
    document.querySelector('.identity-layout').insertAdjacentElement('afterend',wrap);
    document.querySelector('.identity-section').classList.add('has-generated-card');
    document.getElementById('saveCardLink').onclick=()=>track('identity_card_save',{attempt_id:state.attemptId,share_id:d.share_id});
    document.getElementById('regenerateCardBtn').onclick=()=>btn.click();
    $('cardStatus').textContent='人格身份证已生成，可以保存或分享给朋友。';
    toast('人格身份证已生成');
    requestAnimationFrame(()=>wrap.scrollIntoView({behavior:'smooth',block:'start'}));
  }catch(e){
    console.error('identity card failed',e);
    $('cardStatus').textContent='人格身份证暂未生成，请稍后重试。';
    toast('生成暂未完成，请稍后重试');
  }finally{btn.disabled=false;btn.textContent=original}
};
