/* Visitor-selected MCP scope. Large lists render only when opened, in bounded pages. */
(function(){
  'use strict';
  function mount(panel,options){
    var list=panel.querySelector('.fnchat-connect-list'),search=panel.querySelector('.fnchat-tool-search'),count=panel.querySelector('.fnchat-selection-count');
    var catalog=[],selected=new Map(),groups=[],busy=false;
    function snapshot(){return Array.from(selected,function(pair){return{server:pair[0],tools:Array.from(pair[1])};}).filter(function(entry){return entry.tools.length;});}
    function changed(){options.change(snapshot());update();}
    function checked(server,name){return !!selected.get(server)&&selected.get(server).has(name);}
    function update(){
      var total=0,chosen=0;catalog.forEach(function(s){total+=s.tools.length;chosen+=(selected.get(s.id)||new Set()).size;});
      count.textContent='已选 '+chosen+' / '+total;count.setAttribute('aria-live','polite');
      panel.querySelectorAll('[data-tools-bulk]').forEach(function(button){button.disabled=busy||!total||(button.dataset.toolsBulk==='all'?chosen===total:!chosen);});
      groups.forEach(function(group){var size=(selected.get(group.server.id)||new Set()).size;group.number.textContent=size+' / '+group.server.tools.length;group.all.disabled=busy||size===group.server.tools.length||!group.server.tools.length;group.none.disabled=busy||!size;group.body.querySelectorAll('input').forEach(function(input){input.checked=checked(group.server.id,input.value);input.disabled=busy;});});
    }
    function select(server,all){if(busy)return;var servers=server?[server]:catalog;servers.forEach(function(s){if(all)selected.set(s.id,new Set(s.tools.map(function(t){return t.name;})));else selected.delete(s.id);});changed();}
    function render(group){
      var query=search.value.trim().toLocaleLowerCase(),items=group.server.tools.filter(function(t){return !query||(t.name+' '+(t.description||'')+' '+group.server.name).toLocaleLowerCase().includes(query);});
      group.details.hidden=!!query&&!items.length&&!group.server.error;if(group.details.hidden)return;
      if(query)group.details.open=true;if(!group.details.open)return;
      group.body.replaceChildren();var fragment=document.createDocumentFragment();
      items.slice(0,group.limit).forEach(function(tool){var label=document.createElement('label'),input=document.createElement('input'),words=document.createElement('span'),name=document.createElement('strong');input.type='checkbox';input.value=tool.name;input.checked=checked(group.server.id,tool.name);input.disabled=busy;name.textContent=tool.name;words.appendChild(name);label.title=tool.description||tool.name;if(tool.description){var detail=document.createElement('small');detail.textContent=tool.description;words.appendChild(detail);}label.append(input,words);input.addEventListener('change',function(){var names=selected.get(group.server.id)||new Set();if(input.checked)names.add(tool.name);else names.delete(tool.name);selected.set(group.server.id,names);changed();});fragment.appendChild(label);});
      if(items.length>group.limit){var more=document.createElement('button');more.type='button';more.className='fnchat-tools-more';more.textContent='显示更多 · 还有 '+(items.length-group.limit)+' 项';more.addEventListener('click',function(){group.limit+=80;render(group);});fragment.appendChild(more);}
      group.body.appendChild(fragment);update();
    }
    function setSelection(value){
      selected.clear();(Array.isArray(value)?value:[]).slice(0,32).forEach(function(entry){var id=typeof entry==='string'?entry:entry.server,server=catalog.find(function(s){return s.id===id;});if(!server)return;var names=typeof entry==='string'?server.tools.map(function(t){return t.name;}):entry.tools;if(!Array.isArray(names))return;selected.set(id,new Set(names.filter(function(name){return server.tools.some(function(t){return t.name===name;});})));});update();
    }
    function setCatalog(servers,value){
      catalog=(Array.isArray(servers)?servers:[]).slice(0,32).filter(function(s){return s&&typeof s.id==='string';}).map(function(s){return{id:s.id,name:s.name||s.id,error:s.error||'',tools:(Array.isArray(s.tools)?s.tools:[]).slice(0,512).filter(function(t){return t&&typeof t.name==='string';})};});
      groups=[];list.replaceChildren();setSelection(value);
      if(!catalog.length){list.textContent='还没有连接工具。请站主在 Echoes 设置中添加 MCP 服务。';changed();return;}
      catalog.forEach(function(server){var details=document.createElement('details'),summary=document.createElement('summary'),name=document.createElement('span'),number=document.createElement('small'),actions=document.createElement('div'),all=document.createElement('button'),none=document.createElement('button'),body=document.createElement('div');details.className='fnchat-tool-group';name.textContent=server.name;summary.append(name,number);details.appendChild(summary);actions.className='fnchat-tool-group-actions';all.type=none.type='button';all.textContent='全选此服务';none.textContent='取消选择';actions.append(all,none);details.appendChild(actions);body.className='fnchat-tool-group-body';details.appendChild(body);var group={server:server,details:details,number:number,all:all,none:none,body:body,limit:80};groups.push(group);if(server.error){var note=document.createElement('p');note.className='fnchat-tool-error';note.textContent=server.error;details.appendChild(note);details.open=true;}all.addEventListener('click',function(){select(server,true);});none.addEventListener('click',function(){select(server,false);});details.addEventListener('toggle',function(){if(details.open)render(group);});list.appendChild(details);});
      if(groups.length===1)groups[0].details.open=true;changed();
    }
    panel.querySelectorAll('[data-tools-bulk]').forEach(function(button){button.addEventListener('click',function(){select(null,button.dataset.toolsBulk==='all');});});
    var frame=0;search.addEventListener('input',function(){if(frame)cancelAnimationFrame(frame);frame=requestAnimationFrame(function(){frame=0;groups.forEach(function(group){group.limit=80;render(group);});});});
    return{setCatalog:setCatalog,setSelection:setSelection,setBusy:function(value){busy=!!value;search.disabled=busy;update();},snapshot:snapshot};
  }
  window.EchoesToolPicker={mount:mount};
}());
