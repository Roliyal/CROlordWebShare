(function(){
  'use strict';
  if(!window.markdownit)return;
  function safeUrl(value){try{var u=new URL(value);return ['https:','http:'].includes(u.protocol)&&!u.username&&!u.password?u.href:null;}catch(e){return null;}}
  var md=window.markdownit({html:false,linkify:true,breaks:false,typographer:false});
  md.validateLink=function(value){return !!safeUrl(value)||/^mailto:[^\s<>]+$/i.test(value);};
  var linkOpen=md.renderer.rules.link_open||function(tokens,index,options,env,self){return self.renderToken(tokens,index,options);};
  md.renderer.rules.link_open=function(tokens,index,options,env,self){tokens[index].attrSet('target','_blank');tokens[index].attrSet('rel','noopener noreferrer');return linkOpen(tokens,index,options,env,self);};
  md.renderer.rules.heading_open=function(tokens,index){var level=Math.min(5,Number(tokens[index].tag.slice(1))+1);return '<h'+level+'>';};
  md.renderer.rules.heading_close=function(tokens,index){var level=Math.min(5,Number(tokens[index].tag.slice(1))+1);return '</h'+level+'>\n';};
  md.renderer.rules.table_open=function(){return '<div class="fnchat-table-wrap"><table>';};
  md.renderer.rules.table_close=function(){return '</table></div>\n';};
  md.renderer.rules.image=function(tokens,index){var token=tokens[index],url=safeUrl(token.attrGet('src')),label=md.utils.escapeHtml(token.content||'图片');return url?'<a class="fnchat-image-link" href="'+md.utils.escapeHtml(url)+'" target="_blank" rel="noopener noreferrer">'+label+'</a>':label;};
  md.renderer.rules.fence=function(tokens,index){var token=tokens[index],language=(token.info||'').trim().split(/\s+/)[0];if(!/^[\w.+#-]{1,24}$/.test(language))language='代码';return '<div class="fnchat-code"><div class="fnchat-code-heading"><span>'+md.utils.escapeHtml(language)+'</span><button type="button" class="fnchat-code-copy" aria-label="复制代码">复制</button></div><pre><code>'+md.utils.escapeHtml(token.content)+'</code></pre></div>\n';};
  function citations(text,references){var clean=text.replace(/\uE200cite\uE202([^\uE201]*)(?:\uE201|$)/g,function(all,ids){return ids.split(/\uE202|[,\s]+/).map(function(id){var ref=references&&references[id],url=ref&&safeUrl(ref.url);return url?' ['+(ref.number||'来源')+'](<'+url+'>)':'';}).join('');});var open=clean.lastIndexOf('\uE200');if(open>=0&&'\uE200cite\uE202'.startsWith(clean.slice(open)))clean=clean.slice(0,open);return clean;}
  function prepare(text,streaming,references){
    var lines=String(text).replace(/\r\n?/g,'\n').split('\n'),fence=null,lastStart=0;
    for(var i=0;i<lines.length;i++){
      var mark=/^\s{0,3}(`{3,}|~{3,})/.exec(lines[i]);
      if(mark){if(!fence)fence={char:mark[1][0],size:mark[1].length};else if(mark[1][0]===fence.char&&mark[1].length>=fence.size)fence=null;continue;}
      if(!fence){lines[i]=citations(lines[i],references);if(!lines[i].trim())lastStart=i+1;}
    }
    if(!streaming||fence)return lines.join('\n');
    if(/^\s{0,3}#{1,6}\s*$/.test(lines[lines.length-1]))lines.pop();
    var tail=lines.slice(lastStart).join('\n');
    tail=tail.replace(/!?\[([^\]\n]*)\]\([^\n)]*$/g,'$1').replace(/!?\[([^\]\n]*)$/g,'$1');
    // Close only the unfinished inline tail in the preview; stored text is untouched.
    var stack=[],code=null;
    for(var p=0;p<tail.length;){
      if(tail[p]==='\\'){p+=2;continue;}
      if(tail[p]==='`'){var run=/^`+/.exec(tail.slice(p))[0];if(code===run){code=null;stack.pop();}else if(!code){code=run;stack.push(run);}p+=run.length;continue;}
      if(code){p++;continue;}
      var found=/^(\*\*|__|~~|\*|_)/.exec(tail.slice(p));
      if(!found){p++;continue;}var token=found[0],before=tail[p-1]||'',after=tail[p+token.length]||'';
      if(stack[stack.length-1]===token&&before&&!/\s/.test(before))stack.pop();
      else if(after&&!/\s/.test(after)&&!(token==='_'&&/\w/.test(before)&&/\w/.test(after)))stack.push(token);
      else if(!after){tail=tail.slice(0,p);break;}
      p+=token.length;
    }
    tail+=stack.reverse().join('');
    var rows=tail.split('\n'),header=rows.findIndex(function(line){return /^\s*\|/.test(line)&&line.split('|').length>2;});
    if(header>=0&&(header===rows.length-1||header===rows.length-2&&/^[\s|:-]*$/.test(rows[header+1]))){var cells=rows[header].trim().replace(/^\||\|$/g,'').split('|').length;rows.splice(header+1,rows.length-header-1,'|'+Array(cells).fill('---').join('|')+'|');tail=rows.join('\n');}
    if(header<0&&rows.length===1&&/^\s*\|/.test(tail))tail='';
    return lines.slice(0,lastStart).join('\n')+(lastStart?'\n':'')+tail;
  }
  function patchNode(target,source){
    if(target.isEqualNode(source))return target;
    if(target.nodeType!==source.nodeType||target.nodeName!==source.nodeName){var replacement=source.cloneNode(true);target.replaceWith(replacement);return replacement;}
    if(target.nodeType===3){if(source.data.startsWith(target.data))target.appendData(source.data.slice(target.data.length));else target.data=source.data;return target;}
    if(target.nodeType!==1)return target;
    Array.from(target.attributes).forEach(function(attribute){if(!source.hasAttribute(attribute.name))target.removeAttribute(attribute.name);});
    Array.from(source.attributes).forEach(function(attribute){if(target.getAttribute(attribute.name)!==attribute.value)target.setAttribute(attribute.name,attribute.value);});
    patchChildren(target,source);return target;
  }
  function patchChildren(target,source,offset){offset=offset||0;var desired=Array.from(source.childNodes);for(var i=0;i<desired.length;i++){if(target.childNodes[offset+i])patchNode(target.childNodes[offset+i],desired[i]);else target.appendChild(desired[i].cloneNode(true));}while(target.childNodes.length>offset+desired.length)target.lastChild.remove();}
  function render(parent,text,streaming,references){
    var prepared=prepare(text,streaming,references),env={},tokens=md.parse(prepared,env),lines=prepared.split('\n'),groups=[],group=[],depth=0,referenceKey=JSON.stringify(env.references||{});
    tokens.forEach(function(token){group.push(token);depth+=token.nesting;if(depth===0){var map=group[0].map,key=map?lines.slice(map[0],map[1]).join('\n'):JSON.stringify(group);groups.push({tokens:group,key:key+referenceKey});group=[];}});
    var old=parent._echoesBlocks||[],prefix=0,offset=0;while(prefix<groups.length&&prefix<old.length&&groups[prefix].key===old[prefix].key){offset+=old[prefix].nodes;prefix++;}
    if(prefix===groups.length&&prefix===old.length)return;
    var cache=old.slice(0,prefix),fragment=document.createDocumentFragment();
    groups.slice(prefix).forEach(function(block){var template=document.createElement('template');template.innerHTML=md.renderer.render(block.tokens,md.options,env);cache.push({key:block.key,nodes:template.content.childNodes.length});fragment.appendChild(template.content);});
    patchChildren(parent,fragment,offset);parent._echoesBlocks=cache;
  }
  window.EchoesText={render:render,safeUrl:safeUrl};
}());
