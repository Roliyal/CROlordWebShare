(function () {
  'use strict';
  function el(tag, className, text) {
    var node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined) node.textContent = String(text);
    return node;
  }
  function icon(name) {
    var paths = {
      arrow: 'M6 18 18 6M6 6h12v12',
      landscape: 'M3 18h18M4 16l5-6 4 4 3-3 4 5M15 5h.01',
      search: 'm16 16 5 5M17 10a7 7 0 1 1-14 0 7 7 0 0 1 14 0'
    };
    var svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    svg.setAttribute('viewBox', '0 0 24 24');
    svg.setAttribute('fill', 'none');
    svg.setAttribute('stroke', 'currentColor');
    svg.setAttribute('stroke-width', '1.5');
    svg.setAttribute('stroke-linecap', 'round');
    svg.setAttribute('stroke-linejoin', 'round');
    svg.setAttribute('aria-hidden', 'true');
    svg.setAttribute('focusable', 'false');
    var path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
    path.setAttribute('d', paths[name] || paths.landscape);
    svg.appendChild(path);
    return svg;
  }
  function safeURL(value) {
    if (!value || typeof value !== 'string') return '';
    try {
      var parsed = new URL(value, window.location.href);
      return parsed.protocol === 'https:' || parsed.protocol === 'http:' ? parsed.href : '';
    } catch (error) { return ''; }
  }
  function cover(point, className) {
    var frame = el('span', className);
    frame.appendChild(icon('landscape'));
    var url = safeURL(point.preview);
    if (url) {
      var image = el('img');
      image.src = url; image.alt = ''; image.loading = 'lazy'; image.decoding = 'async';
      image.addEventListener('error', function () { image.remove(); });
      frame.appendChild(image);
    }
    return frame;
  }
  function cityShape(geometry) {
    if(!geometry||!Array.isArray(geometry.coordinates))return null;
    var polygons=geometry.type==='Polygon'?[geometry.coordinates]:geometry.type==='MultiPolygon'?geometry.coordinates:[],rings=[],xs=[],ys=[];
    polygons.forEach(function(polygon){polygon.forEach(function(ring){var projected=ring.map(function(p){var x=Number(p[0]),lat=Math.max(-85,Math.min(85,Number(p[1]))),y=-Math.log(Math.tan(Math.PI/4+lat*Math.PI/360))*180/Math.PI;xs.push(x);ys.push(y);return[x,y];});rings.push(projected);});});
    if(!xs.length||xs.some(function(n){return!Number.isFinite(n);})||ys.some(function(n){return!Number.isFinite(n);}))return null;
    var left=Math.min.apply(null,xs),top=Math.min.apply(null,ys),width=Math.max.apply(null,xs)-left,height=Math.max.apply(null,ys)-top,scale=56/Math.max(width,height||1),dx=(64-width*scale)/2,dy=(64-height*scale)/2;
    var svg=document.createElementNS('http://www.w3.org/2000/svg','svg');svg.setAttribute('viewBox','0 0 64 64');svg.setAttribute('aria-hidden','true');svg.classList.add('fntm-city-silhouette');
    var shape=document.createElementNS(svg.namespaceURI,'path');shape.setAttribute('d',rings.map(function(r){return r.map(function(p,i){return(i?'L':'M')+((p[0]-left)*scale+dx).toFixed(2)+' '+((p[1]-top)*scale+dy).toFixed(2);}).join('')+'Z';}).join(''));shape.setAttribute('fill-rule','evenodd');svg.appendChild(shape);return svg;
  }
  function popupFor(point, labels) {
    var root = el('div', 'fntm-popup');
    var preview = safeURL(point.preview);
    if (preview) {
      var image = el('img', 'fntm-popup-image');
      image.src = preview; image.alt = ''; image.decoding = 'async';
      image.addEventListener('error', function () { image.remove(); });
      root.appendChild(image);
    }
    var copy = el('div', 'fntm-popup-copy');
    copy.appendChild(el('span', 'fntm-popup-kicker', [point.country, point.year].filter(Boolean).join(' · ')));
    copy.appendChild(el('h3', '', point.title));
    if (point.description && !/^\s*原创演示地点\s*[：:]/.test(point.description)) copy.appendChild(el('p', 'fntm-popup-description', point.description));
    if (point.stories.length) {
      var list = el('ul', 'fntm-popup-stories');
      point.stories.forEach(function (story) {
        var item = el('li'), link = el('a');
        link.href = story.url;
        link.appendChild(el('span', '', story.title)); link.appendChild(icon('arrow'));
        item.appendChild(link); list.appendChild(item);
      });
      copy.appendChild(list);
    } else copy.appendChild(el('p', 'fntm-popup-empty', labels.noPosts || '笔记待续。'));
    root.appendChild(copy);
    return root;
  }
  function regionPolygons(region) {
    return region.geometry.type === 'Polygon' ? [region.geometry.coordinates] : region.geometry.coordinates;
  }
  function inRing(x, y, ring) {
    var inside = false;
    for (var i = 0, j = ring.length - 1; i < ring.length; j = i++) {
      var a = ring[i], b = ring[j];
      if ((a[1] > y) !== (b[1] > y) && x < (b[0] - a[0]) * (y - a[1]) / (b[1] - a[1]) + a[0]) inside = !inside;
    }
    return inside;
  }
  function regionAnchor(region) {
    var polygons = regionPolygons(region), best = null, largest = 0;
    polygons.forEach(function (polygon) {
      var ring = polygon[0], area = 0, x = 0, y = 0;
      for (var i = 0, j = ring.length - 1; i < ring.length; j = i++) {
        var cross = ring[j][0] * ring[i][1] - ring[i][0] * ring[j][1];
        area += cross; x += (ring[j][0] + ring[i][0]) * cross; y += (ring[j][1] + ring[i][1]) * cross;
      }
      if (Math.abs(area) > largest) { largest = Math.abs(area); best = { polygon: polygon, point: [x / (3 * area), y / (3 * area)] }; }
    });
    if (!best) return null;
    function inside(p) { return inRing(p[0], p[1], best.polygon[0]) && !best.polygon.slice(1).some(function (hole) { return inRing(p[0], p[1], hole); }); }
    var box = best.polygon[0].reduce(function (b, p) { return [Math.min(b[0], p[0]), Math.min(b[1], p[1]), Math.max(b[2], p[0]), Math.max(b[3], p[1])]; }, [180, 90, -180, -90]);
    if (!inside(best.point)) {
      var nearest = Infinity, point = best.polygon[0][0];
      for (var row = 1; row < 12; row++) for (var column = 1; column < 12; column++) {
        var candidate = [box[0] + (box[2] - box[0]) * column / 12, box[1] + (box[3] - box[1]) * row / 12];
        var distance = Math.pow(candidate[0] - best.point[0], 2) + Math.pow(candidate[1] - best.point[1], 2);
        if (inside(candidate) && distance < nearest) { nearest = distance; point = candidate; }
      }
      best.point = point;
    }
    return { latlng: [best.point[1], best.point[0]], box: box };
  }
  function regionName(region) {
    if (region.code === 'TW') return '中国台湾';
    if (region.code === 'HK') return '中国香港';
    if (region.code === 'MO') return '中国澳门';
    try { if (window.Intl && Intl.DisplayNames && /^[A-Z]{2}$/.test(region.code)) return new Intl.DisplayNames('zh-CN', { type: 'region' }).of(region.code); } catch (error) {}
    return region.name;
  }
  var atlas = (window.fntmAtlas || []).map(function (region) {
    region.boxes = regionPolygons(region).map(function (polygon) {
      return polygon[0].reduce(function (b, p) { return [Math.min(b[0],p[0]),Math.min(b[1],p[1]),Math.max(b[2],p[0]),Math.max(b[3],p[1])]; }, [180,90,-180,-90]);
    });
    region.names = [region.name.toLocaleLowerCase()];
    if (window.Intl && Intl.DisplayNames && /^[A-Z]{2}$/.test(region.code)) {
      ['zh-CN','en'].forEach(function (locale) { try { region.names.push(new Intl.DisplayNames(locale,{type:'region'}).of(region.code).toLocaleLowerCase()); } catch (e) {} });
    }
    region.labelAnchor = regionAnchor(region); region.label = regionName(region);
    return region;
  });
  function locateRegion(point) {
    for (var r = 0; r < atlas.length; r++) {
      var region = atlas[r], polygons = regionPolygons(region);
      for (var i = 0; i < polygons.length; i++) {
        var b = region.boxes[i];
        if (point.lng < b[0] || point.lng > b[2] || point.lat < b[1] || point.lat > b[3]) continue;
        if (inRing(point.lng,point.lat,polygons[i][0]) && !polygons[i].slice(1).some(function (hole) { return inRing(point.lng,point.lat,hole); })) return region.id;
      }
    }
    // Coastlines are deliberately coarse; use the saved country label only when coordinates miss.
    var name = point.country.toLocaleLowerCase().replace(/^中国(?=台湾|香港|澳门)/,'');
    var named = atlas.find(function (region) { return region.names.indexOf(name) !== -1; });
    return named ? named.id : '';
  }
  function init(wrapper) {
    var node = wrapper.querySelector('.fntm-map'), source = wrapper.querySelector('.fntm-map-data');
    if (!node || !source || wrapper.dataset.mapReady === '1') return;
    var data; try { data = JSON.parse(source.textContent); } catch (e) { return; }
    wrapper.dataset.mapReady = '1';
    var config = window.fntmConfig || {}, settings = data.settings || {}, labels = config.labels || {}, seen = new Set();
    var points = (Array.isArray(data.places) ? data.places : []).filter(function (p) {
      if (!p || !/^\d+$/.test(String(p.id)) || seen.has(String(p.id)) || !Number.isFinite(p.lat) || !Number.isFinite(p.lng) || Math.abs(p.lat)>90 || Math.abs(p.lng)>180) return false;
      seen.add(String(p.id)); return true;
    }).map(function (p) {
      var point = Object.assign({},p,{title:String(p.title||''),country:String(p.country||''),year:String(p.year||''),description:String(p.description||''),stories:(p.stories||[]).filter(function(s){return s&&safeURL(s.url);}).map(function(s){return {title:String(s.title||''),url:safeURL(s.url)};})});
      point.searchText = [point.title,point.country,point.year,point.description].concat(point.stories.map(function(s){return s.title;})).join(' ').toLocaleLowerCase();
      point.region = locateRegion(point); return point;
    }).sort(function(a,b){return b.year.localeCompare(a.year,undefined,{numeric:true})||a.title.localeCompare(b.title);});
    var byId = new Map(points.map(function(p){return [String(p.id),p];}));
    var country = wrapper.querySelector('.fntm-filter-country'), year = wrapper.querySelector('.fntm-filter-year'), search = wrapper.querySelector('.fntm-search'), list = wrapper.querySelector('.fntm-place-list'), count = wrapper.querySelector('.fntm-map-count');
    var status = wrapper.querySelector('.fntm-map-status'), statusText = wrapper.querySelector('.fntm-map-status-text'), retry = wrapper.querySelector('.fntm-tile-retry'), browse = wrapper.querySelector('.fntm-browse-places'), reset = wrapper.querySelector('.fntm-map-reset'), detail = wrapper.querySelector('.fntm-detail-toggle'), regionCount = wrapper.querySelector('.fntm-region-count');
    var map = null, markers = new Map(), shown = new Set(), markerLayer = null, countries = null, cityLayer = null, cityCache = new Map(), citiesShown = new Map(), cards = new Map(), nameLayer = null, focusMarker = null;
    var osmCredit=wrapper.querySelector('[data-map-osm-credit]'),detailCredit=wrapper.querySelector('[data-map-detail-credit]'),mapboxLogo=null,hasBoundaries=points.some(function(p){return !!p.boundary;});
    var visible = points, visibleIds = new Set(), activeId = '', previousActive = '', offset = 0, pageSize = 40, epoch = 0, searchTimer, resizeFrame, boundaryFrame, tileTimer, tiles = null, detailed = false;
    var center = settings.center || config.center || [23,12], zoom = Number(settings.zoom || config.zoom || 2), reduced = !!(window.matchMedia&&window.matchMedia('(prefers-reduced-motion: reduce)').matches);
    var empty = el('div','fntm-map-empty'); empty.hidden=true; node.parentNode.appendChild(empty);
    var detailPanel = el('section', 'fntm-map-detail'); detailPanel.hidden = true; detailPanel.setAttribute('aria-label', '地点详情');
    var detailClose = el('button', 'fntm-detail-close', '×'); detailClose.type = 'button'; detailClose.setAttribute('aria-label', '关闭地点详情');
    var detailBody = el('div', 'fntm-map-detail-body'); detailBody.setAttribute('aria-live', 'polite');
    detailPanel.append(detailClose, detailBody); node.parentNode.appendChild(detailPanel);
    function clearDetail() { detailPanel.hidden = true; detailPanel.removeAttribute('data-place-id'); if (focusMarker && map) map.removeLayer(focusMarker); focusMarker = null; }
    detailClose.addEventListener('click', function () { var card = cards.get(activeId), restore = detailPanel.contains(document.activeElement); setActive('', false); scheduleCities(); if (restore && card) card.querySelector('button').focus({ preventScroll: true }); });
    function state(name,text) { wrapper.dataset.mapState=name;if(status)status.hidden=!text;if(statusText)statusText.textContent=text||'';if(retry)retry.hidden=!tiles;if(browse)browse.hidden=!!map; }
    function setActive(id,scroll) {
      activeId=String(id||'');
      if (!activeId) clearDetail();
      if (previousActive && previousActive!==activeId) {
        var old=cards.get(previousActive),oldMarker=markers.get(previousActive);if(old){old.classList.remove('is-active');old.querySelector('button').setAttribute('aria-pressed','false');}if(oldMarker&&oldMarker.getElement())oldMarker.getElement().classList.remove('is-active');
      }
      if(scroll&&activeId){var index=visible.findIndex(function(p){return String(p.id)===activeId;});if(index>=0&&(index<offset||index>=offset+pageSize)){offset=Math.floor(index/pageSize)*pageSize;renderList();}}
      var card=cards.get(activeId),marker=markers.get(activeId);if(card){card.classList.add('is-active');card.querySelector('button').setAttribute('aria-pressed','true');if(scroll&&card.isConnected&&list){var box=card.getBoundingClientRect(),area=list.getBoundingClientRect();if(box.top<area.top)list.scrollTop-=area.top-box.top;else if(box.bottom>area.bottom)list.scrollTop+=box.bottom-area.bottom;}}if(marker&&marker.getElement())marker.getElement().classList.add('is-active');previousActive=activeId;
    }
    function selectPoint(point) {
      if(!visibleIds.has(String(point.id))){if(search)search.value='';if(country)country.value='';if(year)year.value='';render(false);}
      setActive(point.id,true);wrapper.dataset.view='map';if(!map)return;map.stop();
      var marker=getMarker(point);if(!shown.has(String(point.id))){markerLayer.addLayer(marker);shown.add(String(point.id));}
      // Move smoothly at a consistent regional scale; keep the detail card docked.
      if (focusMarker) map.removeLayer(focusMarker);
      focusMarker = window.L.marker([point.lat, point.lng], { interactive: false, keyboard: false, zIndexOffset: 1000, icon: window.L.divIcon({ className: 'fntm-div-icon fntm-focus-marker', html: '<span class="fntm-pin" aria-hidden="true"></span>', iconSize: [32, 32], iconAnchor: [16, 16] }) });
      focusMarker.bindTooltip(el('span', '', point.title), { permanent: true, direction: 'bottom', offset: [0, 10], className: 'fntm-place-label is-selected', opacity: 1, interactive: false }).addTo(map);
      detailBody.replaceChildren(popupFor(point, labels)); detailPanel.dataset.placeId = String(point.id); detailPanel.hidden = false;
      var targetZoom = Math.max(4, Math.min(map.getZoom(), 6));
      map.flyTo([point.lat, point.lng], targetZoom, { animate: !reduced, duration: .75, easeLinearity: .25 });
      scheduleCities();
    }
    function makeCard(point) {
      var id=String(point.id);if(cards.has(id))return cards.get(id);
      var card=el('article','fntm-place-card');card.dataset.placeId=id;var button=el('button','fntm-place-select');button.type='button';button.setAttribute('aria-label','在地图中查看'+point.title);button.setAttribute('aria-pressed','false');
      var shape=cityShape(point.boundary);if(shape){var thumb=el('span','fntm-place-thumb fntm-city-lit');thumb.appendChild(shape);button.appendChild(thumb);}else button.appendChild(cover(point,'fntm-place-thumb'));
      var copy=el('span','fntm-place-copy');copy.append(el('span','fntm-place-country',point.country||'TRAVEL NOTE'),el('strong','fntm-place-title',point.title),el('span','fntm-place-meta',[point.year,point.stories.length?point.stories.length+' 篇笔记':'笔记待续'].filter(Boolean).join(' · ')));button.appendChild(copy);var arrow=icon('arrow');arrow.setAttribute('class','fntm-place-arrow');button.appendChild(arrow);button.addEventListener('click',function(){selectPoint(point);});card.appendChild(button);
      if(point.stories.length){var link=el('a','fntm-place-story',point.stories[0].title);link.href=point.stories[0].url;card.appendChild(link);}else card.appendChild(el('span','fntm-place-story fntm-place-unlinked',labels.noPosts||'笔记待续。'));cards.set(id,card);return card;
    }
    function noResults(){var box=el('div','fntm-place-empty');box.appendChild(el('p','',points.length?'暂时没有匹配的足迹。':'旅程尚未落笔。'));if(points.length){var clear=el('button','fntm-filter-reset','清除筛选');clear.type='button';clear.addEventListener('click',function(){if(search)search.value='';if(country)country.value='';if(year)year.value='';render(true);});box.appendChild(clear);}return box;}
    function renderList(){
      if(!list)return;var fragment=document.createDocumentFragment();visible.slice(offset,offset+pageSize).forEach(function(p){fragment.appendChild(makeCard(p));});
      if(!visible.length)fragment.appendChild(noResults());
      if(visible.length>pageSize){var pages=el('nav','fntm-index-pages');pages.setAttribute('aria-label','地点分页');[[-pageSize,'上一组'],[pageSize,'下一组']].forEach(function(item){var b=el('button','',item[1]);b.type='button';b.disabled=offset+item[0]<0||offset+item[0]>=visible.length;b.addEventListener('click',function(){offset+=item[0];renderList();list.scrollTop=0;});pages.appendChild(b);});pages.insertBefore(el('small','',(offset+1)+'–'+Math.min(offset+pageSize,visible.length)),pages.lastChild);fragment.appendChild(pages);}
      list.replaceChildren(fragment);
    }
    function getMarker(point){
      var id=String(point.id);if(markers.has(id))return markers.get(id);
      var marker=window.L.marker([point.lat,point.lng],{title:point.title,alt:point.title,icon:window.L.divIcon({className:'fntm-div-icon',html:'<span class="fntm-pin" aria-hidden="true"></span>',iconSize:[32,32],iconAnchor:[16,16],popupAnchor:[0,-10]})});
      marker.bindTooltip(el('span', '', point.title), { permanent: true, direction: 'bottom', offset: [0, 8], className: 'fntm-place-label', opacity: 1, interactive: false });
      marker.on('click',function(){selectPoint(point);});markers.set(id,marker);return marker;
    }
    function syncMarkers(ticket){
      if(!map||!markerLayer)return;var remove=[];shown.forEach(function(id){if(!visibleIds.has(id)){remove.push(markers.get(id));shown.delete(id);}});if(markerLayer.removeLayers)markerLayer.removeLayers(remove);else remove.forEach(function(m){markerLayer.removeLayer(m);});
      var queue=visible.filter(function(p){return !shown.has(String(p.id));}),index=0;
      function chunk(){if(ticket!==epoch)return;var until=performance.now()+6,batch=[];do{if(index>=queue.length)break;var point=queue[index++],id=String(point.id);batch.push(getMarker(point));shown.add(id);}while(performance.now()<until);if(markerLayer.addLayers)markerLayer.addLayers(batch);else batch.forEach(function(m){markerLayer.addLayer(m);});if(index<queue.length)window.setTimeout(chunk,8);else wrapper.dataset.markersReady='1';}
      wrapper.dataset.markersReady='0';if(queue.length)chunk();else wrapper.dataset.markersReady='1';
    }
    function regionStyle(feature){var visited=visitedRegions.has(feature.properties.id);return {color:visited?'#a59d89':'#d9dadd',weight:visited?.8:.55,fillColor:visited?'#bcb39d':'#eeeeef',fillOpacity:detailed?(visited?.24:0):1,opacity:detailed?(visited?.65:.15):1,interactive:false};}
    var visitedRegions=new Set();
    function paintRegions(){visitedRegions=new Set(visible.map(function(p){return p.region;}).filter(Boolean));wrapper.dataset.visitedRegions=Array.from(visitedRegions).join(',');if(regionCount)regionCount.textContent=visitedRegions.size?visitedRegions.size+' 个区域':'';if(countries)countries.setStyle(regionStyle);}
    function updateRegionNames() {
      if (!map || !nameLayer) return;
      nameLayer.clearLayers(); if (detailed) return;
      var size = map.getSize(), rect = node.getBoundingClientRect(), occupied = [];
      node.querySelectorAll('.leaflet-marker-icon:not(.fntm-region-label),.fntm-place-label').forEach(function (element) {
        var b = element.getBoundingClientRect(); if (b.width && b.height) occupied.push([b.left - rect.left - 4, b.top - rect.top - 4, b.right - rect.left + 4, b.bottom - rect.top + 4]);
      });
      var candidates = atlas.filter(function (r) { return r.labelAnchor && r.label; }).map(function (region) {
        var b = region.labelAnchor.box, a = map.latLngToContainerPoint([b[1], b[0]]), z = map.latLngToContainerPoint([b[3], b[2]]);
        return { region: region, area: Math.abs((z.x - a.x) * (z.y - a.y)), width: Math.abs(z.x - a.x), visited: visitedRegions.has(region.id) };
      }).sort(function (a, b) { return Number(b.visited) - Number(a.visited) || b.area - a.area; });
      candidates.forEach(function (item) {
        var region = item.region, width = Math.max(28, Array.from(region.label).length * 11 + 8), height = 18;
        if (!item.visited && (item.area < width * height * 1.3 || item.width < width)) return;
        var point = map.latLngToContainerPoint(region.labelAnchor.latlng), placement = null;
        var offsets = item.visited ? [[0, 0], [0, 26], [0, -26], [-30, 0], [30, 0]] : [[0, 0]];
        offsets.some(function (offset) {
          var x = point.x + offset[0], y = point.y + offset[1], box = [x - width / 2 - 3, y - height / 2 - 2, x + width / 2 + 3, y + height / 2 + 2];
          if (box[0] < 10 || box[1] < 10 || box[2] > size.x - 10 || box[3] > size.y - 10 || occupied.some(function (b) { return box[0] < b[2] && box[2] > b[0] && box[1] < b[3] && box[3] > b[1]; })) return false;
          occupied.push(box); placement = map.containerPointToLatLng([x, y]); return true;
        });
        if (placement) window.L.marker(placement, { pane: 'fntm-names', interactive: false, keyboard: false, icon: window.L.divIcon({ className: 'fntm-region-label' + (item.visited ? ' is-visited' : ''), html: el('span', '', region.label), iconSize: [width, height], iconAnchor: [width / 2, height / 2] }) }).addTo(nameLayer);
      });
    }
    function updateCities(){
      markers.forEach(function (marker, id) { if (marker.getElement()) { if (id === activeId) marker.closeTooltip(); else marker.openTooltip(); } });
      updateRegionNames();
      if(!map||!cityLayer)return;var next=new Map();if(map.getZoom()>=4){var viewport=map.getBounds().pad(.15);visible.filter(function(p){return p.boundary&&viewport.contains([p.lat,p.lng]);}).slice(0,32).forEach(function(p){var id=String(p.id),layer=cityCache.get(id);if(!layer){layer=window.L.geoJSON(p.boundary,{interactive:false,pane:'fntm-cities',style:{color:'#91815e',weight:1,fillColor:'#b9a883',fillOpacity:.48}});cityCache.set(id,layer);}next.set(id,layer);if(!citiesShown.has(id))cityLayer.addLayer(layer);});}citiesShown.forEach(function(layer,id){if(!next.has(id))cityLayer.removeLayer(layer);});citiesShown=next;wrapper.dataset.visibleCities=String(next.size);
    }
    function scheduleCities(){if(boundaryFrame)cancelAnimationFrame(boundaryFrame);boundaryFrame=requestAnimationFrame(updateCities);}
    function fit(force){if(!map||(!force&&settings.fitBounds===false))return;map.stop();if(visible.length>1)map.fitBounds(visible.map(function(p){return [p.lat,p.lng];}),{padding:[42,42],maxZoom:6,animate:false});else if(visible.length===1)map.setView([visible[0].lat,visible[0].lng],Math.max(zoom,7),{animate:false});else map.setView(center,zoom,{animate:false});}
    function render(shouldFit){
      ++epoch;offset=0;setActive('',false);if(map)map.closePopup();var c=country?country.value:'',y=year?year.value:'',q=search?search.value.trim().toLocaleLowerCase():'';
      visible=points.filter(function(p){return (!c||p.country===c)&&(!y||p.year===y)&&(!q||p.searchText.indexOf(q)!==-1);});visibleIds=new Set(visible.map(function(p){return String(p.id);}));renderList();paintRegions();if(count)count.textContent=visible.length+' / '+points.length+' 处地点';empty.hidden=visible.length>0;empty.replaceChildren();if(!visible.length)empty.appendChild(noResults());syncMarkers(epoch);if(shouldFit)fit(false);scheduleCities();
    }
    function creditLink(text,url){var link=el('a','',text);link.href=url;link.target='_blank';link.rel='noopener noreferrer';return link;}
    function updateAttribution(){
      if(osmCredit)osmCredit.hidden=!hasBoundaries&&!detailed;
      if(detailCredit){detailCredit.replaceChildren();detailCredit.hidden=!detailed;if(detailed){if(config.token){detailCredit.append(creditLink('© Mapbox','https://www.mapbox.com/about/maps/'),creditLink('Improve this map','https://apps.mapbox.com/feedback/'));}else detailCredit.appendChild(creditLink('© CARTO','https://carto.com/attributions'));}}
      if(detailed&&config.token&&!mapboxLogo){mapboxLogo=creditLink('','https://www.mapbox.com/about/maps/');mapboxLogo.className='fntm-mapbox-logo';var image=el('img');image.src=safeURL(config.logoUrl);image.alt='Mapbox';image.width=88;image.height=23;image.decoding='async';mapboxLogo.appendChild(image);node.parentNode.appendChild(mapboxLogo);}
      if(mapboxLogo)mapboxLogo.hidden=!detailed;
    }
    function setDetail(enabled){
      detailed=enabled;if(detail)detail.setAttribute('aria-pressed',String(enabled));wrapper.classList.toggle('is-detailed',enabled);if(countries)countries.setStyle(regionStyle);clearTimeout(tileTimer);updateAttribution();
      scheduleCities();
      if(!map)return;if(!enabled){if(tiles)map.removeLayer(tiles);state('ready','');return;}
      if(!tiles){var url,options={maxZoom:19,updateWhenIdle:true,keepBuffer:1,className:'fntm-tile-layer'};if(config.token){var style=String(config.style||'mapbox/light-v11').replace(/[^A-Za-z0-9_/-]/g,'');url='https://api.mapbox.com/styles/v1/'+style+'/tiles/256/{z}/{x}/{y}?access_token='+encodeURIComponent(config.token);}else{url='https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}.png';options.subdomains='abcd';}tiles=window.L.tileLayer(url,options);tiles.on('tileload',function(){if(detailed){clearTimeout(tileTimer);state('ready','');}});}
      state('loading','详细底图正在展开…');tiles.addTo(map);tileTimer=setTimeout(function(){setDetail(false);state('ready','详细底图暂不可用，足迹地图仍可浏览。');},8000);
    }
    function addOptions(select,values,reverse){if(!select)return;values.sort(function(a,b){return a.localeCompare(b,undefined,{numeric:true});});if(reverse)values.reverse();values.forEach(function(v){var option=el('option','',v);option.value=v;select.appendChild(option);});}
    addOptions(country,Array.from(new Set(points.map(function(p){return p.country;}).filter(Boolean))),false);addOptions(year,Array.from(new Set(points.map(function(p){return p.year;}).filter(Boolean))),true);
    try{if(window.L){
      map=window.L.map(node,{scrollWheelZoom:false,worldCopyJump:true,zoomControl:false,attributionControl:false,minZoom:1,maxZoom:19,preferCanvas:true,zoomAnimation:!reduced,fadeAnimation:false}).setView(center,zoom);window.L.control.zoom({position:'topright'}).addTo(map);
      var atlasPane=map.createPane('fntm-atlas');atlasPane.style.zIndex='250';atlasPane.style.pointerEvents='none';map.createPane('fntm-cities').style.zIndex='410';
      var namesPane = map.createPane('fntm-names'); namesPane.style.zIndex = '450'; namesPane.style.pointerEvents = 'none'; nameLayer = window.L.layerGroup().addTo(map);
      if(atlas.length){countries=window.L.geoJSON({type:'FeatureCollection',features:atlas.map(function(r){return {type:'Feature',properties:{id:r.id},geometry:r.geometry};})},{pane:'fntm-atlas',interactive:false,smoothFactor:1.5,style:regionStyle}).addTo(map);}
      cityLayer=window.L.layerGroup().addTo(map);
      markerLayer=settings.cluster!==false&&window.L.markerClusterGroup?window.L.markerClusterGroup({maxClusterRadius:Number(config.clusterRadius||55),showCoverageOnHover:false,spiderfyOnMaxZoom:true,animate:!reduced,animateAddingMarkers:false,removeOutsideVisibleBounds:true}):window.L.layerGroup();markerLayer.addTo(map);map.on('moveend zoomend',scheduleCities);state('ready','');
    }else state('unavailable','地图暂时未能展开，足迹索引仍可浏览。');}catch(e){if(map)map.remove();map=null;markerLayer=null;state('unavailable','地图暂时未能展开，足迹索引仍可浏览。');}
    if(country)country.addEventListener('change',function(){clearTimeout(searchTimer);render(true);});if(year)year.addEventListener('change',function(){clearTimeout(searchTimer);render(true);});if(search){search.addEventListener('input',function(){clearTimeout(searchTimer);searchTimer=setTimeout(function(){render(false);},120);});search.addEventListener('keydown',function(e){if(e.key==='Escape'){search.value='';clearTimeout(searchTimer);render(true);}});}
    if(reset)reset.addEventListener('click',function(){setActive('',false);if(map)map.closePopup();fit(true);});if(detail)detail.addEventListener('click',function(){setDetail(!detailed);});if(retry)retry.addEventListener('click',function(){setDetail(true);});if(browse)browse.addEventListener('click',function(){wrapper.dataset.view='list';});
    var size='';function resize(){if(resizeFrame)cancelAnimationFrame(resizeFrame);resizeFrame=requestAnimationFrame(function(){var next=node.clientWidth+'x'+node.clientHeight;if(map&&next!==size){size=next;map.invalidateSize({pan:false});}});}if(map&&window.ResizeObserver)new ResizeObserver(resize).observe(node);else window.addEventListener('resize',resize,{passive:true});
    render(true);resize();function openLinked(){var match=/^#place-(\d+)$/.exec(location.hash),point=match&&byId.get(match[1]);if(point)selectPoint(point);}window.addEventListener('hashchange',openLinked);openLinked();
    window.addEventListener('pagehide',function(){epoch++;clearTimeout(searchTimer);clearTimeout(tileTimer);cancelAnimationFrame(resizeFrame);cancelAnimationFrame(boundaryFrame);});
  }
  function start(){document.querySelectorAll('.fntm-map-wrap').forEach(init);}
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',start);else start();
}());
