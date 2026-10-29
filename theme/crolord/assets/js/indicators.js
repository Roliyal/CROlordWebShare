(function(root){
  'use strict';
  function blank(n){return Array(n).fill(null);}
  function average(values,n){var out=blank(values.length),sum=0,queue=[];values.forEach(function(v,i){if(v==null||!Number.isFinite(v)){sum=0;queue=[];return;}queue.push(v);sum+=v;if(queue.length>n)sum-=queue.shift();if(queue.length===n)out[i]=sum/n;});return out;}
  function smooth(values,n,wilder){var out=blank(values.length),seed=[],last=null,alpha=wilder?1/n:2/(n+1);values.forEach(function(v,i){if(v==null||!Number.isFinite(v)){seed=[];last=null;return;}if(last==null){seed.push(v);if(seed.length===n){last=seed.reduce(function(a,b){return a+b;},0)/n;out[i]=last;}}else{last=last+alpha*(v-last);out[i]=last;}});return out;}
  function extremes(bars,n,key,high){return bars.map(function(b,i){if(i<n-1)return null;var v=bars.slice(i-n+1,i+1).map(function(x){return x[key];});return high?Math.max.apply(null,v):Math.min.apply(null,v);});}
  function series(name,values,color,kind){return{name:name,values:values,color:color,kind:kind||'line'};}
  var ink=['#9b8874','#85879b','#778b99'];
  var catalog=[
    {id:'ma',name:'MA',group:'overlay',params:'5 / 10 / 20',description:'收盘价的简单移动平均。'},
    {id:'ema',name:'EMA',group:'overlay',params:'12 / 26',description:'以首个完整周期的均值为初值，再进行指数平滑。'},
    {id:'boll',name:'BOLL',group:'overlay',params:'20 / 2',description:'20 周期均线，上下轨为均线加减 2 倍总体标准差。'},
    {id:'macd',name:'MACD',group:'panel',params:'12 / 26 / 9',description:'DIF = EMA12 − EMA26；DEA 为 DIF 的 EMA9；柱值为 DIF − DEA。'},
    {id:'kdj',name:'KDJ',group:'panel',params:'9 / 3 / 3',description:'9 周期 RSV，以 50 初始化 K、D；J = 3K − 2D。'},
    {id:'rsi',name:'RSI',group:'panel',params:'6 / 12 / 24',description:'用 Wilder 平滑涨跌幅计算相对强弱；没有涨跌时取 50。'},
    {id:'cci',name:'CCI',group:'panel',params:'20',description:'典型价格相对其均值的偏离，除以 0.015 倍平均绝对偏差。'},
    {id:'wr',name:'WR',group:'panel',params:'14',description:'(14 周期最高价 − 收盘价) / 高低价区间 × 100；使用 0–100 刻度。'},
    {id:'atr',name:'ATR',group:'panel',params:'14',description:'真实波幅的 14 周期 Wilder 平滑值，单位与价格一致。'},
    {id:'dmi',name:'DMI',group:'panel',params:'14 / 14',description:'正负方向运动经 Wilder 平滑得到 +DI、−DI，DX 再平滑得到 ADX。'},
    {id:'obv',name:'OBV',group:'panel',params:'累计',description:'以 0 开始，按收盘价涨跌累加或减去成交量；平收不变。'},
    {id:'vol',name:'VOL',group:'panel',params:'成交量',description:'数据源实际返回的每根 K 线成交量，单位为手。'},
    {id:'roc',name:'ROC',group:'panel',params:'12',description:'相对 12 周期前收盘价的变化百分比。'},
    {id:'mom',name:'MOM',group:'panel',params:'10',description:'收盘价减去 10 周期前的收盘价。'},
    {id:'bias',name:'BIAS',group:'panel',params:'6 / 12 / 24',description:'收盘价相对简单移动平均的偏离百分比。'},
    {id:'trix',name:'TRIX',group:'panel',params:'12 / 9',description:'三重 EMA12 的一周期变化率，以及其 9 周期简单均线。'}
  ];
  function calculate(bars){
    if(!Array.isArray(bars)||!bars.every(function(b){return b&&['open','high','low','close','volume'].every(function(k){return Number.isFinite(b[k]);})&&b.low>0&&b.high>=b.low&&b.volume>=0;}))return{};
    var close=bars.map(function(b){return b.close;}),vol=bars.map(function(b){return b.volume;}),size=bars.length,out={};
    out.ma=[5,10,20].map(function(n,i){return series('MA'+n,average(close,n),ink[i]);});
    var ema12=smooth(close,12),ema26=smooth(close,26);out.ema=[series('EMA12',ema12,ink[0]),series('EMA26',ema26,ink[1])];
    var middle=average(close,20),dev=close.map(function(v,i){if(middle[i]==null)return null;return Math.sqrt(close.slice(i-19,i+1).reduce(function(sum,p){return sum+Math.pow(p-middle[i],2);},0)/20);});
    out.boll=[series('MID',middle,ink[0]),series('UPPER',middle.map(function(v,i){return v==null?null:v+2*dev[i];}),ink[1]),series('LOWER',middle.map(function(v,i){return v==null?null:v-2*dev[i];}),ink[2])];
    var dif=close.map(function(v,i){return ema12[i]==null||ema26[i]==null?null:ema12[i]-ema26[i];}),dea=smooth(dif,9),hist=dif.map(function(v,i){return v==null||dea[i]==null?null:v-dea[i];});
    out.macd=[series('DIF',dif,ink[0]),series('DEA',dea,ink[1]),series('HIST',hist,ink[2],'bar')];
    var hi9=extremes(bars,9,'high',true),lo9=extremes(bars,9,'low',false),k=50,d=50,ks=blank(size),ds=blank(size),js=blank(size);
    close.forEach(function(v,i){if(hi9[i]==null)return;var rsv=hi9[i]===lo9[i]?50:100*(v-lo9[i])/(hi9[i]-lo9[i]);k=(2*k+rsv)/3;d=(2*d+k)/3;ks[i]=k;ds[i]=d;js[i]=3*k-2*d;});out.kdj=[series('K',ks,ink[0]),series('D',ds,ink[1]),series('J',js,ink[2])];
    var gains=close.map(function(v,i){return i?Math.max(0,v-close[i-1]):null;}),losses=close.map(function(v,i){return i?Math.max(0,close[i-1]-v):null;});
    out.rsi=[6,12,24].map(function(n,i){var up=smooth(gains,n,true),down=smooth(losses,n,true);return series('RSI'+n,close.map(function(v,j){return up[j]==null||down[j]==null?null:up[j]+down[j]===0?50:100*up[j]/(up[j]+down[j]);}),ink[i]);});
    var typical=bars.map(function(b){return(b.high+b.low+b.close)/3;}),mean=average(typical,20);
    out.cci=[series('CCI',typical.map(function(v,i){if(mean[i]==null)return null;var mad=typical.slice(i-19,i+1).reduce(function(sum,p){return sum+Math.abs(p-mean[i]);},0)/20;return mad?(v-mean[i])/(.015*mad):0;}),ink[0])];
    var hi14=extremes(bars,14,'high',true),lo14=extremes(bars,14,'low',false);out.wr=[series('WR14',close.map(function(v,i){return hi14[i]==null?null:hi14[i]===lo14[i]?50:100*(hi14[i]-v)/(hi14[i]-lo14[i]);}),ink[0])];
    var tr=bars.map(function(b,i){return i?Math.max(b.high-b.low,Math.abs(b.high-close[i-1]),Math.abs(b.low-close[i-1])):null;}),atr=smooth(tr,14,true);out.atr=[series('ATR14',atr,ink[0])];
    var plus=smooth(bars.map(function(b,i){if(!i)return null;var up=b.high-bars[i-1].high,down=bars[i-1].low-b.low;return up>down&&up>0?up:0;}),14,true),minus=smooth(bars.map(function(b,i){if(!i)return null;var up=b.high-bars[i-1].high,down=bars[i-1].low-b.low;return down>up&&down>0?down:0;}),14,true);
    var pdi=atr.map(function(v,i){return v==null?null:v?100*plus[i]/v:0;}),mdi=atr.map(function(v,i){return v==null?null:v?100*minus[i]/v:0;}),dx=pdi.map(function(v,i){return v==null?null:v+mdi[i]?100*Math.abs(v-mdi[i])/(v+mdi[i]):0;});out.dmi=[series('+DI',pdi,ink[0]),series('−DI',mdi,ink[1]),series('ADX',smooth(dx,14,true),ink[2])];
    var obv=0;out.obv=[series('OBV',close.map(function(v,i){if(i)obv+=v>close[i-1]?vol[i]:v<close[i-1]?-vol[i]:0;return obv;}),ink[0])];out.vol=[series('VOL',vol,ink[2],'bar')];
    out.roc=[series('ROC12',close.map(function(v,i){return i<12?null:100*(v/close[i-12]-1);}),ink[0])];out.mom=[series('MOM10',close.map(function(v,i){return i<10?null:v-close[i-10];}),ink[0])];
    out.bias=[6,12,24].map(function(n,i){var ma=average(close,n);return series('BIAS'+n,close.map(function(v,j){return ma[j]==null?null:100*(v/ma[j]-1);}),ink[i]);});
    var triple=smooth(smooth(ema12,12),12),trix=triple.map(function(v,i){return !i||v==null||triple[i-1]==null?null:100*(v/triple[i-1]-1);});out.trix=[series('TRIX',trix,ink[0]),series('MATRIX9',average(trix,9),ink[1])];
    return out;
  }
  var api={catalog:catalog,calculate:calculate,sma:average,ema:function(v,n){return smooth(v,n,false);},wilder:function(v,n){return smooth(v,n,true);}};
  if(typeof module!=='undefined'&&module.exports)module.exports=api;else root.FieldnotesIndicators=api;
}(typeof window!=='undefined'?window:this));
