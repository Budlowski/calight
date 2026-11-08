(()=>{
  'use strict';
  function find(nodes,numbers,query){
    const needle=String(query??'').trim().toLocaleLowerCase('de-DE');
    if(!needle)return[];
    const hits=[];
    const walk=items=>items.forEach(node=>{
      const number=numbers.get(node.id)||'';
      const value=node.type==='title'
        ?`${node.name??''} ${node.description??''}`
        :node.type==='text'
          ?`${node.title??''} ${node.text??''}`
          :`${number} ${node.shortText??''} ${node.longText??''}`;
      if(value.toLocaleLowerCase('de-DE').includes(needle))hits.push({
        id:node.id,
        label:node.type==='title'?`${number} ${node.name}`:node.type==='text'?(node.title||node.text):`${number} ${node.shortText}`,
      });
      if(node.type==='title')walk(Array.isArray(node.children)?node.children:[]);
    });
    walk(Array.isArray(nodes)?nodes:[]);
    return hits;
  }
  globalThis.CalightOfferSearch={find};
})();
