/* Product visual adapter: customer-supplied campaign visuals + explicit preview disclosure. */
(function(){
  const baseRenderProduct=renderProduct;

  renderProduct=function(){
    baseRenderProduct();
    const p=state.result.primary;
    const product=state.products.products[p.key];
    const media=state.media?.personalities?.[p.key]||{};
    const photo=$('productPhoto');
    const gallery=$('productGallery');
    if(!photo||!gallery)return;

    const items=[
      {label:'主视觉',src:media.main||product?.image},
      {label:'正面穿搭',src:media.front},
      {label:'背面 / 版型',src:media.back},
      {label:'校园场景',src:media.scene}
    ].filter(item=>item.src);

    const show=(index)=>{
      const item=items[index]||items[0];
      if(!item)return;
      photo.innerHTML=`<img class="real-product-image" src="${assetUrl(item.src)}" alt="${p.type} ${p.cn}${item.label}" width="1200" height="1200"><span class="visual-badge">品牌视觉示意</span>`;
      [...gallery.querySelectorAll('button')].forEach((button,i)=>button.classList.toggle('active',i===index));
    };

    gallery.innerHTML=items.map((item,index)=>`<button type="button" aria-label="查看${item.label}"><img src="${assetUrl(item.src)}" alt="" width="1200" height="1200" loading="lazy"><span>${item.label}</span></button>`).join('');
    [...gallery.querySelectorAll('button')].forEach((button,index)=>button.onclick=()=>show(index));
    show(0);
  };
})();
