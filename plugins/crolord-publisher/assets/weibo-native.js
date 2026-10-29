(function(){
    'use strict';
    const root=document.querySelector('.cp-weibo-native');if(!root||!window.CrolordWeibo)return;
    const qs=s=>root.querySelector(s);let handle='',timer,pollTimer,busy=false,alive=true,epoch=0;
    const notice=text=>{qs('.cp-weibo-notice').textContent=text;};
    function diagnostic(text=''){const box=qs('.cp-weibo-diagnostic');box.hidden=!text;box.open=!!text;box.querySelector('pre').textContent=text;qs('.cp-weibo-copy-state').textContent='';}
    function failure(error){notice(error.message);diagnostic(typeof error.diagnostic==='string'?error.diagnostic:'');}
    const setBusy=value=>{busy=value;root.setAttribute('aria-busy',String(value));root.querySelectorAll('[data-weibo]').forEach(button=>{button.disabled=value;});};
    async function call(operation,extra={}){
        const response=await fetch(CrolordWeibo.ajax,{method:'POST',credentials:'same-origin',cache:'no-store',signal:AbortSignal.timeout(90000),body:new URLSearchParams({action:'crolord_publisher_weibo',nonce:CrolordWeibo.nonce,operation,...extra})});let result;
        try{result=await response.json();}catch{throw new Error('后台未返回 JSON，请检查 WordPress 登录、PHP 错误或安全插件。');}if(!result.success){const error=new Error(result.data?.message||'请求未完成。');error.diagnostic=result.data?.diagnostic;throw error;}return result.data;
    }
    function render(d){qs('.cp-weibo-account').textContent=d.uid?(d.name||'微博账号')+' · UID '+d.uid:'尚未连接微博';qs('.cp-weibo-readiness').textContent=d.status;qs('.cp-weibo-runtime').textContent=d.enabled?'插件内自动同步已启用':'插件内自动同步已暂停';}
    function clearQR(){handle='';clearTimeout(timer);qs('.cp-weibo-qr').hidden=true;qs('.cp-weibo-qr img').removeAttribute('src');qs('.cp-weibo-qr-state').textContent='';}
    function progress(d){
        if(d.image&&d.handle){if(!/^data:image\/(?:png|jpeg|gif);base64,[A-Za-z0-9+/=]+$/.test(d.image))throw new Error('二维码格式无效。');handle=d.handle;qs('.cp-weibo-qr img').src=d.image;qs('.cp-weibo-qr').hidden=false;}
        if(handle){qs('.cp-weibo-qr-state').textContent=d.message||'正在完成登录';if(d.qr_status==='finishing')qs('.cp-weibo-qr img').removeAttribute('src');}
        if(d.qr_status==='connected'){clearQR();diagnostic();return;}if(handle)timer=setTimeout(qrPoll,d.qr_status==='finishing'?1200:3000);
    }
    async function qrPoll(){
        clearTimeout(timer);if(!alive||!handle||document.hidden)return;if(busy){timer=setTimeout(qrPoll,1500);return;}setBusy(true);const gen=epoch,current=handle;
        try{const d=await call('qr_poll',{handle:current});if(!alive||gen!==epoch||handle!==current)return;render(d);notice(d.message);diagnostic();progress(d);}catch(e){if(!alive||gen!==epoch||handle!==current)return;failure(e);qs('.cp-weibo-qr-state').textContent='本步未完成，可查看连接诊断、重试或重新扫码。';}finally{setBusy(false);}
    }
    root.addEventListener('click',async event=>{
        const button=event.target.closest('[data-weibo]');if(!button||button.disabled||busy)return;const op=button.dataset.weibo;
        if(op==='qr_poll'){qrPoll();return;}setBusy(true);epoch++;clearTimeout(timer);diagnostic();notice('正在处理…');
        try{const extra=op==='limits'?{image_mb:qs('[name="weibo_image_mb"]').value,video_mb:qs('[name="weibo_video_mb"]').value}:{};if(['qr_start','qr_cancel','disconnect'].includes(op))clearQR();const d=await call(op,extra);if(!alive)return;render(d);notice(d.message);progress(d);}
        catch(e){if(alive)failure(e);}finally{setBusy(false);}
    });
    qs('[data-weibo-copy]').addEventListener('click',async()=>{
        const text=qs('.cp-weibo-diagnostic pre').textContent;if(!text)return;
        try{await navigator.clipboard.writeText(text);qs('.cp-weibo-copy-state').textContent='已复制';}
        catch{const selection=window.getSelection(),range=document.createRange();range.selectNodeContents(qs('.cp-weibo-diagnostic pre'));selection.removeAllRanges();selection.addRange(range);qs('.cp-weibo-copy-state').textContent='请复制已选中的诊断文字';}
    });
    async function poll(){clearTimeout(pollTimer);if(!alive)return;if(!busy&&!handle&&!document.hidden){const gen=epoch;try{const d=await call('poll');if(alive&&!busy&&!handle&&gen===epoch)render(d);}catch(e){if(alive&&gen===epoch)notice(e.message);}}pollTimer=setTimeout(poll,45000);}
    document.addEventListener('visibilitychange',()=>{if(!document.hidden){if(handle)qrPoll();else poll();}});
    window.addEventListener('pagehide',()=>{alive=false;epoch++;clearTimeout(pollTimer);clearQR();diagnostic();});poll();
})();
