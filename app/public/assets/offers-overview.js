(()=>{
  'use strict';
  const root=document.querySelector('#offers-overview');if(!root)return;
  const list=document.querySelector('#offer-list'),search=document.querySelector('#offer-search'),filter=document.querySelector('#offer-status'),message=document.querySelector('#offers-message'),form=document.querySelector('#new-offer-form'),importButton=document.querySelector('#import-new-offer'),importFile=document.querySelector('#import-new-file');
  let offers=[];
  const esc=v=>String(v??'').replace(/[&<>'"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]));
  const labels={draft:'Entwurf',submitted:'Abgegeben',commissioned:'Beauftragt',rejected:'Abgelehnt'};
  const date=v=>new Intl.DateTimeFormat('de-DE',{dateStyle:'short',timeStyle:'short'}).format(new Date(v));
  function notify(text,type='error'){message.textContent=text;message.className=`notice notice-${type}`;message.hidden=false;}
  function render(){
    const q=search.value.trim().toLocaleLowerCase('de'),status=filter.value,rows=offers.filter(o=>(!status||o.status===status)&&(!q||`${o.title} ${o.projectName} ${o.projectNumber||''} ${o.offerNumber||''}`.toLocaleLowerCase('de').includes(q)));
    list.innerHTML=rows.length?rows.map(o=>`<article class="service-list-item"><div class="service-list-main"><span class="service-number">${esc(o.offerNumber||'Entwurf ohne Angebotsnummer')}</span><strong>${esc(o.title)}</strong><span class="muted">${esc(o.projectNumber||'Ohne Projektnummer')} · ${esc(o.projectName)} · ${labels[o.status]||esc(o.status)}</span><span class="muted">Bearbeitet ${date(o.updatedAt)} · Version ${o.draftVersion}</span></div><div class="service-list-actions"><a class="button button-light button-small" data-icon="file-text" href="/offers/${encodeURIComponent(o.id)}">Öffnen</a><a class="button button-light button-small" data-icon="folder-kanban" href="/projects/${encodeURIComponent(o.projectId)}">Projekt</a><button class="button button-light button-small" type="button" data-icon="copy" data-copy-offer="${o.id}">Kopieren</button>${o.status==='draft'?`<button class="button button-light button-small button-danger-light" type="button" data-icon="trash" data-delete-offer="${o.id}" data-confirm-message="Angebotsentwurf ${esc(o.title)} endgültig löschen?">Löschen</button>`:''}</div></article>`).join(''):'<p class="card muted">Keine Angebote gefunden.</p>';
  }
  async function load(){const response=await fetch('/api/offers',{credentials:'same-origin'}),result=await response.json();if(!response.ok)throw new Error(result.error||'Angebote konnten nicht geladen werden.');offers=result.offers;render();}
  search.addEventListener('input',render);filter.addEventListener('change',render);
  list.addEventListener('click',async event=>{
    const copyButton=event.target.closest('[data-copy-offer]');
    if(copyButton){const offer=offers.find(item=>item.id===copyButton.dataset.copyOffer);if(!offer)return;copyButton.disabled=true;try{const response=await fetch(`/api/offers/${encodeURIComponent(offer.id)}/copy`,{method:'POST',credentials:'same-origin',headers:{'X-CSRF-Token':root.dataset.csrf}}),result=await response.json();if(!response.ok)throw new Error(result.error||'Angebot konnte nicht kopiert werden.');location.assign(result.url);}catch(error){copyButton.disabled=false;notify(error.message);}return;}
    const button=event.target.closest('[data-delete-offer]');if(!button)return;
    const offer=offers.find(item=>item.id===button.dataset.deleteOffer);if(!offer)return;
    button.disabled=true;
    try{const response=await fetch(`/api/offers/${encodeURIComponent(offer.id)}`,{method:'DELETE',credentials:'same-origin',headers:{'X-CSRF-Token':root.dataset.csrf}}),result=await response.json();if(!response.ok)throw new Error(result.error||'Entwurf konnte nicht gelöscht werden.');offers=offers.filter(item=>item.id!==offer.id);render();notify('Angebotsentwurf gelöscht.','success');}catch(error){button.disabled=false;notify(error.message);}
  });
  form.addEventListener('submit',async event=>{event.preventDefault();const response=await fetch('/api/offers',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':root.dataset.csrf},body:JSON.stringify({projectId:form.elements.projectId.value,title:form.elements.title.value})}),result=await response.json();if(!response.ok)return notify(result.error||'Entwurf konnte nicht angelegt werden.');location.assign(result.url);});
  importButton.onclick=()=>{if(!form.elements.projectId.value)return notify('Bitte zuerst ein Projekt auswählen.');importFile.click();};
  importFile.onchange=async()=>{const file=importFile.files[0];if(!file)return;try{if(file.size>5_000_000)throw new Error('Die JSON-Datei darf höchstens 5 MB groß sein.');const offer=JSON.parse(await file.text()),response=await fetch('/api/offers/import',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':root.dataset.csrf},body:JSON.stringify({projectId:form.elements.projectId.value,offer})}),result=await response.json();if(!response.ok)throw new Error((result.details||[]).join(' ')||result.error);location.assign(result.url);}catch(error){notify(`Import fehlgeschlagen: ${error.message}`);}finally{importFile.value='';}};
  load().catch(error=>notify(error.message));
})();
