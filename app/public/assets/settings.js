(()=>{
  'use strict';
  const root=document.querySelector('#settings-app');
  if(!root)return;
  const form=document.querySelector('#settings-form');
  const message=document.querySelector('#settings-message');
  const file=document.querySelector('#company-logo');
  const preview=document.querySelector('#logo-preview');
  const booleanNames=['showEmptyTitles','showHeader','showFooter','showProjectInfo','showTitleSummary'];
  let logoData=null;
  // Ungespeicherte Änderungen wie auf den anderen Bearbeitungsseiten schützen; doppeltes Absenden verhindern.
  let dirty=false,saving=false;
  const saveButtons=[...document.querySelectorAll('button[form="settings-form"], #settings-form button[type="submit"]')];
  form.addEventListener('input',()=>{dirty=true;});
  form.addEventListener('change',()=>{dirty=true;});
  addEventListener('beforeunload',event=>{if(!dirty)return;event.preventDefault();event.returnValue='';});
  const tabs=[...root.querySelectorAll('[data-settings-tab]')],panels=[...root.querySelectorAll('[data-settings-panel]')];
  // Bereiche über die gemeinsame Verwaltungsnavigation (partials/admin-nav.php), Auswahl im URL-Hash.
  const selectTab=name=>{
    if(!tabs.some(tab=>tab.dataset.settingsTab===name))name='company';
    tabs.forEach(tab=>{if(tab.dataset.settingsTab===name)tab.setAttribute('aria-current','page');else tab.removeAttribute('aria-current');});
    const current=tabs.find(tab=>tab.dataset.settingsTab===name),label=root.querySelector('[data-admin-current]');
    if(label&&current)label.textContent=current.textContent;
    root.querySelector('.admin-nav-compact')?.removeAttribute('open');
    panels.forEach(panel=>{const active=panel.dataset.settingsPanel===name;panel.classList.toggle('active',active);panel.hidden=!active;});
    history.replaceState(null,'',`#settings-${name}`);
  };
  tabs.forEach(tab=>tab.addEventListener('click',event=>{event.preventDefault();selectTab(tab.dataset.settingsTab);}));
  addEventListener('hashchange',()=>selectTab(location.hash.replace('#settings-','')||'company'));
  selectTab(location.hash.replace('#settings-','')||'company');
  const updateConditionalFields=()=>{
    const duration=form.elements.validityMode.value==='duration';
    form.querySelector('[data-validity-duration]').hidden=!duration;form.querySelector('[data-validity-date]').hidden=duration;
    form.elements.validityDays.disabled=!duration;form.elements.validityDate.disabled=duration;
    form.elements.vatRate.disabled=form.elements.taxMode.value==='exempt';
  };
  form.elements.validityMode.addEventListener('change',updateConditionalFields);
  form.elements.taxMode.addEventListener('change',updateConditionalFields);
  const notify=(text,type='success')=>{message.textContent=text;message.className=`notice notice-${type}`;message.hidden=false;};
  const showLogo=()=>preview.innerHTML=logoData?`<img class="settings-logo-preview" src="${logoData}" alt="Aktuelles Firmenlogo">`:'<span class="muted">Kein Logo hinterlegt</span>';
  fetch('/api/settings',{credentials:'same-origin'}).then(async response=>{
    const result=await response.json();
    if(!response.ok)throw Error(result.error);
    Object.entries(result.settings).forEach(([key,value])=>{
      const field=form.elements[key];
      if(!field||key==='logoData')return;
      if(field.type==='checkbox')field.checked=Boolean(value);else field.value=value??'';
    });
    logoData=result.settings.logoData;showLogo();updateConditionalFields();dirty=false;
  }).catch(error=>notify(error.message,'error'));
  file.onchange=()=>{
    const selected=file.files[0];if(!selected)return;
    if(!['image/png','image/jpeg'].includes(selected.type)||selected.size>1_000_000){notify('Das Logo muss eine PNG- oder JPEG-Datei mit höchstens 1 MB sein.','error');file.value='';return;}
    const reader=new FileReader();
    reader.onload=()=>{const image=new Image();image.onload=()=>{const scale=Math.min(1,1000/image.width,400/image.height),canvas=document.createElement('canvas');canvas.width=Math.max(1,Math.round(image.width*scale));canvas.height=Math.max(1,Math.round(image.height*scale));const context=canvas.getContext('2d');context.fillStyle='#fff';context.fillRect(0,0,canvas.width,canvas.height);context.drawImage(image,0,0,canvas.width,canvas.height);logoData=canvas.toDataURL('image/jpeg',.9);dirty=true;showLogo();};image.src=reader.result;};
    reader.readAsDataURL(selected);
  };
  form.onsubmit=async event=>{
    event.preventDefault();if(saving)return;const data=Object.fromEntries(new FormData(form));booleanNames.forEach(name=>data[name]=form.elements[name].checked);data.validityDays=form.elements.validityDays.value;data.validityDate=form.elements.validityDate.value;data.vatRate=form.elements.vatRate.value;data.logoData=logoData;
    saving=true;saveButtons.forEach(button=>button.disabled=true);
    try{
      const response=await fetch('/api/settings',{method:'PUT',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':root.dataset.csrf},body:JSON.stringify(data)}),result=await response.json();
      if(!response.ok)return notify(result.error||'Speichern fehlgeschlagen.','error');
      dirty=false;notify('Einstellungen gespeichert.');
    }catch{notify('Speichern fehlgeschlagen.','error');}
    finally{saving=false;saveButtons.forEach(button=>button.disabled=false);}
  };
})();
