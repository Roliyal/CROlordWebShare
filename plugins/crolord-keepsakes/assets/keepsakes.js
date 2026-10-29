(function(){
  'use strict';
  var root=document.querySelector('.boughs-keepsakes');if(!root||root.dataset.purchaseReady)return;
  root.dataset.purchaseReady='1';root.classList.add('has-purchase-js');
  var config=window.crolordKeepsakes||{},bar=root.querySelector('.fn-keepsakes-selection'),caption=root.querySelector('.fn-keepsakes-selection-caption'),clear=root.querySelector('.fn-keepsakes-clear'),review=root.querySelector('.fn-keepsakes-review');
  var dialog=root.querySelector('.fn-keepsakes-dialog');if(!dialog)return;
  var list=dialog.querySelector('.fn-keepsakes-dialog-list'),status=dialog.querySelector('.fn-keepsakes-dialog-status'),heading=dialog.querySelector('h2');
  var items=[],selected=[],details={},choices={},opened={},csrf='',busy=false,epoch=0,trigger=null,catalogPass=0,retryPass=0,readTimer=0,activeController=null,handoffLocked=false,handoffTimer=0,linkTimer=0,key='crolord:keepsakes:multi:v4:'+location.pathname;
  dialog.querySelectorAll('.fn-keepsakes-close,.fn-keepsakes-authorize,.fn-keepsakes-add,.fn-keepsakes-cart,.fn-keepsakes-reload,.fn-keepsakes-dialog-caption,.crks-cart-footer').forEach(function(node){node.remove();});
  function el(tag,cls,text){var n=document.createElement(tag);if(cls)n.className=cls;if(text!=null)n.textContent=text;return n;}
  function icon(name){var shapes={cart:'M3 4h2l2.3 10.2a1.5 1.5 0 0 0 1.5 1.2h8.6a1.5 1.5 0 0 0 1.5-1.2L20.5 8H6M10 20h.01M18 20h.01',close:'m6 6 12 12M18 6 6 18',minus:'M5 12h14',plus:'M5 12h14M12 5v14'};var svg=document.createElementNS('http://www.w3.org/2000/svg','svg'),path=document.createElementNS(svg.namespaceURI,'path');svg.setAttribute('viewBox','0 0 24 24');svg.setAttribute('width','18');svg.setAttribute('height','18');svg.setAttribute('fill','none');svg.setAttribute('stroke','currentColor');svg.setAttribute('stroke-width','1.5');svg.setAttribute('stroke-linecap','round');svg.setAttribute('stroke-linejoin','round');svg.setAttribute('aria-hidden','true');path.setAttribute('d',shapes[name]||shapes.cart);svg.appendChild(path);return svg;}
  function quantity(value){return /^[1-9][0-9]?$/.test(String(value))?Number(value):0;}
  function addURL(sku,count){if(!/^[1-9][0-9]{4,17}$/.test(sku)||!quantity(count))return '';var u=new URL('https://cart.jd.com/gate.action');u.searchParams.set('pid',sku);u.searchParams.set('pcount',String(count));u.searchParams.set('ptype','1');return u.href;}
  function externalLink(node){node.target='_blank';node.rel='noopener noreferrer';node.referrerPolicy='no-referrer';return node;}
  function showStatus(message){status.textContent=message;status.hidden=!message;}
  var footer=dialog.querySelector('footer')||el('footer'),actions=el('div','crks-cart-actions'),cartLink=externalLink(el('a','crks-native-add')),cartLabel=el('span','crks-add-label'),cartCount=el('span','crks-add-count'),cartNote=el('span','crks-handoff-status');
  cartLink.append(icon('cart'),cartLabel,cartCount);cartNote.setAttribute('role','status');actions.append(cartLink,cartNote);footer.replaceChildren(actions);if(!footer.parentNode)dialog.appendChild(footer);
  if(heading)heading.tabIndex=-1;showStatus('');
  function productURL(value){try{var u=new URL(value);if(!['https:','http:'].includes(u.protocol)||!['item.jd.com','item.m.jd.com'].includes(u.hostname)||u.username||u.password||u.port)return '';var m=u.pathname.match(/^\/(?:product\/)?([1-9][0-9]{4,17})\.html$/);return m?'https://item.jd.com/'+m[1]+'.html':'';}catch(ignore){return '';}}
  function safeMediaURL(value){try{var url=new URL(value,window.location.href);return url.protocol==='https:'||(url.protocol==='http:'&&url.origin===window.location.origin)?url.href:'';}catch(ignore){return '';}}
  function save(){try{sessionStorage.setItem(key,JSON.stringify({ids:selected,choices:choices}));}catch(ignore){}}
  function update(){items.forEach(function(item){var active=selected.includes(item.data.id);item.input.checked=active;item.card.classList.toggle('is-selected',active);});bar.hidden=!selected.length;caption.textContent='已选 '+selected.length+' 件';review.disabled=!selected.length;}
  root.querySelectorAll('[data-keepsake]').forEach(function(card){try{var data=JSON.parse(card.dataset.keepsake),input=card.querySelector('input[type=checkbox]');if(!input||!/^\d+$/.test(String(data.id)))return;data.id=String(data.id);data.url=productURL(data.url);data.preview=safeMediaURL(data.preview);var kind=card.querySelector('.fave-card-kind'),selectable=!!kind&&kind.textContent.trim()===(config.selectableKind||'生活器物');items.push({data:data,input:input,card:card,selectable:selectable});card.classList.toggle('crks-life',selectable);card.querySelector('.fn-keepsake-choose').hidden=!selectable;input.disabled=!selectable;input.checked=false;if(!selectable)card.classList.remove('is-selected');card.querySelector('.fn-keepsake-choose').title='选择规格并加入已选之物';
    input.addEventListener('change',function(){if(!selectable){input.checked=false;return;}if(input.checked&&!selected.includes(data.id)){if(selected.length>=12){input.checked=false;caption.textContent='一次可挑选至多 12 件。';return;}selected.push(data.id);}else if(!input.checked)selected=selected.filter(function(id){return id!==data.id;});save();update();});
  }catch(ignore){}});
  var likedKey='crolord:keepsakes:likes:v1',liked={},likeStates=new Map();
  try{var savedLikes=JSON.parse(localStorage.getItem(likedKey)||'{}');if(savedLikes&&typeof savedLikes==='object'&&!Array.isArray(savedLikes))liked=savedLikes;}catch(ignore){}
  function paintLike(card,id,value){var button=card.querySelector('[data-keepsake-like]');button.setAttribute('aria-pressed',String(value));button.title=value?'取消喜欢':'喜欢';button.setAttribute('aria-label',(value?'取消喜欢 ':'喜欢 ')+items.find(function(item){return item.data.id===id;}).data.title);}
  function saveLike(id,value){if(value)liked[id]=true;else delete liked[id];var ids=Object.keys(liked);if(ids.length>500)ids.slice(0,ids.length-500).forEach(function(old){delete liked[old];});try{localStorage.setItem(likedKey,JSON.stringify(liked));}catch(ignore){}}
  function likeRequest(id,method,body){
    var params=new URLSearchParams({action:'crolord_like',post_id:id}),controller=window.AbortController?new AbortController():null,timer=controller?setTimeout(function(){controller.abort();},15000):null;
    if(body)Object.keys(body).forEach(function(key){params.set(key,body[key]);});
    var url=new URL(config.likeUrl,location.href),options={method:method,credentials:'same-origin',cache:'no-store',signal:controller?controller.signal:undefined};
    if(method==='GET')url.search=params.toString();else{options.headers={'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'};options.body=params.toString();}
    return fetch(url.href,options).then(function(response){return response.json().catch(function(){throw Error('连接暂未完成，请稍后重试。');}).then(function(result){if(!response.ok||!result.success){var error=Error(result.data&&result.data.message||'暂时未能确认喜欢状态，请稍后重试。');error.status=response.status;throw error;}if(!result.data||typeof result.data.liked!=='boolean'||typeof result.data.nonce!=='string')throw Error('喜欢状态暂时不可读，请稍后再试。');likeStates.set(id,result.data);return result.data;});}).finally(function(){if(timer)clearTimeout(timer);});
  }
  if(config.likeUrl){items.forEach(function(item){var button=item.card.querySelector('[data-keepsake-like]');if(button){button.hidden=false;paintLike(item.card,item.data.id,liked[item.data.id]===true);}});
    root.addEventListener('click',function(event){var button=event.target.closest('[data-keepsake-like]');if(!button||button.disabled)return;var card=button.closest('[data-keepsake]'),item=items.find(function(row){return row.card===card;});if(!item)return;var id=item.data.id,message=card.querySelector('.fn-keepsake-like-status'),wanted=button.getAttribute('aria-pressed')!=='true';
      button.disabled=true;button.setAttribute('aria-busy','true');card.classList.remove('has-like-error');message.textContent='';
      (likeStates.has(id)?Promise.resolve(likeStates.get(id)):likeRequest(id,'GET')).then(function(value){return likeRequest(id,'POST',{nonce:value.nonce,liked:wanted?'1':'0'}).catch(function(error){if(error.status!==403)throw error;return likeRequest(id,'GET').then(function(fresh){return likeRequest(id,'POST',{nonce:fresh.nonce,liked:wanted?'1':'0'});});});}).then(function(value){paintLike(card,id,value.liked);saveLike(id,value.liked);message.textContent=value.liked?'谢谢你的喜欢。':'已取消喜欢。';}).catch(function(error){likeStates.delete(id);card.classList.add('has-like-error');message.textContent=error.name==='AbortError'?'连接暂未完成，请稍后再试。':error.message;}).finally(function(){button.disabled=false;button.removeAttribute('aria-busy');});
    });
  }
  try{var stored=JSON.parse(sessionStorage.getItem(key)||'null');if(stored&&Array.isArray(stored.ids)){Object.keys(stored.choices||{}).forEach(function(id){var value=stored.choices[id];if(/^\d+$/.test(id)&&value&&typeof value.sku==='string'&&/^[1-9][0-9]{4,17}$/.test(value.sku))choices[id]={sku:value.sku,quantity:quantity(value.quantity)||1};});stored=stored.ids;}else if(!Array.isArray(stored)){stored=JSON.parse(sessionStorage.getItem('crolord:keepsakes:multi:v3:'+location.pathname)||'null');if(!Array.isArray(stored)){var old=sessionStorage.getItem('fieldnotes:keepsakes:single:v2:'+location.pathname);stored=old?[old]:[];}}selected=items.filter(function(item){return item.selectable&&stored.includes(item.data.id);}).map(function(item){return item.data.id;}).slice(0,12);}catch(ignore){}
  clear.addEventListener('click',function(){selected=[];save();update();});
  function request(path,body){
    var controller=window.AbortController?new AbortController():null,timer=controller?setTimeout(function(){controller.abort();},20000):null;
    activeController=controller;
    var options={credentials:'same-origin',cache:'no-store',signal:controller?controller.signal:undefined,headers:{'Content-Type':'application/json','X-Crolord-CSRF':csrf}};
    if(body){options.method='POST';options.body=JSON.stringify(body);}
    return fetch(config.restUrl+path,options).then(function(response){return response.json().catch(function(){return {};}).then(function(data){if(!response.ok){var error=Error(data.message||'规格暂时无法读取。');error.status=response.status;throw error;}return data;});}).finally(function(){if(timer)clearTimeout(timer);if(activeController===controller)activeController=null;});
  }
  function current(id){return details[id]||items.find(function(item){return item.data.id===id;}).data;}
  function variantLabel(v){var keys=Object.keys(v.attributes||{}),labels={color:'颜色',size:'尺寸',spec:'规格',model:'型号',material:'材质',pattern:'图案'};return keys.length?keys.map(function(k){return (keys.length>1?(labels[k]||k)+' ':'')+v.attributes[k];}).join(' · '):(v.name||'SKU '+v.sku);}
  function cartPlan(){
    var tasks=[],reason='';
    selected.forEach(function(id){
      var p=current(id),choice=choices[id],variants=Array.isArray(p.variants)?p.variants:[],v=choice&&variants.find(function(row){return row.sku===choice.sku&&row.available!==false;}),count=quantity(choice&&choice.quantity);
      if(!v){reason=reason||'选择规格';return;}if(!count){reason=reason||'填写数量';return;}
      var sent=opened[id];tasks.push({id:id,title:p.title,variant:v,quantity:count,url:addURL(v.sku,count),opened:!!(sent&&sent.sku===v.sku&&sent.quantity===count)});
    });
    return {tasks:tasks,reason:reason,ready:!reason&&tasks.length===selected.length&&tasks.length>0};
  }
  function updateFooter(){
    var plan=cartPlan(),next=plan.tasks.find(function(task){return !task.opened;}),target=next||plan.tasks[0],complete=plan.ready&&!next,index=target?plan.tasks.indexOf(target):0;
    footer.hidden=!selected.length;
    cartLabel.textContent=handoffLocked?'正在打开':plan.ready?complete?'重新加购':'去购买':busy?'读取规格…':plan.reason||'选择规格';
    cartCount.hidden=!plan.ready;cartCount.textContent=plan.tasks.length===1?plan.tasks[0].quantity+' 件':(complete?plan.tasks.length:index+1)+' / '+plan.tasks.length;
    cartLink.setAttribute('aria-disabled',String(!plan.ready||handoffLocked));
    cartLink.setAttribute('aria-label',plan.ready?(complete?'重新加购所选商品':'去购买，第 '+(index+1)+' 项，共 '+plan.tasks.length+' 项：'+target.title+'，'+variantLabel(target.variant)+'，'+target.quantity+' 件'):cartLabel.textContent);
    if(plan.ready&&!handoffLocked){cartLink.href=target.url;cartLink.title=target.title+' · '+variantLabel(target.variant)+' · '+target.quantity+' 件';cartLink.removeAttribute('tabindex');}
    else{cartLink.removeAttribute('href');cartLink.removeAttribute('title');cartLink.setAttribute('tabindex','-1');}
  }
  cartLink.addEventListener('click',function(event){
    var plan=cartPlan(),next=plan.tasks.find(function(task){return !task.opened;});
    if(!plan.ready||handoffLocked){event.preventDefault();return;}
    if(!next){
      if(!window.confirm('再次加购可能增加数量。确认已核对京东购物车，仍要重新打开？')){event.preventDefault();return;}
      plan.tasks.forEach(function(task){delete opened[task.id];});next=plan.tasks[0];
    }
    cartLink.href=next.url;opened[next.id]={sku:next.variant.sku,quantity:next.quantity};handoffLocked=true;
    cartNote.textContent='已打开 '+next.title+' 的京东加购页面，请在京东确认结果。';
    clearTimeout(linkTimer);clearTimeout(handoffTimer);linkTimer=setTimeout(updateFooter,0);handoffTimer=setTimeout(function(){handoffLocked=false;updateFooter();},600);
  });
  function render(){
    list.replaceChildren();
    selected.forEach(function(id){
      var p=current(id),row=el('article','fn-keepsakes-line'),summary=el('div','crks-line-summary'),copy=el('div','fn-keepsakes-line-copy'),top=el('div','fn-keepsakes-line-top'),remove=el('button','fn-keepsakes-remove');
      row.dataset.itemId=id;remove.type='button';remove.title='移除';remove.setAttribute('aria-label','移除 '+p.title);remove.appendChild(icon('close'));top.append(el('h3','',p.title),remove);copy.appendChild(top);
      if(p.product_title){var info=el('p','crks-product-title','商品信息：'+p.product_title);info.title=p.product_title;copy.appendChild(info);}
      var picture=items.find(function(item){return item.data.id===id;}).data.preview;
      if(picture){var img=el('img');img.src=picture;img.alt='';img.width=64;img.height=64;summary.appendChild(img);}
      summary.appendChild(copy);row.appendChild(summary);
      var variants=(Array.isArray(p.variants)?p.variants:[]).filter(function(v){return v&&typeof v.sku==='string'&&/^[1-9][0-9]{4,17}$/.test(v.sku);});
      if(variants.length){
        var controls=el('div','fn-keepsakes-options'),label=el('label','crks-spec-label','规格'),wrap=el('span','crks-select'),select=el('select'),placeholder=el('option','','选择规格');
        placeholder.value='';select.appendChild(placeholder);select.setAttribute('aria-label',p.title+' 的规格');
        variants.forEach(function(v){var option=el('option','',variantLabel(v)+(v.available===false?' · 暂不可选':''));option.value=v.sku;option.disabled=v.available===false;select.appendChild(option);});
        if(!choices[id]){var available=variants.filter(function(v){return v.available!==false;});choices[id]={sku:available.length===1?available[0].sku:'',quantity:1};}
        if(!variants.some(function(v){return v.sku===choices[id].sku&&v.available!==false;}))choices[id].sku='';
        if(!quantity(choices[id].quantity))choices[id].quantity=1;
        select.value=choices[id].sku;select.addEventListener('change',function(){choices[id].sku=select.value;save();updateFooter();});wrap.appendChild(select);
        var arrow=icon('minus');arrow.firstChild.setAttribute('d','m8 10 4 4 4-4');wrap.appendChild(arrow);label.appendChild(wrap);controls.appendChild(label);
        var countLabel=el('div','crks-count-label'),stepper=el('span','crks-stepper'),countInput=el('input'),less=el('button','crks-step'),more=el('button','crks-step');
        countLabel.appendChild(el('span','','数量'));countInput.type='number';countInput.min='1';countInput.max='99';countInput.step='1';countInput.inputMode='numeric';countInput.value=String(choices[id].quantity);countInput.setAttribute('aria-label',p.title+' 的数量');
        less.type=more.type='button';less.setAttribute('aria-label','减少 '+p.title+' 的数量');more.setAttribute('aria-label','增加 '+p.title+' 的数量');less.appendChild(icon('minus'));more.appendChild(icon('plus'));
        function updateQuantity(){var value=quantity(countInput.value);choices[id].quantity=countInput.value;countInput.setAttribute('aria-invalid',String(!value));less.disabled=value===1;more.disabled=value===99;save();updateFooter();}
        function step(delta){var value=quantity(countInput.value)||0;countInput.value=String(Math.max(1,Math.min(99,value+delta)));updateQuantity();}
        countInput.addEventListener('input',updateQuantity);less.addEventListener('click',function(){step(-1);});more.addEventListener('click',function(){step(1);});less.disabled=quantity(countInput.value)===1;more.disabled=quantity(countInput.value)===99;
        stepper.append(less,countInput,more);countLabel.appendChild(stepper);controls.appendChild(countLabel);row.appendChild(controls);
      }else if(!busy&&!['crks_catalog_pending','crks_public_pending'].includes(p.catalog_status)){row.appendChild(el('p','crks-read-note','暂时无法读取此商品规格。'));}
      remove.addEventListener('click',function(){selected=selected.filter(function(x){return x!==id;});delete details[id];delete choices[id];delete opened[id];save();update();if(!selected.length)dismiss();else loadCatalog(false,true);});
      list.appendChild(row);
    });
    updateFooter();save();
  }
  function scheduleRead(refresh,delay){clearTimeout(readTimer);var version=epoch;readTimer=setTimeout(function(){if(version===epoch&&dialog.open)loadCatalog(true,refresh);},delay);}
  function loadCatalog(continuing,refresh){
    if(!continuing){catalogPass=0;retryPass=0;clearTimeout(readTimer);}
    catalogPass++;var version=++epoch;busy=true;showStatus('正在读取规格…');if(!continuing)render();
    if(!config.restUrl){busy=false;showStatus('暂时无法读取规格。');render();return;}
    (csrf?Promise.resolve():request('session').then(function(s){csrf=s.csrf;})).then(function(){if(version!==epoch||!dialog.open)return null;return request('catalog',{ids:selected,refresh:refresh===true});}).then(function(data){
      if(!data||version!==epoch||!dialog.open)return;
      (data.products||[]).forEach(function(p){if(selected.includes(String(p.id)))details[String(p.id)]=p;});busy=false;render();
      var ready=selected.filter(function(id){return details[id]&&Array.isArray(details[id].variants)&&details[id].variants.length;}).length;
      var pending=(data.products||[]).some(function(p){return ['crks_catalog_pending','crks_public_pending'].includes(p.catalog_status);});
      if(pending&&catalogPass<24){showStatus(ready?'正在读取其余规格…':'正在读取规格…');scheduleRead(refresh,700);}
      else showStatus(ready===selected.length?'':ready?'部分规格暂时无法读取。':'暂时无法读取规格。');
    }).catch(function(error){
      if(version!==epoch||!dialog.open)return;busy=false;csrf='';
      if(retryPass<1&&(error.name==='AbortError'||error.name==='TypeError'||error.status===403||error.status>=500)){retryPass++;showStatus('正在重新连接…');scheduleRead(refresh,1200);}
      else{render();showStatus('暂时无法读取规格，稍后重新打开即可。');}
    }).finally(function(){if(version===epoch)busy=false;});
  }
  function dismiss(){++epoch;clearTimeout(readTimer);clearTimeout(linkTimer);clearTimeout(handoffTimer);handoffLocked=false;if(activeController)activeController.abort();busy=false;if(typeof dialog.close==='function')dialog.close();else dialog.removeAttribute('open');document.body.classList.remove('fn-keepsakes-open');if(trigger)trigger.focus();}
  review.addEventListener('click',function(){trigger=review;if(typeof dialog.showModal==='function')dialog.showModal();else dialog.setAttribute('open','');document.body.classList.add('fn-keepsakes-open');if(heading)heading.focus({preventScroll:true});loadCatalog(false,true);});dialog.addEventListener('cancel',function(event){event.preventDefault();dismiss();});dialog.addEventListener('click',function(event){if(event.target===dialog)dismiss();});
  dialog.addEventListener('keydown',function(event){if(event.key==='Escape'){event.preventDefault();dismiss();}else if(event.key==='Tab'){var nodes=Array.from(dialog.querySelectorAll('button,a[href],input,select')).filter(function(n){return !n.disabled&&!n.closest('[hidden]');}),first=nodes[0],last=nodes[nodes.length-1];if(first&&last&&event.shiftKey&&document.activeElement===first){event.preventDefault();last.focus();}else if(first&&last&&!event.shiftKey&&document.activeElement===last){event.preventDefault();first.focus();}}});
  update();
}());
