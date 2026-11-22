(()=>{
  'use strict';
  const root=document.querySelector('#order-detail');
  if(!root)return;
  const editForm=root.querySelector('[data-order-edit]'),editButton=root.querySelector('[data-edit-order]'),description=root.querySelector('[data-order-description]'),editError=root.querySelector('[data-order-edit-error]');
  let editDirty=false;
  // Reiter: Auswahl steht im URL-Hash (#execution), damit Direktlinks und Zurück funktionieren.
  const tabs=[...root.querySelectorAll('[data-order-tab]')];
  const showTab=(key,focus=false)=>{
    const tab=tabs.find(item=>item.dataset.orderTab===key)||tabs[0];if(!tab)return;
    tabs.forEach(item=>{const active=item===tab;item.classList.toggle('active',active);item.setAttribute('aria-selected',active?'true':'false');});
    root.querySelectorAll('[data-order-panel]').forEach(panel=>{panel.hidden=panel.dataset.orderPanel!==tab.dataset.orderTab;});
    if(focus)tab.focus();
  };
  tabs.forEach(tab=>tab.addEventListener('click',()=>{history.replaceState(null,'',tab.dataset.orderTab==='overview'?location.pathname+location.search:`#${tab.dataset.orderTab}`);showTab(tab.dataset.orderTab);}));
  root.querySelectorAll('[data-order-tab-link]').forEach(link=>link.addEventListener('click',()=>{history.replaceState(null,'',`#${link.dataset.orderTabLink}`);showTab(link.dataset.orderTabLink,true);}));
  showTab(location.hash.slice(1)||'overview');
  const request=async(url,options)=>{
    const response=await fetch(url,options),result=await response.json();
    if(!response.ok){const error=new Error((result.details||[result.error]).filter(Boolean).join(' ')||'Aktion fehlgeschlagen.');error.status=response.status;error.result=result;throw error;}
    return result.data??result;
  };
  const setEditOpen=(open)=>{
    editForm.hidden=!open;if(editButton)editButton.disabled=open;
    if(open){editForm.elements.title.focus();editForm.elements.title.select();}
    else{editError.hidden=true;editError.textContent='';}
  };
  const resetEditForm=()=>{
    editForm.reset();
    editDirty=false;setEditOpen(false);
  };
  editButton?.addEventListener('click',()=>setEditOpen(true));
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
  const statusUrl=`/api/orders/${encodeURIComponent(root.dataset.id)}/status`;
  const jsonHeaders={'Content-Type':'application/json','X-CSRF-Token':root.dataset.csrf};
  const showError=async message=>{await CalightModal.open({title:'Statuswechsel fehlgeschlagen',content:message,actions:[{label:'Schließen',primary:true}]});};
  // Statuswechsel vorwärts: offene Vorgänge (409) werden angezeigt und nur nach ausdrücklicher Bestätigung übergangen.
  root.querySelector('[data-next-status]')?.addEventListener('click',async event=>{
    const button=event.currentTarget,next=button.dataset.nextStatus,nextLabel=button.dataset.nextStatusLabel;
    if(!await CalightModal.confirm({title:'Auftragsstatus ändern',content:`Den Auftrag auf „${nextLabel}“ setzen? Der Wechsel wird dauerhaft im Statusverlauf gespeichert.`,confirmLabel:`${nextLabel} setzen`}))return;
    button.disabled=true;
    const send=confirmOpen=>request(statusUrl,{method:'PATCH',headers:jsonHeaders,body:JSON.stringify({expectedStatus:root.dataset.status,status:next,confirmOpen})});
    try{
      await send(false);
      location.reload();
    }catch(error){
      if(error.status===409&&error.result?.requiresConfirmation){
        const content=document.createElement('div');
        content.innerHTML=`<p>Zu diesem Auftrag gibt es noch offene Vorgänge. Sie werden <strong>nicht</strong> automatisch abgeschlossen oder storniert.</p><ul>${error.result.openItems.map(item=>`<li>${Calight.esc(item.count)} × ${Calight.esc(item.label)}</li>`).join('')}</ul><p>Nach dem Wechsel sind keine neuen Leistungen mehr möglich.</p>`;
        if(await CalightModal.confirm({title:`Trotzdem auf „${nextLabel}“ setzen?`,content,confirmLabel:'Trotzdem setzen',danger:true})){
          try{await send(true);location.reload();return;}catch(retry){await showError(retry.message);}
        }
      }else{
        await showError(error.message);
      }
      button.disabled=false;
    }
  });
  // Rücknahme um einen Schritt: nur Administratoren (Server prüft), Begründung Pflicht.
  root.querySelector('[data-revert-status]')?.addEventListener('click',async event=>{
    const button=event.currentTarget,label=button.dataset.revertStatusLabel;
    const form=document.createElement('form');
    form.className='form-stack';
    form.innerHTML=`<p>Der Auftrag wird auf „${Calight.esc(label)}“ zurückgesetzt. Die Rücknahme wird mit Begründung im Statusverlauf dokumentiert; bestehende Vorgänge bleiben unverändert.</p><label><span class="field-label">Begründung <span aria-hidden="true">*</span></span><textarea name="reason" rows="3" maxlength="500" required></textarea></label>`;
    let done=false;
    await CalightModal.open({title:'Status zurücknehmen',content:form,initialFocus:'[name="reason"]',actions:[{label:'Abbrechen',value:false},{label:'Zurücknehmen',value:true,primary:true,validate:true,onClick:async()=>{
      try{await request(`${statusUrl}/revert`,{method:'POST',headers:jsonHeaders,body:JSON.stringify({expectedStatus:root.dataset.status,reason:form.elements.reason.value.trim()})});done=true;return undefined;}
      catch(error){Calight.notify(form.querySelector('[data-error]')||(()=>{const n=document.createElement('div');n.dataset.error='';form.append(n);return n;})(),error.message);return false;}
    }}]});
    if(done)location.reload();
  });
  root.querySelector('[data-create-change-order]')?.addEventListener('click',async event=>{
    const button=event.currentTarget;
    const form=document.createElement('form');
    form.innerHTML='<label>Bezeichnung des Nachtrags<input name="title" maxlength="200" required placeholder="z. B. Zusätzliche Leitungswege"></label><p class="notice">Der neue Nachtrag wird als bearbeitbarer Entwurf mit eigener Nachtragsnummer und Revisionshistorie angelegt.</p>';
    const confirmed=await CalightModal.open({title:'Nachtrag anlegen',content:form,actions:[{label:'Abbrechen',value:false},{label:'Nachtrag erstellen',value:true,primary:true,validate:true}],initialFocus:'[name="title"]'});
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
