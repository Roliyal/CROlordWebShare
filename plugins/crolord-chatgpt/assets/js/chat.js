(function () {
  'use strict';
  var roots = document.querySelectorAll('.fnchat');
  var config = window.fieldnotesChatGPT;
  if (!roots.length || !config) return;
  var root = document.querySelector('.chatgpt-page-content .fnchat') || document.querySelector('#main-content .fnchat') || roots[0];
  // Covers direct template/widget output as well as filtered page content.
  Array.prototype.forEach.call(roots, function (node) { if (node !== root) node.remove(); });
  if (root.dataset.fnchatInitialized === '1') return;
  root.dataset.fnchatInitialized = '1';
  var strings = config.strings || {};
  window.addEventListener('pageshow', function (event) { if (event.persisted) window.location.reload(); });

  function sendRequest(action, fields) {
    var body = fields instanceof FormData ? fields : new URLSearchParams();
    body.set('action', action);
    body.set('nonce', config.nonce);
    Object.keys(fields || {}).forEach(function (key) { if (!(fields instanceof FormData)) body.set(key, fields[key]); });
    return fetch(config.ajaxUrl, {
      method: 'POST', credentials: 'same-origin', cache: 'no-store',
      headers: fields instanceof FormData ? {} : { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
      body: fields instanceof FormData ? body : body.toString()
    }).then(function (response) {
      return response.json().catch(function () { throw new Error(strings.networkError); });
    }).then(function (result) {
      if (!result || !result.success) {
        var error = new Error((result && result.data && result.data.message) || strings.networkError);
        error.code = result && result.data && result.data.code;
        error.definite = error.code !== 'account_network';
        throw error;
      }
      return result;
    });
  }

  var unlockForm = root.querySelector('.fnchat-unlock');
  if (unlockForm) {
    var input = unlockForm.querySelector('input[name="password"]');
    var button = unlockForm.querySelector('button[type="submit"]');
    var status = unlockForm.querySelector('.fnchat-form-status');
    var show = unlockForm.querySelector('.fnchat-show-password');
    var unlocking = false;
    if (show) show.addEventListener('click', function () {
      var visible = input.type === 'password';
      input.type = visible ? 'text' : 'password';
      show.setAttribute('aria-pressed', String(visible));
      show.setAttribute('aria-label', visible ? show.dataset.hide : show.dataset.show);
    });
    unlockForm.addEventListener('submit', function (event) {
      event.preventDefault();
      if (unlocking) return;
      if (!input.value.trim()) {
        status.textContent = strings.passwordRequired;
        input.setAttribute('aria-invalid', 'true');
        input.focus();
        return;
      }
      unlocking = true;
      button.disabled = true;
      button.querySelector('span').textContent = strings.working;
      input.removeAttribute('aria-invalid');
      status.textContent = '';
      sendRequest('fieldnotes_chatgpt_unlock', { password: input.value }).then(function () {
        input.value = '';
        window.location.reload();
      }).catch(function (error) {
        status.textContent = error.message || strings.networkError;
        input.setAttribute('aria-invalid', 'true');
        input.focus();
        unlocking = false;
        button.disabled = false;
        button.querySelector('span').textContent = strings.enter || '进入对话';
      });
    });
    return;
  }

  var log = root.querySelector('.fnchat-log');
  var form = root.querySelector('.fnchat-compose'), textarea = root.querySelector('#fnchat-message');
  if (!log || !form || !textarea) return;
  var sendButton = form.querySelector('.fnchat-send'), composeStatus = root.querySelector('#fnchat-compose-status');
  var clearButton = root.querySelector('.fnchat-clear'), exportButton = root.querySelector('.fnchat-export');
  var welcome = log.querySelector('.fnchat-welcome').cloneNode(true), history = [], busy = false, active = null;
  var draftFiles = [], usedFiles = [], uploadCount = 0, selectedTools = [], pollTimer = null, retryPreparing=false;
  var attachButton = root.querySelector('.fnchat-attach'), fileInput = root.querySelector('.fnchat-file-input'), attachmentArea = root.querySelector('.fnchat-attachments');
  var lockButton = root.querySelector('.fnchat-lock'), reauth = root.querySelector('.fnchat-reauth');
  var conversationId=identifier(),modelSelect=root.querySelector('.fnchat-model'),effortSelect=root.querySelector('.fnchat-effort'),modelCatalog=[],preferences={},restoredChoice=null;
  var historyPanel=root.querySelector('.fnchat-history-panel'),historyList=root.querySelector('.fnchat-history-list'),historyButton=root.querySelector('.fnchat-history-toggle');
  try{preferences=JSON.parse(localStorage.getItem('echoes-preferences-'+config.historyScope)||'{}');}catch(error){}
  if(preferences.toolOwner===config.visitorScope&&Array.isArray(preferences.tools))selectedTools=preferences.tools.slice(0,32).filter(function(s){return s&&typeof s.server==='string'&&Array.isArray(s.tools);}).map(function(s){return{server:s.server,tools:s.tools.filter(function(name){return typeof name==='string';}).slice(0,512)};});
  var db=new Promise(function(resolve){if(!window.indexedDB){resolve(null);return;}var request=indexedDB.open('crolord-echoes-'+config.historyScope,1);request.onupgradeneeded=function(){request.result.createObjectStore('conversations',{keyPath:'id'});};request.onsuccess=function(){resolve(request.result);};request.onerror=function(){resolve(null);};});
  var arrowIcon=sendButton.firstElementChild.cloneNode(true),lastBusy=null,layoutFrame=0,streamDisabled=false,historyStart=0,lastCompletedId='',leaving=false;
  var earlier=root.querySelector('.fnchat-earlier'),latest=root.querySelector('.fnchat-latest');
  var pendingKey='echoes-pending-'+config.historyScope,draftKey='echoes-draft-'+config.historyScope;
  var preview = root.querySelector('.fnchat-preview'), previewContent = root.querySelector('.fnchat-preview-content'), previewTitle = root.querySelector('.fnchat-preview-title'), previewReturn = null;
  function say(text,info){composeStatus.textContent=text||'';composeStatus.classList.toggle('is-info',!!info);}
  function scrollLog() { log.scrollTop = log.scrollHeight; }
  function resizeInput(){if(layoutFrame)return;layoutFrame=requestAnimationFrame(function(){layoutFrame=0;textarea.style.height='auto';textarea.style.height=Math.min(textarea.scrollHeight,root.classList.contains('is-compact')?64:160)+'px';root.style.setProperty('--fnchat-composer-height',root.querySelector('.fnchat-composer-area').offsetHeight+'px');});}
  function controls() {
    root.classList.toggle('is-welcome', !!log.querySelector('.fnchat-welcome'));
    root.classList.toggle('is-busy', busy);
    log.setAttribute('aria-busy', String(busy));
    sendButton.disabled = busy ? !!(active && active.stopping) : (!config.ready || !modelSelect.value || uploadCount > 0 || (!textarea.value.trim() && !draftFiles.some(function(f){return f.id;})));
    if(lastBusy!==busy){
      if(window.EchoesActions)window.EchoesActions.setBusy(busy,root);
      if(toolPicker)toolPicker.setBusy(busy);
      lastBusy=busy;sendButton.type=busy?'button':'submit';sendButton.setAttribute('aria-label',busy?'停止生成':'发送消息');sendButton.title=busy?'停止生成':'发送消息';sendButton.replaceChildren();
      if(busy){var stop=document.createElement('span');stop.className='fnchat-stop-mark';sendButton.appendChild(stop);}else sendButton.appendChild(arrowIcon.cloneNode(true));
    }
    textarea.disabled = !config.ready;modelSelect.disabled=busy;effortSelect.disabled=busy;historyButton.disabled=busy||uploadCount>0;
    textarea.placeholder = busy ? '可以先写下一条想法…' : '写下想法，或放入一份资料…';
    clearButton.disabled = busy || uploadCount > 0;
    exportButton.disabled = busy || !history.length;
    lockButton.disabled = busy || uploadCount > 0;
    attachButton.disabled = !config.ready || draftFiles.length >= Number(config.maxFiles||4);
    root.querySelectorAll('[data-prompt]').forEach(function(button){button.disabled = busy || !config.ready;});
    var hint = root.querySelector('.fnchat-key-hint');
    hint.textContent = busy ? '回复中 · 可停止' : 'Enter 发送 · Shift + Enter 换行';
  }
  function row(role,text,pendingReply,host) {
    host=host||log;
    if(host===log){root.classList.remove('is-welcome');var intro=log.querySelector('.fnchat-welcome');if(intro)intro.remove();}
    var article=document.createElement('article');article.className='fnchat-message fnchat-message-'+role+(pendingReply?' is-pending':'');
    var label=document.createElement('span');label.className='fnchat-message-label';label.textContent=role==='assistant'?'issac':'你';
    var body=document.createElement('div');body.className='fnchat-message-body';body.textContent=pendingReply?'':text;body.hidden=!!pendingReply;
    article.append(label,body);host.appendChild(article);var reply={row:article,body:body};if(pendingReply)showActivity(reply,{status:'running',phase:'正在连接'});if(host===log)scrollLog();return reply;
  }
  function renderReply(parent,text,streaming,references){if(window.EchoesText)window.EchoesText.render(parent,text,!!streaming,references);else parent.textContent=text;}
  function outputFiles(files){
    var kinds={'image/png':'image','image/jpeg':'image','image/webp':'image','image/gif':'image','video/mp4':'video','video/webm':'video','audio/mpeg':'audio','audio/wav':'audio','audio/ogg':'audio'};
    return (Array.isArray(files)?files:[]).slice(0,12).filter(function(file){return file&&/^[a-f0-9]{32}$/.test(file.id)&&typeof file.name==='string'&&file.name.length<=200&&Number.isSafeInteger(file.size)&&file.size>0&&file.size<=268435456&&Number.isFinite(file.expires)&&typeof file.mime==='string'&&(kinds[file.mime]===file.kind||file.kind==='document')&&/^[a-f0-9]{64}$/.test(file.sha256);}).map(function(file){return{id:file.id,name:file.name,mime:file.mime,kind:file.kind,size:file.size,sha256:file.sha256,expires:file.expires,unavailable:!!file.unavailable};});
  }
  function outputUrl(file,inline){
    var url=new URL(config.ajaxUrl,location.href);if(url.origin!==location.origin)return '';
    url.searchParams.set('action','fieldnotes_chatgpt_artifact');url.searchParams.set('nonce',config.nonce);url.searchParams.set('file_id',file.id);if(inline)url.searchParams.set('inline','1');return url.href;
  }
  function showArtifacts(reply,data){
    var files=outputFiles(data.artifacts),notices=(Array.isArray(data.artifact_notices)?data.artifact_notices:[]).filter(function(n){return typeof n==='string';}).slice(0,12);
    if(!files.length&&!notices.length&&!reply.artifacts)return;
    if(!reply.artifacts){var container=document.createElement('div');container.className='fnchat-artifacts';container.setAttribute('aria-label','回复文件');reply.row.appendChild(container);reply.artifacts={container:container,nodes:new Map()};}
    var view=reply.artifacts,keep=new Set();
    files.forEach(function(file){
      keep.add(file.id);var expired=file.unavailable||file.expires*1000<=Date.now(),signature=JSON.stringify([file,expired]),old=view.nodes.get(file.id);if(old&&old.signature===signature)return;
      var card=document.createElement('figure');card.className='fnchat-output-file';card.dataset.fileId=file.id;card.dataset.kind=file.kind;
      var info=document.createElement('figcaption'),words=document.createElement('span'),title=document.createElement('strong'),detail=document.createElement('small');title.textContent=file.name;
      detail.textContent=expired?'文件已过期或不属于当前访问':(file.size>=1048576?(file.size/1048576).toFixed(1)+' MB':Math.max(1,Math.ceil(file.size/1024))+' KB')+' · 保存至 '+new Date(file.expires*1000).toLocaleDateString();words.append(title,detail);info.appendChild(words);
      if(!expired){
        var url=outputUrl(file,true),download=document.createElement('a');download.href=outputUrl(file,false);download.className='fnchat-file-download';download.setAttribute('download',file.name);download.setAttribute('aria-label','下载 '+file.name);download.title='下载 '+file.name;download.referrerPolicy='no-referrer';
        var icon=document.createElementNS('http://www.w3.org/2000/svg','svg');icon.setAttribute('viewBox','0 0 24 24');icon.setAttribute('fill','none');icon.setAttribute('stroke','currentColor');icon.setAttribute('stroke-width','1.5');icon.setAttribute('stroke-linecap','round');icon.setAttribute('stroke-linejoin','round');icon.setAttribute('aria-hidden','true');var path=document.createElementNS(icon.namespaceURI,'path');path.setAttribute('d','M12 3v12m-5-5 5 5 5-5M5 16v4h14v-4');icon.appendChild(path);download.appendChild(icon);info.appendChild(download);
        if(file.kind==='image'){
          var button=document.createElement('button'),img=document.createElement('img');button.type='button';button.className='fnchat-output-image';button.setAttribute('aria-label','预览 '+file.name);img.src=url;img.alt=file.name;img.loading='lazy';img.decoding='async';img.referrerPolicy='no-referrer';button.appendChild(img);button.addEventListener('click',function(){openPreview({kind:'image',name:file.name,url:url},button);});card.appendChild(button);
        }else if(file.kind==='video'||file.kind==='audio'){
          var media=document.createElement(file.kind);media.controls=true;media.preload='none';media.setAttribute('aria-label',file.name);if(file.kind==='video')media.setAttribute('playsinline','');var source=document.createElement('source');source.src=url;source.type=file.mime;media.appendChild(source);card.appendChild(media);
        }
      }
      card.appendChild(info);if(old)old.node.replaceWith(card);else view.container.appendChild(card);view.nodes.set(file.id,{node:card,signature:signature});
    });
    view.nodes.forEach(function(record,id){if(!keep.has(id)){record.node.remove();view.nodes.delete(id);}});
    if(notices.length&&!view.notices){view.notices=document.createElement('p');view.notices.className='fnchat-artifact-notice';view.notices.setAttribute('role','status');view.container.appendChild(view.notices);}if(view.notices){view.notices.hidden=!notices.length;view.notices.textContent=notices.join(' ');}
    view.container.hidden=!files.length&&!notices.length;
  }
  function activityView(reply){
    if(reply.activity)return reply.activity;
    var details=document.createElement('details'),summary=document.createElement('summary'),symbol=document.createElement('i'),label=document.createElement('span'),time=document.createElement('small'),list=document.createElement('ol');
    details.className='fnchat-activity';details.setAttribute('aria-label','本次处理进度');symbol.className='fnchat-activity-symbol';symbol.setAttribute('aria-hidden','true');label.className='fnchat-activity-label';time.className='fnchat-activity-time';list.className='fnchat-activity-list';summary.append(symbol,label,time);details.append(summary,list);reply.row.insertBefore(details,reply.body);
    reply.activity={details:details,label:label,time:time,list:list,items:new Map(),signature:'',data:null};details.addEventListener('toggle',function(){if(details.open)renderActivity(reply);});return reply.activity;
  }
  function showSources(reply,sources){
    if(!sources||!sources.length)return;var signature=JSON.stringify(sources);if(reply.sourceSignature===signature)return;reply.sourceSignature=signature;
    if(!reply.sources){reply.sources=document.createElement('nav');reply.sources.className='fnchat-sources';reply.sources.setAttribute('aria-label','资料来源');reply.row.appendChild(reply.sources);}reply.sources.replaceChildren();
    var label=document.createElement('span');label.textContent='来源';reply.sources.appendChild(label);var more=null,list=null;
    sources.forEach(function(source,index){var url=window.EchoesText&&EchoesText.safeUrl(source.url);if(!url)return;var a=document.createElement('a');a.href=url;a.target='_blank';a.rel='noopener noreferrer';a.title=source.title||url;a.textContent=(source.number?source.number+' · ':'')+new URL(url).hostname.replace(/^www\./,'');if(index<5)reply.sources.appendChild(a);else{if(!more){more=document.createElement('details');var summary=document.createElement('summary');summary.textContent='更多来源';list=document.createElement('div');more.append(summary,list);reply.sources.appendChild(more);}list.appendChild(a);}});
  }
  function showActivity(reply,data){
    showArtifacts(reply,data);
    var view=activityView(reply),running=data.status==='running'||data.status==='approval',items=data.activity||[],elapsed=Number(data.elapsed||0);
    view.details.hidden=!running&&!items.length;view.details.setAttribute('aria-busy',String(running));view.label.textContent=running?(data.phase||'处理中'):(data.status==='done'?'处理过程':'本次进度');view.time.textContent=elapsed?elapsed+' 秒':'';
    view.data=data;if(view.details.open)renderActivity(reply);
    showSources(reply,data.sources);
  }
  function renderActivity(reply){
    if(!reply.activity||!reply.activity.data)return;
    var view=reply.activity,data=view.data,items=data.activity||[],signature=JSON.stringify(items),referenceSignature=JSON.stringify(data.references||{}),refsChanged=referenceSignature!==view.referenceSignature;
    if(signature===view.signature&&!refsChanged)return;
    view.signature=signature;view.referenceSignature=referenceSignature;view.itemSignatures=view.itemSignatures||new Map();
    var keep=new Set();
    items.forEach(function(item){
      keep.add(item.id);
      var row=view.items.get(item.id),itemSignature=JSON.stringify([item.kind,item.status,item.label,item.detail||'']);
      if(row&&!refsChanged&&view.itemSignatures.get(item.id)===itemSignature)return;
      if(!row){row=document.createElement('li');row.dataset.activityId=item.id;var title=document.createElement('span'),detail=document.createElement('div');title.className='fnchat-activity-title';detail.className='fnchat-activity-detail';row.append(title,detail);view.items.set(item.id,row);view.list.appendChild(row);}
      row.dataset.kind=item.kind;row.dataset.status=item.status;if(row.firstChild.textContent!==item.label)row.firstChild.textContent=item.label;
      renderReply(row.lastChild,item.detail||'',item.status==='running',data.references);view.itemSignatures.set(item.id,itemSignature);
    });
    view.items.forEach(function(row,id){if(!keep.has(id)){row.remove();view.items.delete(id);view.itemSignatures.delete(id);}});
  }
  function queueProgress(turn,data){
    turn.nextProgress=data;if(turn.renderFrame||turn.renderDelay)return;
    turn.renderDelay=setTimeout(function(){turn.renderDelay=0;turn.renderFrame=requestAnimationFrame(function(){turn.renderFrame=0;if(active!==turn||leaving)return;turn.lastRenderAt=performance.now();var next=turn.nextProgress,signature=JSON.stringify([next.status,next.phase,next.elapsed,next.activity,next.sources,next.references,next.partial,next.artifacts,next.artifact_notices]);if(signature===turn.progressSignature)return;turn.progressSignature=signature;var bottom=follow(),references=next.references||{},refsChanged=JSON.stringify(turn.references||{})!==JSON.stringify(references);turn.references=references;
      showActivity(turn.reply,next);
      if(typeof next.partial==='string'&&(next.partial!==turn.rendered||refsChanged)){turn.rendered=next.partial;turn.reply.body.hidden=!next.partial;renderReply(turn.reply.body,next.partial,true,references);turn.reply.row.classList.add('is-streaming');turn.reply.row.classList.remove('is-pending');}
      if(bottom)scrollLog();
    });},Math.max(0,80-(performance.now()-(turn.lastRenderAt||0))));
  }

  function legacyCopy(text) {
    return new Promise(function (resolve, reject) {
      var active = document.activeElement;
      var helper = document.createElement('textarea');
      helper.value = text; helper.setAttribute('readonly', '');
      helper.style.position = 'fixed'; helper.style.opacity = '0';
      document.body.appendChild(helper); helper.select();
      var copied = false;
      try { copied = document.execCommand('copy'); } catch (error) { copied = false; }
      helper.remove();
      if (active && active.focus) active.focus({ preventScroll: true });
      copied ? resolve() : reject(new Error(strings.copyFailed));
    });
  }
  function copyText(text) {
    if (window.isSecureContext && navigator.clipboard && navigator.clipboard.writeText) {
      return navigator.clipboard.writeText(text).catch(function () { return legacyCopy(text); });
    }
    return legacyCopy(text);
  }
  function replyIcon(checked) {
    var svg=document.createElementNS('http://www.w3.org/2000/svg','svg');
    svg.setAttribute('viewBox','0 0 24 24');svg.setAttribute('width','19');svg.setAttribute('height','19');svg.setAttribute('fill','none');svg.setAttribute('stroke','currentColor');svg.setAttribute('stroke-width','1.5');svg.setAttribute('stroke-linecap','round');svg.setAttribute('stroke-linejoin','round');svg.setAttribute('aria-hidden','true');
    var path=document.createElementNS(svg.namespaceURI,'path');path.setAttribute('d',checked?'m5 12 4 4L19 6':'M8 7V5a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2h-2M5 8h9a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-9a2 2 0 0 1 2-2Z');svg.appendChild(path);return svg;
  }
  function addCopyButton(row, text) {
    var actions=document.createElement('div');actions.className='fnchat-message-actions';
    var copy=document.createElement('button');copy.type='button';copy.className='fnchat-copy fnchat-message-copy';
    function state(checked){var label=checked?(strings.copied||'已复制'):(strings.copy||'复制回复');copy.replaceChildren(replyIcon(checked));copy.setAttribute('aria-label',label);copy.title=label;copy.dataset.copied=checked?'true':'false';}
    state(false);copy.addEventListener('click',function(){copyText(text).then(function(){state(true);setTimeout(function(){state(false);},1600);}).catch(function(){say(strings.copyFailed);});});
    actions.appendChild(copy);row.appendChild(actions);
  }
  function variantOf(message){var value={};['content','status','model','effort','activity','sources','references','elapsed','artifacts','artifact_notices','feedback','error'].forEach(function(key){if(message[key]!==undefined)value[key]=message[key];});return JSON.parse(JSON.stringify(value));}
  function addReplyActions(reply,message){
    if(!message.id)message.id=identifier();reply.row.dataset.messageId=message.id;
    if(!window.EchoesActions){addCopyButton(reply.row,message.content);return;}
    window.EchoesActions.mount(reply.row,reply.body,message,{root:root,copy:copyText,notice:say,feedback:function(value){message.feedback=value;if(message.variants&&message.variants[message.version||0])message.variants[message.version||0].feedback=value;saveConversation();},retry:function(){retryMessage(message.id);},variant:function(index){
      if(busy||!message.variants||!message.variants[index])return;window.EchoesActions.stop();message.variants[message.version||0]=variantOf(message);var chosen=JSON.parse(JSON.stringify(message.variants[index]));Object.keys(variantOf(message)).forEach(function(key){delete message[key];});Object.assign(message,chosen);message.version=index;
      reply.body._echoesBlocks=null;reply.body.replaceChildren();renderReply(reply.body,message.content,false,message.references);if(message.status&&message.status!=='done'&&message.error&&message.error!==message.content){var note=document.createElement('p');note.textContent=message.error;reply.body.prepend(note);}showActivity(reply,Object.assign({status:'done'},message));reply.row.classList.toggle('is-failed',!!message.status&&message.status!=='done');reply.row.querySelector('.fnchat-message-label').textContent='issac'+(message.model?' · '+message.model:'')+(message.effort?' · '+message.effort:'');addReplyActions(reply,message);saveConversation();
    }});
  }
  function retainVariants(turn,message){if(turn.previousVariants){message.variants=turn.previousVariants.concat([variantOf(message)]);message.version=message.variants.length-1;}return message;}
  async function retryMessage(id){
    if(busy||uploadCount||retryPreparing||!config.ready)return;
    var index=history.findIndex(function(m){return m.role==='assistant'&&m.id===id;});if(index<1||history[index-1].role!=='user')return;
    var original=history[index],message=JSON.parse(JSON.stringify(history[index-1]));
    if(message.attachments_expired){say('原问题的上传附件已过期，请重新上传后发送。');return;}
    var previous=original.variants?JSON.parse(JSON.stringify(original.variants)):[variantOf(original)];if(original.variants)previous[original.version||0]=variantOf(original);
    // Keep the original conversation and its later messages; retry creates a separate branch.
    retryPreparing=true;var archived;try{archived=await saveConversation();}catch(error){say('原对话未能保存，请先导出备份。');}finally{retryPreparing=false;}if(archived===false||archived===undefined||busy||uploadCount)return;
    if(window.EchoesActions)window.EchoesActions.stop();history=history.slice(0,index-1);conversationId=identifier();lastCompletedId='';renderHistory(true);
    message.id=identifier();delete message.tools;message.model=modelSelect.value;message.effort=effortSelect.value;
    var files=(message.files||[]).map(function(fileId){return usedFiles.find(function(f){return f.id===fileId;})||{id:fileId,name:'原附件',size:0,kind:'document'};});
    launchTurn(message,files,{previousVariants:previous,regenerate:true});
  }

  function identifier(){var bytes=new Uint8Array(16);if(window.crypto&&window.crypto.getRandomValues)window.crypto.getRandomValues(bytes);else for(var i=0;i<bytes.length;i++)bytes[i]=Math.floor(Math.random()*256);return Array.from(bytes,function(value){return value.toString(16).padStart(2,'0');}).join('');}
  function action(name, fields) { return sendRequest('fieldnotes_chatgpt_' + name, fields || {}).then(function(result){return result.data;}); }
  function follow() { return log.scrollHeight - log.scrollTop - log.clientHeight < 100; }
  function contextFor(message){var complete=[];for(var i=0;i<history.length-1;i++){if(history[i].role==='user'&&history[i+1].role==='assistant'&&(!history[i+1].status||history[i+1].status==='done'))complete.push(history[i],history[++i]);}function clean(m){var value={role:m.role,content:m.content};if(m.files)value.files=m.files;return value;}var messages=complete.slice(-22).map(clean).concat([clean(message)]);while(messages.length>1&&new TextEncoder().encode(JSON.stringify(messages)).length>62000)messages.splice(0,2);return messages;}
  function recoverDraft(turn) {
    if (!textarea.value.trim()) textarea.value = turn.text;
    turn.files.forEach(function(file){if(draftFiles.length<Number(config.maxFiles||4)&&!draftFiles.some(function(f){return f.id===file.id;}))draftFiles.push(file);});
    renderAttachments(); resizeInput(); controls(); textarea.focus({preventScroll:true});
  }
  function finish(turn, data) {
    if(active!==turn)return;
    data=Object.assign({},turn.snapshot||{},data);
    clearTimeout(pollTimer);if(turn.controller)turn.controller.abort();if(turn.renderFrame)cancelAnimationFrame(turn.renderFrame);if(turn.renderDelay)clearTimeout(turn.renderDelay);busy=false;active=null;showActivity(turn.reply,Object.assign({},data,data.result||{},{status:data.status}));turn.reply.body.hidden=false;turn.reply.row.classList.remove('is-streaming');
    turn.reply.row.classList.remove('is-pending');
    if(data.status==='done'&&data.result&&(typeof data.result.text==='string'&&data.result.text.trim()||outputFiles(data.result.artifacts).length)){
      data.result.text=data.result.text||'已交付：'+outputFiles(data.result.artifacts).map(function(file){return file.name;}).join('、');
      renderReply(turn.reply.body,data.result.text,false,data.result.references);
      var replyMessage=retainVariants(turn,{id:identifier(),role:'assistant',status:'done',content:data.result.text,model:data.result.model,effort:data.result.effort,activity:data.result.activity||data.activity||[],sources:data.result.sources||data.sources||[],references:data.result.references||data.references||{},elapsed:data.result.elapsed||data.elapsed||0,artifacts:outputFiles(data.result.artifacts||data.artifacts),artifact_notices:data.result.artifact_notices||data.artifact_notices||[]});history=history.concat([turn.message,replyMessage]);addReplyActions(turn.reply,replyMessage);turn.reply.row.querySelector('.fnchat-message-label').textContent='issac'+(data.result.model?' · '+data.result.model:'')+(data.result.effort?' · '+data.result.effort:'');say('',true);lastCompletedId=turn.id;saveConversation().then(function(){clearPending(turn.id);});
    } else {
      clearPending(turn.id);
      turn.user.row.classList.add('is-failed');
      turn.reply.row.classList.add('is-failed');turn.reply.row.dataset.errorCode=data.error_code||'';
      turn.reply.body.replaceChildren();var errorNote=document.createElement('p');errorNote.textContent=data.status==='cancelled'?'已停止生成。':data.error||strings.networkError;turn.reply.body.appendChild(errorNote);if(data.partial){var unfinished=document.createElement('div');renderReply(unfinished,data.partial,false,data.references);turn.reply.body.appendChild(unfinished);}
      var edit=document.createElement('button');edit.type='button';edit.className='fnchat-copy';edit.textContent='放回输入框';
      edit.addEventListener('click',function(){recoverDraft(turn);});turn.user.row.appendChild(edit);
      say(data.status==='cancelled'?'已停止；已执行的工具操作不会被撤销。':'发送未完成，内容保留在对话中，没有自动重发。');
      var failedMessage=retainVariants(turn,{id:identifier(),role:'assistant',status:data.status,content:data.partial||errorNote.textContent,error:errorNote.textContent,activity:data.activity||[],sources:data.sources||[],references:data.references||{},elapsed:data.elapsed||0,artifacts:outputFiles(data.artifacts),artifact_notices:data.artifact_notices||[]});history=history.concat([turn.message,failedMessage]);addReplyActions(turn.reply,failedMessage);saveConversation();
    }
    controls();if(follow())scrollLog();
  }
  function showProgress(turn,data){
    if(active!==turn||leaving)return;
    turn.errors=0;
    if(typeof data.delta==='string')data.partial=(turn.partial||'')+data.delta;
    if(typeof data.partial==='string')turn.partial=data.partial;
    turn.snapshot=Object.assign(turn.snapshot||{},data);delete turn.snapshot.delta;data=Object.assign({},turn.snapshot);
    if(['done','failed','cancelled'].indexOf(data.status)!==-1){finish(turn,data);return;}
    queueProgress(turn,data);
    if(!turn.streaming&&!turn.stopping)pollTimer=setTimeout(function(){poll(turn);},900);
  }
  async function beginStream(turn){
    if(active!==turn||turn.stopping||leaving)return;
    if(streamDisabled||!window.ReadableStream||!window.AbortController){poll(turn);return;}
    clearTimeout(pollTimer);turn.streaming=true;var controller=new AbortController();turn.controller=controller;var watchdog=setTimeout(function(){controller.abort();},2500),received=false;
    try{
      var fields=new FormData();fields.set('action','fieldnotes_chatgpt_stream');fields.set('nonce',config.nonce);fields.set('request_id',turn.id);fields.set('stream_protocol','2');
      var response=await fetch(config.ajaxUrl,{method:'POST',credentials:'same-origin',body:fields,signal:controller.signal});
      if(!response.ok||!response.body||!(response.headers.get('Content-Type')||'').includes('text/event-stream'))throw new Error('Event stream unavailable');
      var reader=response.body.getReader(),decoder=new TextDecoder(),buffer='';
      while(active===turn&&!turn.stopping){var chunk=await reader.read();if(chunk.done)break;received=true;clearTimeout(watchdog);watchdog=setTimeout(function(){controller.abort();},4000);root.dataset.transport='stream';buffer+=decoder.decode(chunk.value,{stream:true}).replace(/\r\n/g,'\n');
        if(buffer.length>1000000)throw new Error('Event stream too large');
        var split;while((split=buffer.indexOf('\n\n'))>=0){var block=buffer.slice(0,split);buffer=buffer.slice(split+2);var lines=block.split('\n').filter(function(line){return line.startsWith('data:');});if(!lines.length)continue;var event=JSON.parse(lines.map(function(line){return line.slice(5).trimStart();}).join('\n'));if(event.stream_error)throw new Error(event.stream_error);showProgress(turn,event);}
      }
      if(active===turn&&!turn.stopping&&!leaving){turn.streaming=false;pollTimer=setTimeout(function(){beginStream(turn);},60);}
    }catch(error){if(active===turn&&!turn.stopping&&!leaving){streamDisabled=true;turn.streaming=false;root.dataset.transport='poll';pollTimer=setTimeout(function(){poll(turn);},100);}}
    finally{clearTimeout(watchdog);if(turn.controller===controller)turn.controller=null;}
  }
  function poll(turn){
    if(active!==turn||turn.stopping||leaving)return;
    var epoch=turn.pollEpoch||0;
    action('poll',{request_id:turn.id}).then(function(data){if(epoch===(turn.pollEpoch||0))showProgress(turn,data);}).catch(function(error){
      if(active!==turn||epoch!==(turn.pollEpoch||0)||turn.stopping)return;
      if(error.code==='session_expired'){reauth.hidden=false;finish(turn,{status:'failed',error:error.message});return;}
      turn.errors++;
      if(turn.errors>=5){
        say('连接中断，结果尚未确认。可检查已有结果或停止等待；不会重复发送。');
        var check=document.createElement('button');check.type='button';check.className='fnchat-copy';check.textContent='检查已有结果';
        check.addEventListener('click',function(){check.remove();turn.errors=0;poll(turn);});composeStatus.appendChild(check);
        return;
      }
      pollTimer=setTimeout(function(){poll(turn);},Math.min(turn.errors*1500,6000));
    });
  }
  function submit(){
    if(latest&&!latest.hidden&&!busy)renderHistory(true);
    var text=textarea.value.trim();if(busy||uploadCount||!config.ready||!modelSelect.value||(!text&&!draftFiles.length))return;
    if(draftFiles.some(function(file){return !file.id;})){say('请移除上传失败的附件，或重新选择文件。');return;}
    var files=draftFiles.slice(),message={id:identifier(),role:'user',content:text||'请阅读这些附件，概括重点。',model:modelSelect.value,effort:effortSelect.value};
    if(files.length)message.files=files.map(function(file){return file.id;});
    launchTurn(message,files,{});
  }
  function launchTurn(message,files,options){
    var text=message.content;
    var messages=contextFor(message),serialized=JSON.stringify(messages);
    if(new TextEncoder().encode(serialized).length>65000){say('这段内容较长，请缩短消息或作为文本附件上传。');return;}
    busy=true;closeTools();say('');
    if(window.EchoesActions)window.EchoesActions.stop();
    var turn={id:identifier(),text:text,message:message,messages:messages,files:files,tools:JSON.parse(JSON.stringify(selectedTools)),errors:0,user:row('user',text||'阅读附件'),reply:row('assistant','正在连接…',true),previousVariants:options.previousVariants,preserveDraft:!!options.regenerate};
    active=turn;
    files.forEach(function(file){turn.user.body.appendChild(fileChip(file,false));});
    // Clear only the submitted draft. Typing the next message never gets erased by a reply.
    if(!options.regenerate){textarea.value='';draftFiles=[];renderAttachments();}resizeInput();controls();
    persistPending(turn);
    action('start',{request_id:turn.id,messages:serialized,tools:JSON.stringify(turn.tools),model:message.model||modelSelect.value,effort:message.effort||effortSelect.value,conversation_id:conversationId}).then(function(data){showProgress(turn,data);if(active===turn)beginStream(turn);}).catch(function(error){
      if(error.code==='session_expired'){reauth.hidden=false;finish(turn,{status:'failed',error:error.message});return;}
      if(error.definite){finish(turn,{status:'failed',error:error.message});return;}
      // The start response may have been lost after acceptance. Poll this same id; never submit again automatically.
      showActivity(turn.reply,{status:'running',phase:'确认连接状态'});say(error.message||strings.networkError);pollTimer=setTimeout(function(){poll(turn);},1000);
    });
  }
  function stop(){
    if(!active||active.stopping)return;
    var turn=active;turn.stopping=true;if(turn.controller)turn.controller.abort();turn.pollEpoch=(turn.pollEpoch||0)+1;clearTimeout(pollTimer);controls();
    action('cancel',{request_id:turn.id}).then(function(){turn.stopping=false;poll(turn);}).catch(function(error){turn.stopping=false;say(error.message||strings.networkError);controls();poll(turn);});
  }
  form.addEventListener('submit',function(event){event.preventDefault();submit();});
  sendButton.addEventListener('click',function(event){if(busy){event.preventDefault();stop();}});
  textarea.addEventListener('input',function(){resizeInput();controls();try{localStorage.setItem(draftKey,textarea.value);}catch(e){}});
  textarea.addEventListener('keydown',function(event){if(event.key==='Enter'&&!event.shiftKey&&!event.isComposing&&event.keyCode!==229&&!busy){event.preventDefault();submit();}});
  function releaseFiles(){usedFiles.forEach(function(file){if(file.url)URL.revokeObjectURL(file.url);});usedFiles=[];draftFiles=[];renderAttachments();}
  clearButton.addEventListener('click',function(){if(busy||uploadCount)return;if(window.EchoesActions)window.EchoesActions.stop();saveConversation();history=[];historyStart=0;earlier.hidden=true;latest.hidden=true;lastCompletedId='';clearPending();try{localStorage.removeItem(draftKey);}catch(e){};conversationId=identifier();preferences.last='new';storePreferences();log.replaceChildren(welcome.cloneNode(true));say('');textarea.value='';releaseFiles();resizeInput();controls();if(config.ready)textarea.focus({preventScroll:true});});
  exportButton.addEventListener('click',function(){if(!history.length||busy)return;var text=history.map(function(message){return '## '+(message.role==='user'?'你':'issac')+'\n\n'+message.content+(outputFiles(message.artifacts).length?'\n\n文件：'+outputFiles(message.artifacts).map(function(file){return file.name;}).join('、'):'');}).join('\n\n');var blob=new Blob([text],{type:'text/markdown;charset=utf-8'}),url=URL.createObjectURL(blob),link=document.createElement('a');link.href=url;link.download='echoes-'+new Date().toISOString().slice(0,10)+'.md';link.click();setTimeout(function(){URL.revokeObjectURL(url);},1000);});
  root.addEventListener('click',function(event){var codeCopy=event.target.closest('.fnchat-code-copy');if(codeCopy){var code=codeCopy.closest('.fnchat-code').querySelector('code').textContent;copyText(code).then(function(){codeCopy.textContent='已复制';setTimeout(function(){codeCopy.textContent='复制';},1500);}).catch(function(){say(strings.copyFailed);});return;}var prompt=event.target.closest('[data-prompt]');if(!prompt||prompt.disabled||textarea.disabled)return;textarea.value=prompt.dataset.prompt||'';resizeInput();controls();textarea.focus();});
  lockButton.addEventListener('click',function(){if(busy||uploadCount)return;busy=true;controls();action('reset').catch(function(){}).then(function(){return action('lock');}).then(function(){releaseFiles();history=[];textarea.value='';log.replaceChildren();location.reload();}).catch(function(error){say(error.message||strings.networkError);busy=false;controls();});});
  reauth.addEventListener('click',function(){location.reload();});
  var connect=root.querySelector('.fnchat-connections-toggle'),panel=root.querySelector('.fnchat-connect-panel'),toolList=root.querySelector('.fnchat-connect-list');
  var toolCount=root.querySelector('.fnchat-tool-count'),toolsLoaded=false,toolsLoading=false;
  var toolPicker=window.EchoesToolPicker?window.EchoesToolPicker.mount(panel,{change:function(value){selectedTools=value;var count=value.reduce(function(n,s){return n+s.tools.length;},0);toolCount.hidden=!count;toolCount.textContent=count;preferences.tools=value;preferences.toolOwner=config.visitorScope;storePreferences();}}):null;
  var restoredToolCount=selectedTools.reduce(function(n,s){return n+s.tools.length;},0);toolCount.hidden=!restoredToolCount;toolCount.textContent=restoredToolCount;
  function closeTools(){panel.hidden=true;connect.setAttribute('aria-expanded','false');}
  function loadTools(){
    if(toolsLoading)return;toolsLoading=true;toolList.textContent='正在检查连接…';
    action('tools').then(function(data){
      toolsLoaded=true;
      if(toolPicker){toolPicker.setCatalog(data.servers,selectedTools);toolPicker.setBusy(busy);return;}
      toolList.replaceChildren();
      var servers=data.servers||[],available=[];
      if(!servers.length){toolList.textContent='还没有连接工具。请站主在 Echoes 设置中添加 MCP 服务与允许的工具名。';}
      servers.forEach(function(server){
        var group=document.createElement('details'),heading=document.createElement('summary');heading.textContent=server.name+' · '+server.count+' 个工具';group.appendChild(heading);var note=document.createElement('small');note.textContent=server.error||'选中的工具将按任务自动调用。';group.appendChild(note);
        if(server.count)available.push(server.id);var old=selectedTools.find(function(v){return (typeof v==='string'?v:v.server)===server.id;});
        (server.tools||[]).forEach(function(tool){var label=document.createElement('label'),input=document.createElement('input'),text=document.createElement('span');input.type='checkbox';input.value=tool.name;input.dataset.server=server.id;input.checked=!!old&&(typeof old==='string'||old.tools.includes(tool.name));text.textContent=tool.name;label.title=tool.description||'';label.append(input,text);group.appendChild(label);input.addEventListener('change',function(){var chosen={};toolList.querySelectorAll('input:checked').forEach(function(c){(chosen[c.dataset.server]||(chosen[c.dataset.server]=[])).push(c.value);});selectedTools=Object.keys(chosen).map(function(id){return{server:id,tools:chosen[id]};});var count=Object.values(chosen).reduce(function(n,a){return n+a.length;},0);toolCount.hidden=!count;toolCount.textContent=count;});});toolList.appendChild(group);
      });
      selectedTools=selectedTools.filter(function(v){return available.includes(typeof v==='string'?v:v.server);});
    }).catch(function(error){toolList.textContent=error.message||'工具列表读取失败，请重试。';}).finally(function(){toolsLoading=false;});
  }
  connect.addEventListener('click',function(){panel.hidden=!panel.hidden;connect.setAttribute('aria-expanded',String(!panel.hidden));if(!panel.hidden&&!toolsLoaded)loadTools();});
  root.querySelector('.fnchat-refresh-connections').addEventListener('click',loadTools);
  root.querySelector('.fnchat-close-connections').addEventListener('click',function(){closeTools();connect.focus();});
  document.addEventListener('click',function(event){if(!panel.hidden&&!panel.contains(event.target)&&!connect.contains(event.target))closeTools();});
  root.addEventListener('keydown',function(event){if(event.key==='Escape'&&!panel.hidden){closeTools();connect.focus();}});
  function fileChip(file,removable){
    var chip=document.createElement('div');chip.className='fnchat-file-chip'+(file.error?' is-error':'');
    var open=document.createElement('button');open.type='button';open.className='fnchat-file-open';
    if(file.kind==='image'&&file.url){var img=document.createElement('img');img.src=file.url;img.alt='';open.appendChild(img);}
    else {var mark=document.createElement('span');mark.className='fnchat-file-mark';mark.textContent=file.name.split('.').pop();open.appendChild(mark);}
    var info=document.createElement('span');info.className='fnchat-file-info';var name=document.createElement('strong');name.textContent=file.name;var status=document.createElement('small');status.textContent=file.error||(!file.id?file.progress||'正在上传…':file.truncated?'已就绪 · 仅前 10 万字':Math.ceil(file.size/1024)+' KB · 已就绪');info.append(name,status);open.appendChild(info);
    open.addEventListener('click',function(){openPreview(file,open);});chip.appendChild(open);
    if(removable){var remove=document.createElement('button');remove.type='button';remove.className='fnchat-file-remove';remove.textContent='×';remove.setAttribute('aria-label','移除 '+file.name);remove.disabled=!file.id&&!file.error;
      remove.addEventListener('click',function(){
        remove.disabled=true;
        var referenced=history.some(function(message){return message.files&&message.files.indexOf(file.id)!==-1;});
        var request=file.id&&!referenced?action('remove_file',{file_id:file.id}):Promise.resolve();
        request.then(function(){draftFiles=draftFiles.filter(function(f){return f!==file;});renderAttachments();controls();}).catch(function(error){say(error.message);remove.disabled=false;});
      });chip.appendChild(remove);
    }return chip;
  }
  function renderAttachments(){attachmentArea.replaceChildren();attachmentArea.hidden=!draftFiles.length;draftFiles.forEach(function(file){attachmentArea.appendChild(fileChip(file,true));});resizeInput();}
  function addFiles(files){
    Array.from(files).forEach(function(raw){
      if(draftFiles.length>=Number(config.maxFiles||4)){say('每条消息最多 '+config.maxFiles+' 份附件。');return;}
      if(raw.size>Number(config.uploadMax)||raw.size===0){say('附件为空或超过上传上限（'+(Number(config.uploadMax)/1048576).toFixed(1)+' MB）。');return;}
      if(!/\.(jpe?g|png|webp|gif|pdf|txt|md|csv|tsv|json|log|docx|xlsx|pptx)$/i.test(raw.name)){say('支持图片、PDF、文本、DOCX、XLSX 和 PPTX；暂不支持音视频或旧版 Office。');return;}
      var file={name:raw.name,size:raw.size,kind:/\.(jpe?g|png|webp|gif)$/i.test(raw.name)?'image':'document',url:URL.createObjectURL(raw)};
      draftFiles.push(file);usedFiles.push(file);uploadCount++;renderAttachments();controls();
      action('upload_begin',{name:raw.name,size:raw.size}).then(async function(start){
        file.uploadId=start.upload_id;var offset=start.offset||0,chunkSize=Number(config.chunkSize||1048576);
        while(offset<raw.size){var data=new FormData();data.set('upload_id',file.uploadId);data.set('offset',String(offset));data.set('file',raw.slice(offset,Math.min(raw.size,offset+chunkSize)),'chunk');var next=await action('upload_chunk',data);if(next.offset<=offset||next.offset>raw.size)throw new Error('上传进度无效，请重试。');offset=next.offset;file.progress=Math.round(offset/raw.size*100)+'%';renderAttachments();}
        return action('upload_finish',{upload_id:file.uploadId});
      }).then(function(result){Object.assign(file,result);}).catch(function(error){file.error=error.message||'上传失败';say(file.error);}).finally(function(){uploadCount--;renderAttachments();controls();});
    });
  }
  attachButton.addEventListener('click',function(){fileInput.click();});
  fileInput.addEventListener('change',function(){addFiles(fileInput.files);fileInput.value='';});
  textarea.addEventListener('paste',function(event){var files=event.clipboardData&&event.clipboardData.files;if(files&&files.length){event.preventDefault();addFiles(files);}});
  form.addEventListener('dragover',function(event){event.preventDefault();form.classList.add('is-dragging');});
  form.addEventListener('dragleave',function(event){if(!form.contains(event.relatedTarget))form.classList.remove('is-dragging');});
  form.addEventListener('drop',function(event){event.preventDefault();form.classList.remove('is-dragging');addFiles(event.dataTransfer.files);});
  function openPreview(file,trigger){
    previewReturn=trigger;previewContent.replaceChildren();previewTitle.textContent=file.name;
    if(file.kind==='image'&&file.url){var img=document.createElement('img');img.src=file.url;img.alt=file.name;previewContent.appendChild(img);}
    else {var note=document.createElement('p');note.textContent=file.error||(!file.id?'正在上传与解析…':'附件已解析为文字。Office 中的图片、PDF 扫描图和版式不会作为完整文档传入。');previewContent.appendChild(note);}
    preview.hidden=false;root.querySelector('.fnchat-preview-close').focus();
  }
  function closePreview(){preview.hidden=true;previewContent.replaceChildren();if(previewReturn&&previewReturn.isConnected)previewReturn.focus({preventScroll:true});}
  root.querySelector('.fnchat-preview-close').addEventListener('click',closePreview);
  preview.addEventListener('click',function(event){if(event.target===preview)closePreview();});
  preview.addEventListener('keydown',function(event){if(event.key==='Escape'){event.preventDefault();event.stopPropagation();closePreview();}if(event.key==='Tab'){event.preventDefault();root.querySelector('.fnchat-preview-close').focus();}});
  var choiceFrame=0,choiceMeasure=document.createElement('canvas').getContext('2d');
  // Measure only on choice/font/viewport changes, never on each keystroke or reply token.
  function fitChoices(){
    if(choiceFrame||!choiceMeasure)return;
    choiceFrame=requestAnimationFrame(function(){
      choiceFrame=0;
      var sizes=[modelSelect,effortSelect].map(function(select){
        var style=getComputedStyle(select),option=select.options[select.selectedIndex],text=option?option.text:'';
        choiceMeasure.font=style.font||style.fontWeight+' '+style.fontSize+' '+style.fontFamily;
        var extra=(parseFloat(style.letterSpacing)||0)*Math.max(0,Array.from(text).length-1);
        ['paddingLeft','paddingRight','borderLeftWidth','borderRightWidth'].forEach(function(key){extra+=parseFloat(style[key])||0;});
        return{select:select,text:text,width:Math.max(42,Math.ceil(choiceMeasure.measureText(text).width+extra+2))+'px'};
      });
      sizes.forEach(function(size){if(size.select.style.getPropertyValue('--fnchat-choice-width')!==size.width)size.select.style.setProperty('--fnchat-choice-width',size.width);size.select.title=size.text;});
    });
  }
  if(document.fonts&&document.fonts.ready)document.fonts.ready.then(fitChoices);
  var viewportFrame=0,viewportWidth=0,restingHeight=0,lastViewportHeight=0,lastCompact=null;
  function viewport(){
    if(viewportFrame)return;
    viewportFrame=requestAnimationFrame(function(){
      viewportFrame=0;
      var visual=window.visualViewport,width=window.innerWidth,height=Math.floor(visual?visual.height:window.innerHeight);
      var offset=Math.max(0,Math.floor(visual?visual.offsetTop:0)),rect=root.getBoundingClientRect();
      var focused=root.contains(document.activeElement)&&document.activeElement.matches('textarea,input,[contenteditable="true"]');
      var mobile=width<=740&&window.matchMedia('(pointer: coarse)').matches;
      if(width!==viewportWidth){restingHeight=window.innerHeight;viewportWidth=width;fitChoices();}
      if(!focused)restingHeight=Math.max(height,window.innerHeight);
      var keyboard=mobile&&focused&&(!visual||Math.abs(visual.scale-1)<.05)&&Math.max(restingHeight,window.innerHeight)-height>120;
      var wasKeyboard=root.classList.contains('is-keyboard-open');
      if(keyboard&&!wasKeyboard)root.style.setProperty('--fnchat-rest-height',Math.ceil(rect.height)+'px');
      root.classList.toggle('is-keyboard-open',keyboard);
      if(!keyboard)root.style.removeProperty('--fnchat-rest-height');
      [['--fnchat-visible-height',height],['--fnchat-viewport-top',offset],['--fnchat-shell-top',Math.max(12,Math.floor(rect.top-offset))]].forEach(function(pair){
        var value=pair[1]+'px';if(root.style.getPropertyValue(pair[0])!==value)root.style.setProperty(pair[0],value);
      });
      var compact=height<440;root.classList.toggle('is-compact',compact);
      if(height!==lastViewportHeight||compact!==lastCompact||keyboard!==wasKeyboard)resizeInput();
      lastViewportHeight=height;lastCompact=compact;
    });
  }
  window.addEventListener('resize',viewport,{passive:true});
  root.addEventListener('focusin',viewport);root.addEventListener('focusout',viewport);
  if(window.visualViewport){window.visualViewport.addEventListener('resize',viewport,{passive:true});window.visualViewport.addEventListener('scroll',viewport,{passive:true});}
  function storePreferences(){try{preferences.model=modelSelect.value;preferences.effort=effortSelect.value;delete preferences.web;localStorage.setItem('echoes-preferences-'+config.historyScope,JSON.stringify(preferences));}catch(error){}}
  function populateEfforts(preferred){var model=modelCatalog.find(function(m){return m.id===modelSelect.value;});effortSelect.replaceChildren();var values=model&&model.efforts&&model.efforts.length?model.efforts:[''];values.forEach(function(value){effortSelect.add(new Option(value||'默认强度',value));});if(values.includes(preferred))effortSelect.value=preferred;else if(model&&values.includes(model.defaultEffort))effortSelect.value=model.defaultEffort;fitChoices();}
  function applyModelChoice(choice){var requested=choice&&choice.model;if(requested&&modelCatalog.some(function(m){return m.id===requested;}))modelSelect.value=requested;else if(requested){modelSelect.value='';say('原模型 '+requested+' 不可用，请重新选择；不会静默降级。');}populateEfforts(choice&&choice.effort);controls();}
  modelSelect.addEventListener('change',function(){populateEfforts(effortSelect.value);storePreferences();controls();});effortSelect.addEventListener('change',function(){storePreferences();fitChoices();});
  var modelsReady=action('models').then(function(data){config.ready=!!(data.models&&data.models.length);root.dataset.ready=config.ready?'1':'0';if(config.ready){var notice=root.querySelector('.fnchat-notice');if(notice)notice.hidden=true;}modelCatalog=data.models||[];modelSelect.replaceChildren();modelCatalog.forEach(function(model){modelSelect.add(new Option(model.label,model.id));});var chosen=restoredChoice||preferences;if(!chosen.model)chosen={model:data.model||((modelCatalog.find(function(m){return m.id==='gpt-6.1-sol';})||modelCatalog.find(function(m){return m.default;})||modelCatalog[0]||{}).id),effort:data.effort};applyModelChoice(chosen);if(!modelCatalog.length)say('账号尚未提供可用模型，请在后台检查。');}).catch(function(error){config.ready=false;root.dataset.ready='0';modelSelect.replaceChildren(new Option('暂不可用',''));say(error.message);controls();});
  function historyRequest(mode,run){return db.then(function(database){if(!database)throw new Error('本浏览器不支持历史保存');return new Promise(function(resolve,reject){var transaction=database.transaction('conversations',mode),request=run(transaction.objectStore('conversations'));transaction.oncomplete=function(){resolve(request.result);};transaction.onerror=function(){reject(transaction.error);};});});}
  function saveConversation(){if(!history.length)return Promise.resolve(true);var references=new Set();history.forEach(function(m){(m.files||[]).forEach(function(id){references.add(id);});});var record={id:conversationId,title:((history.find(function(m){return m.role==='user';})||{}).content||'对话').slice(0,40),updated:Date.now(),messages:history.slice(),model:modelSelect.value,effort:effortSelect.value,visitorScope:config.visitorScope,lastRequest:lastCompletedId,attachments:usedFiles.filter(function(f){return references.has(f.id);}).map(function(f){return{id:f.id,name:f.name,size:f.size,kind:f.kind,truncated:f.truncated};})};preferences.last=conversationId;storePreferences();return historyRequest('readwrite',function(store){return store.put(record);}).then(function(){return true;}).catch(function(){say('本浏览器未能保存历史，可使用导出备份。',true);return false;});}
  function historyRows(messages){var fragment=document.createDocumentFragment();messages.forEach(function(m){var item=row(m.role,m.content,false,fragment);if(m.role==='assistant'){renderReply(item.body,m.content,false,m.references);showArtifacts(item,m);if(m.activity&&m.activity.length||m.sources&&m.sources.length)showActivity(item,Object.assign({status:'done'},m));if(m.status&&m.status!=='done'){item.row.classList.add('is-failed');if(m.error&&m.error!==m.content){var note=document.createElement('p');note.textContent=m.error;item.body.prepend(note);}}if(m.model)item.row.querySelector('.fnchat-message-label').textContent='issac · '+m.model+(m.effort?' · '+m.effort:'');addReplyActions(item,m);}else(m.files||[]).forEach(function(id){var file=usedFiles.find(function(f){return f.id===id;});if(file)item.body.appendChild(fileChip(file,false));});});return fragment;}
  function renderHistory(bottom){historyStart=Math.max(0,history.length-40);log.replaceChildren(historyRows(history.slice(historyStart)));if(!history.length)log.replaceChildren(welcome.cloneNode(true));earlier.hidden=!historyStart;if(bottom)requestAnimationFrame(function(){scrollLog();latest.hidden=true;});}
  earlier.addEventListener('click',function(){var end=historyStart;historyStart=Math.max(0,end-40);var height=log.scrollHeight,top=log.scrollTop;log.prepend(historyRows(history.slice(historyStart,end)));log.scrollTop=top+log.scrollHeight-height;earlier.hidden=!historyStart;});
  latest.addEventListener('click',function(){scrollLog();latest.hidden=true;});log.addEventListener('scroll',function(){latest.hidden=follow();},{passive:true});
  function persistPending(turn){try{preferences.last=conversationId;storePreferences();if(!turn.preserveDraft)localStorage.removeItem(draftKey);localStorage.setItem(pendingKey,JSON.stringify({id:turn.id,conversation:conversationId,message:turn.message,text:turn.text,files:turn.files.map(function(f){return{id:f.id,name:f.name,size:f.size,kind:f.kind};}),started:Date.now(),visitor:config.visitorScope,previousVariants:turn.previousVariants}));}catch(e){}}
  function clearPending(id){try{var saved=JSON.parse(localStorage.getItem(pendingKey)||'null');if(!id||saved&&saved.id===id)localStorage.removeItem(pendingKey);}catch(e){}}
  function restorePending(){var pending;try{pending=JSON.parse(localStorage.getItem(pendingKey)||'null');}catch(e){}if(!pending)return;if(pending.visitor!==config.visitorScope||Date.now()-pending.started>Number(config.pendingLifetime||5400)*1000){clearPending();return;}if(pending.id===lastCompletedId){clearPending();return;}if(!/^[a-f0-9]{32}$/.test(pending.id)||!/^[a-f0-9]{32}$/.test(pending.conversation)||!pending.message||typeof pending.message.content!=='string'){clearPending();return;}conversationId=pending.conversation;var user=row('user',pending.message.content),reply=row('assistant','正在恢复已有回复…',true);var turn={id:pending.id,message:pending.message,text:pending.text,files:pending.files||[],user:user,reply:reply,errors:0,previousVariants:pending.previousVariants};usedFiles=usedFiles.concat(turn.files);turn.files.forEach(function(f){user.body.appendChild(fileChip(f,false));});active=turn;busy=true;controls();beginStream(turn);}
  function closeHistory(){historyPanel.hidden=true;historyButton.setAttribute('aria-expanded','false');}
  function restoreConversation(record){if(busy||uploadCount||!record||!Array.isArray(record.messages))return;if(window.EchoesActions)window.EchoesActions.stop();releaseFiles();history=JSON.parse(JSON.stringify(record.messages)).filter(function(m){return m&&['user','assistant'].includes(m.role)&&typeof m.content==='string';});conversationId=record.id;var foreign=record.visitorScope!==config.visitorScope,expired=foreign||Date.now()-record.updated>6*3600000;if(expired)history.forEach(function(m){if(m.files&&m.files.length)m.attachments_expired=true;delete m.files;});if(foreign)history.forEach(function(m){[m].concat(m.variants||[]).forEach(function(v){v.artifacts=outputFiles(v.artifacts).map(function(file){file.unavailable=true;return file;});});});usedFiles=expired?[]:(record.attachments||[]);lastCompletedId=record.lastRequest||'';renderHistory(true);restoredChoice={model:record.model,effort:record.effort};applyModelChoice(restoredChoice);preferences.last=record.id;storePreferences();closeHistory();textarea.value='';resizeInput();controls();say(expired?'旧上传附件需重新上传；有效的回复文件仍可下载。':'',true);}
  function loadHistory(){historyList.textContent='正在读取…';historyRequest('readonly',function(store){return store.getAll();}).then(function(records){historyList.replaceChildren();records.sort(function(a,b){return b.updated-a.updated;});if(!records.length){historyList.textContent='还没有已保存的对话。';return;}records.forEach(function(record){var item=document.createElement('div'),open=document.createElement('button'),title=document.createElement('strong'),time=document.createElement('small'),remove=document.createElement('button');item.className='fnchat-history-item';open.type='button';title.textContent=record.title||'未命名对话';time.textContent=new Date(record.updated).toLocaleString();open.append(title,time);open.addEventListener('click',function(){saveConversation();restoreConversation(record);});remove.type='button';remove.textContent='×';remove.setAttribute('aria-label','删除 '+record.title);remove.addEventListener('click',function(){historyRequest('readwrite',function(store){return store.delete(record.id);}).then(loadHistory);});item.append(open,remove);historyList.appendChild(item);});}).catch(function(){historyList.textContent='本浏览器未能保存历史，请使用导出。';});}
  historyButton.addEventListener('click',function(){if(busy||uploadCount)return;historyPanel.hidden=!historyPanel.hidden;historyButton.setAttribute('aria-expanded',String(!historyPanel.hidden));if(!historyPanel.hidden){closeTools();loadHistory();}});root.querySelector('.fnchat-history-close').addEventListener('click',function(){closeHistory();historyButton.focus();});root.addEventListener('keydown',function(event){if(event.key==='Escape'&&!historyPanel.hidden){closeHistory();historyButton.focus();}});
  var historyReady=preferences.last&&preferences.last!=='new'?historyRequest('readonly',function(store){return store.get(preferences.last);}).then(function(record){if(record&&!busy&&!history.length&&!textarea.value&&!draftFiles.length)restoreConversation(record);}).catch(function(){}):Promise.resolve();
  Promise.all([modelsReady,historyReady]).then(function(){restorePending();try{var draft=localStorage.getItem(draftKey);if(draft)textarea.value=draft;}catch(e){}resizeInput();controls();});
  window.addEventListener('pagehide',function(){leaving=true;clearTimeout(pollTimer);if(active&&active.controller)active.controller.abort();});
  viewport();controls();
}());
