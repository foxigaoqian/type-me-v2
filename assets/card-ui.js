generateIdentityCard = async function(){
  const btn=$('generateCardBtn');
  const original=btn.textContent;
  btn.disabled=true;btn.textContent='正在生成人格身份证…';
  try{
    const d=await postJSON(`${API_BASE}/api/card/generate-v2.php`,{attempt_id:state.attemptId,session_id:state.sessionId});
    state.shareId=d.share_id||state.shareId;
    const wrap=document.createElement('div');
    wrap.id='generatedCardWrap';
    wrap.style.cssText='margin-top:16px;display:grid;gap:10px';
    wrap.innerHTML=`<img src="${d.card_url}" alt="TYPE ME 人格身份证" style="width:100%;border-radius:18px;display:block" loading="eager"><a id="saveCardLink" class="secondary-btn" href="${d.card_url}" download style="text-align:center;text-decoration:none">保存人格身份证 PNG</a>`;
    const old=document.getElementById('generatedCardWrap');if(old)old.remove();
    $('cardStatus').insertAdjacentElement('afterend',wrap);
    document.getElementById('saveCardLink').onclick=()=>track('identity_card_save',{attempt_id:state.attemptId,share_id:d.share_id});
    $('cardStatus').textContent=`已生成 ${d.width}×${d.height} PNG。二维码已绑定本次分享归因。`;
    toast('人格身份证已生成');
  }catch(e){
    $('cardStatus').textContent=`生成失败：${e.message}`;
    toast(e.message);
  }finally{btn.disabled=false;btn.textContent=original}
};
