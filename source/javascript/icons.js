(function () {
  'use strict';
  if (window.unraidIconsLoaded) return;
  window.unraidIconsLoaded = true;

  var API = '/plugins/unraid-icons/include/api.php';
  var PUBLIC = '/plugins/unraid-icons/icons/';
  var STORAGE = 'file:///boot/config/plugins/unraid-icons/icons/';
  var PAGE_SIZE = 40;
  var icons = [];
  var librarySequence = 0;
  var catalogs = Object.create(null);
  var catalogRequests = Object.create(null);
  var iconifyPreviewCache = Object.create(null);
  var PROVIDERS = {
    selfhst: {name: 'selfh.st', url: 'https://selfh.st/icons/'},
    dashboard: {name: 'Dashboard Icons', url: 'https://github.com/homarr-labs/dashboard-icons'},
    iconify: {name: 'Iconify', url: 'https://iconify.design/'}
  };
  var ICONIFY_COLLECTIONS = {tabler: 'Tabler Icons', mdi: 'Material Design Icons', ph: 'Phosphor'};
  var picker = null;
  var settings = null;

  function node(tag, className, text) {
    var result = document.createElement(tag);
    if (className) result.className = className;
    if (text !== undefined) result.textContent = text;
    return result;
  }

  function button(text, handler, className) {
    var result = node('button', className, text);
    result.type = 'button';
    if (handler) result.addEventListener('click', handler);
    return result;
  }

  function message(target, text) {
    target.textContent = text || '';
    target.hidden = !text;
  }

  function errorText(error) {
    return error instanceof Error ? error.message : 'An unexpected error occurred.';
  }

  async function api(action, values) {
    var body = new URLSearchParams(values || {});
    body.set('action', action);
    // The field supports older Unraid; the header survives PHP post_max_size rejection.
    var headers = {Accept: 'application/json'};
    var token = typeof csrf_token !== 'undefined' ? csrf_token : window.csrf_token;
    if (typeof token === 'string') {
      body.set('csrf_token', token);
      headers['X-CSRF-Token'] = token;
    }
    var response;
    try {
      response = await fetch(API, {method: 'POST', body: body, credentials: 'same-origin', headers: headers});
    } catch (error) {
      throw new Error('Unable to reach the icon service. Check your connection and try again.');
    }
    var data;
    try {
      data = await response.json();
    } catch (error) {
      throw new Error('The icon service returned an invalid response (HTTP ' + response.status + '). Your session may have expired; reload the page.');
    }
    if (!response.ok || !data || data.ok !== true) {
      throw new Error(data && typeof data.error === 'string' ? data.error : 'Icon request failed (HTTP ' + response.status + ').');
    }
    return data;
  }

  async function loadLibrary() {
    var sequence = ++librarySequence;
    var data = await api('library');
    if (!Array.isArray(data.icons)) throw new Error('The icon service returned an invalid library.');
    if (sequence === librarySequence) {
      icons = data.icons;
      var names = new Map(icons.map(function (record) { return [record.filename, record.name]; }));
      document.querySelectorAll('#template_img_chooser .template_img_chooser_inner img[basename]').forEach(function (image) {
        var name = names.get(image.getAttribute('basename'));
        var label = image.parentElement.querySelector('p');
        if (name && label) label.textContent = name;
      });
      if (settings) settings.render();
      if (picker) picker.renderLocal();
    }
    return data.icons;
  }

  function catalogKey(provider, collection) {
    return provider === 'iconify' ? provider + ':' + collection : provider;
  }

  async function loadCatalog(provider, collection) {
    var key = catalogKey(provider, collection);
    if (catalogs[key]) return catalogs[key];
    if (!catalogRequests[key]) {
      var values = {provider: provider};
      if (provider === 'iconify') values.collection = collection;
      catalogRequests[key] = api('catalog', values).then(function (data) {
        if (!Array.isArray(data.icons)) throw new Error('The icon service returned an invalid ' + PROVIDERS[provider].name + ' catalog.');
        catalogs[key] = data;
        return data;
      }).finally(function () { delete catalogRequests[key]; });
    }
    return catalogRequests[key];
  }

  function thumbnail(url, name) {
    var wrap = node('div', 'ui-icons-thumbnail');
    var image = node('img');
    image.alt = '';
    image.loading = 'lazy';
    image.decoding = 'async';
    image.referrerPolicy = 'no-referrer';
    // The API supplies only local library URLs or public HTTP(S) catalog images.
    if (typeof url === 'string' && (url.indexOf(PUBLIC) === 0 || /^https?:\/\//i.test(url))) image.src = url;
    image.addEventListener('error', function () {
      wrap.replaceChildren(node('span', 'ui-icons-image-error', 'Preview unavailable'));
    }, {once: true});
    wrap.appendChild(image);
    return wrap;
  }

  function matches(record, query) {
    return ((record.name || '') + ' ' + (record.slug || '') + ' ' + (record.iconId || '') + ' ' + (record.filename || '')).toLowerCase().includes(query);
  }

  function card(record, imageURL) {
    var result = node('article', 'ui-icons-card');
    result.append(thumbnail(imageURL, record.name), node('h3', 'ui-icons-name', record.name || record.slug || record.filename));
    return result;
  }

  function creditLink(label, url) {
    var text = typeof label === 'string' ? label : '';
    try {
      var parsed = new URL(url);
      if (parsed.protocol === 'https:' && !parsed.username && !parsed.password) {
        var link = node('a', '', text);
        link.href = parsed.href;
        link.target = '_blank';
        link.rel = 'noopener noreferrer';
        return link;
      }
    } catch (error) { /* Invalid metadata stays plain text. */ }
    return document.createTextNode(text);
  }

  function attributionNotice(attribution) {
    var notice = node('p', 'ui-icons-attribution');
    if (!attribution || typeof attribution !== 'object') return notice;
    if (typeof attribution.collection === 'string') notice.appendChild(document.createTextNode(attribution.collection));
    if (attribution.author && typeof attribution.author.name === 'string') {
      notice.append(document.createTextNode(' · '), creditLink(attribution.author.name, attribution.author.url));
    }
    if (attribution.license && typeof attribution.license.title === 'string') {
      notice.append(document.createTextNode(' · '), creditLink(attribution.license.title, attribution.license.url));
    }
    return notice;
  }

  function libraryCredit(record) {
    if (record.provider === 'iconify' && record.attribution && ICONIFY_COLLECTIONS[record.collection]) {
      return {
        key: 'iconify:' + record.collection,
        label: record.attribution.collection || ICONIFY_COLLECTIONS[record.collection],
        attribution: record.attribution,
        url: 'https://icon-sets.iconify.design/' + record.collection + '/'
      };
    }
    var source = typeof record.source === 'string' ? record.source : '';
    if (source.startsWith('https://raw.githubusercontent.com/selfhst/icons/main/png/')) {
      return {key: 'selfhst', label: PROVIDERS.selfhst.name, url: PROVIDERS.selfhst.url,
        license: 'https://github.com/selfhst/icons/blob/main/LICENSE'};
    }
    if (source.startsWith('https://raw.githubusercontent.com/homarr-labs/dashboard-icons/main/png/')) {
      return {key: 'dashboard', label: PROVIDERS.dashboard.name, url: PROVIDERS.dashboard.url,
        license: 'https://github.com/homarr-labs/dashboard-icons/blob/main/LICENSE'};
    }
    return null;
  }

  function iconifyURL(iconId, color, size) {
    if (typeof iconId !== 'string' || !/^(tabler|mdi|ph):[a-z0-9]+(?:-[a-z0-9]+)*$/.test(iconId) || iconId.slice(iconId.indexOf(':') + 1).length > 160) {
      throw new Error('This Iconify identity is invalid. Refresh the catalog and try again.');
    }
    if (!/^#[a-f0-9]{6}$/i.test(color)) throw new Error('Choose a six-digit icon color.');
    return 'https://api.iconify.design/' + iconId.replace(':', '/') + '.svg?color=' + encodeURIComponent(color) + '&height=' + size + '&width=' + size;
  }

  function validatedIconifySvg(source, color) {
    function invalid() { throw new Error('Iconify returned an unsafe or unsupported SVG. Try another icon.'); }
    if (!source || /<!DOCTYPE|<!ENTITY|<\?/i.test(source)) invalid();
    var documentSvg = new DOMParser().parseFromString(source, 'image/svg+xml');
    var root = documentSvg.documentElement;
    if (documentSvg.querySelector('parsererror') || root.localName !== 'svg' || root.namespaceURI !== 'http://www.w3.org/2000/svg') invalid();
    var elements = root.querySelectorAll('*');
    if (elements.length > 10000) invalid();
    var allowedElements = new Set(['svg', 'g', 'path', 'rect', 'circle', 'ellipse', 'line', 'polyline', 'polygon', 'defs', 'clipPath', 'mask', 'linearGradient', 'radialGradient', 'stop', 'title', 'desc', 'use']);
    var allowedAttributes = new Set(['xmlns', 'xmlns:xlink', 'id', 'class', 'viewBox', 'width', 'height', 'x', 'y', 'x1', 'x2', 'y1', 'y2', 'cx', 'cy', 'r', 'rx', 'ry', 'd', 'points', 'transform', 'fill', 'fill-rule', 'fill-opacity', 'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin', 'stroke-miterlimit', 'stroke-dasharray', 'stroke-dashoffset', 'stroke-opacity', 'opacity', 'color', 'clip-path', 'clip-rule', 'clipPathUnits', 'mask', 'maskUnits', 'maskContentUnits', 'gradientUnits', 'gradientTransform', 'spreadMethod', 'offset', 'stop-color', 'stop-opacity', 'href', 'xlink:href', 'preserveAspectRatio', 'vector-effect', 'focusable', 'aria-hidden', 'role']);
    [root].concat(Array.from(elements)).forEach(function (element) {
      if (element.namespaceURI !== root.namespaceURI || !allowedElements.has(element.localName) || (element !== root && element.localName === 'svg')) invalid();
      Array.from(element.attributes).forEach(function (attribute) {
        var name = attribute.name;
        var value = attribute.value;
        if (!allowedAttributes.has(name) || value.length > 131072 || /[\\<>\u0000-\u001f\u007f]/.test(value)) invalid();
        if (attribute.namespaceURI && attribute.namespaceURI !== 'http://www.w3.org/2000/xmlns/' && !(name === 'xlink:href' && attribute.namespaceURI === 'http://www.w3.org/1999/xlink')) invalid();
        if (name === 'xmlns' && value !== root.namespaceURI) invalid();
        if (name === 'xmlns:xlink' && value !== 'http://www.w3.org/1999/xlink') invalid();
        if ((name === 'href' || name === 'xlink:href') && !/^#[a-zA-Z_][\w:.-]*$/.test(value)) invalid();
        if (/url\s*\(/i.test(value) && !/^url\(#[a-zA-Z_][\w:.-]*\)$/.test(value)) invalid();
        if (/javascript:|data:|https?:|@import|expression\s*\(/i.test(value) && name !== 'xmlns' && name !== 'xmlns:xlink') invalid();
        if (name === 'width' || name === 'height') {
          if (!/^(?:\d+(?:\.\d+)?|\.\d+)(?:px|%)?$/.test(value)) invalid();
          var dimension = parseFloat(value);
          if (dimension <= 0 || dimension > 4096 || (value.endsWith('%') && dimension > 100)) invalid();
        }
      });
      Array.from(element.childNodes).forEach(function (child) {
        if (child.nodeType !== 1 && child.nodeType !== 3 && child.nodeType !== 8) invalid();
        if (child.nodeType === 3 && child.textContent.trim() && element.localName !== 'title' && element.localName !== 'desc') invalid();
      });
    });
    var viewBox = (root.getAttribute('viewBox') || '').trim().split(/[\s,]+/).map(Number);
    if (viewBox.length !== 4 || !viewBox.every(Number.isFinite) || viewBox.some(function (value) { return Math.abs(value) > 4096; }) || viewBox[2] <= 0 || viewBox[3] <= 0) invalid();
    root.setAttribute('width', '512');
    root.setAttribute('height', '512');
    root.setAttribute('preserveAspectRatio', 'xMidYMid meet');
    root.setAttribute('color', color);
    return new XMLSerializer().serializeToString(root);
  }

  async function iconifyPng(iconId, color, progress) {
    var url = iconifyURL(iconId, color, 512);
    var controller = new AbortController();
    var timeout = setTimeout(function () { controller.abort(); }, 20000);
    var source;
    try {
      var response = await fetch(url, {signal: controller.signal, credentials: 'omit', mode: 'cors', redirect: 'error', referrerPolicy: 'no-referrer'});
      if (!response.ok) throw new Error('Iconify could not download this icon (HTTP ' + response.status + '). Try again.');
      if (Number(response.headers.get('Content-Length')) > 1048576) throw new Error('This Iconify SVG exceeds the 1 MiB safety limit.');
      if (!response.body) throw new Error('Your browser cannot securely read this SVG. Use a current browser and try again.');
      var reader = response.body.getReader();
      var chunks = [];
      var bytes = 0;
      try {
        while (true) {
          var part = await reader.read();
          if (part.done) break;
          bytes += part.value.byteLength;
          if (bytes > 1048576) throw new Error('This Iconify SVG exceeds the 1 MiB safety limit.');
          chunks.push(part.value);
        }
      } finally {
        await reader.cancel().catch(function () {});
      }
      source = await new Blob(chunks).text();
    } catch (error) {
      if (error.name === 'AbortError') throw new Error('Iconify download timed out. Check your connection and try again.');
      if (error instanceof TypeError) throw new Error('Unable to download from Iconify. Check your connection or content blocker and try again.');
      throw error;
    } finally {
      clearTimeout(timeout);
    }
    progress('Converting… Rendering a transparent 512 × 512 PNG in your browser.');
    var svg = validatedIconifySvg(source, color);
    var objectURL = URL.createObjectURL(new Blob([svg], {type: 'image/svg+xml'}));
    var image = new Image();
    var imageTimeout;
    try {
      await new Promise(function (resolve, reject) {
        image.onload = resolve;
        image.onerror = function () { reject(new Error('Your browser could not render this Iconify SVG. Try another icon.')); };
        imageTimeout = setTimeout(function () { reject(new Error('SVG conversion timed out. Try another icon.')); }, 15000);
        image.src = objectURL;
      });
      var canvas = document.createElement('canvas');
      canvas.width = 512;
      canvas.height = 512;
      var context = canvas.getContext('2d');
      if (!context) throw new Error('Your browser does not support canvas conversion. Use a current browser.');
      context.clearRect(0, 0, 512, 512);
      context.drawImage(image, 0, 0, 512, 512);
      return await new Promise(function (resolve, reject) {
        canvas.toBlob(function (blob) {
          if (blob && blob.type === 'image/png' && blob.size <= 8388608) resolve(blob);
          else reject(new Error('The browser could not create a valid PNG. Try another icon.'));
        }, 'image/png');
      });
    } finally {
      clearTimeout(imageTimeout);
      image.onload = null;
      image.onerror = null;
      image.src = '';
      URL.revokeObjectURL(objectURL);
    }
  }

  function iconifyPreviewSvg(data, icon) {
    if (!icon || typeof icon.body !== 'string' || icon.body.length > 1048576) throw new Error('Iconify returned invalid preview data.');
    function property(name, fallback) {
      return icon[name] !== undefined ? icon[name] : data[name] !== undefined ? data[name] : fallback;
    }
    var width = property('width', 16);
    var height = property('height', 16);
    var left = property('left', 0);
    var top = property('top', 0);
    var rotate = property('rotate', 0);
    var hFlip = property('hFlip', false);
    var vFlip = property('vFlip', false);
    if (![width, height, left, top].every(function (value) { return typeof value === 'number' && Number.isFinite(value) && Math.abs(value) <= 4096; }) ||
      width <= 0 || height <= 0 || !Number.isInteger(rotate) || Math.abs(rotate) > 3 || typeof hFlip !== 'boolean' || typeof vFlip !== 'boolean') {
      throw new Error('Iconify returned unsupported preview dimensions.');
    }
    var centerX = left + width / 2;
    var centerY = top + height / 2;
    var rotated = Math.abs(rotate) % 2 === 1;
    var boxWidth = rotated ? height : width;
    var boxHeight = rotated ? width : height;
    var viewBox = [centerX - boxWidth / 2, centerY - boxHeight / 2, boxWidth, boxHeight].join(' ');
    var transform = 'translate(' + centerX + ' ' + centerY + ') rotate(' + (rotate * 90) + ') scale(' + (hFlip ? -1 : 1) + ' ' + (vFlip ? -1 : 1) + ') translate(' + (-centerX) + ' ' + (-centerY) + ')';
    return validatedIconifySvg('<svg xmlns="http://www.w3.org/2000/svg" width="' + boxWidth + '" height="' + boxHeight + '" viewBox="' + viewBox + '"><g transform="' + transform + '">' + icon.body + '</g></svg>', '#000000');
  }

  async function loadIconifyPreviews(collection, names, signal) {
    if (!Object.prototype.hasOwnProperty.call(ICONIFY_COLLECTIONS, collection) || !names.length || names.length > PAGE_SIZE ||
      names.some(function (name) { return !/^[a-z0-9]+(?:-[a-z0-9]+)*$/.test(name) || name.length > 160; })) {
      throw new Error('The Iconify preview identities are invalid. Refresh the catalog.');
    }
    var response = await fetch('https://api.iconify.design/' + collection + '.json?icons=' + encodeURIComponent(names.join(',')), {
      signal: signal, credentials: 'omit', mode: 'cors', redirect: 'error', referrerPolicy: 'no-referrer'
    });
    if (!response.ok) {
      var retryAfter = response.headers.get('Retry-After');
      throw new Error('Iconify previews are unavailable (HTTP ' + response.status + ').' +
        (response.status === 429 ? ' The service is rate-limiting requests; ' + (/^\d+$/.test(retryAfter || '') ? 'wait ' + retryAfter + ' seconds before retrying.' : 'wait before retrying.') : ' Try again.'));
    }
    if (!response.body || Number(response.headers.get('Content-Length')) > 4194304) throw new Error('Iconify preview data exceeds the browser safety limit.');
    var reader = response.body.getReader();
    var chunks = [];
    var size = 0;
    try {
      while (true) {
        var part = await reader.read();
        if (part.done) break;
        size += part.value.byteLength;
        if (size > 4194304) throw new Error('Iconify preview data exceeds the 4 MiB safety limit.');
        chunks.push(part.value);
      }
    } finally {
      await reader.cancel().catch(function () {});
    }
    var data;
    try {
      data = JSON.parse(await new Blob(chunks).text());
    } catch (error) {
      throw new Error('Iconify returned invalid preview JSON. Try again.');
    }
    if (!data || data.prefix !== collection || !data.icons || typeof data.icons !== 'object' || Array.isArray(data.icons)) throw new Error('Iconify returned an invalid preview collection.');
    var cache = iconifyPreviewCache[collection] || (iconifyPreviewCache[collection] = Object.create(null));
    var unavailable = 0;
    names.forEach(function (name) {
      try {
        if (!Object.prototype.hasOwnProperty.call(data.icons, name)) throw new Error('Missing icon');
        cache[name] = iconifyPreviewSvg(data, data.icons[name]);
      } catch (error) {
        unavailable++;
      }
    });
    if (unavailable) throw new Error(unavailable + ' Iconify previews were unavailable or failed SVG safety validation. Download remains available; retry previews or choose another icon.');
  }

  function paintPage(container, records, count, createCard, onPage) {
    var fragment = document.createDocumentFragment();
    var page = Math.min(Math.max(0, Math.ceil(records.length / PAGE_SIZE) - 1), Math.max(0, count / PAGE_SIZE - 1));
    var start = page * PAGE_SIZE;
    var end = Math.min(start + PAGE_SIZE, records.length);
    records.slice(start, end).forEach(function (record) { fragment.appendChild(createCard(record)); });
    if (!records.length) fragment.appendChild(node('p', 'ui-icons-empty', 'No icons match your search.'));
    if (records.length > PAGE_SIZE) {
      var navigation = node('nav', 'ui-icons-pagination');
      navigation.setAttribute('aria-label', 'Icon result pages');
      function changePage(nextCount) {
        onPage(nextCount);
        var first = container.querySelector('.ui-icons-card button:not(:disabled)');
        if (first) first.focus();
      }
      var previous = button('Previous page', function () { changePage(start); });
      previous.disabled = start === 0;
      var next = button('Next page', function () { changePage(start + PAGE_SIZE * 2); });
      next.disabled = end === records.length;
      var summary = node('span', '', (start + 1) + '–' + end + ' of ' + records.length);
      summary.setAttribute('role', 'status');
      navigation.append(previous, summary, next);
      fragment.appendChild(navigation);
    }
    container.replaceChildren(fragment);
  }

  function notify(target, text, isError) {
    if (target && target.feedback && target.feedback.isConnected) {
      target.feedback.classList.toggle('ui-icons-error', !!isError);
      target.feedback.setAttribute('role', isError ? 'alert' : 'status');
      message(target.feedback, text);
    } else if (settings) {
      settings.notify(text, isError);
    }
  }

  function applyIcon(target, record) {
    if (!target || !target.input.isConnected) throw new Error('The editor is no longer available. The icon is saved in the library; reopen the editor to select it.');
    target.input.value = target.type === 'vm' ? record.vmIcon : record.dockerIcon;
    target.input.dispatchEvent(new Event('input', {bubbles: true}));
    target.input.dispatchEvent(new Event('change', {bubbles: true}));
    // Core Docker listeners try to preview file:// directly. Set HTTP preview last.
    var preview = document.getElementById(target.type === 'vm' ? 'template_img' : 'contIconPreview');
    if (preview) {
      preview.src = record.url;
      preview.style.display = '';
    }
    notify(target, 'Selected “' + record.name + '”. Use the editor’s Apply button to save this change.', false);
  }

  function Picker() {
    var self = this;
    this.target = null;
    this.opener = null;
    this.busy = false;
    this.localCount = PAGE_SIZE;
    this.catalogCount = PAGE_SIZE;
    this.activeTab = 'local';
    this.provider = 'selfhst';
    this.collection = 'tabler';
    this.catalogSequence = 0;
    this.previewSequence = 0;
    this.previewURLs = [];
    this.previewTimer = null;
    this.previewRequest = null;
    this.dialog = node('dialog', 'ui-icons ui-icons-dialog');
    this.dialog.setAttribute('aria-labelledby', 'unraid-icons-dialog-title');
    this.dialog.setAttribute('aria-describedby', 'unraid-icons-dialog-help');
    var header = node('div', 'ui-icons-dialog-header');
    var heading = node('h2', '', 'Choose an icon');
    heading.id = 'unraid-icons-dialog-title';
    var close = button('Close', function () { self.dialog.close(); }, 'ui-icons-close');
    close.setAttribute('aria-label', 'Close icon picker');
    header.append(heading, close);
    var help = node('p', 'ui-icons-help', 'Downloads are saved in the library even if you close this picker. Select an icon, then use Apply in the editor.');
    help.id = 'unraid-icons-dialog-help';
    this.help = help;
    this.status = node('p', 'ui-icons-status');
    this.status.setAttribute('role', 'status');
    this.status.setAttribute('aria-live', 'polite');
    this.error = node('p', 'ui-icons-error');
    this.error.setAttribute('role', 'alert');
    this.error.hidden = true;
    this.result = node('div', 'ui-icons-result');
    this.result.hidden = true;
    this.tabs = node('div', 'ui-icons-tabs tabs');
    this.tabs.setAttribute('role', 'tablist');
    this.tabs.setAttribute('aria-label', 'Icon sources');
    this.panels = {};
    this.tabButtons = {};
    [['local', 'Saved icons'], ['catalog', 'Browse icons'], ['url', 'Custom URL']].forEach(function (item) {
      var key = item[0];
      var tab = button(item[1], function () { self.setTab(key); });
      tab.id = 'unraid-icons-tab-' + key;
      tab.setAttribute('role', 'tab');
      tab.setAttribute('aria-controls', 'unraid-icons-panel-' + key);
      tab.addEventListener('keydown', function (event) {
        var keys = Object.keys(self.tabButtons).filter(function (name) { return !self.tabButtons[name].hidden; });
        var index = keys.indexOf(key);
        if (event.key === 'ArrowRight') index = (index + 1) % keys.length;
        else if (event.key === 'ArrowLeft') index = (index + keys.length - 1) % keys.length;
        else if (event.key === 'Home') index = 0;
        else if (event.key === 'End') index = keys.length - 1;
        else return;
        event.preventDefault();
        self.setTab(keys[index]);
        self.tabButtons[keys[index]].focus();
      });
      var panel = node('section', 'ui-icons-panel');
      panel.id = 'unraid-icons-panel-' + key;
      panel.setAttribute('role', 'tabpanel');
      panel.setAttribute('aria-labelledby', tab.id);
      self.tabs.appendChild(tab);
      self.tabButtons[key] = tab;
      self.panels[key] = panel;
    });
    this.localSearch = this.search(this.panels.local, 'Search saved icons', 'unraid-icons-local-search', function () {
      self.localCount = PAGE_SIZE;
      self.renderLocal();
    });
    this.localRetry = button('Refresh saved icons', function () { self.refreshLocal(); });
    this.localGrid = node('div', 'ui-icons-grid');
    this.panels.local.append(this.localRetry, this.localGrid);
    var sourceRow = node('div', 'ui-icons-search');
    var sourceLabel = node('label', '', 'Icon source');
    sourceLabel.htmlFor = 'unraid-icons-provider';
    this.providerSelect = node('select');
    this.providerSelect.id = sourceLabel.htmlFor;
    Object.keys(PROVIDERS).forEach(function (key) {
      var option = node('option', '', PROVIDERS[key].name);
      option.value = key;
      self.providerSelect.appendChild(option);
    });
    this.providerSelect.addEventListener('change', function () {
      self.provider = self.providerSelect.value;
      self.catalogCount = PAGE_SIZE;
      self.catalogGrid.replaceChildren();
      self.fetchCatalog();
    });
    sourceRow.append(sourceLabel, this.providerSelect);
    this.iconifyControls = node('div', 'ui-icons-search ui-icons-iconify-controls');
    this.iconifyControls.hidden = true;
    var collectionLabel = node('label', '', 'Icon collection');
    collectionLabel.htmlFor = 'unraid-icons-collection';
    this.collectionSelect = node('select');
    this.collectionSelect.id = collectionLabel.htmlFor;
    Object.keys(ICONIFY_COLLECTIONS).forEach(function (key) {
      var option = node('option', '', ICONIFY_COLLECTIONS[key]);
      option.value = key;
      self.collectionSelect.appendChild(option);
    });
    this.collectionSelect.addEventListener('change', function () {
      self.collection = self.collectionSelect.value;
      self.catalogCount = PAGE_SIZE;
      self.catalogGrid.replaceChildren();
      self.fetchCatalog();
    });
    var colorLabel = node('label', '', 'Icon color');
    colorLabel.htmlFor = 'unraid-icons-color';
    this.colorInput = node('input');
    this.colorInput.type = 'color';
    this.colorInput.id = colorLabel.htmlFor;
    this.colorInput.value = '#ff8c2f';
    this.colorValue = node('code', '', this.colorInput.value);
    this.colorValue.id = 'unraid-icons-color-value';
    this.colorInput.setAttribute('aria-describedby', this.colorValue.id);
    this.colorInput.addEventListener('input', function () { self.colorValue.textContent = self.colorInput.value; });
    this.colorInput.addEventListener('change', function () { self.renderCatalog(); });
    this.iconifyControls.append(collectionLabel, this.collectionSelect, colorLabel, this.colorInput, this.colorValue);
    this.panels.catalog.append(sourceRow, this.iconifyControls);
    this.catalogSearch = this.search(this.panels.catalog, 'Search icons', 'unraid-icons-catalog-search', function () {
      self.catalogCount = PAGE_SIZE;
      self.renderCatalog();
    });
    this.catalogCredit = node('p', 'ui-icons-help');
    this.catalogAttribution = node('div');
    this.catalogStatus = node('p', 'ui-icons-status');
    this.catalogStatus.setAttribute('role', 'status');
    this.catalogRetry = button('Retry catalog', function () { self.fetchCatalog(); });
    this.catalogRetry.hidden = true;
    this.previewStatus = node('p', 'ui-icons-status');
    this.previewStatus.setAttribute('role', 'status');
    this.previewStatus.hidden = true;
    this.previewRetry = button('Retry previews', function () { self.renderCatalog(); });
    this.previewRetry.hidden = true;
    this.catalogGrid = node('div', 'ui-icons-grid');
    this.panels.catalog.append(this.catalogCredit, this.catalogAttribution, this.catalogStatus, this.catalogRetry, this.previewStatus, this.previewRetry, this.catalogGrid);
    this.createURLForm();
    this.dialog.append(header, help, this.tabs, this.status, this.error, this.result, this.panels.local, this.panels.catalog, this.panels.url);
    this.dialog.addEventListener('close', function () {
      self.clearPreviews();
      self.result.replaceChildren();
      self.result.hidden = true;
      if (self.opener && self.opener.isConnected) self.opener.focus();
    });
    document.body.appendChild(this.dialog);
    this.setTab('local');
  }

  Picker.prototype.search = function (panel, text, id, onInput) {
    var row = node('div', 'ui-icons-search');
    var label = node('label', '', text);
    label.htmlFor = id;
    var input = node('input');
    input.type = 'search';
    input.id = id;
    input.placeholder = text;
    input.autocomplete = 'off';
    input.addEventListener('input', onInput);
    row.append(label, input);
    panel.appendChild(row);
    return input;
  };

  Picker.prototype.createURLForm = function () {
    var self = this;
    var form = node('form', 'ui-icons-url-form');
    var help = node('p', '', 'Enter a public HTTP or HTTPS image URL. PNG, JPEG, WebP and GIF are supported when available on your server, and converted to PNG. SVG is not supported. Private network addresses are blocked.');
    var urlLabel = node('label', '', 'Image URL');
    urlLabel.htmlFor = 'unraid-icons-url';
    this.urlInput = node('input');
    this.urlInput.id = urlLabel.htmlFor;
    this.urlInput.type = 'url';
    this.urlInput.required = true;
    this.urlInput.placeholder = 'https://example.com/icon.png';
    var nameLabel = node('label', '', 'Name (optional)');
    nameLabel.htmlFor = 'unraid-icons-name';
    this.nameInput = node('input');
    this.nameInput.id = nameLabel.htmlFor;
    this.nameInput.type = 'text';
    this.nameInput.maxLength = 120;
    var submit = node('button', '', 'Download icon');
    submit.type = 'submit';
    submit.dataset.iconDownload = 'true';
    form.append(help, urlLabel, this.urlInput, nameLabel, this.nameInput, submit);
    form.addEventListener('submit', function (event) {
      event.preventDefault();
      if (!form.reportValidity()) return;
      self.download(self.urlInput.value.trim(), self.nameInput.value.trim());
    });
    this.panels.url.appendChild(form);
  };

  Picker.prototype.open = function (target, opener) {
    this.target = target;
    this.opener = opener;
    this.help.textContent = target
      ? 'Downloads stay in your library even if you close this picker. Choose Use icon, then use the editor’s Apply button to save your change.'
      : 'Downloads are saved immediately to the shared VM and Docker library. You can select them later from either editor.';
    if (!this.busy) {
      message(this.status, '');
      message(this.error, '');
    }
    this.result.replaceChildren();
    this.result.hidden = true;
    this.tabButtons.local.hidden = !target;
    this.dialog.querySelector('h2').textContent = target ? 'Choose an icon' : 'Add an icon';
    this.dialog.showModal();
    this.setTab(target ? 'local' : 'catalog');
    (target ? this.localSearch : this.catalogSearch).focus();
    if (target) this.refreshLocal();
  };

  Picker.prototype.setTab = function (key) {
    this.activeTab = key;
    if (key !== 'catalog') this.clearPreviews();
    var self = this;
    Object.keys(this.panels).forEach(function (name) {
      var selected = name === key;
      self.panels[name].hidden = !selected;
      self.tabButtons[name].setAttribute('aria-selected', selected ? 'true' : 'false');
      self.tabButtons[name].tabIndex = selected ? 0 : -1;
    });
    if (key === 'catalog') this.fetchCatalog();
  };

  Picker.prototype.refreshLocal = async function () {
    this.localRetry.disabled = true;
    this.localGrid.setAttribute('aria-busy', 'true');
    try {
      await loadLibrary();
    } catch (error) {
      message(this.error, errorText(error));
      if (!this.dialog.open) notify(this.target, errorText(error), true);
    } finally {
      this.localRetry.disabled = false;
      this.localGrid.setAttribute('aria-busy', 'false');
    }
  };

  Picker.prototype.choose = function (record) {
    try {
      if (this.target) applyIcon(this.target, record);
      else if (settings) settings.notify('“' + record.name + '” is saved in your library and available in both editors.', false);
      this.dialog.close();
    } catch (error) {
      message(this.error, errorText(error));
    }
  };

  Picker.prototype.renderLocal = function () {
    var self = this;
    var query = this.localSearch.value.trim().toLowerCase();
    var found = icons.filter(function (record) { return matches(record, query); });
    paintPage(this.localGrid, found, this.localCount, function (record) {
      var item = card(record, record.url);
      item.appendChild(button(self.target ? 'Use icon' : 'Done', function () { self.choose(record); }));
      return item;
    }, function (count) { self.localCount = count; self.renderLocal(); });
    if (!icons.length && !query) this.localGrid.replaceChildren(node('p', 'ui-icons-empty', 'Your library is empty. Browse icons or add one from a custom URL.'));
  };

  Picker.prototype.fetchCatalog = async function () {
    var provider = this.provider;
    var collection = this.collection;
    var sequence = ++this.catalogSequence;
    this.clearPreviews();
    message(this.previewStatus, '');
    this.previewRetry.hidden = true;
    message(this.error, '');
    this.iconifyControls.hidden = provider !== 'iconify';
    this.catalogAttribution.replaceChildren();
    this.catalogCredit.replaceChildren(document.createTextNode('Browse icons from '), creditLink(PROVIDERS[provider].name, PROVIDERS[provider].url));
    this.catalogCredit.appendChild(document.createTextNode(provider === 'iconify'
      ? '. Previews load from Iconify. Download converts the SVG locally into a transparent 512 × 512 PNG, then saves it to your library.'
      : '. Previews load from GitHub. Downloaded images are stored locally. Logos remain the property of their respective owners.'));
    message(this.catalogStatus, 'Loading ' + (provider === 'iconify' ? ICONIFY_COLLECTIONS[collection] : PROVIDERS[provider].name) + ' catalog…');
    this.catalogRetry.hidden = true;
    this.catalogGrid.setAttribute('aria-busy', 'true');
    try {
      var data = await loadCatalog(provider, collection);
      if (sequence !== this.catalogSequence) return;
      if (provider === 'iconify') this.catalogAttribution.replaceChildren(attributionNotice(data.attribution));
      message(this.catalogStatus, typeof data.warning === 'string' ? data.warning : data.icons.length + ' icons available.');
      this.renderCatalog();
    } catch (error) {
      if (sequence !== this.catalogSequence) return;
      message(this.catalogStatus, '');
      message(this.error, errorText(error));
      if (!this.dialog.open) notify(this.target, errorText(error), true);
      this.catalogRetry.hidden = false;
    } finally {
      if (sequence === this.catalogSequence) this.catalogGrid.setAttribute('aria-busy', 'false');
    }
  };

  Picker.prototype.clearPreviews = function (keepRequestKey) {
    ++this.previewSequence;
    clearTimeout(this.previewTimer);
    this.previewTimer = null;
    this.previewURLs.forEach(function (url) { URL.revokeObjectURL(url); });
    this.previewURLs = [];
    if (this.previewRequest && this.previewRequest.key !== keepRequestKey) {
      this.previewRequest.controller.abort();
      this.previewRequest = null;
    }
  };

  Picker.prototype.schedulePreviews = function (targets) {
    var self = this;
    var collection = this.collection;
    var cache = iconifyPreviewCache[collection] || (iconifyPreviewCache[collection] = Object.create(null));
    var missing = Array.from(new Set(targets.map(function (target) { return target.name; }).filter(function (name) { return !cache[name]; }))).sort();
    var key = collection + ':' + missing.join(',');
    this.clearPreviews(missing.length ? key : undefined);
    var sequence = this.previewSequence;
    message(this.previewStatus, '');
    this.previewRetry.hidden = true;
    if (!this.dialog.open || this.activeTab !== 'catalog' || this.provider !== 'iconify') return;
    function status(text, isError) {
      self.previewStatus.classList.toggle('ui-icons-error', !!isError);
      self.previewStatus.setAttribute('role', isError ? 'alert' : 'status');
      message(self.previewStatus, text);
      self.previewRetry.hidden = !isError;
    }
    function paintCached() {
      targets.forEach(function (target) {
        if (!cache[target.name] || target.painted) return;
        target.painted = true;
        var svg = cache[target.name].replace('color="#000000"', 'color="' + self.colorInput.value + '"');
        var url = URL.createObjectURL(new Blob([svg], {type: 'image/svg+xml'}));
        self.previewURLs.push(url);
        var image = node('img');
        image.alt = '';
        image.decoding = 'async';
        image.addEventListener('error', function () {
          if (sequence !== self.previewSequence) return;
          target.wrap.replaceChildren(node('span', 'ui-icons-image-error', 'Preview unavailable'));
          status('Your browser could not render an Iconify preview. Download remains available; retry previews or choose another icon.', true);
        }, {once: true});
        target.wrap.replaceChildren(image);
        image.src = url;
      });
    }
    paintCached();
    if (!missing.length) return;
    status('Loading Iconify previews…', false);
    this.previewTimer = setTimeout(async function () {
      if (sequence !== self.previewSequence) return;
      self.previewTimer = null;
      var remaining = missing.filter(function (name) { return !cache[name]; });
      if (!remaining.length) {
        paintCached();
        status('', false);
        return;
      }
      var request = self.previewRequest;
      if (!request || request.key !== key) {
        var controller = new AbortController();
        request = {key: key, controller: controller};
        self.previewRequest = request;
        var timeout = setTimeout(function () { controller.abort(); }, 20000);
        request.promise = loadIconifyPreviews(collection, remaining, controller.signal).finally(function () {
          clearTimeout(timeout);
          if (self.previewRequest === request) self.previewRequest = null;
        });
      }
      try {
        await request.promise;
        if (sequence !== self.previewSequence) return;
        paintCached();
        status('', false);
      } catch (error) {
        if (sequence !== self.previewSequence) return;
        paintCached();
        status(error.name === 'AbortError' ? 'Iconify preview download timed out. Check your connection and retry previews.'
          : error instanceof TypeError ? 'Unable to reach Iconify for previews. Check your connection or content blocker, then retry previews.'
            : errorText(error), true);
      }
    }, 250);
  };

  Picker.prototype.renderCatalog = function () {
    var catalog = catalogs[catalogKey(this.provider, this.collection)];
    if (!catalog) return;
    var self = this;
    var query = this.catalogSearch.value.trim().toLowerCase();
    var found = catalog.icons.filter(function (record) { return matches(record, query); });
    var previewTargets = [];
    paintPage(this.catalogGrid, found, this.catalogCount, function (record) {
      var isIconify = self.provider === 'iconify';
      var item = card(record, isIconify ? null : record.preview);
      if (isIconify) {
        iconifyURL(record.iconId, self.colorInput.value, 88);
        var parts = record.iconId.split(':');
        if (parts[0] !== self.collection) throw new Error('Iconify returned a preview from the wrong collection. Refresh the catalog.');
        var wrap = item.querySelector('.ui-icons-thumbnail');
        wrap.replaceChildren(node('span', 'ui-icons-image-error', 'Loading preview…'));
        previewTargets.push({name: parts[1], wrap: wrap});
      }
      var download = button('Download', function () {
        if (isIconify) self.download(null, null, {iconId: record.iconId, color: self.colorInput.value});
        else self.download(record.url, record.name);
      });
      download.dataset.iconDownload = 'true';
      download.disabled = self.busy;
      download.setAttribute('aria-label', 'Download ' + record.name);
      item.appendChild(download);
      return item;
    }, function (count) { self.catalogCount = count; self.renderCatalog(); });
    if (this.provider === 'iconify') this.schedulePreviews(previewTargets);
    else {
      this.clearPreviews();
      message(this.previewStatus, '');
      this.previewRetry.hidden = true;
    }
  };

  function encodedPng(blob) {
    return new Promise(function (resolve, reject) {
      var reader = new FileReader();
      reader.onerror = function () { reject(new Error('The browser could not prepare the PNG for saving. Try Download again.')); };
      reader.onabort = reader.onerror;
      reader.onload = function () {
        var prefix = 'data:image/png;base64,';
        if (typeof reader.result !== 'string' || !reader.result.startsWith(prefix)) {
          reject(new Error('The browser produced an invalid PNG. Try Download again.'));
          return;
        }
        resolve(reader.result.slice(prefix.length));
      };
      reader.readAsDataURL(blob);
    });
  }

  Picker.prototype.download = async function (url, name, iconify) {
    if (this.busy) return;
    this.busy = true;
    var self = this;
    var target = this.target;
    message(this.error, '');
    message(this.status, iconify ? 'Downloading… Fetching the selected Iconify SVG. You can close this picker.' : 'Downloading and validating icon… You can close this picker; the icon will remain in your library.');
    this.result.hidden = true;
    this.dialog.querySelectorAll('[data-icon-download]').forEach(function (control) { control.disabled = true; });
    try {
      var data;
      if (iconify) {
        var png = await iconifyPng(iconify.iconId, iconify.color, function (text) { message(self.status, text); });
        message(this.status, 'Saving… Validating and storing the PNG in your library.');
        data = await api('save-iconify', {
          png: await encodedPng(png),
          iconId: iconify.iconId,
          color: iconify.color
        });
      } else {
        data = await api('download', {url: url, name: name});
      }
      var record = data.icon;
      if (!record || typeof record.filename !== 'string') throw new Error('The icon service returned an invalid downloaded icon.');
      ++librarySequence;
      icons = icons.filter(function (saved) { return saved.filename !== record.filename; });
      icons.unshift(record);
      if (settings) settings.render();
      this.renderLocal();
      message(this.status, 'Saved: “' + record.name + '” is in the library.');
      if (this.dialog.open && this.target === target) {
        var result = card(record, record.url);
        result.appendChild(button(target ? 'Use icon' : 'Done', function () { self.choose(record); }));
        this.result.replaceChildren(result);
        this.result.hidden = false;
        result.querySelector('button').focus();
      } else {
        notify(target, '“' + record.name + '” was saved to the library.' + (target ? ' Open Choose icon to select it, then Apply in the editor.' : ' It is available in both editors.'), false);
      }
    } catch (error) {
      message(this.status, '');
      message(this.error, errorText(error));
      if (!this.dialog.open) notify(target, errorText(error), true);
    } finally {
      this.busy = false;
      this.dialog.querySelectorAll('[data-icon-download]').forEach(function (control) { control.disabled = false; });
    }
  };

  function openPicker(target, opener) {
    if (!picker) picker = new Picker();
    picker.open(target, opener);
  }

  function Settings(root) {
    var self = this;
    this.root = root;
    this.grid = root.querySelector('#unraid-icons-library');
    this.credits = root.querySelector('#unraid-icons-credits');
    this.creditList = root.querySelector('#unraid-icons-credit-list');
    this.status = root.querySelector('#unraid-icons-settings-status');
    this.error = root.querySelector('#unraid-icons-settings-error');
    this.filter = root.querySelector('#unraid-icons-filter');
    this.refresh = root.querySelector('#unraid-icons-refresh');
    this.count = PAGE_SIZE;
    this.deleting = false;
    this.headerShortcut = root.querySelector('#unraid-icons-header-shortcut');
    this.preferencesStatus = root.querySelector('#unraid-icons-preferences-status');
    this.savedHeaderShortcut = false;
    this.headerShortcut.addEventListener('change', function () { self.savePreferences(); });
    this.loadPreferences();
    root.querySelector('#unraid-icons-add').addEventListener('click', function (event) { openPicker(null, event.currentTarget); });
    this.refresh.addEventListener('click', function () { self.load(); });
    this.filter.addEventListener('input', function () { self.count = PAGE_SIZE; self.render(); });
  }

  Settings.prototype.loadPreferences = async function () {
    try {
      var data = await api('preferences');
      this.savedHeaderShortcut = data.preferences.headerShortcut === true;
      this.headerShortcut.checked = this.savedHeaderShortcut;
      this.headerShortcut.disabled = false;
    } catch (error) {
      this.preferencesStatus.setAttribute('role', 'alert');
      message(this.preferencesStatus, errorText(error));
    }
  };

  Settings.prototype.savePreferences = async function () {
    if (this.headerShortcut.disabled) return;
    var enabled = this.headerShortcut.checked;
    this.headerShortcut.disabled = true;
    this.preferencesStatus.setAttribute('role', 'status');
    message(this.preferencesStatus, 'Saving…');
    try {
      var data = await api('save-preferences', {headerShortcut: String(enabled)});
      this.savedHeaderShortcut = data.preferences.headerShortcut === true;
      this.headerShortcut.checked = this.savedHeaderShortcut;
      document.dispatchEvent(new CustomEvent('unraid-icons-preferences', {detail: data.preferences}));
      message(this.preferencesStatus, 'Settings saved.');
    } catch (error) {
      this.headerShortcut.checked = this.savedHeaderShortcut;
      this.preferencesStatus.setAttribute('role', 'alert');
      message(this.preferencesStatus, errorText(error));
    } finally {
      this.headerShortcut.disabled = false;
    }
  };

  Settings.prototype.notify = function (text, isError) {
    message(this.error, isError ? text : '');
    message(this.status, isError ? '' : text);
  };

  Settings.prototype.load = async function () {
    this.refresh.disabled = true;
    this.grid.setAttribute('aria-busy', 'true');
    this.notify('Checking library and icon usage…', false);
    try {
      await loadLibrary();
      this.notify(icons.length + (icons.length === 1 ? ' saved icon.' : ' saved icons.'), false);
    } catch (error) {
      this.notify(errorText(error), true);
    } finally {
      this.refresh.disabled = false;
      this.grid.setAttribute('aria-busy', 'false');
    }
  };

  Settings.prototype.render = function () {
    var self = this;
    var query = this.filter.value.trim().toLowerCase();
    var found = icons.filter(function (record) { return matches(record, query); });
    paintPage(this.grid, found, this.count, function (record) {
      var item = card(record, record.url);
      var credit = libraryCredit(record);
      if (credit) item.appendChild(node('p', 'ui-icons-collection', credit.label));
      var refs = Array.isArray(record.references) ? record.references : [];
      var known = record.usageKnown === true && Array.isArray(record.references);
      var displayRefs = Array.from(new Set(refs.map(function (reference) {
        return reference.replace(/^(VM: .*) \(saved definition\)$/, '$1')
          .replace(/^Docker (?:template|container): /, 'Docker: ');
      })));
      var explanation = !known ? 'Usage check incomplete; removal is disabled.' : refs.length ? 'In use by: ' + displayRefs.join(', ') : 'Not in use by any VM or Docker container.';
      var usage = node('p', 'ui-icons-usage', explanation);
      usage.id = 'ui-icons-usage-' + record.filename.replace(/[^a-zA-Z0-9_-]/g, '-');
      var remove = button('Remove', function () { self.remove(record); });
      remove.disabled = !known || refs.length > 0 || self.deleting;
      remove.setAttribute('aria-describedby', usage.id);
      remove.title = explanation;
      item.append(usage, remove);
      return item;
    }, function (count) { self.count = count; self.render(); });
    if (!icons.length && !query) this.grid.replaceChildren(node('p', 'ui-icons-empty', 'No saved icons yet. Choose Add icon to get started.'));
    // Credits describe the whole saved library, independent of search and pagination.
    var credits = new Map();
    icons.forEach(function (record) {
      var credit = libraryCredit(record);
      if (credit && !credits.has(credit.key)) credits.set(credit.key, credit);
    });
    this.creditList.replaceChildren();
    Array.from(credits.values()).sort(function (a, b) { return a.label.localeCompare(b.label); }).forEach(function (credit) {
      var notice;
      if (credit.attribution) {
        notice = attributionNotice(credit.attribution);
        notice.append(document.createTextNode(' · via '), creditLink('Iconify', credit.url));
      } else {
        notice = node('p', 'ui-icons-attribution');
        notice.append(creditLink(credit.label, credit.url), document.createTextNode(' · '), creditLink('License', credit.license));
      }
      self.creditList.appendChild(notice);
    });
    this.credits.hidden = credits.size === 0;
  };

  Settings.prototype.remove = async function (record) {
    if (this.deleting || record.usageKnown !== true || !Array.isArray(record.references) || record.references.length) return;
    if (!window.confirm('Permanently remove “' + record.name + '” from the shared icon library? The server will check again that it is unused.')) return;
    this.deleting = true;
    this.render();
    this.notify('Rechecking usage and removing icon…', false);
    try {
      await api('delete', {filename: record.filename});
      ++librarySequence;
      icons = icons.filter(function (item) { return item.filename !== record.filename; });
      this.notify('Removed “' + record.name + '”.', false);
      if (picker) picker.renderLocal();
    } catch (error) {
      this.notify(errorText(error), true);
    } finally {
      this.deleting = false;
      this.render();
    }
  };

  function attach(input, type) {
    if (input.dataset.unraidIconsAttached === 'true') return;
    input.dataset.unraidIconsAttached = 'true';
    var group = node('span', 'ui-icons ui-icons-editor');
    var feedback = node('span', 'ui-icons-editor-status');
    feedback.setAttribute('role', 'status');
    feedback.setAttribute('aria-live', 'polite');
    feedback.hidden = true;
    var target = {input: input, type: type, feedback: feedback};
    var choose = button('Choose icon…', function () { openPicker(target, choose); });
    choose.title = 'Choose a shared VM or Docker icon';
    group.append(choose, feedback);
    var preview = document.getElementById(type === 'vm' ? 'template_img' : 'contIconPreview');
    (preview || input).insertAdjacentElement('afterend', group);
    if (type === 'vm') {
      loadLibrary().catch(function (error) { notify(target, errorText(error), true); });
    }
    if (type === 'docker') {
      var updatePreview = function () {
        var value = input.value.trim();
        if (value.indexOf(STORAGE) !== 0) return;
        var filename = value.slice(STORAGE.length);
        if (!/^[a-z0-9][a-z0-9_-]{0,99}\.png$/.test(filename)) return;
        var image = document.getElementById('contIconPreview');
        if (image) { image.src = PUBLIC + filename; image.style.display = ''; }
      };
      // Run after core's direct/jQuery input handlers, including on initial load.
      input.addEventListener('input', function () { queueMicrotask(updatePreview); });
      input.addEventListener('change', function () { queueMicrotask(updatePreview); });
      updatePreview();
    }
  }

  function attachEditors() {
    document.querySelectorAll('#template_icon').forEach(function (input) { attach(input, 'vm'); });
    document.querySelectorAll('input[name="contIcon"]').forEach(function (input) { attach(input, 'docker'); });
  }

  function init() {
    var root = document.getElementById('unraid-icons-settings');
    if (root) {
      settings = new Settings(root);
      settings.load();
    }
    attachEditors();
    // VM template switches may replace the editor without navigating.
    var pending = false;
    new MutationObserver(function (mutations) {
      if (pending || !mutations.some(function (entry) { return entry.addedNodes.length > 0; })) return;
      pending = true;
      queueMicrotask(function () { pending = false; attachEditors(); });
    }).observe(document.body, {childList: true, subtree: true});
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, {once: true});
  else init();
}());
