import '../../public/assets/offer-search.js';

const nodes=[{id:'t1',type:'title',name:'Elektro',description:'',children:[
  {id:'txt',type:'text',title:'Vorbemerkung',text:'Bauseitige Leistung'},
  {id:'p1',type:'position',shortText:'Steckdose montieren',longText:'Unterputz-Ausführung'},
]}];
const numbers=new Map([['t1','1'],['txt',''],['p1','1.01']]);
const assert=(condition,message)=>{if(!condition)throw new Error(message);};

assert(CalightOfferSearch.find(nodes,numbers,'elektro')[0]?.id==='t1','Titel wurde nicht gefunden.');
assert(CalightOfferSearch.find(nodes,numbers,'1.01')[0]?.id==='p1','Positionsnummer wurde nicht gefunden.');
assert(CalightOfferSearch.find(nodes,numbers,'UNTERPUTZ')[0]?.id==='p1','Langtextsuche ignoriert Großschreibung nicht.');
assert(CalightOfferSearch.find(nodes,numbers,'bauseitige')[0]?.id==='txt','Textposition wurde nicht gefunden.');
assert(CalightOfferSearch.find(nodes,numbers,'nicht vorhanden').length===0,'Unerwarteter Suchtreffer.');
console.log('offer search tests passed');
