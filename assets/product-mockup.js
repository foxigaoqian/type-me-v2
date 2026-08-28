/* Product visual adapter: gallery images are independent from SKU color selection. */
(function(){
  const baseRenderProduct=renderProduct;
  let activeGalleryIndex=0;

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
      {label:'场景图 / 上身图',src:media.scene}
    ].filter(item=>item.src);

    if(!items.length){
      photo.innerHTML='';
      gallery.innerHTML='';
      return;
    }

    const show=(index)=>{
      activeGalleryIndex=Math.max(0,Math.min(index,items.length-1));
      const item=items[activeGalleryIndex];
      photo.innerHTML=`<img class="real-product-image" src="${assetUrl(item.src)}" alt="${p.type} ${p.cn}${item.label}" width="1200" height="1200"><span class="visual-badge">${item.label}</span>`;
      [...gallery.querySelectorAll('button')].forEach((button,i)=>button.classList.toggle('active',i===activeGalleryIndex));
    };

    gallery.innerHTML=items.map((item,index)=>`
      <button type="button" aria-label="查看${item.label}" data-gallery-index="${index}">
        <img src="${assetUrl(item.src)}" alt="" width="1200" height="1200" loading="lazy">
        <span>${item.label}</span>
      </button>
    `).join('');

    [...gallery.querySelectorAll('button')].forEach((button,index)=>{
      button.onclick=()=>show(index);
    });

    show(activeGalleryIndex);
  };
})();
