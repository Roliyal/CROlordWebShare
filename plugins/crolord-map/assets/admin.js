(function () {
  'use strict';
  function one(root, selector) { return root.querySelector(selector); }
  function setStatus(box, message) {
    var status = one(box, '.fntm-geocode-status');
    if (status) status.textContent = message || '';
  }
  function placeFromFeature(feature) {
    var coords = feature && feature.center ? feature.center : [0, 0];
    var context = feature && Array.isArray(feature.context) ? feature.context : [];
    var country = '';
    var code = '';
    context.forEach(function (item) {
      if (item.id && item.id.indexOf('country.') === 0) {
        country = item.text || '';
        code = item.short_code || '';
      }
    });
    return {
      title: feature.text || feature.place_name || '',
      lat: Number(coords[1]),
      lng: Number(coords[0]),
      country: country,
      country_code: code ? code.slice(-2).toUpperCase() : ''
    };
  }
  function readPlaces(input) {
    try { return JSON.parse(input.value || '[]'); } catch (e) { return []; }
  }
  function writePlaces(input, places) {
    input.value = JSON.stringify(places);
    input.dispatchEvent(new Event('change', { bubbles: true }));
  }
  function renderSelected(box, places, hidden) {
    var list = one(box, '.fntm-selected-places');
    if (!list) return;
    list.innerHTML = '';
    places.forEach(function (place, index) {
      var li = document.createElement('li');
      li.className = 'fntm-selected-place';
      var label = document.createElement('span');
      label.textContent = place.title + (place.country ? ' · ' + place.country : '');
      var remove = document.createElement('button');
      remove.type = 'button';
      remove.className = 'button-link-delete';
      remove.textContent = (window.fntmAdmin && fntmAdmin.remove) || '移除';
      remove.addEventListener('click', function () {
        places.splice(index, 1);
        writePlaces(hidden, places);
        renderSelected(box, places, hidden);
      });
      li.appendChild(label);
      li.appendChild(remove);
      list.appendChild(li);
    });
  }
  function addSearchResults(box, features, target) {
    var results = one(box, '.fntm-geocode-results');
    if (!results) return;
    results.innerHTML = '';
    if (!features.length) {
      results.textContent = fntmAdmin.noResults;
      return;
    }
    features.forEach(function (feature) {
      var place = placeFromFeature(feature);
      var button = document.createElement('button');
      button.type = 'button';
      button.className = 'button-link fntm-geocode-result';
      button.textContent = feature.place_name || place.title;
      button.addEventListener('click', function () {
        if (target === 'place') {
          var title = document.getElementById('title');
          if (title) title.value = place.title;
          var lat = box.querySelector('[name="fntm_lat"]');
          var lng = box.querySelector('[name="fntm_lng"]');
          var country = box.querySelector('[name="fntm_country"]');
          var code = box.querySelector('[name="fntm_country_code"]');
          if (lat) lat.value = place.lat;
          if (lng) lng.value = place.lng;
          if (country) country.value = place.country;
          if (code) code.value = place.country_code;
          results.innerHTML = '';
          setStatus(box, '已填入地点坐标');
          return;
        }
        var hidden = one(box, '[name="fntm_post_places"]');
        if (!hidden) return;
        var places = readPlaces(hidden);
        var duplicate = places.some(function (item) {
          return Math.abs(Number(item.lat) - place.lat) < 0.00001 && Math.abs(Number(item.lng) - place.lng) < 0.00001;
        });
        if (!duplicate) places.push(place);
        writePlaces(hidden, places);
        renderSelected(box, places, hidden);
        results.innerHTML = '';
        setStatus(box, '地点已添加，请保存文章');
      });
      results.appendChild(button);
    });
  }
  function search(box) {
    var input = one(box, '#fntm-place-search') || one(box, '#fntm-post-place-search');
    if (!input || !input.value.trim()) return;
    if (!window.fntmAdmin || !fntmAdmin.token) {
      setStatus(box, fntmAdmin ? fntmAdmin.noToken : '请先配置 Mapbox 令牌。');
      return;
    }
    setStatus(box, fntmAdmin.searching);
    var url = 'https://api.mapbox.com/geocoding/v5/mapbox.places/' +
      encodeURIComponent(input.value.trim()) +
      '.json?autocomplete=true&limit=6&language=zh,en&access_token=' +
      encodeURIComponent(fntmAdmin.token);
    fetch(url, { credentials: 'omit' })
      .then(function (response) {
        if (!response.ok) throw new Error('request failed');
        return response.json();
      })
      .then(function (data) {
        setStatus(box, '');
        addSearchResults(box, Array.isArray(data.features) ? data.features : [], box.querySelector('[name="fntm_post_places"]') ? 'post' : 'place');
      })
      .catch(function () { setStatus(box, fntmAdmin.searchError); });
  }
  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.fntm-admin-fields').forEach(function (box) {
      var hidden = one(box, '[name="fntm_post_places"]');
      if (hidden) renderSelected(box, readPlaces(hidden), hidden);
      var button = one(box, '.fntm-geocode');
      var input = one(box, '#fntm-place-search') || one(box, '#fntm-post-place-search');
      if (button) button.addEventListener('click', function () { search(box); });
      if (input) input.addEventListener('keydown', function (event) {
        if (event.key === 'Enter') { event.preventDefault(); search(box); }
      });
    });
    document.querySelectorAll('.fntm-media').forEach(function (button) {
      button.addEventListener('click', function (event) {
        event.preventDefault();
        if (!window.wp || !wp.media) return;
        var frame = wp.media({ title: '选择地点预览图', button: { text: '使用图片' }, multiple: false });
        frame.on('select', function () {
          var selected = frame.state().get('selection').first().toJSON();
          var input = button.parentNode.querySelector('.fntm-preview-url');
          if (input && selected.url) input.value = selected.url;
        });
        frame.open();
      });
    });
  });
}());
