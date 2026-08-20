/* TYPE ME scoring fallback. Must mirror api/lib/personality.php. */
(function(){
  const ALGORITHM='campus-zscore-v1';
  function normalizer(questions,keys,start=0,length=null){
    const end=length===null?questions.length:Math.min(questions.length,start+length),out={};
    keys.forEach(key=>{
      let mean=0,variance=0;
      for(let qi=start;qi<end;qi++){
        const options=questions[qi].options||[];
        if(!options.length)continue;
        const values=options.map(o=>Number((o.weights||{})[key]||0));
        const qm=values.reduce((a,b)=>a+b,0)/values.length;
        mean+=qm;
        variance+=values.reduce((a,v)=>a+(v-qm)**2,0)/values.length;
      }
      out[key]={mean,sd:Math.sqrt(Math.max(variance,0.000001))};
    });
    return out;
  }
  function standardized(raw,norm){
    const z={},index={};
    Object.keys(raw).forEach(key=>{
      const scoreZ=(raw[key]-norm[key].mean)/Math.max(0.000001,norm[key].sd);
      z[key]=scoreZ;
      index[key]=Math.round(Math.max(0,Math.min(100,50+scoreZ*15))*100)/100;
    });
    return{z,index};
  }
  scoreLocally=function(answers){
    const keys=Object.keys(state.personalities.personalities),questions=state.quiz.questions;
    const raw=Object.fromEntries(keys.map(k=>[k,0])),rawLast4=Object.fromEntries(keys.map(k=>[k,0]));
    answers.forEach((idx,qi)=>{
      const weights=questions[qi].options[idx].weights||{};
      Object.entries(weights).forEach(([k,v])=>{if(k in raw){raw[k]+=Number(v);if(qi>=questions.length-4)rawLast4[k]+=Number(v)}});
    });
    const all=standardized(raw,normalizer(questions,keys));
    const last=standardized(rawLast4,normalizer(questions,keys,Math.max(0,questions.length-4),Math.min(4,questions.length)));
    const seed=answers.join(',');
    const ranked=[...keys].sort((a,b)=>all.z[b]-all.z[a]||last.z[b]-last.z[a]||stable(a,seed)-stable(b,seed));
    const make=key=>{
      const p=state.personalities.personalities[key],n=all.index[key]/100;
      const metrics=p.metrics.map((name,i)=>({name,value:Math.max(8,Math.min(97,Math.round(36+n*50+p.metric_bias[i])))}));
      return{key,...p,score:all.index[key],raw_score:raw[key],metrics};
    };
    return{algorithm:ALGORITHM,primary:make(ranked[0]),secondary:make(ranked[1]),scores:all.index,raw_scores:raw,last4_scores:last.index,raw_last4_scores:rawLast4};
  };
})();
