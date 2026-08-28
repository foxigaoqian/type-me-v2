/* Product visual adapter: customer-supplied campaign visuals. */
(function(){
  const baseRenderProduct=renderProduct;

  renderProduct=function(){
    baseRenderProduct();
    const p=state.result.primary;
    const product=state.products.products[p.key];
    const media=state.media?.personalities?.[p.key]||{};
    const photo=$('productPhoto');
    const gallery=$('productGallery');
    const details=$('productDetailPhotos');
    if(!photo||!gallery)return;

    if(details){
      const printed=[
        {src:'assets/media/product/printed-white.webp',label:'白色印花效果'},
        {src:'assets/media/product/printed-gray.webp',label:'灰色印花效果'},
        {src:'assets/media/product/printed-black.webp',label:'黑色印花效果'}
      ];
      details.innerHTML=printed.map(item=>`<figure><img src="${assetUrl(item.src)}" alt="TYPE ME ${item.label}" width="1200" height="1200" loading="lazy"><figcaption>${item.label}</figcaption></figure>`).join('');
    }

    const items=[
      {label:'主视觉',src:media.main||product?.image},
      {label:'正面穿搭',src:media.front},
      {label:'背面 / 版型',src:media.back},
      {label:'校园场景',src:media.scene}
    ].filter(item=>item.src);

    const show=(index)=>{
      const item=items[index]||items[0];
      if(!item)return;
      photo.innerHTML=`<img class="real-product-image" src="${assetUrl(item.src)}" alt="${p.type} ${p.cn}${item.label}" width="1200" height="1200"><span class="visual-badge">印花穿搭效果</span>`;
      [...gallery.querySelectorAll('button')].forEach((button,i)=>button.classList.toggle('active',i===index));
    };

    gallery.innerHTML=items.map((item,index)=>`<button type="button" aria-label="查看${item.label}"><img src="${assetUrl(item.src)}" alt="" width="1200" height="1200" loading="lazy"><span>${item.label}</span></button>`).join('');
    [...gallery.querySelectorAll('button')].forEach((button,index)=>button.onclick=()=>show(index));
    show(0);
  };
})();
