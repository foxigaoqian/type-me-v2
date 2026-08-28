/* Product visual adapter: show all three purchasable colors together. */
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

    const colorItems=[
      {color:'黑',label:'黑色',src:'assets/media/product/printed-black.webp'},
      {color:'白',label:'白色',src:'assets/media/product/printed-white.webp'},
      {color:'灰',label:'灰色',src:'assets/media/product/printed-gray.webp'}
    ];
    const items=[
      {label:'三色一览',group:true},
      {label:'主视觉',src:media.main||product?.image},
      {label:'正面穿搭',src:media.front},
      {label:'背面 / 版型',src:media.back},
      {label:'校园场景',src:media.scene}
    ].filter(item=>item.group||item.src);

    const renderThreeColors=()=>{
      photo.innerHTML=`<div class="three-color-showcase">${colorItems.map(item=>`<div class="three-color-card ${item.color===state.color?'selected':''}"><img src="${assetUrl(item.src)}" alt="${item.label}印花 T 恤效果" width="720" height="720"><span>${item.label}</span></div>`).join('')}</div><span class="visual-badge">三色印花效果</span>`;
    };

    const show=(index)=>{
      const item=items[index]||items[0];
      if(!item)return;
      if(item.group){
        renderThreeColors();
      }else{
        photo.innerHTML=`<img class="real-product-image" src="${assetUrl(item.src)}" alt="${p.type} ${p.cn}${item.label}" width="1200" height="1200"><span class="visual-badge">印花穿搭效果</span>`;
      }
      [...gallery.querySelectorAll('button')].forEach((button,i)=>button.classList.toggle('active',i===index));
    };

    gallery.innerHTML=items.map((item,index)=>{
      if(item.group){
        return `<button type="button" aria-label="查看三色印花效果"><div class="three-color-thumb">${colorItems.map(c=>`<img src="${assetUrl(c.src)}" alt="" width="240" height="240" loading="lazy">`).join('')}</div><span>三色一览</span></button>`;
      }
      return `<button type="button" aria-label="查看${item.label}"><img src="${assetUrl(item.src)}" alt="" width="1200" height="1200" loading="lazy"><span>${item.label}</span></button>`;
    }).join('');

    [...gallery.querySelectorAll('button')].forEach((button,index)=>button.onclick=()=>show(index));
    show(0);
  };
})();
