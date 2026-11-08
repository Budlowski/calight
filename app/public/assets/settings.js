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
  const documentOptions=form.querySelector('input[name="showProjectInfo"]')?.closest('.form-grid');
  if(documentOptions&&!form.elements.fileNamePattern){
    documentOptions.insertAdjacentHTML('beforebegin','<div class="form-grid"><label>Dateinamenvorlage<input name="fileNamePattern" maxlength="150" placeholder="{JJJJ}-{MM}-{TT}_{DOKUMENTTYP}_{DOKUMENTNUMMER}"><small>Platzhalter: {JJJJ}, {MM}, {TT}, {DOKUMENTTYP}, {DOKUMENTNUMMER}</small></label></div>');
  }
  if(documentOptions&&!form.elements.showTitleSummary){
    documentOptions.insertAdjacentHTML('beforeend','<label class="checkbox-row"><input name="showTitleSummary" type="checkbox"> Titelübersicht als Abschlussseite</label>');
  }
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
    logoData=result.settings.logoData;showLogo();
  }).catch(error=>notify(error.message,'error'));
  file.onchange=()=>{
    const selected=file.files[0];if(!selected)return;
    if(!['image/png','image/jpeg'].includes(selected.type)||selected.size>1_000_000){notify('Das Logo muss eine PNG- oder JPEG-Datei mit höchstens 1 MB sein.','error');file.value='';return;}
    const reader=new FileReader();
    reader.onload=()=>{const image=new Image();image.onload=()=>{const scale=Math.min(1,1000/image.width,400/image.height),canvas=document.createElement('canvas');canvas.width=Math.max(1,Math.round(image.width*scale));canvas.height=Math.max(1,Math.round(image.height*scale));const context=canvas.getContext('2d');context.fillStyle='#fff';context.fillRect(0,0,canvas.width,canvas.height);context.drawImage(image,0,0,canvas.width,canvas.height);logoData=canvas.toDataURL('image/jpeg',.9);showLogo();};image.src=reader.result;};
    reader.readAsDataURL(selected);
  };
  form.onsubmit=async event=>{
    event.preventDefault();const data=Object.fromEntries(new FormData(form));booleanNames.forEach(name=>data[name]=form.elements[name].checked);data.logoData=logoData;
    const response=await fetch('/api/settings',{method:'PUT',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':root.dataset.csrf},body:JSON.stringify(data)}),result=await response.json();
    if(!response.ok)return notify(result.error||'Speichern fehlgeschlagen.','error');notify('Einstellungen gespeichert.');
  };
})();
