(function () {
  'use strict';
  var root = document.querySelector('.fn-market'), config = window.fieldnotesMarket;
  if (!root || !config || root.dataset.initialized) return;
  root.dataset.initialized = '1';
  var form = root.querySelector('.fn-market-search'), input = root.querySelector('#fn-market-code');
  var chart = root.querySelector('.fn-market-chart'), svg = root.querySelector('.fn-market-svg'), empty = root.querySelector('.fn-market-chart-empty');
  var status = root.querySelector('.fn-market-status'), retry = root.querySelector('.fn-market-retry'), tooltip = root.querySelector('.fn-market-tooltip');
  var title = root.querySelector('.fn-market-stock-title'), subtitle = root.querySelector('.fn-market-stock-subtitle'), source = root.querySelector('.fn-market-source');
  var adjustSelect = root.querySelector('#fn-market-adjust'), table = root.querySelector('.fn-market-data-table');
  var symbol = root.dataset.symbol || '', period = 'minute', adjust = 'qfq', data = null, count = 80, offset = 0, cursor = -1;
  var quoteSequence = 0, quoteController = null, noteController = null, plot = null;
  var live = root.querySelector('.fn-market-live'), intervalSelect = root.querySelector('#fn-market-interval'), autoState = root.querySelector('.fn-market-auto-state');
  var liveController = null, liveSequence = 0, liveBusy = false, liveFailures = 0, lastRefresh = 0, refreshSeconds = 5;
  var storageKey = 'fieldnotes:markets:v1:' + config.ajaxUrl, savedPreferences = {}, rememberedSymbol = '';
  var technical=window.FieldnotesIndicators,computed=null,indicatorData=null,prefs={overlay:['ma'],panel:['macd']};
  var indicatorArea=root.querySelector('.fn-market-indicators'),indicatorOptions=root.querySelector('.fn-market-indicator-options'),indicatorValues=root.querySelector('.fn-market-indicator-values');
  var NS = 'http://www.w3.org/2000/svg', colors = {up:'#b66a62',down:'#7f929a',ma5:'#9b8874',ma10:'#85879b',ma20:'#789494'};
  function node(tag, attrs, text) { var el = document.createElementNS(NS,tag); Object.keys(attrs || {}).forEach(function (key) { el.setAttribute(key,attrs[key]); }); if (text != null) el.textContent = text; return el; }
  function html(tag, cls, text) { var el = document.createElement(tag); if (cls) el.className=cls; if (text!=null) el.textContent=text; return el; }
  function normalize(value) {
    if(typeof value!=='string')return '';
    var v = value.trim().toUpperCase(), match, market, code;
    if ((match=/^(SH|SZ|BJ)[:.]?(\d{6})$/.exec(v))) { market=match[1]; code=match[2]; }
    else if ((match=/^(\d{6})\.(SH|SZ|BJ)$/.exec(v))) { market=match[2]; code=match[1]; }
    else if (/^\d{6}$/.test(v)) { code=v; market=/^(4|8|92)/.test(code)?'BJ':/^[69]/.test(code)?'SH':'SZ'; }
    else return '';
    if (market==='SH'&&code!=='000001'&&!/^[69]/.test(code)||market==='SZ'&&!/^[023]/.test(code)||market==='BJ'&&!/^(4|8|92)/.test(code)) return '';
    return market+code;
  }
  function paramsFromURL() {
    var url = new URL(window.location.href);
    return {symbol:normalize(url.searchParams.get('stock')||''),period:['minute','day','week','month'].includes(url.searchParams.get('period'))?url.searchParams.get('period'):'minute',adjust:url.searchParams.get('adjust')==='none'?'none':'qfq',page:1};
  }
  function updateURL(page, replace) {
    var url=new URL(window.location.href); if(symbol)url.searchParams.set('stock',symbol);else url.searchParams.delete('stock');
    url.searchParams.set('period',period);url.searchParams.set('adjust',adjust);
    url.searchParams.delete('notes_page');
    window.history[replace?'replaceState':'pushState']({},'',url.pathname+url.search+url.hash);
  }
  function request(action, fields, controller) {
    var url=new URL(config.ajaxUrl,window.location.href);url.searchParams.set('action',action);
    Object.keys(fields).forEach(function(key){url.searchParams.set(key,fields[key]);});
    return fetch(url.href,{credentials:'same-origin',cache:'no-store',signal:controller?controller.signal:undefined}).then(function(response){
      return response.json().catch(function(){throw new Error('站点暂时没有返回有效数据，请稍后重试。');});
    }).then(function(result){if(!result||!result.success)throw new Error(result&&result.data&&result.data.message||'读取未完成，请重试。');return result.data;});
  }
  function message(text,error) { status.textContent=text||'';status.classList.toggle('is-error',!!error); }
  function updateControls() {
    root.classList.toggle('has-stock',!!symbol);
    root.querySelectorAll('[data-period]').forEach(function(button){button.setAttribute('aria-pressed',String(button.dataset.period===period));button.disabled=!symbol;});
    adjustSelect.value=adjust;adjustSelect.disabled=!symbol;adjustSelect.hidden=period==='minute';
    root.querySelector('.fn-market-ma-legend').hidden=period==='minute';
    root.querySelectorAll('.fn-market-zoom-out,.fn-market-zoom-in,.fn-market-zoom-reset').forEach(function(button){button.disabled=!data;button.hidden=period==='minute';});
    root.querySelector('.fn-market-refresh').disabled=!symbol;
    root.querySelectorAll('[data-indicator]').forEach(function(box){box.disabled=false;});
    root.querySelector('.fn-market-indicator-hint').textContent=period==='minute'?'选择指标会自动切换到日 K；主图与副图使用同一段日期。':'指标按当前周期和复权数据计算；完整周期不足时，数值暂留空。';
    root.querySelectorAll('.fn-market-all-indicators,.fn-market-reset-indicators').forEach(function(button){button.disabled=false;});
  }
  function resetChart(text) {
    data=null;plot=null;computed=null;indicatorData=null;indicatorArea.replaceChildren();indicatorValues.hidden=true;svg.replaceChildren();tooltip.hidden=true;empty.hidden=false;empty.querySelector('p').textContent=text||'输入名称或代码，展开一段价格记录。';table.hidden=true;
    svg.setAttribute('aria-label','等待查询股票历史行情');updateControls();
  }
  function validData(result, expected) {
    if(period==='minute') return result&&result.symbol===expected&&result.period==='minute'&&/^\d{4}-\d{2}-\d{2}$/.test(result.date)&&Array.isArray(result.points)&&result.points.length>0&&result.points.length<=300&&(result.previous_close===null||typeof result.previous_close==='number'&&Number.isFinite(result.previous_close)&&result.previous_close>0)&&result.points.every(function(point,index,points){
      return /^\d{2}:\d{2}$/.test(point.time)&&typeof point.price==='number'&&Number.isFinite(point.price)&&point.price>0&&typeof point.volume==='number'&&Number.isFinite(point.volume)&&point.volume>=0&&(index===0||point.time>points[index-1].time);
    });
    return result&&result.symbol===expected&&Array.isArray(result.bars)&&result.bars.length>0&&result.bars.every(function(bar){
      return /^\d{4}-\d{2}-\d{2}$/.test(bar.date)&&['open','close','high','low','volume'].every(function(key){return typeof bar[key]==='number'&&Number.isFinite(bar[key]);})&&bar.low>0&&bar.volume>=0&&bar.high>=Math.max(bar.open,bar.close)&&bar.low<=Math.min(bar.open,bar.close);
    });
  }
  function format(value) { return Number(value).toLocaleString('zh-CN',{minimumFractionDigits:2,maximumFractionDigits:2}); }
  function average(index,length) { if(index<length-1)return null;var sum=0;for(var i=index-length+1;i<=index;i++)sum+=data.bars[i].close;return sum/length; }
  function draw() {
    if(!data)return;
    if(data.period==='minute'){drawMinute();return;}
    var width=Math.max(280,chart.clientWidth||1000),height=chart.clientHeight||480;
    if(technical&&indicatorData!==data){computed=technical.calculate(data.bars);indicatorData=data;}
    var overlays=activeOverlays();
    var bars=data.bars,end=Math.max(1,bars.length-offset),start=Math.max(0,end-count),visible=bars.slice(start,end);
    var left=12,right=62,top=30,bottom=height-120,volumeTop=height-93,volumeBottom=height-28;
    var plotWidth=width-left-right,step=plotWidth/visible.length;
    var max=Math.max.apply(null,visible.map(function(bar){return bar.high;})),min=Math.min.apply(null,visible.map(function(bar){return bar.low;}));
    overlays.forEach(function(line){line.values.slice(start,end).forEach(function(v){if(v!=null&&Number.isFinite(v)){max=Math.max(max,v);min=Math.min(min,v);}});});
    var pad=(max-min)*.12||max*.04;max+=pad;min=min<0?min-pad:Math.max(0,min-pad);
    var y=function(value){return bottom-(value-min)/(max-min)*(bottom-top);};
    var x=function(index){return left+step*(index+.5);};
    var volumeMax=Math.max.apply(null,visible.map(function(bar){return bar.volume;}))||1;
    svg.replaceChildren();svg.setAttribute('viewBox','0 0 '+width+' '+height);
    svg.setAttribute('aria-label',data.name+' '+symbol+' 历史'+({day:'日',week:'周',month:'月'}[period])+' K 线，'+visible[0].date+' 至 '+visible[visible.length-1].date);
    var defs=node('defs'),clip=node('clipPath',{id:'fn-market-clip'});clip.appendChild(node('rect',{x:left,y:top,width:plotWidth,height:bottom-top}));defs.appendChild(clip);svg.appendChild(defs);
    for(var grid=0;grid<5;grid++){
      var gy=top+(bottom-top)*grid/4,value=max-(max-min)*grid/4;
      svg.appendChild(node('line',{x1:left,y1:gy,x2:width-right,y2:gy,stroke:'#e8e8ed','stroke-width':.7}));
      svg.appendChild(node('text',{x:width-right+9,y:gy+4,fill:'#929298','font-size':10,'font-family':'-apple-system, sans-serif'},format(value)));
    }
    visible.forEach(function(bar,index){
      var color=bar.close>=bar.open?colors.up:colors.down,bw=Math.max(1,Math.min(10,step*.65));
      svg.appendChild(node('line',{x1:x(index),x2:x(index),y1:y(bar.high),y2:y(bar.low),stroke:color,'stroke-width':1}));
      svg.appendChild(node('rect',{x:x(index)-bw/2,y:Math.min(y(bar.open),y(bar.close)),width:bw,height:Math.max(1,Math.abs(y(bar.open)-y(bar.close))),fill:bar.close>=bar.open?'#fafafb':color,stroke:color,'stroke-width':1}));
      var vh=bar.volume/volumeMax*(volumeBottom-volumeTop);
      svg.appendChild(node('rect',{x:x(index)-bw/2,y:volumeBottom-vh,width:bw,height:Math.max(.5,vh),fill:color,opacity:.6}));
    });
    overlays.forEach(function(line){var segments=[],segment=[];visible.forEach(function(bar,index){var value=line.values[start+index];if(value!=null&&Number.isFinite(value))segment.push(x(index)+','+y(value));else if(segment.length){segments.push(segment);segment=[];}});if(segment.length)segments.push(segment);segments.forEach(function(points){if(points.length>1)svg.appendChild(node('polyline',{points:points.join(' '),fill:'none',stroke:line.color,'stroke-width':1.1,'clip-path':'url(#fn-market-clip)'}));});});
    svg.appendChild(node('text',{x:left,y:volumeTop-8,fill:'#a1a1a6','font-size':9,'font-family':'-apple-system, sans-serif'},'成交量（手）'));
    [0,Math.floor(visible.length/2),visible.length-1].forEach(function(index,order){
      svg.appendChild(node('text',{x:order===0?left:order===2?width-right:x(index),y:height-6,fill:'#a1a1a6','font-size':9,'text-anchor':order===0?'start':order===2?'end':'middle','font-family':'-apple-system, sans-serif'},visible[index].date));
    });
    var line=node('line',{x1:0,x2:0,y1:top,y2:volumeBottom,stroke:'#a1a1a6','stroke-width':.8,'stroke-dasharray':'3 3',visibility:'hidden'});svg.appendChild(line);
    plot={bars:visible,start:start,left:left,right:width-right,width:width,step:step,line:line,x:x};
    empty.hidden=true;drawIndicatorPanels(start,end);cursor=Math.min(cursor,visible.length-1);if(cursor>=0)showBar(cursor);
  }

  function minutePosition(time) {
    var parts=time.split(':'),clock=Number(parts[0])*60+Number(parts[1]);
    return Math.max(0,Math.min(240,clock<=690?clock-570:120+clock-780));
  }
  function drawMinute() {
    indicatorArea.replaceChildren();indicatorValues.hidden=true;
    var points=data.points,width=Math.max(280,chart.clientWidth||1000),height=chart.clientHeight||480;
    var left=12,right=65,top=28,bottom=height-120,volumeTop=height-90,volumeBottom=height-29,pw=width-left-right;
    var prices=points.map(function(p){return p.price;}),min=Math.min.apply(null,prices),max=Math.max.apply(null,prices),previous=data.previous_close;
    if(previous){var delta=Math.max(Math.abs(max-previous),Math.abs(min-previous),previous*.003)*1.15;min=Math.max(0,previous-delta);max=previous+delta;}
    else {var pad=(max-min)*.12||max*.005;max+=pad;min-=pad;}
    var y=function(v){return bottom-(v-min)/(max-min)*(bottom-top);},x=function(i){return left+minutePosition(points[i].time)/240*pw;};
    var volumeMax=Math.max.apply(null,points.map(function(p){return p.volume;}))||1;
    svg.replaceChildren();svg.setAttribute('viewBox','0 0 '+width+' '+height);svg.setAttribute('aria-label',data.name+' '+symbol+' '+data.date+' 分时图，已取得 '+points[0].time+' 至 '+points[points.length-1].time+' 的数据');
    for(var grid=0;grid<5;grid++){
      var price=max-(max-min)*grid/4,gy=y(price);svg.appendChild(node('line',{x1:left,x2:width-right,y1:gy,y2:gy,stroke:'#e8e8ed','stroke-width':.7}));
      svg.appendChild(node('text',{x:width-right+8,y:gy+4,fill:'#929298','font-size':10},format(price)));
    }
    if(previous){svg.appendChild(node('line',{x1:left,x2:width-right,y1:y(previous),y2:y(previous),stroke:'#b5b5bc','stroke-width':1,'stroke-dasharray':'4 4'}));svg.appendChild(node('text',{x:left+5,y:y(previous)-6,fill:'#a1a1a6','font-size':9},'昨收'));}
    var coordinates=points.map(function(p,i){return x(i)+','+y(p.price);}).join(' ');
    if(points.length>1){svg.appendChild(node('polygon',{points:x(0)+','+bottom+' '+coordinates+' '+x(points.length-1)+','+bottom,fill:'#8e9ba50d'}));svg.appendChild(node('polyline',{points:coordinates,fill:'none',stroke:'#778b99','stroke-width':1.6,'stroke-linejoin':'round'}));}
    svg.appendChild(node('circle',{cx:x(points.length-1),cy:y(points[points.length-1].price),r:2.8,fill:'#778b99'}));
    points.forEach(function(p,i){var vh=p.volume/volumeMax*(volumeBottom-volumeTop),bw=Math.max(.8,Math.min(5,pw/241*.7));svg.appendChild(node('rect',{x:x(i)-bw/2,y:volumeBottom-vh,width:bw,height:Math.max(.5,vh),fill:'#9aa8b1',opacity:.7}));});
    svg.appendChild(node('text',{x:left,y:volumeTop-8,fill:'#a1a1a6','font-size':9},'成交量（手）'));
    [[0,'09:30'],[120,'11:30 / 13:00'],[240,'15:00']].forEach(function(mark,i){svg.appendChild(node('text',{x:left+mark[0]/240*pw,y:height-6,fill:'#a1a1a6','font-size':9,'text-anchor':i===0?'start':i===2?'end':'middle'},mark[1]));});
    var line=node('line',{x1:0,x2:0,y1:top,y2:volumeBottom,stroke:'#a1a1a6','stroke-width':.8,'stroke-dasharray':'3 3',visibility:'hidden'});svg.appendChild(line);
    plot={minute:true,bars:points,start:0,left:left,right:width-right,width:width,step:pw/241,line:line,x:x};empty.hidden=true;cursor=Math.min(cursor,points.length-1);if(cursor>=0)showBar(cursor);
  }

  function showBar(index) {
    if(!plot)return;cursor=Math.max(0,Math.min(plot.bars.length-1,index));var bar=plot.bars[cursor];
    plot.line.setAttribute('x1',plot.x(cursor));plot.line.setAttribute('x2',plot.x(cursor));plot.line.setAttribute('visibility','visible');
    tooltip.textContent=period==='minute'?data.date+' '+bar.time+'\n价格 '+format(bar.price)+(data.previous_close?'   较昨收 '+((bar.price/data.previous_close-1)*100).toFixed(2)+'%':'')+'\n本分钟成交量 '+Number(bar.volume).toLocaleString('zh-CN')+' 手':bar.date+'  '+({day:'日',week:'周',month:'月'}[period])+' K\n开 '+format(bar.open)+'   收 '+format(bar.close)+'   高 '+format(bar.high)+'   低 '+format(bar.low)+'\n成交量 '+Number(bar.volume).toLocaleString('zh-CN')+' 手';tooltip.hidden=false;updateIndicatorCursor(plot.start+cursor,plot.x(cursor));
  }
  function hideBar(){tooltip.hidden=true;cursor=-1;if(plot){plot.line.setAttribute('visibility','hidden');updateIndicatorCursor(plot.start+plot.bars.length-1,null);}}
  function tableData() {
    var body=table.querySelector('tbody'),head=table.querySelector('thead tr'),minute=period==='minute';body.replaceChildren();head.replaceChildren();
    (minute?['时间','价格','较昨收','成交量（手）']:['日期','开盘','收盘','最高','最低','成交量（手）']).forEach(function(label){head.appendChild(html('th','',label));});
    (minute?data.points:data.bars).slice(-20).reverse().forEach(function(bar){
      var row=html('tr');(minute?['time','price','change','volume']:['date','open','close','high','low','volume']).forEach(function(key){var value=key==='date'||key==='time'?bar[key]:key==='change'?(data.previous_close?((bar.price/data.previous_close-1)*100).toFixed(2)+'%':'—'):key==='volume'?Number(bar[key]).toLocaleString('zh-CN'):format(bar[key]);row.appendChild(html('td','',value));});body.appendChild(row);
    });table.hidden=false;
  }
  function loadQuotes(quiet) {
    if(!symbol)return;
    quoteSequence++;var sequence=quoteSequence,target=symbol;
    if(quoteController)quoteController.abort();quoteController=typeof AbortController!=='undefined'?new AbortController():null;
    var keep=quiet===true&&data&&data.period===period;
    if(!keep){resetChart('正在读取行情…');message('正在读取 '+target+' 的行情。');title.textContent=target;subtitle.textContent=period==='minute'?'当日分时':'历史 K 线';source.textContent='';}retry.hidden=true;chart.setAttribute('aria-busy','true');
    request('fieldnotes_market',{symbol:target,period:period,adjust:adjust},quoteController).then(function(result){
      if(sequence!==quoteSequence)return;if(!validData(result,target))throw new Error('行情代码或价格字段不匹配，未显示本次数据。');
      data=result;if(!keep){offset=0;cursor=-1;}title.textContent=result.name+' · '+target;
      var bars=period==='minute'?result.points:result.bars,last=bars[bars.length-1],prior=bars[bars.length-2],change=period==='minute'?(result.previous_close?(last.price/result.previous_close-1)*100:null):(prior?(last.close/prior.close-1)*100:null);
      subtitle.textContent=(period==='minute'?'分时末价 '+format(last.price):'K 线末价 '+format(last.close))+(change==null?'':(period==='minute'?'  较昨收 ':'  较前根 ')+(change>=0?'+':'')+change.toFixed(2)+'%')+' · '+(period==='minute'?result.date+' '+last.time:last.date);
      var fetched=new Date(result.fetched_at*1000).toLocaleString('zh-CN',{timeZone:'Asia/Shanghai',hour12:false});
      source.textContent=result.source+' · 数据截至 '+(period==='minute'?result.date+' '+last.time:last.date)+' · 获取于 '+fetched+' · '+(period==='minute'?'分时数据可能延迟':adjust==='qfq'?'前复权':'不复权')+(result.stale?' · 上次缓存':'');
      message(result.stale?'行情服务暂不可达，当前展示上次缓存，请留意数据时间。':period==='minute'?'分时价格与每分钟成交量 · 轻触或使用方向键查看 · 按所选间隔尝试更新，数据可能延迟':'红涨蓝跌 · 移动指针或轻触查看价格 · Ctrl + 滚轮缩放',!!result.stale);tableData();draw();updateControls();
    }).catch(function(error){
      if(sequence!==quoteSequence||error.name==='AbortError')return;if(keep){message('更新暂未完成，当前保留上次读取的图表，请留意数据时间。',true);}else{resetChart('暂时没有取得这只股票的行情。');message(error.message||'行情读取失败，请重试。',true);source.textContent='本次未显示价格数据。';}retry.hidden=false;
    }).finally(function(){if(sequence===quoteSequence)chart.removeAttribute('aria-busy');});
  }

  function activeOverlays(){
    if(!computed)return[];var lines=[];prefs.overlay.forEach(function(id){if(computed[id])lines=lines.concat(computed[id]);});
    var legend=root.querySelector('.fn-market-ma-legend');legend.replaceChildren();lines.forEach(function(line){var el=html('span','',line.name);el.style.color=line.color;legend.appendChild(el);});return lines;
  }
  function indicatorNumber(value){return value==null||!Number.isFinite(value)?'—':Math.abs(value)>=100000?Number(value).toLocaleString('zh-CN',{maximumFractionDigits:0}):Number(value).toLocaleString('zh-CN',{maximumFractionDigits:3});}
  function drawIndicatorPanels(start,end){
    indicatorArea.replaceChildren();if(!computed||!technical)return;var width=plot.width,left=plot.left,right=plot.right,height=154,top=12,bottom=126;
    prefs.panel.forEach(function(id){
      var definition=technical.catalog.find(function(item){return item.id===id;}),lines=computed[id];if(!definition||!lines)return;
      var section=html('section','fn-market-indicator'),heading=html('header','fn-market-indicator-heading'),label=html('h3','',definition.name),values=html('div','fn-market-indicator-readout'),graphic=node('svg',{viewBox:'0 0 '+width+' '+height,role:'img','aria-label':definition.name+' '+definition.params+' 技术指标'});
      section.dataset.panel=id;heading.append(label,html('span','fn-market-indicator-params',definition.params),values);section.append(heading,graphic);
      var numbers=[];lines.forEach(function(line){line.values.slice(start,end).forEach(function(v){if(v!=null&&Number.isFinite(v))numbers.push(v);});});
      if(!numbers.length){section.appendChild(html('p','fn-market-indicator-empty','这段数据尚不足一个完整计算周期。'));indicatorArea.appendChild(section);return;}
      var lo=Math.min.apply(null,numbers),hi=Math.max.apply(null,numbers);if(lines.some(function(line){return line.kind==='bar';})){lo=Math.min(0,lo);hi=Math.max(0,hi);}if(id==='rsi'||id==='wr'){lo=0;hi=100;}
      var pad=(hi-lo)*.1||Math.max(Math.abs(hi)*.1,1);if(id!=='rsi'&&id!=='wr'){lo-=pad;hi+=pad;}
      var y=function(v){return bottom-(v-lo)/(hi-lo)*(bottom-top);};
      for(var i=0;i<3;i++){var value=hi-(hi-lo)*i/2,gy=y(value);graphic.append(node('line',{x1:left,x2:right,y1:gy,y2:gy,stroke:'#e8e8ed','stroke-width':.7}),node('text',{x:right+9,y:gy+4,fill:'#929298','font-size':10},indicatorNumber(value)));}
      if(lo<0&&hi>0)graphic.appendChild(node('line',{x1:left,x2:right,y1:y(0),y2:y(0),stroke:'#c4c4ca','stroke-dasharray':'3 3','stroke-width':.8}));
      lines.forEach(function(line){
        if(line.kind==='bar'){var bw=Math.max(1,Math.min(8,plot.step*.65));line.values.slice(start,end).forEach(function(v,index){if(v==null||!Number.isFinite(v))return;graphic.appendChild(node('rect',{x:plot.x(index)-bw/2,y:Math.min(y(0),y(v)),width:bw,height:Math.max(.5,Math.abs(y(v)-y(0))),fill:id==='vol'?(data.bars[start+index].close>=data.bars[start+index].open?colors.up:colors.down):v>=0?colors.up:colors.down,opacity:.72}));});}
        else{var segments=[],segment=[];line.values.slice(start,end).forEach(function(v,index){if(v==null||!Number.isFinite(v)){if(segment.length)segments.push(segment);segment=[];}else segment.push(plot.x(index)+','+y(v));});if(segment.length)segments.push(segment);segments.forEach(function(points){if(points.length>1)graphic.appendChild(node('polyline',{points:points.join(' '),fill:'none',stroke:line.color,'stroke-width':1.25}));});}
      });
      [0,plot.bars.length-1].forEach(function(index,i){graphic.appendChild(node('text',{x:i?right:left,y:149,fill:'#a1a1a6','font-size':10,'text-anchor':i?'end':'start'},plot.bars[index].date));});
      graphic.appendChild(node('line',{'data-indicator-cursor':'',x1:0,x2:0,y1:top,y2:bottom,stroke:'#a1a1a6','stroke-width':.8,'stroke-dasharray':'3 3',visibility:'hidden'}));
      graphic.addEventListener('pointermove',function(event){var rect=graphic.getBoundingClientRect(),x=(event.clientX-rect.left)*plot.width/Math.max(1,rect.width);showBar(Math.floor((x-plot.left)/plot.step));});graphic.addEventListener('pointerleave',hideBar);
      indicatorArea.appendChild(section);
    });
    updateIndicatorCursor(end-1,null);renderIndicatorTable(start,end);
  }
  function updateIndicatorCursor(index,x){
    if(!computed||period==='minute')return;
    indicatorArea.querySelectorAll('[data-panel]').forEach(function(section){var readout=section.querySelector('.fn-market-indicator-readout');readout.replaceChildren();(computed[section.dataset.panel]||[]).forEach(function(line){var label=html('span','',line.name+' '+indicatorNumber(line.values[index]));label.style.color=line.color;readout.appendChild(label);});var cursor=section.querySelector('[data-indicator-cursor]');if(cursor){cursor.setAttribute('visibility',x==null?'hidden':'visible');if(x!=null){cursor.setAttribute('x1',x);cursor.setAttribute('x2',x);}}});
  }
  function renderIndicatorTable(start,end){
    var ids=prefs.overlay.concat(prefs.panel),lines=[],formulas=root.querySelector('.fn-market-indicator-formulas'),wrap=root.querySelector('.fn-market-indicator-table');wrap.replaceChildren();formulas.replaceChildren();
    ids.forEach(function(id){lines=lines.concat(computed[id]||[]);var def=technical.catalog.find(function(d){return d.id===id;});if(def){var p=html('p');p.append(html('strong','',def.name+' '+def.params+' · '),html('span','',def.description));formulas.appendChild(p);}});
    indicatorValues.hidden=!lines.length;if(!lines.length)return;
    var table=html('table'),head=html('thead'),row=html('tr'),body=html('tbody');row.appendChild(html('th','','日期'));lines.forEach(function(line){row.appendChild(html('th','',line.name));});head.appendChild(row);
    for(var i=end-1;i>=Math.max(start,end-20);i--){var tr=html('tr');tr.appendChild(html('td','',data.bars[i].date));lines.forEach(function(line){tr.appendChild(html('td','',indicatorNumber(line.values[i])));});body.appendChild(tr);}table.append(head,body);wrap.appendChild(table);
  }
  function initIndicators(){
    if(!technical){root.querySelector('.fn-market-indicator-chooser').hidden=true;return;}
    try{var stored=JSON.parse(sessionStorage.getItem(storageKey+':indicators')||'null');if(stored){['overlay','panel'].forEach(function(group){if(Array.isArray(stored[group]))prefs[group]=technical.catalog.filter(function(def){return def.group===group&&stored[group].includes(def.id);}).map(function(def){return def.id;});});}}catch(ignore){}
    ['overlay','panel'].forEach(function(group){var fieldset=html('fieldset'),legend=html('legend','',group==='overlay'?'主图叠加':'副图指标');fieldset.appendChild(legend);technical.catalog.filter(function(def){return def.group===group;}).forEach(function(def){var label=html('label'),box=html('input'),text=html('span');box.type='checkbox';box.dataset.indicator=def.id;box.dataset.group=group;box.checked=prefs[group].includes(def.id);text.append(html('strong','',def.name),html('small','',def.params));label.append(box,text);fieldset.appendChild(label);});indicatorOptions.appendChild(fieldset);});
    function changed(){try{sessionStorage.setItem(storageKey+':indicators',JSON.stringify(prefs));}catch(ignore){}indicatorOptions.querySelectorAll('[data-indicator]').forEach(function(box){box.checked=prefs[box.dataset.group].includes(box.dataset.indicator);});if(period==='minute'){period='day';updateControls();if(symbol){updateURL(paramsFromURL().page,false);loadQuotes();}}else if(data)draw();}
    indicatorOptions.addEventListener('change',function(event){var box=event.target;if(!box.dataset.indicator)return;var group=box.dataset.group;prefs[group]=prefs[group].filter(function(id){return id!==box.dataset.indicator;});if(box.checked)prefs[group].push(box.dataset.indicator);changed();});
    root.querySelector('.fn-market-all-indicators').addEventListener('click',function(){prefs.panel=technical.catalog.filter(function(d){return d.group==='panel';}).map(function(d){return d.id;});changed();});
    root.querySelector('.fn-market-reset-indicators').addEventListener('click',function(){prefs={overlay:['ma'],panel:['macd']};changed();});
  }
  initIndicators();

  // Latest notes stay in place when the chart changes. Older notes load inside one fold.
  var moreNotes=root.querySelector('.fn-market-more-notes');
  if(moreNotes){
    var olderList=moreNotes.querySelector('.fn-market-older-list'),moreButton=moreNotes.querySelector('.fn-market-notes-more'),notesStatus=moreNotes.querySelector('.fn-market-notes-status');
    var olderPage=2,olderLoaded=false,olderHasMore=true,olderBusy=false,seenNotes=new Set();
    root.querySelectorAll('.fn-market-note-link').forEach(function(link){seenNotes.add(link.href);});
    function appendOlderNotes(posts){
      posts.forEach(function(note){
        if(!note||typeof note.url!=='string')return;
        var url;try{url=new URL(note.url,window.location.href);}catch(ignore){return;}
        if(!/^https?:$/.test(url.protocol)||seenNotes.has(url.href))return;
        seenNotes.add(url.href);
        var article=html('article','fn-market-note'),link=html('a','fn-market-note-link'),time=html('time','',note.date),body=html('div'),arrow=html('span','','↗');
        link.href=url.href;time.dateTime=note.iso_date;arrow.setAttribute('aria-hidden','true');
        if(note.sample)body.append(html('span','fn-sample-label','示例笔记'));
        body.append(html('h3','',note.title),html('p','',note.excerpt));link.append(time,body,arrow);article.appendChild(link);olderList.appendChild(article);
      });
    }
    function loadOlderNotes(){
      if(olderBusy||!olderHasMore)return;
      olderBusy=true;moreButton.disabled=true;notesStatus.hidden=false;notesStatus.textContent='正在读取…';
      noteController=typeof AbortController!=='undefined'?new AbortController():null;
      request('fieldnotes_market_notes',{notes_page:olderPage},noteController).then(function(result){
        if(!Array.isArray(result.posts))throw new Error('记录暂未读取，请重试。');
        appendOlderNotes(result.posts);olderLoaded=true;olderHasMore=Boolean(result.has_more);olderPage++;
        notesStatus.hidden=true;notesStatus.textContent='';moreButton.textContent='继续展开';moreButton.hidden=!olderHasMore;
      }).catch(function(error){
        if(error.name==='AbortError')return;
        notesStatus.textContent=error.message||'记录暂未读取，请重试。';moreButton.hidden=false;moreButton.textContent='重试';
      }).finally(function(){olderBusy=false;moreButton.disabled=false;});
    }
    moreNotes.addEventListener('toggle',function(){if(moreNotes.open&&!olderLoaded)loadOlderNotes();});
    moreButton.addEventListener('click',loadOlderNotes);
  }

  function setStock(value, page, updateHistory) {
    var target=normalize(value);if(!target){input.setAttribute('aria-invalid','true');message('请输入有效的沪深北股票代码，例如 600519。',true);input.focus();return;}
    var changed=symbol!==target;symbol=target;input.value=target;input.removeAttribute('aria-invalid');closeMatches();
    if(changed){clearLive();liveFailures=0;}rememberStock(target);title.textContent=target==='SH000001'?'上证指数 · SH000001':target;lastRefresh=Date.now();
    if(updateHistory)updateURL(page,false);
    var write=root.querySelector('.fn-market-write form');
    if(write&&!write.querySelector('[name=note_title]').value&&!write.querySelector('[name=note_content]').value)write.querySelector('[name=stock]').value=target;
    loadQuotes();loadLive();updateAutoState();
  }

  // Store the last valid input; retain previous preference fields for compatibility.
  function readSession() {
    var raw='';
    try{raw=window.localStorage.getItem(storageKey)||'';}catch(ignore){}
    if(!raw)try{raw=window.sessionStorage.getItem(storageKey)||'';}catch(ignore){}
    try{var value=JSON.parse(raw||'{}');savedPreferences=value&&typeof value==='object'&&!Array.isArray(value)?value:{};}catch(ignore){savedPreferences={};}
    rememberedSymbol=normalize(savedPreferences.symbol||'');
    if(!rememberedSymbol&&Array.isArray(savedPreferences.items)&&savedPreferences.items[0])rememberedSymbol=normalize(savedPreferences.items[0].symbol);
    if([0,2,5,10,30].includes(savedPreferences.interval))refreshSeconds=savedPreferences.interval;
    intervalSelect.value=String(refreshSeconds);
  }
  function writeSession(){
    savedPreferences.symbol=rememberedSymbol;savedPreferences.interval=refreshSeconds;
    var value=JSON.stringify(savedPreferences);
    try{window.localStorage.setItem(storageKey,value);}catch(ignore){try{window.sessionStorage.setItem(storageKey,value);}catch(unavailable){}}
  }
  function rememberStock(code){var value=normalize(code);if(!value)return;rememberedSymbol=value;writeSession();}

  // Accessible name search: IME composition, disambiguation and stale-response cancellation.
  var matches=root.querySelector('.fn-market-matches'),searchHint=root.querySelector('#fn-market-search-hint');
  var searchController=null,searchSequence=0,searchTimer=0,composing=false,candidates=[],activeMatch=-1;
  function closeMatches(){input.removeAttribute('aria-busy');searchSequence++;if(searchController)searchController.abort();window.clearTimeout(searchTimer);matches.hidden=true;input.setAttribute('aria-expanded','false');input.removeAttribute('aria-activedescendant');candidates=[];activeMatch=-1;}
  function highlightMatch(index){
    activeMatch=index;matches.querySelectorAll('[role=option]').forEach(function(option,i){option.setAttribute('aria-selected',String(i===index));});
    if(index>=0){var option=matches.children[index];input.setAttribute('aria-activedescendant',option.id);if(option.scrollIntoView)option.scrollIntoView({block:'nearest'});}else input.removeAttribute('aria-activedescendant');
  }
  function renderMatches(items){
    candidates=items.filter(function(item){return item&&normalize(item.symbol)===item.symbol&&typeof item.name==='string';}).slice(0,12);activeMatch=-1;matches.replaceChildren();
    candidates.forEach(function(item,i){var option=html('button','fn-market-match'),name=html('span','',item.name),code=html('small','',item.symbol);option.type='button';option.id='fn-stock-option-'+i;option.dataset.match=i;option.setAttribute('role','option');option.setAttribute('aria-selected','false');option.tabIndex=-1;option.append(name,code);matches.appendChild(option);});
    matches.hidden=!candidates.length;input.setAttribute('aria-expanded',String(candidates.length>0));input.removeAttribute('aria-activedescendant');
  }
  function searchStock(submit){
    window.clearTimeout(searchTimer);var query=input.value.trim();if(!query)return;
    var direct=normalize(query);if(direct){if(submit)setStock(direct,1,true);return;}
    if (/^\d{6}$/.test(query)||/^(?:(?:SH|SZ|BJ)[:.]?\d+|\d+\.(?:SH|SZ|BJ))$/i.test(query)) { input.setAttribute('aria-invalid','true');searchHint.textContent='代码与交易市场不匹配，请核对后重试。';return; }
    if(Array.from(query).length<2){searchHint.textContent='再输入一个字，或使用六位股票代码。';return;}
    searchSequence++;var sequence=searchSequence;if(searchController)searchController.abort();searchController=typeof AbortController!=='undefined'?new AbortController():null;
    searchHint.textContent='正在检索…';input.setAttribute('aria-busy','true');
    request('fieldnotes_market_search',{q:query},searchController).then(function(result){
      if(sequence!==searchSequence||input.value.trim()!==query)return;
      renderMatches(Array.isArray(result.items)?result.items:[]);
      if(candidates.length===1)rememberStock(candidates[0].symbol);
      if(submit&&candidates.length===1){var item=candidates[0];setStock(item.symbol,1,true);searchHint.textContent=item.name+' · '+item.symbol;}
      else searchHint.textContent=candidates.length?'选择匹配的股票，再展开行情。':'没有找到沪深北 A 股，请补全名称或输入六位代码。';
    }).catch(function(error){if(sequence===searchSequence&&error.name!=='AbortError'){renderMatches([]);searchHint.textContent=error.message||'检索暂不可用，可直接输入六位代码。';}}).finally(function(){if(sequence===searchSequence)input.removeAttribute('aria-busy');});
  }
  function scheduleSearch(){var typed=normalize(input.value);if(typed)rememberStock(typed);closeMatches();input.removeAttribute('aria-invalid');input.removeAttribute('aria-busy');if(!composing&&input.value.trim().length>=2&&!normalize(input.value))searchTimer=window.setTimeout(function(){searchStock(false);},350);else searchHint.textContent='沪深北 A 股、上证指数 · 名称、简拼或代码';}
  input.addEventListener('compositionstart',function(){composing=true;closeMatches();});
  input.addEventListener('compositionend',function(){composing=false;scheduleSearch();});
  input.addEventListener('input',scheduleSearch);
  input.addEventListener('keydown',function(event){
    if(composing||event.isComposing||event.keyCode===229)return;
    if(event.key==='Escape'){closeMatches();return;}
    if(!matches.hidden&&candidates.length&&(event.key==='ArrowDown'||event.key==='ArrowUp')){event.preventDefault();highlightMatch(activeMatch<0?(event.key==='ArrowDown'?0:candidates.length-1):(activeMatch+(event.key==='ArrowDown'?1:-1)+candidates.length)%candidates.length);}
    else if(event.key==='Enter'&&activeMatch>=0&&!matches.hidden){event.preventDefault();var item=candidates[activeMatch];setStock(item.symbol,1,true);searchHint.textContent=item.name+' · '+item.symbol;}
  });
  matches.addEventListener('pointerdown',function(event){event.preventDefault();});
  matches.addEventListener('click',function(event){var option=event.target.closest('[data-match]');if(!option)return;var item=candidates[Number(option.dataset.match)];if(item){setStock(item.symbol,1,true);searchHint.textContent=item.name+' · '+item.symbol;}});
  form.addEventListener('focusout',function(){window.setTimeout(function(){if(!form.contains(document.activeElement))closeMatches();},0);});
  form.addEventListener('submit',function(event){event.preventDefault();if(!composing)searchStock(true);});

  // A quote has its own exchange timestamp; polling never invents new ticks or candles.
  function clearLive(){liveSequence++;if(liveController)liveController.abort();liveBusy=false;live.hidden=true;delete live.dataset.asOf;live.classList.remove('is-stale');}
  function loadLive(){
    if(!symbol||liveBusy)return;liveBusy=true;var sequence=++liveSequence,target=symbol;
    liveController=typeof AbortController!=='undefined'?new AbortController():null;
    request('fieldnotes_market_quote',{symbol:target},liveController).then(function(result){
      if(sequence!==liveSequence||target!==symbol)return;
      if(result.symbol!==target||typeof result.price!=='number'||!Number.isFinite(result.price)||result.price<=0||typeof result.percent!=='number'||!Number.isFinite(result.percent)||!/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+08:00$/.test(result.as_of))throw new Error('报价格式无效');
      var old=result.stale||Date.now()-Date.parse(result.as_of)>90000;
      live.hidden=false;live.dataset.asOf=result.as_of;live.classList.toggle('is-stale',!!old);live.querySelector('.fn-market-live-price').textContent=format(result.price);
      live.querySelector('.fn-market-live-change').textContent=(result.percent>=0?'+':'')+result.percent.toFixed(2)+'%';live.querySelector('.fn-market-live-change').dataset.direction=result.percent>=0?'up':'down';
      live.querySelector('.fn-market-live-time').textContent=result.source+' · 报价 '+result.as_of.slice(0,10)+' '+result.as_of.slice(11,19)+(old?' · 请留意报价时间':'');
      liveFailures=result.stale?Math.min(liveFailures+1,4):0;
    }).catch(function(error){if(sequence!==liveSequence||error.name==='AbortError')return;liveFailures=Math.min(liveFailures+1,4);if(live.hidden){live.hidden=false;live.querySelector('.fn-market-live-price').textContent='—';live.querySelector('.fn-market-live-change').textContent='';}live.classList.add('is-stale');live.querySelector('.fn-market-live-time').textContent=(live.dataset.asOf?'上次报价 '+live.dataset.asOf.slice(0,10)+' '+live.dataset.asOf.slice(11,19)+' · ':'')+'最新报价暂未连接，稍后重试。';}).finally(function(){if(sequence===liveSequence){liveBusy=false;updateAutoState();}});
  }
  function inTradingHours(){
    var parts=new Intl.DateTimeFormat('en-GB',{timeZone:'Asia/Shanghai',weekday:'short',hour:'2-digit',minute:'2-digit',hourCycle:'h23'}).formatToParts(new Date()),clock={};parts.forEach(function(p){clock[p.type]=p.value;});
    var minutes=Number(clock.hour)*60+Number(clock.minute);return !['Sat','Sun'].includes(clock.weekday)&&((minutes>=570&&minutes<690)||(minutes>=780&&minutes<900));
  }
  function updateAutoState(){
    var stateText=!symbol?'选择股票后开始':!refreshSeconds?'自动刷新已暂停':document.visibilityState==='hidden'?'页面离开时暂停':!inTradingHours()?'非交易时段 · 可手动刷新':liveFailures?'连接稍慢 · 自动降低频率重试':'每 '+refreshSeconds+' 秒尝试更新 · 行情可能延迟';if(autoState.textContent!==stateText)autoState.textContent=stateText;
  }
  function poll(){
    updateAutoState();if(!symbol||!refreshSeconds||document.visibilityState==='hidden'||!inTradingHours()||liveBusy||chart.getAttribute('aria-busy')==='true')return;
    var delay=Math.min(60,refreshSeconds*Math.pow(2,liveFailures))*1000;if(Date.now()-lastRefresh<delay)return;
    lastRefresh=Date.now();loadLive();loadQuotes(true);
  }
  intervalSelect.addEventListener('change',function(){var value=Number(intervalSelect.value);refreshSeconds=[0,2,5,10,30].includes(value)?value:5;lastRefresh=0;writeSession();poll();});
  document.addEventListener('visibilitychange',function(){if(document.visibilityState==='visible'){lastRefresh=0;poll();}else updateAutoState();});
  window.addEventListener('pagehide',function(){closeMatches();clearLive();if(quoteController)quoteController.abort();if(noteController)noteController.abort();});

  root.querySelectorAll('[data-period]').forEach(function(button){button.addEventListener('click',function(){if(!symbol||period===button.dataset.period)return;period=button.dataset.period;updateControls();updateURL(paramsFromURL().page,false);loadQuotes();});});
  adjustSelect.addEventListener('change',function(){adjust=adjustSelect.value==='none'?'none':'qfq';if(symbol){updateURL(paramsFromURL().page,false);loadQuotes();}});
  retry.addEventListener('click',function(){loadQuotes(false);loadLive();});
  root.querySelector('.fn-market-refresh').addEventListener('click',function(){lastRefresh=Date.now();loadQuotes(true);loadLive();});
  function zoom(direction){if(!data||period==='minute')return;var visible=Math.min(count,data.bars.length-offset);count=Math.max(24,Math.min(data.bars.length,Math.round(visible*(direction>0?.8:1.25))));draw();}
  root.querySelector('.fn-market-zoom-out').addEventListener('click',function(){zoom(-1);});
  root.querySelector('.fn-market-zoom-in').addEventListener('click',function(){zoom(1);});
  root.querySelector('.fn-market-zoom-reset').addEventListener('click',function(){count=80;offset=0;hideBar();draw();});
  chart.addEventListener('pointermove',function(event){if(!plot)return;var rect=chart.getBoundingClientRect(),x=(event.clientX-rect.left)*plot.width/Math.max(1,rect.width);var index=Math.floor((x-plot.left)/plot.step);if(plot.minute){var distance=Infinity;plot.bars.forEach(function(p,i){var diff=Math.abs(plot.x(i)-x);if(diff<distance){distance=diff;index=i;}});}showBar(index);});
  chart.addEventListener('pointerleave',hideBar);chart.addEventListener('blur',hideBar);
  chart.addEventListener('keydown',function(event){if(!plot)return;if(event.key==='ArrowLeft'||event.key==='ArrowRight'){event.preventDefault();showBar(cursor<0?plot.bars.length-1:cursor+(event.key==='ArrowLeft'?-1:1));}else if(event.key==='Escape')hideBar();});
  chart.addEventListener('wheel',function(event){if(!data||period==='minute')return;if(event.ctrlKey||event.metaKey){event.preventDefault();zoom(event.deltaY<0?1:-1);}else if(event.shiftKey){event.preventDefault();offset=Math.max(0,Math.min(data.bars.length-24,offset+(event.deltaY>0?10:-10)));hideBar();draw();}},{passive:false});
  var writeLink=root.querySelector('a[href="#fn-market-write"]');if(writeLink)writeLink.addEventListener('click',function(){root.querySelector('.fn-market-write').open=true;});
  var resizeFrame=0,chartWidth=0,chartHeight=0;function resizeChart(){if(resizeFrame)return;resizeFrame=requestAnimationFrame(function(){resizeFrame=0;var width=chart.clientWidth,height=chart.clientHeight;if(width!==chartWidth||height!==chartHeight){chartWidth=width;chartHeight=height;draw();}});}
  if(typeof ResizeObserver!=='undefined'){new ResizeObserver(resizeChart).observe(chart);}else window.addEventListener('resize',resizeChart,{passive:true});
  window.addEventListener('popstate',function(){var current=paramsFromURL();period=current.period;adjust=current.adjust;setStock(current.symbol||rememberedSymbol||'SH000001',1,false);updateControls();});
  window.setInterval(poll,1000);
  readSession();
  var initial=paramsFromURL();period=initial.period;adjust=initial.adjust;updateControls();setStock(initial.symbol||rememberedSymbol||normalize(symbol)||'SH000001',1,false);if(new URL(window.location.href).searchParams.has('notes_page'))updateURL(1,true);updateAutoState();
}());

(function () {
  'use strict';
  var panel = document.querySelector('.fn-market-panel');
  if (!panel || panel.dataset.fullscreenReady) return;
  panel.dataset.fullscreenReady = '1';
  var chart = panel.querySelector('.fn-market-chart');
  var fallback = false, pending = false, wasActive = false, previousFocus = null;
  function nativeActive() {
    return (document.fullscreenElement || document.webkitFullscreenElement) === panel;
  }
  function syncFullscreen() {
    var active = nativeActive() || fallback;
    panel.classList.toggle('is-fullscreen', active);
    document.body.classList.toggle('fn-market-fullscreen-open', active);
    panel.title = active ? '双击退出全屏，或按 Esc' : '双击行情区域进入全屏';
    if (active && !wasActive) panel.focus({preventScroll: true});
    if (!active && wasActive && previousFocus && previousFocus.isConnected) previousFocus.focus({preventScroll: true});
    var tooltip = panel.querySelector('.fn-market-tooltip');
    if (tooltip) tooltip.hidden = true;
    wasActive = active;
  }
  function toggleFullscreen() {
    if (pending) return;
    if (fallback) { fallback = false; syncFullscreen(); return; }
    var exiting = nativeActive();
    var operation = exiting ? (document.exitFullscreen || document.webkitExitFullscreen) : (panel.requestFullscreen || panel.webkitRequestFullscreen);
    if (!exiting) previousFocus = document.activeElement;
    if (!operation) { fallback = !exiting; syncFullscreen(); return; }
    pending = true;
    var result;
    try { result = operation.call(exiting ? document : panel); }
    catch (error) { if (!exiting) fallback = true; pending = false; syncFullscreen(); return; }
    Promise.resolve(result).then(syncFullscreen).catch(function () {
      if (!exiting) fallback = true;
      syncFullscreen();
    }).finally(function () { pending = false; });
  }
  panel.tabIndex = -1;
  panel.addEventListener('dblclick', function (event) {
    if (event.target.closest('button,a,input,select,textarea,summary,label,[contenteditable]')) return;
    event.preventDefault();
    var selection = window.getSelection();
    if (selection) selection.removeAllRanges();
    toggleFullscreen();
  });
  chart.setAttribute('aria-keyshortcuts', 'F');
  chart.addEventListener('keydown', function (event) {
    if (event.key.toLowerCase() === 'f' && !event.ctrlKey && !event.metaKey && !event.altKey) {
      event.preventDefault(); toggleFullscreen();
    }
  });
  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape' && fallback) { event.preventDefault(); fallback = false; syncFullscreen(); }
  });
  document.addEventListener('fullscreenchange', syncFullscreen);
  document.addEventListener('webkitfullscreenchange', syncFullscreen);
  syncFullscreen();
})();
