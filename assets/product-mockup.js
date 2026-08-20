/* Product visual adapter: uses real product.image when available, otherwise a clearly labeled concept mockup. */
(function(){
  const baseRenderProduct=renderProduct;
  const colorMap={'黑':'#171717','白':'#f7f5ef','灰':'#b9b9b5'};
  renderProduct=function(){
    baseRenderProduct();
    const p=state.result.primary,product=state.products.products[p.key],photo=document.querySelector('.product-photo');
    if(!photo)return;
    if(product?.image){
      photo.classList.add('has-real-image');
      photo.innerHTML=`<img class="real-product-image" src="${product.image}" alt="${product.name||p.cn}" loading="lazy">`;
      return;
    }
    photo.classList.remove('has-real-image');
    const teeColor=colorMap[state.color]||'#f7f5ef';
    const ink=state.color==='黑'?'#f7f5ef':'#111';
    photo.innerHTML=`
      <div class="mockup-stage" style="--tee:${teeColor};--tee-ink:${ink};--type-accent:${p.accent||'#f3ff38'}">
        <div class="mockup-label">CONCEPT MOCKUP · PHOTO PENDING</div>
        <div class="tee-shape" aria-hidden="true">
          <div class="tee-neck"></div>
          <div class="tee-print">
            <span>${p.type}</span>
            <strong>${p.cn}</strong>
            <em>${p.en}</em>
          </div>
        </div>
        <div class="mockup-foot">${state.color} / ${state.size} · 最终实物图以后直接替换</div>
      </div>`;
  };
})();
