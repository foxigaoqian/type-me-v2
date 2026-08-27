'use strict';

const adminToken = () => $('token').value.trim();

async function adminRequest(path, options = {}) {
  const token = adminToken();
  if (!token) throw new Error('请先输入后台密码');
  const headers = {'X-Admin-Token': token, ...(options.headers || {})};
  const response = await fetch(path, {...options, headers, cache: 'no-store', credentials: 'same-origin'});
  const data = await response.json().catch(() => ({}));
  if (!response.ok || data.code !== 0) throw new Error(data.message || ('HTTP ' + response.status));
  return data;
}

async function loadCatalog() {
  $('catalogStatus').textContent = '商品加载中…';
  try {
    const data = await adminRequest('./api/admin/catalog.php');
    renderCatalog(data.products || []);
    $('catalogStatus').textContent = '已加载 ' + (data.products || []).length + ' 个商品';
  } catch (error) {
    $('catalogStatus').textContent = '商品加载失败：' + error.message;
  }
}

function productTotalStock(product) {
  return (product.skus || []).reduce((sum, sku) => sum + num(sku.stock_on_hand), 0);
}

function renderCatalog(products) {
  $('catalogList').innerHTML = products.map(product => {
    const rows = (product.skus || []).map(sku => `
      <tr data-sku="${esc(sku.sku_id)}">
        <td><b>${esc(sku.color)} / ${esc(sku.size)}</b><div class="muted">${esc(sku.sku_id)}</div></td>
        <td><input class="sku-stock" type="number" min="0" max="999999" inputmode="numeric" value="${num(sku.stock_on_hand)}" aria-label="${esc(sku.sku_id)} 实物库存"></td>
        <td>${num(sku.stock_reserved)}</td><td>${num(sku.available)}</td>
        <td><label class="check"><input class="sku-active" type="checkbox" ${num(sku.active) ? 'checked' : ''}>启用</label></td>
      </tr>`).join('');
    return `<details class="product-admin" data-product="${esc(product.product_id)}">
      <summary>${esc(product.name)} · ¥${(num(product.price_fen) / 100).toFixed(2)} · 总库存 ${productTotalStock(product)}</summary>
      <div class="product-form">
        <label class="field name-field">商品<input value="${esc(product.name)}" disabled></label>
        <label class="field">售价（元）<input class="product-price" type="number" min="0.01" step="0.01" value="${(num(product.price_fen) / 100).toFixed(2)}"></label>
        <label class="field">划线价（元）<input class="product-regular" type="number" min="0.01" step="0.01" value="${(num(product.regular_price_fen) / 100).toFixed(2)}"></label>
        <label class="check"><input class="product-active" type="checkbox" ${num(product.active) ? 'checked' : ''}>商品启用</label>
        <button class="btn save-product" type="button">保存价格</button>
      </div>
      <div class="sku-wrap"><table><thead><tr><th>颜色/尺码</th><th>实物库存</th><th>预占</th><th>可售</th><th>状态</th></tr></thead><tbody>${rows}</tbody></table>
      <button class="btn alt save-stock" type="button">保存本款库存</button></div>
    </details>`;
  }).join('');
}

async function saveProduct(button) {
  const box = button.closest('.product-admin');
  const payload = {action:'save_product',product_id:box.dataset.product,
    price_fen:Math.round(num(box.querySelector('.product-price').value)*100),
    regular_price_fen:Math.round(num(box.querySelector('.product-regular').value)*100),
    active:box.querySelector('.product-active').checked};
  await saveCatalog(button, payload, '商品价格已保存');
}

async function saveStock(button) {
  const box = button.closest('.product-admin');
  const items = [...box.querySelectorAll('tbody tr')].map(row => ({
    sku_id:row.dataset.sku,stock_on_hand:num(row.querySelector('.sku-stock').value),active:row.querySelector('.sku-active').checked,
  }));
  await saveCatalog(button, {action:'bulk_stock',items}, '本款库存已保存');
}

async function saveCatalog(button, payload, message) {
  const original = button.textContent;button.disabled=true;button.textContent='保存中…';$('catalogStatus').textContent='';
  try {
    const data = await adminRequest('./api/admin/catalog.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)});
    renderCatalog(data.products || []);$('catalogStatus').textContent=message;
  } catch (error) {$('catalogStatus').textContent='保存失败：'+error.message}
  finally {button.disabled=false;button.textContent=original}
}

async function loadOrders() {
  $('orderStatus').textContent='订单加载中…';
  try {const data=await adminRequest('./api/order/list.php?limit=100');renderOrders(data.items||[]);$('orderStatus').textContent='共加载 '+num(data.count)+' 条订单'}
  catch(error){$('orderStatus').textContent='订单加载失败：'+error.message}
}

function renderOrders(orders) {
  $('orderRows').innerHTML=orders.map(order=>{
    const items=(order.items||[]).map(item=>{const spec=[item.color,item.size].filter(Boolean).join(' / ');return esc(item.name||item.product_id||'')+(spec?'<div class="muted">'+esc(spec)+' × '+num(item.qty||1)+'</div>':'')}).join('');
    const contact=[order.receiver_name,order.receiver_phone,order.receiver_address].filter(Boolean).map(esc).join('<br>');
    return `<tr><td><b>${esc(order.out_trade_no)}</b></td><td>${esc(order.created_at||'')}</td><td><span class="pill">${esc(order.status||'')}</span></td><td>${money(order.amount_pay_fen||order.amount_fen||order.amount||0)}</td><td>${items||'—'}</td><td>${contact||'—'}</td></tr>`;
  }).join('')||'<tr><td colspan="6">暂无订单</td></tr>';
}

async function loadPersonalities(openType = '') {
  $('personalityStatus').textContent='人格内容加载中…';
  try {
    const data=await adminRequest('./api/admin/personality-content.php');
    renderPersonalities(data.types||[],openType);
    $('personalityStatus').textContent='已加载 '+(data.types||[]).length+' 个 TYPE';
  } catch(error){$('personalityStatus').textContent='人格内容加载失败：'+error.message}
}

function personalityContent(type) {
  return type.draft?.content||type.published?.content||type.default_content||{};
}

function renderPersonalities(types, openType = '') {
  $('personalityList').innerHTML=types.map(type=>{
    const content=personalityContent(type),draft=type.draft||{},published=type.published||{};
    const metrics=(content.metrics||['','','','']).slice(0,4),biases=(content.metric_bias||[0,0,0,0]).slice(0,4);
    const metricInputs=metrics.map((metric,index)=>`<div class="metric-pair"><input class="metric-name" maxlength="40" value="${esc(metric)}" aria-label="指标 ${index+1}"><input class="metric-bias" type="number" min="-50" max="50" value="${num(biases[index])}" aria-label="指标 ${index+1} 偏移"></div>`).join('');
    const history=(type.history||[]).map(item=>`<li>v${num(item.version)} · ${esc(item.status)} · ${esc(item.published_at||item.updated_at||'')}</li>`).join('');
    return `<details class="personality-admin" data-type-id="${esc(type.type_id)}" ${openType===type.type_id?'open':''}>
      <summary><div class="personality-head"><span>${esc(type.type_id)} · ${esc(content.name||type.name)}</span><span class="personality-head-meta"><span>${type.active?'数据库文案已启用':'使用代码默认文案'}</span><span>线上 v${num(published.version)}</span>${draft.revision_id?`<span>草稿 v${num(draft.version)}</span>`:''}</span></div></summary>
      <div class="personality-body"><div class="personality-form">
        <label class="field">固定 TYPE 编号<input value="${esc(type.type_id)}" readonly aria-readonly="true"></label>
        <label class="field">内部人格键<input value="${esc(type.personality_key)}" readonly aria-readonly="true"></label>
        <label class="field">人格中文名称<input data-field="name" maxlength="64" value="${esc(content.name||'')}"></label>
        <label class="field">英文名称<input data-field="en" maxlength="80" value="${esc(content.en||'')}"></label>
        <label class="field wide">结果页主梗<textarea data-field="main_meme" maxlength="120">${esc(content.main_meme||'')}</textarea></label>
        <label class="field wide">人格身份证文案<textarea data-field="identity_card_meme" maxlength="120">${esc(content.identity_card_meme||'')}</textarea></label>
        <label class="field wide">好友视角文案<textarea data-field="friend_meme" maxlength="160">${esc(content.friend_meme||'')}</textarea></label>
        <label class="field">T 恤文案<textarea data-field="tshirt_copy" maxlength="120">${esc(content.tshirt_copy||'')}</textarea></label>
        <label class="field">分享文案<textarea data-field="share_copy" maxlength="240">${esc(content.share_copy||'')}</textarea></label>
        <label class="field wide">人格描述<textarea data-field="description" maxlength="1200">${esc(content.description||'')}</textarea></label>
        <label class="field wide">四项人格指标<div class="metrics-editor">${metricInputs}</div></label>
        <label class="field">高光技能<textarea data-field="skill" maxlength="300">${esc(content.skill||'')}</textarea></label>
        <label class="field">容易踩坑<textarea data-field="weakness" maxlength="300">${esc(content.weakness||'')}</textarea></label>
        <label class="field">人格强调色<input data-field="accent" maxlength="7" pattern="#[0-9a-fA-F]{6}" value="${esc(content.accent||'#f3ff38')}"></label>
      </div>
      <div class="personality-actions"><label class="check"><input class="personality-active" type="checkbox" ${type.active?'checked':''}>启用数据库文案覆盖</label><div class="personality-actions-right"><button class="btn danger save-personality-draft" type="button">保存草稿</button><button class="btn alt publish-personality" type="button" ${draft.revision_id?'':'disabled'} data-revision-id="${num(draft.revision_id)}">发布草稿</button></div></div>
      <div class="muted">最后更新时间：${esc(draft.updated_at||published.updated_at||type.updated_at||'—')}</div>${history?`<details><summary class="muted">最近版本记录</summary><ol class="revision-list">${history}</ol></details>`:''}
      </div></details>`;
  }).join('');
}

function collectPersonalityContent(box) {
  const field=name=>box.querySelector(`[data-field="${name}"]`).value.trim();
  return {name:field('name'),en:field('en'),main_meme:field('main_meme'),identity_card_meme:field('identity_card_meme'),friend_meme:field('friend_meme'),tshirt_copy:field('tshirt_copy'),share_copy:field('share_copy'),description:field('description'),skill:field('skill'),weakness:field('weakness'),accent:field('accent'),metrics:[...box.querySelectorAll('.metric-name')].map(input=>input.value.trim()),metric_bias:[...box.querySelectorAll('.metric-bias')].map(input=>num(input.value))};
}

async function savePersonalityDraft(button) {
  const box=button.closest('.personality-admin'),typeId=box.dataset.typeId,original=button.textContent;button.disabled=true;button.textContent='保存中…';
  try {const data=await adminRequest('./api/admin/personality-content.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'save_draft',type_id:typeId,content:collectPersonalityContent(box)})});renderPersonalities(data.types||[],typeId);$('personalityStatus').textContent=typeId+' 草稿已保存，线上内容未改变'}
  catch(error){$('personalityStatus').textContent='草稿保存失败：'+error.message;button.disabled=false;button.textContent=original}
}

async function publishPersonality(button) {
  const box=button.closest('.personality-admin'),typeId=box.dataset.typeId;if(!confirm(`确认发布 ${typeId} 的当前草稿？发布后前端与人格身份证会立即使用新文案。`))return;
  const original=button.textContent;button.disabled=true;button.textContent='发布中…';
  try {const data=await adminRequest('./api/admin/personality-content.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'publish',type_id:typeId,revision_id:num(button.dataset.revisionId)})});renderPersonalities(data.types||[],typeId);$('personalityStatus').textContent=typeId+' 已发布'}
  catch(error){$('personalityStatus').textContent='发布失败：'+error.message;button.disabled=false;button.textContent=original}
}

async function setPersonalityActive(input) {
  const box=input.closest('.personality-admin'),typeId=box.dataset.typeId;input.disabled=true;
  try {const data=await adminRequest('./api/admin/personality-content.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'set_active',type_id:typeId,active:input.checked})});renderPersonalities(data.types||[],typeId);$('personalityStatus').textContent=typeId+(input.checked?' 已启用数据库文案':' 已回退代码默认文案')}
  catch(error){input.checked=!input.checked;input.disabled=false;$('personalityStatus').textContent='状态更新失败：'+error.message}
}

$('reloadPersonalities').addEventListener('click',()=>loadPersonalities());
$('reloadCatalog').addEventListener('click',loadCatalog);
$('reloadOrders').addEventListener('click',loadOrders);
$('personalityList').addEventListener('click',event=>{const save=event.target.closest('.save-personality-draft'),publish=event.target.closest('.publish-personality');if(save)savePersonalityDraft(save);if(publish)publishPersonality(publish)});
$('personalityList').addEventListener('change',event=>{if(event.target.matches('.personality-active'))setPersonalityActive(event.target)});
$('catalogList').addEventListener('click',event=>{const productButton=event.target.closest('.save-product'),stockButton=event.target.closest('.save-stock');if(productButton)saveProduct(productButton);if(stockButton)saveStock(stockButton)});
