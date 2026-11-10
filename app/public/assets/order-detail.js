(()=>{
  'use strict';
  const root=document.querySelector('#order-detail');
  if(!root)return;
  const editForm=root.querySelector('[data-order-edit]'),editButton=root.querySelector('[data-edit-order]'),description=root.querySelector('[data-order-description]'),editError=root.querySelector('[data-order-edit-error]');
  let editDirty=false;
  const request=async(url,options)=>{
    const response=await fetch(url,options),result=await response.json();
    if(!response.ok)throw new Error((result.details||[result.error]).filter(Boolean).join(' ')||'Aktion fehlgeschlagen.');
    return result.data??result;
  };
  const setEditOpen=(open)=>{
    editForm.hidden=!open;editButton.disabled=open;
    if(open){editForm.elements.title.focus();editForm.elements.title.select();}
    else{editError.hidden=true;editError.textContent='';}
  };
  const resetEditForm=()=>{
    editForm.reset();
    editDirty=false;setEditOpen(false);
  };
  editButton.addEventListener('click',()=>setEditOpen(true));
  editForm.addEventListener('input',()=>{editDirty=true;editError.hidden=true;});
  root.querySelector('[data-cancel-order-edit]').addEventListener('click',async()=>{
    if(editDirty&&!await CalightModal.confirm({title:'Ungespeicherte Änderungen',content:'Die Änderungen an Bezeichnung und Beschreibung verwerfen?',confirmLabel:'Verwerfen',danger:true}))return;
    resetEditForm();
  });
  editForm.addEventListener('submit',async event=>{
    event.preventDefault();
    const title=editForm.elements.title.value.trim(),newDescription=editForm.elements.description.value.trim(),submit=editForm.querySelector('[type="submit"]');
    if(title===''){editError.textContent='Die Auftragsbezeichnung ist erforderlich.';editError.hidden=false;editForm.elements.title.focus();return;}
    if(title.length>200||newDescription.length>5000){editError.textContent=title.length>200?'Die Auftragsbezeichnung darf höchstens 200 Zeichen lang sein.':'Die Auftragsbeschreibung darf höchstens 5.000 Zeichen lang sein.';editError.hidden=false;return;}
    submit.disabled=true;editError.hidden=true;
    try{
      const updated=await request(`/api/orders/${encodeURIComponent(root.dataset.id)}`,{method:'PATCH',headers:{'Content-Type':'application/json','X-CSRF-Token':root.dataset.csrf},body:JSON.stringify({title,description:newDescription,expectedUpdatedAt:root.dataset.updatedAt})});
      root.dataset.updatedAt=updated.updatedAt;root.querySelector('[data-order-title]').textContent=updated.title;
      description.textContent=updated.description;description.hidden=updated.description==='';
      editForm.elements.title.value=updated.title;editForm.elements.description.value=updated.description;
      editForm.elements.title.defaultValue=updated.title;editForm.elements.description.defaultValue=updated.description;
      editDirty=false;setEditOpen(false);
    }catch(error){editError.textContent=error.message;editError.hidden=false;}
    finally{submit.disabled=false;}
  });
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
  window.addEventListener('beforeunload',event=>{if(editDirty){event.preventDefault();event.returnValue='';}});
})();
