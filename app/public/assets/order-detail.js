(()=>{
  'use strict';
  const root=document.querySelector('#order-detail');
  if(!root)return;
  const request=async(url,options)=>{
    const response=await fetch(url,options),result=await response.json();
    if(!response.ok)throw new Error((result.details||[result.error]).filter(Boolean).join(' ')||'Aktion fehlgeschlagen.');
    return result.data??result;
  };
  root.querySelector('[data-next-status]')?.addEventListener('click',async event=>{
    const button=event.currentTarget,next=button.dataset.nextStatus,nextLabel=button.dataset.nextStatusLabel;
    if(!await CalightModal.confirm({title:'Auftragsstatus ändern',content:`Den Auftrag auf „${nextLabel}“ setzen? Der Wechsel wird dauerhaft im Statusverlauf gespeichert.`,confirmLabel:`${nextLabel} setzen`}))return;
    button.disabled=true;
    try{
      await request(`/api/orders/${encodeURIComponent(root.dataset.id)}/status`,{method:'PATCH',headers:{'Content-Type':'application/json','X-CSRF-Token':root.dataset.csrf},body:JSON.stringify({expectedStatus:root.dataset.status,status:next})});
      location.reload();
    }catch(error){
      button.disabled=false;
      await CalightModal.open({title:'Statuswechsel fehlgeschlagen',content:error.message,actions:[{label:'Schließen',primary:true}]});
    }
  });
  root.querySelector('[data-create-change-order]')?.addEventListener('click',async event=>{
    const button=event.currentTarget;
    const form=document.createElement('form');
    form.innerHTML='<label>Bezeichnung des Nachtrags<input name="title" maxlength="200" required placeholder="z. B. Zusätzliche Leitungswege"></label><p class="notice">Der neue Nachtrag wird als bearbeitbarer Entwurf mit eigener Nachtragsnummer und Revisionshistorie angelegt.</p>';
    const confirmed=await CalightModal.open({title:'Nachtrag erstellen',content:form,actions:[{label:'Abbrechen',value:false},{label:'Nachtrag erstellen',value:true,primary:true,validate:true}],initialFocus:'[name="title"]'});
    if(!confirmed)return;
    button.disabled=true;
    try{
      const result=await request(`/api/orders/${encodeURIComponent(root.dataset.id)}/change-orders`,{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':root.dataset.csrf},body:JSON.stringify({title:form.elements.title.value.trim()})});
      location.assign(result.url);
    }catch(error){
      button.disabled=false;
      await CalightModal.open({title:'Nachtrag konnte nicht erstellt werden',content:error.message,actions:[{label:'Schließen',primary:true}]});
    }
  });
})();
