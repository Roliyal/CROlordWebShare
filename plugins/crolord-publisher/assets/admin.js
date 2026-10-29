(function () {
    'use strict';
    const root = document.querySelector('.cp-workspace,.cp-settings');
    if (!root || !window.CrolordPublisher) return;
    const qs = selector => root.querySelector(selector);
    const notice = text => { let el = qs('.cp-notice'); if (!el) { el = element('p', '', 'cp-notice'); el.setAttribute('role', 'status'); root.insertBefore(el, qs('.cp-status')); } el.textContent = text; };
    const request = async data => {
        const response = await fetch(CrolordPublisher.ajax, { method: 'POST', credentials: 'same-origin', body: new URLSearchParams({nonce: CrolordPublisher.nonce, ...data}) });
        let result; try { result = await response.json(); } catch { throw new Error('后台返回了非预期页面，请刷新登录状态并检查安全插件拦截。'); }
        if (!result.success) throw new Error(result.data?.message || '请求失败，请刷新后台后重试。');
        return result.data;
    };
    function selectionData() {
        const data={};
        for(const field of root.querySelectorAll('input[name],select[name],textarea[name]')){if(field.type!=='checkbox'&&field.name.startsWith('cp_'))data[field.name]=field.value;}
        const enabled=qs('[name="cp_sync_enabled"]');if(enabled?.checked)data.cp_sync_enabled='1';
        root.querySelectorAll('[name="cp_platforms[]"]:checked').forEach((field,index)=>{data['cp_platforms['+index+']']=field.value;});
        return data;
    }
    const runningJobs = new Set();
    function autoOpenVerification(navigate = url => window.location.assign(url)) {
        if (root.dataset.post !== '0' || document.hidden || root.contains(document.activeElement)) return false;
        const link = qs('.cp-xhs-verify[data-verify-attempt]');
        if (!link || !/^[a-f0-9]{64}$/.test(link.dataset.verifyAttempt)) return false;
        const target = new URL(link.href, window.location.href);
        if (target.origin !== window.location.origin || !target.pathname.endsWith('/admin-post.php') || target.searchParams.get('action') !== 'crolord_publisher_xhs_verify') return false;
        try {
            const key = 'cp-xhs-verify-open:' + link.dataset.verifyAttempt;
            if (window.sessionStorage.getItem(key)) return false;
            window.sessionStorage.setItem(key, '1');
            navigate(target.href);
            return true;
        } catch { return false; }
    }
    function resolutionControls(job) {
        const panel = job?.querySelector('.cp-xhs-resolution');
        if (!panel) return;
        const busy = runningJobs.has(job.dataset.job);
        panel.querySelector('[data-operation="reset_unknown"]').disabled = busy || !panel.querySelector('.cp-absent').checked;
        panel.querySelector('[data-operation="reconcile"]').disabled = busy || !/^[0-9a-f]{24}$/.test(panel.querySelector('.cp-receipt').value.trim());
    }
    root.querySelectorAll('.cp-job[data-platform="xiaohongshu"]').forEach(resolutionControls);
    for (const type of ['input', 'change']) root.addEventListener(type, event => {
        if (event.target.closest('.cp-xhs-resolution')) resolutionControls(event.target.closest('.cp-job'));
    });
    qs('.cp-buffer-load')?.addEventListener('click', async event => {
        const button=event.currentTarget, output=qs('.cp-buffer-result');button.disabled=true;output.textContent='正在读取 Buffer 账号…';
        try {
            const data=await request({action:'crolord_publisher_buffer_channels',key:qs('.cp-buffer-key').value});
            const select=qs('.cp-buffer-channel');select.replaceChildren();
            for(const channel of data.channels){const option=element('option',channel.name+' · UID '+channel.uid+(channel.unavailable?' · 连接需修复':''));option.value=channel.id;select.append(option);}
            if(!data.channels.length){const option=element('option','请先在 Buffer 连接 X');option.value='';select.append(option);}
            output.textContent=data.message;
        } catch(error) { output.textContent=error.message; }
        finally { button.disabled=false; }
    });
    function setImages(images) {
        const host = qs('.cp-images'); host.replaceChildren();
        images.forEach(image => {
            const figure = element('figure'); figure.dataset.id = image.id;
            const img = element('img'); img.src = image.sizes?.thumbnail?.url || image.url; img.alt = image.alt || '分享图片';
            const remove = element('button', '×', 'cp-remove'); remove.type = 'button'; remove.setAttribute('aria-label', '移除图片'); figure.append(img, remove); host.append(figure);
        });
        syncImages();syncEditorFields();if(!qs('.cp-previews')?.hidden)preview();
    }
    root.addEventListener('click', async event => {
        const button = event.target.closest('.cp-environment-test,.cp-import,.cp-enqueue,.cp-run');
        if (!button || button.disabled) return;
        const job = button.closest('.cp-job'); const id = job?.dataset.job;
        if (id && runningJobs.has(id)) return;
        button.disabled = true;
        try {
            if (button.matches('.cp-environment-test')) {
                const output = button.closest('section').querySelector('.cp-diagnostic-result'); output.textContent = '正在检测…';
                try { const result = await request({action: 'crolord_publisher_environment'}); output.textContent = result.message; }
                catch (error) { output.textContent = error.message; }
            } else if (button.matches('.cp-import')) {
                const result = await request({action:'crolord_publisher_images',post:root.dataset.post});
                if (result.images.length) setImages(result.images);
                notice(result.message);
            } else if (button.matches('.cp-enqueue')) {
                const data = {action:'crolord_publisher_enqueue',post:root.dataset.post,...selectionData()};
                const result = await request(data); notice(result.message); await refresh();
            } else if (id) {
                runningJobs.add(id);
                for (let step=0; step<10; step++) {
                    notice('正在继续处理此任务 · 第 ' + (step + 1) + ' 步');
                    const result = await request({action:'crolord_publisher_run',job:id}); notice(result.message); await refresh();
                    if (result.done) break;
                    await new Promise(resolve=>setTimeout(resolve,300));
                }
            }
        } catch (error) { if(button.matches('.cp-enqueue')){await refresh();notice(qs('.cp-preparation-error .cp-error')?.textContent===error.message?'同步检查未通过，请查看面板中的具体原因。':error.message);}else notice(error.message); }
        finally { if (id) runningJobs.delete(id); if (button.isConnected) button.disabled=false; }
    });
    let frame, polling = false, timer, forceRefreshPending = false;
    const forcedRefreshWaiters = [];
    function imageIds() { return Array.from(root.querySelectorAll('.cp-images figure'), f => f.dataset.id); }
    function syncImages() { qs('[name="cp_images"]').value = imageIds().join(','); rebuildPicks(); }
    function syncPicks(group) { group.querySelector('input[type="hidden"]').value = Array.from(group.querySelectorAll('input[type="checkbox"]:checked'), box => box.value).join(','); }
    function selectedImages(platform) {
        const group = qs('.cp-picks[data-platform="' + platform + '"]');
        const selected = group.querySelector('input[type="hidden"]').value.split(',').filter(Boolean);
        return selected.map(id => root.querySelector('.cp-images figure[data-id="' + id + '"] img')?.src).filter(Boolean);
    }
    function rebuildPicks() {
        const images = Array.from(root.querySelectorAll('.cp-images figure'), figure => ({id: figure.dataset.id, src: figure.querySelector('img').src}));
        for (const group of root.querySelectorAll('.cp-picks')) {
            const old = group.querySelector('input[type="hidden"]').value.split(',').filter(Boolean);
            const existed = Array.from(group.querySelectorAll('input[type="checkbox"]'), box => box.value);
            const selected = old.filter(id => images.some(image => image.id === id));
            const maximum = Number(group.dataset.maximum);
            // Preserve explicit choices; new pool images fill remaining slots only.
            for (const image of images) if (!existed.includes(image.id) && !selected.includes(image.id) && selected.length < maximum) selected.push(image.id);
            const list = group.querySelector('.cp-pick-list'); list.replaceChildren();
            images.forEach((image, index) => {
                const label = element('label'); const box = element('input'); box.type = 'checkbox'; box.value = image.id; box.checked = selected.includes(image.id);
                const img = element('img'); img.src = image.src; img.alt = '分享图片 ' + (index + 1);
                label.append(box, img, element('span', String(index + 1))); list.append(label);
            });
            syncPicks(group);
        }
    }
    function syncEditorFields(){
        if(!root.classList.contains('cp-auto-workspace'))return;
        const editor=window.wp?.data?.dispatch('core/editor');if(!editor?.editPost)return;
        const encoded=new URLSearchParams(selectionData()).toString();
        editor.editPost({meta:{_crolord_publisher_editor_fields:encoded}});
    }
    root.addEventListener('input',event=>{if(event.target.name?.startsWith('cp_'))syncEditorFields();});
    root.addEventListener('change', event => {
        const group = event.target.closest('.cp-picks');
        if (group && event.target.type === 'checkbox') {
            if (group.querySelectorAll('input[type="checkbox"]:checked').length > Number(group.dataset.maximum)) { event.target.checked = false; notice('此平台最多选择 ' + group.dataset.maximum + ' 张图片；已有选择已保留。'); }
            else notice('已更新平台选图。保存文章后用于新任务，已有任务保持原来的发布快照。');
            syncPicks(group);
        }
        if (event.target.name === 'cp_platforms[]') {
            const panel = qs('.cp-picks[data-platform="' + event.target.value + '"]'); if (panel) panel.hidden = !event.target.checked;
        }
        syncEditorFields();
        if (['cp_xhs_format','cp_x_presentation','cp_share_layout','cp_media_mode','cp_sync_enabled','cp_weibo_format','cp_platforms[]'].includes(event.target.name)) { syncWeibo(); if(!qs('.cp-previews')?.hidden) preview(); }
    });
    function title() {
        let text = qs('[name="cp_title"]')?.value.trim();
        if (!text) text = window.wp?.data?.select('core/editor')?.getEditedPostAttribute('title') || document.querySelector('#title')?.value || '文章标题';
        return text;
    }
    function element(tag, text, className) { const el = document.createElement(tag); if (text) el.textContent = text; if (className) el.className = className; return el; }
    let previewGeneration=0;
    async function preview() {
        const gen=++previewGeneration,button=qs('.cp-preview-button');if(button)button.disabled=true;
        try{
            const editor=window.wp?.data?.select('core/editor');
            const data={action:'crolord_publisher_preview',post:root.dataset.post,...selectionData(),article_title:title()};
            const content=editor?.getEditedPostContent?.()??document.querySelector('#content')?.value;
            if(typeof content==='string')data.content=content;
            notice('正在读取文章内容…');const info=await request(data);if(gen!==previewGeneration)return;
            const host=qs('.cp-previews');host.replaceChildren();
            const platforms=Array.from(root.querySelectorAll('[name="cp_platforms[]"]:checked'),el=>el.value);
            const names={weibo:'微博',x:'X',xiaohongshu:'小红书'};
            for(const platform of platforms){
                const card=element('section','','cp-preview '+platform);card.append(element('h3',names[platform]));
                const body=element('div','','cp-preview-body');
                if((info.platform_layouts?.[platform]||info.share_layout)==='card'){
                    const data=info.card||{},preview=element('a','','cp-web-card');preview.href=info.url;preview.target='_blank';preview.rel='noopener noreferrer';
                    body.append(element('p',info.title,'cp-preview-title'));
                    if(data.image){const cover=element('img');cover.src=data.image;cover.alt=data.image_alt||data.title||info.title;cover.loading='lazy';preview.append(cover);}
                    const text=element('div','','cp-web-card-text');text.append(element('strong',data.title||info.title));if(data.description)text.append(element('p',data.description));text.append(element('span',info.site+' · '+new URL(info.url).hostname,'cp-web-card-url'));preview.append(text);body.append(preview);
                    card.append(element('p','主帖网页卡片 · 使用文章封面','cp-delivery-note'));
                    const time=qs('[name="cp_schedule"]')?.value;card.append(element('p',time?'分享时间：'+time.replace('T',' ')+' · '+CrolordPublisher.timezone:'跟随文章公开时间','cp-preview-time'));card.append(body,element('p','卡片示意；实际图片、标题与链接样式由平台抓取决定。','cp-card-note'));host.append(card);continue;
                }
                if(platform==='xiaohongshu'){
                    body.append(element('strong',info.xhs?.title||qs('[name="cp_xhs_title"]')?.value.trim()||info.title,'cp-xhs-preview-title'));
                    if(info.xhs?.body)body.append(element('p',info.xhs.body,'cp-xhs-preview-text'));
                }else body.append(element('p',info.title+'\n\n'+info.url,'cp-preview-title'));
                const video=info.share_layout==='album'&&((platform==='weibo'&&qs('[name="cp_weibo_format"]')?.value==='video')||(platform==='xiaohongshu'&&qs('[name="cp_xhs_format"]')?.value==='video'));
                if(video){const selected=qs(platform==='xiaohongshu'?'.cp-xhs-video-preview video':'.cp-weibo-video-preview video');if(selected){const copy=selected.cloneNode(true);copy.preload='none';body.append(copy);}else body.append(element('p','请选择单个 MP4。','cp-error'));if(platform==='xiaohongshu'){const cover=qs('.cp-xhs-cover-preview img');if(cover)body.append(cover.cloneNode(true));else body.append(element('p','请选择视频封面。','cp-error'));}}
                const images=video?[]:(info.platform_images[platform]||[]),grid=element('div','','cp-preview-grid');if(platform==='x')grid.classList.add('cp-x-grid');
                const audit=info.audit||{};
                card.append(element('p',(audit.source==='featured'?'无正文图片，使用特色图':audit.source==='manual'?'手动选图':'正文 '+(audit.occurrences||0)+' 处 · 不同图片 '+(audit.unique||0)+' 张 · 重复 '+(audit.duplicates||0)+' 处')+' · 本平台 '+images.length+' 张','cp-media-audit'));
                card.append(element('p',platform==='xiaohongshu'?'独立标题 · 分段正文 · 原图 · 末尾原文链接':'同一条正文：标题＋本文网址，附加所选媒体。文字与图片顺序由平台排版。','cp-delivery-note'));
                if(platform==='xiaohongshu')card.append(element('p','网址按原文提交；平台可能限制外链，预览不代表链接卡片。','cp-card-note'));
                const time=qs('[name="cp_schedule"]')?.value;card.append(element('p',time?'分享时间：'+time.replace('T',' ')+' · '+CrolordPublisher.timezone:'跟随文章公开时间','cp-preview-time'));
                for(const image of images){const img=element('img');img.src=image.url;img.alt=image.alt||'分享图片';img.loading='lazy';grid.append(img);}if(platform==='xiaohongshu')grid.classList.toggle('cp-single-image',images.length===1);body.append(grid);
                if(platform==='xiaohongshu'){const footer=element('p','','cp-xhs-preview-link'),link=element('a',info.url);link.href=info.url;link.target='_blank';link.rel='noopener noreferrer';footer.append(link);body.append(footer);}
                card.append(body);
                host.append(card);
            }
            host.hidden=false;
            let message=info.enabled?'预览为内容示意，文章公开后自动同步。':'本篇已暂停同步，预览不会建立任务。';
            if(info.warnings){const issues=info.audit?.issues||[];message+=' '+issues.slice(0,3).map(i=>'第 '+i.position+' 处图片'+(i.id?' · 附件 #'+i.id:'')+(i.name?'（'+i.name+'）':'')+'：'+i.reason).join('；');}if(info.slug_pending)message+=' 英文链接生成中，正式同步会等待最终网址。';notice(message);
        }catch(error){if(gen===previewGeneration)notice(error.message);}
        finally{if(button?.isConnected&&gen===previewGeneration)button.disabled=false;}
    }
    function syncWeibo(){
        const layout='album',xCard=qs('[name="cp_x_presentation"]')?.value==='card',weibo=!!qs('[name="cp_platforms[]"][value="weibo"]')?.checked,xhs=!!qs('[name="cp_platforms[]"][value="xiaohongshu"]')?.checked,xhsVideo=xhs&&qs('[name="cp_xhs_format"]')?.value==='video',photos=weibo||!xCard||(xhs&&!xhsVideo);root.dataset.shareLayout=layout;
        const xhsPanel=qs('.cp-xhs-editor');if(xhsPanel)xhsPanel.hidden=!xhs;
        const xhsVideoPanel=qs('.cp-xhs-video');if(xhsVideoPanel)xhsVideoPanel.hidden=!xhsVideo;
        for(const name of ['cp_media_mode','cp_weibo_format']){const label=qs('[name="'+name+'"]')?.closest('label');if(label)label.hidden=name==='cp_media_mode'?!photos:!weibo;}
        const video=weibo&&qs('[name="cp_weibo_format"]')?.value==='video',panel=qs('.cp-weibo-video');if(panel)panel.hidden=!video;
        const manual=photos&&qs('[name="cp_media_mode"]')?.value==='manual',images=qs('.cp-manual-images');if(images)images.hidden=!manual;
        for(const group of root.querySelectorAll('.cp-picks')){group.hidden=!qs('[name="cp_platforms[]"][value="'+group.dataset.platform+'"]')?.checked||(group.dataset.platform==='weibo'&&video)||(group.dataset.platform==='x'&&xCard)||(group.dataset.platform==='xiaohongshu'&&xhsVideo);}
    }
    for(const kind of ['video','cover'])qs('.cp-xhs-'+kind+'-choose')?.addEventListener('click',()=>{
        const picker=wp.media({title:kind==='video'?'选择小红书 MP4':'选择小红书视频封面',library:{type:kind==='video'?'video/mp4':['image/jpeg','image/png']},multiple:false});
        picker.on('select',()=>{const media=picker.state().get('selection').first().toJSON();qs('[name="cp_xhs_'+kind+'"]').value=media.id;const host=qs('.cp-xhs-'+kind+'-preview');host.replaceChildren();const el=element(kind==='video'?'video':'img');el.src=media.url;if(kind==='video'){el.controls=true;el.preload='none';}else el.alt=media.alt||'视频封面';host.append(el);syncEditorFields();if(!qs('.cp-previews')?.hidden)preview();});picker.open();
    });
    qs('.cp-weibo-video-choose')?.addEventListener('click',()=>{
        const picker=wp.media({title:'选择单个微博 MP4 视频',library:{type:'video/mp4'},multiple:false});picker.on('select',()=>{
            const media=picker.state().get('selection').first().toJSON();qs('[name="cp_weibo_video"]').value=media.id;
            const host=qs('.cp-weibo-video-preview');host.replaceChildren();const video=element('video');video.src=media.url;video.controls=true;video.preload='none';host.append(video);syncEditorFields();notice('微博视频已选择，保存后用于新任务。');if(!qs('.cp-previews')?.hidden)preview();
        });picker.open();
    });
    syncWeibo();
    qs('.cp-preview-button')?.addEventListener('click', preview);
    qs('.cp-choose')?.addEventListener('click', () => {
        if (!frame) {
            frame = wp.media({title: '选择分享图片，最多九张', button: {text: '使用所选图片'}, library: {type: ['image/jpeg','image/png']}, multiple: 'add'});
            frame.on('open', () => { const selected = frame.state().get('selection'); selected.reset(); imageIds().forEach(id => selected.add(wp.media.attachment(Number(id)))); });
            frame.on('select', () => {
                const images = frame.state().get('selection').toJSON();
                if (images.length > 9) { notice('最多九张，请重新选择。现有选择已保留。'); return; }
                setImages(images); notice('按选择顺序发布。可移除后重新选择调整顺序。');
            });
        }
        frame.open();
    });
    root.addEventListener('click', async event => {
        const remove = event.target.closest('.cp-remove'); if (remove) { remove.closest('figure').remove(); syncImages(); syncEditorFields(); return; }
        const button = event.target.closest('.cp-action'); if (!button || button.disabled) return;
        const job = button.closest('.cp-job');
        const resolution = job?.dataset.platform === 'xiaohongshu' && button.closest('.cp-xhs-resolution');
        const operation = button.dataset.operation;
        if (resolution && runningJobs.has(job.dataset.job)) return;
        if (resolution) { runningJobs.add(job.dataset.job); resolutionControls(job); job.querySelector('.cp-client-error').textContent = ''; }
        button.disabled = true;
        try {
            const data = {action: 'crolord_publisher_action', job: job.dataset.job, operation};
            if (resolution) {
                // The two outcomes are independent; an unpublished note never needs an ID.
                if (operation === 'reset_unknown') {
                    if (!job.querySelector('.cp-absent').checked) throw new Error('请先核对创作中心并勾选确认未发布。');
                    data.absent = '1';
                } else if (operation === 'reconcile') {
                    data.receipt = job.querySelector('.cp-receipt').value.trim();
                    if (!/^[0-9a-f]{24}$/.test(data.receipt)) throw new Error('登记已发表内容时，请填写该篇笔记的 24 位真实 ID。');
                }
            } else {
                data.receipt = job.querySelector('.cp-receipt')?.value || '';
                data.absent = job.querySelector('.cp-absent')?.checked ? '1' : '';
                data.date = job.querySelector('.cp-job-date')?.value || '';
            }
            const result = await request(data);
            if (result.text) { await navigator.clipboard.writeText(result.text); button.textContent = '已复制'; }
            else await refresh(true);
        } catch (error) {
            let message = job.querySelector('.cp-client-error'); if (!message) { message = element('p', '', 'cp-client-error cp-error'); message.setAttribute('role', 'alert'); job.append(message); } message.textContent = error.message;
        } finally {
            if (resolution) { runningJobs.delete(job.dataset.job); root.querySelectorAll('.cp-job[data-platform="xiaohongshu"]').forEach(resolutionControls); }
            else if (button.isConnected) button.disabled = false;
        }
    });
    async function refresh(force = false) {
        const statusHost = qs('.cp-status');
        if (!statusHost) return;
        if (polling) {
            if (force) { forceRefreshPending = true; return new Promise(resolve => forcedRefreshWaiters.push(resolve)); }
            return;
        }
        if (document.hidden) return;
        polling = true;
        try {
            const result = await request({action: 'crolord_publisher_status', post: root.dataset.post});
            // Preserve partially entered receipts and focused controls while polling.
            if (force || !statusHost.contains(document.activeElement)) statusHost.innerHTML = result.html;
            root.querySelectorAll('.cp-job[data-platform="xiaohongshu"]').forEach(resolutionControls);
            autoOpenVerification();
            const slugHost=qs('.cp-slug-host');if(slugHost&&result.slug)slugHost.innerHTML=result.slug.html;
            clearTimeout(timer); if (result.active) timer = setTimeout(refresh, result.scheduledOnly ? 30000 : 6000);
        } catch (error) { notice(error.message); } finally {
            polling = false;
            if (forceRefreshPending) {
                forceRefreshPending = false;
                const waiters = forcedRefreshWaiters.splice(0);
                try { await refresh(true); } finally { waiters.forEach(resolve => resolve()); }
            }
        }
    }
    root.addEventListener('click',async event=>{
        const button=event.target.closest('.cp-slug-retry');if(!button||button.disabled)return;button.disabled=true;
        try{await request({action:'crolord_publisher_slug',post:button.dataset.post||root.dataset.post});await refresh(true);}catch(error){notice(error.message);}finally{if(button.isConnected)button.disabled=false;}
    });
    root.addEventListener('click', async event => {
        const button = event.target.closest('.cp-recover-post'); if (!button || button.disabled) return;
        button.disabled = true;
        try { const result = await request({action: 'crolord_publisher_recover', post: button.dataset.post}); notice(result.message); await refresh(true); }
        catch (error) { notice(error.message); }
        finally { if (button.isConnected) button.disabled = false; }
    });
    document.addEventListener('crolord-publisher-xhs-changed', () => refresh());
    document.addEventListener('visibilitychange', () => { if (!document.hidden) refresh(); else clearTimeout(timer); });
    let publishChecks=[],unsubscribe,wasSaving=false;
    if(root.classList.contains('cp-auto-workspace')&&window.wp?.data?.subscribe){
        unsubscribe=wp.data.subscribe(()=>{
            const editor=wp.data.select('core/editor'),saving=!!editor?.isSavingPost?.();
            if(saving&&!editor?.isAutosavingPost?.())wasSaving=true;
            if(!saving&&wasSaving){wasSaving=false;publishChecks.forEach(clearTimeout);publishChecks=[setTimeout(refresh,800),setTimeout(refresh,3500)];}
        });
    }
    window.addEventListener('pagehide',()=>{previewGeneration++;clearTimeout(timer);publishChecks.forEach(clearTimeout);unsubscribe?.();});
    if (root.querySelector('.cp-slug-status[data-active="1"],.cp-job:not([data-platform="wechat"]):is([data-state="scheduled"],[data-state="queued"],[data-state="running"],[data-state="publishing"],[data-state="external_pending"],[data-state="preparing"])')) timer = setTimeout(refresh, 3000);
    autoOpenVerification();
})();
