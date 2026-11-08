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
const generated=CalightOfferSearch.numbering([{id:'root',type:'title',children:[
  {id:'root-text',type:'text'},
  {id:'root-position',type:'position'},
  {id:'child',type:'title',children:[{id:'child-position',type:'position'}]},
  {id:'root-position-2',type:'position'},
]}]);
assert(generated.get('root')==='1','Haupttitel wurde falsch nummeriert.');
assert(generated.get('root-text')==='','Textposition darf keine Nummer erhalten.');
assert(generated.get('root-position')==='1.01'&&generated.get('root-position-2')==='1.02','Text oder Untertitel beeinflusst den Positionszähler.');
assert(generated.get('child')==='1.1'&&generated.get('child-position')==='1.1.01','Untertitel wurde falsch nummeriert.');
const counts=CalightOfferSearch.positionCounts([{id:'root',type:'title',children:[
  {id:'direct',type:'position'},
  {id:'note',type:'text'},
  {id:'child',type:'title',children:[{id:'nested',type:'position'},{id:'deep',type:'title',children:[{id:'deep-position',type:'position'}]}]},
]}]);
assert(counts.get('root')===3,'Positionen wurden für den Haupttitel nicht rekursiv gezählt.');
assert(counts.get('child')===2&&counts.get('deep')===1,'Positionen wurden für Untertitel nicht korrekt gezählt.');
console.log('offer search tests passed');
