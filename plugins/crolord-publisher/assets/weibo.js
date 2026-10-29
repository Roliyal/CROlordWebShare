(function(){
    'use strict';
    const root=document.querySelector('.cp-weibo');if(!root||!window.CrolordWeibo)return;
    const qs=s=>root.querySelector(s);let timer,busy=false,alive=true,generation=0;
    async function call(operation,extra={}){
        const response=await fetch(CrolordWeibo.ajax,{method:'POST',credentials:'same-origin',cache:'no-store',body:new URLSearchParams({action:'crolord_publisher_weibo',nonce:CrolordWeibo.nonce,operation,...extra})});
        let result;try{result=await response.json();}catch{throw new Error('后台未返回预期数据，请检查登录状态和 REST/安全插件。');}
        if(!result.success)throw new Error(result.data?.message||'请求失败。');return result.data;
    }
    function render(d){
        qs('.cp-weibo-account').textContent=d.uid?(d.name||'微博账号')+' · UID '+d.uid:'尚未登录微博';
        qs('.cp-weibo-readiness').textContent=(d.enabled?'自动发布已启用 · ':'自动发布已暂停 · ')+d.status;
        qs('.cp-weibo-runtime').textContent=d.seen?'最近连接：'+new Date(d.seen*1000).toLocaleString()+' · 组件 '+d.worker:'浏览器组件尚未连接';
        qs('.cp-weibo-login').hidden=!d.login;
        const image=qs('.cp-weibo-screen');if(d.login&&d.preview)image.src='data:image/jpeg;base64,'+d.preview;else if(!d.login)image.removeAttribute('src');
    }
    async function poll(){
        clearTimeout(timer);if(!alive||document.hidden){timer=setTimeout(poll,15000);return;}
        if(!busy){const gen=generation;try{const data=await call('poll');if(!alive||busy||gen!==generation)return;render(data);timer=setTimeout(poll,data.login?2500:30000);return;}catch(e){qs('.cp-weibo-notice').textContent=e.message;}}
        timer=setTimeout(poll,10000);
    }
    async function action(op,extra={}){
        if(busy)return;busy=true;generation++;
        try{const d=await call(op,extra);render(d);qs('.cp-weibo-notice').textContent=d.message;if(d.code){const box=qs('.cp-weibo-pair');box.textContent='一次性配对码：'+d.code;box.hidden=false;setTimeout(()=>{box.textContent='';box.hidden=true;},600000);}if(op==='type')qs('.cp-weibo-input').value='';}
        catch(e){qs('.cp-weibo-notice').textContent=e.message;}
        finally{busy=false;clearTimeout(timer);timer=setTimeout(poll,1500);}
    }
    root.addEventListener('click',e=>{
        const b=e.target.closest('[data-weibo]');if(!b||b.disabled)return;const op=b.dataset.weibo;const extra={};
        if(op==='limits'){extra.image_mb=qs('[name="weibo_image_mb"]').value;extra.video_mb=qs('[name="weibo_video_mb"]').value;}
        if(op==='type')extra.text=qs('.cp-weibo-input').value;if(op==='key')extra.key=b.dataset.key;action(op,extra);
    });
    const screen=qs('.cp-weibo-screen');let start;
    const point=e=>{const r=screen.getBoundingClientRect();return {x:Math.floor((e.clientX-r.left)*960/r.width),y:Math.floor((e.clientY-r.top)*720/r.height)};};
    screen.addEventListener('pointerdown',e=>{e.preventDefault();start=point(e);screen.setPointerCapture(e.pointerId);});
    screen.addEventListener('pointerup',e=>{if(!start)return;const end=point(e),from=start;start=null;if(Math.hypot(end.x-from.x,end.y-from.y)>4)action('drag',{x0:from.x,y0:from.y,x1:end.x,y1:end.y});else action('click',end);});
    screen.addEventListener('pointercancel',()=>{start=null;});
    document.addEventListener('visibilitychange',()=>{if(!document.hidden){clearTimeout(timer);poll();}});
    window.addEventListener('pagehide',()=>{alive=false;clearTimeout(timer);qs('.cp-weibo-input').value='';qs('.cp-weibo-pair').textContent='';qs('.cp-weibo-screen').removeAttribute('src');});poll();
})();
