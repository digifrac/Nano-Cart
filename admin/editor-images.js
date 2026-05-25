/* Nano Cart admin - editor image SELECTION (no uploading here).
   The product/category editors do not upload. They pick images that already
   live in the media library (uploaded and organised in the Media tab) and set
   the product/category-specific metadata: primary image, gallery order, alt
   text. A "Select from library" button opens a picker scoped to this owner's
   folder; an "Open Media manager" link goes to the full manager.

   Mounts on .nano-cart-admin-image-manager. Data attributes:
     data-endpoint        upload.php          (the `update` persist action)
     data-media-endpoint  media.php           (list, for the picker)
     data-media-url       media.php           (link target)
     data-csrf, data-target-type, data-target-id
     data-rel-root        owner: category-images | product-images/<sku>
     data-media-base      e.g. /shop/media
     data-single-image    "1" for a category banner
     data-images          initial JSON [{file, alt, is_primary}] */

(function () {
  'use strict';

  function el(t, c, h) { var e = document.createElement(t); if (c) e.className = c; if (h != null) e.innerHTML = h; return e; }
  function esc(s) { return String(s).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); }

  function init() { document.querySelectorAll('.nano-cart-admin-image-manager').forEach(setup); }

  function setup(root) {
    var UPDATE = root.dataset.endpoint;
    var MEDIA  = root.dataset.mediaEndpoint;
    var MEDIA_URL = root.dataset.mediaUrl;
    var CSRF   = root.dataset.csrf;
    var type   = root.dataset.targetType;
    var id     = root.dataset.targetId;
    var base   = root.dataset.mediaBase;
    var owner  = root.dataset.relRoot;            // category-images | product-images/<sku>
    var single = root.dataset.singleImage === '1';
    var images = [];
    try { images = JSON.parse(root.dataset.images || '[]'); } catch (e) {}
    images = images.map(function (i) { return { file: String(i.file || ''), alt: String(i.alt || ''), is_primary: !!i.is_primary }; });

    root.innerHTML =
        '<div class="nce-bar">'
      +   '<button type="button" class="nano-cart-admin-button nce-add">' + (single ? 'Select banner image' : 'Select images') + '</button>'
      +   '<a class="nano-cart-admin-button nano-cart-admin-button-secondary" href="' + esc(MEDIA_URL) + '" target="_blank" rel="noopener">Open Media manager &#8599;</a>'
      + '</div>'
      + '<p class="nano-cart-admin-help">Upload and organise images in the Media tab, then select them here.</p>'
      + '<div class="nce-grid"></div>';

    var grid = root.querySelector('.nce-grid');
    root.querySelector('.nce-add').addEventListener('click', openPicker);
    render();

    function thumb(ref) { return base + '/img/' + owner + '/' + ref + '-120.jpg'; }
    function ensurePrimary() { if (images.length && !images.some(function (i) { return i.is_primary; })) images[0].is_primary = true; }

    function render() {
      grid.innerHTML = '';
      if (!images.length) {
        grid.appendChild(el('p', 'nce-empty', single ? 'No banner selected yet.' : 'No images selected yet.'));
        return;
      }
      images.forEach(function (img, idx) {
        var item = el('div', 'nce-item'); item.draggable = !single; item.dataset.idx = String(idx);
        item.innerHTML =
            '<div class="nce-thumb"><img src="' + esc(thumb(img.file)) + '" alt=""></div>'
          + (single ? '' : '<button type="button" class="nce-star' + (img.is_primary ? ' nce-star-on' : '') + '" title="Primary image">' + (img.is_primary ? '★' : '☆') + '</button>')
          + '<button type="button" class="nce-del" title="Remove from this ' + type + '">×</button>'
          + '<input type="text" class="nce-alt" placeholder="alt text (required)" value="' + esc(img.alt) + '">'
          + '<div class="nce-path">' + esc(img.file) + '</div>';
        item.querySelector('img').addEventListener('error', function () { item.querySelector('.nce-thumb').classList.add('nce-broken'); });
        var star = item.querySelector('.nce-star');
        if (star) star.addEventListener('click', function () { images.forEach(function (x, n) { x.is_primary = (n === idx); }); render(); persist(); });
        item.querySelector('.nce-del').addEventListener('click', function () { images.splice(idx, 1); ensurePrimary(); render(); persist(); });
        var alt = item.querySelector('.nce-alt');
        function saveAlt() { images[idx].alt = alt.value.trim(); persist(); }
        alt.addEventListener('input', saveAlt);   // auto-save while typing (persist is debounced)
        alt.addEventListener('blur', saveAlt);     // and when leaving the field
        alt.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); alt.blur(); } });
        if (!single) {
          item.addEventListener('dragstart', onDragStart); item.addEventListener('dragover', onDragOver);
          item.addEventListener('drop', onDrop); item.addEventListener('dragend', onDragEnd);
        }
        grid.appendChild(item);
      });
    }

    var dragSrc = null;
    function onDragStart() { dragSrc = this; this.classList.add('nce-dragging'); }
    function onDragOver(e) { e.preventDefault(); this.classList.add('nce-drop'); return false; }
    function onDragEnd() { grid.querySelectorAll('.nce-item').forEach(function (x) { x.classList.remove('nce-dragging', 'nce-drop'); }); }
    function onDrop(e) {
      e.preventDefault(); this.classList.remove('nce-drop');
      if (!dragSrc || dragSrc === this) return false;
      var from = +dragSrc.dataset.idx, to = +this.dataset.idx;
      if (isNaN(from) || isNaN(to)) return false;
      images.splice(to, 0, images.splice(from, 1)[0]); ensurePrimary(); render(); persist();
      return false;
    }

    var timer = null;
    function persist() {
      if (timer) clearTimeout(timer);
      timer = setTimeout(function () {
        var fd = new FormData();
        fd.append('csrf_token', CSRF); fd.append('action', 'update');
        fd.append('target_type', type); fd.append('target_id', id);
        fd.append('images', JSON.stringify(images));
        fetch(UPDATE, { method: 'POST', body: fd, credentials: 'same-origin' })
          .then(function (r) { return r.text(); })
          .then(function (t) { try { var d = JSON.parse(t); if (!d.ok) console.error('Save failed: ' + (d.error || '')); } catch (e) { console.error('Save failed (non-JSON response).'); } });
      }, 400);
    }

    /* ----- picker: browse the whole media library and pick images ----- */
    function openPicker() {
      var bg = el('div', 'nce-mbg'), m = el('div', 'nce-modal');
      m.innerHTML = '<div class="nce-mhead"><strong>Select ' + (single ? 'banner image' : 'images') + ' from the media library</strong>'
        + '<button type="button" class="nce-mclose" aria-label="Close">&times;</button></div>'
        + '<nav class="nce-mcrumb"></nav><div class="nce-mstatus"></div><div class="nce-mgrid"></div>'
        + '<div class="nce-mfoot"><span class="nce-mhint">Upload new images in the Media tab. Chosen images are copied into this ' + type + '.</span>'
        + '<button type="button" class="nano-cart-admin-button nce-muse" disabled>Use selected</button></div>';
      bg.appendChild(m); document.body.appendChild(bg);
      var mcrumb = m.querySelector('.nce-mcrumb'), mgrid = m.querySelector('.nce-mgrid'),
          mstatus = m.querySelector('.nce-mstatus'), muse = m.querySelector('.nce-muse');
      var picked = {};   // full media path -> true
      function close() { if (bg.parentNode) bg.parentNode.removeChild(bg); }
      m.querySelector('.nce-mclose').addEventListener('click', close);
      bg.addEventListener('click', function (e) { if (e.target === bg) close(); });

      // On confirm: copy each chosen library image into this owner's folder,
      // then store the owner-relative reference returned by the server.
      muse.addEventListener('click', function () {
        var paths = Object.keys(picked);
        if (!paths.length) return;
        muse.disabled = true; mstatus.textContent = 'Adding...';
        var i = 0;
        (function next() {
          if (i >= paths.length) {
            if (single && images.length > 1) { images = images.slice(-1); images[0].is_primary = true; }
            ensurePrimary(); close(); render(); persist(); return;
          }
          var fd = new FormData();
          fd.append('csrf_token', CSRF); fd.append('action', 'copyinto');
          fd.append('src', paths[i]); fd.append('owner', owner);
          fetch(MEDIA, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.text(); })
            .then(function (t) {
              var d; try { d = JSON.parse(t); } catch (e) { d = { ok: false }; }
              if (d.ok && d.file && !images.some(function (x) { return x.file === d.file; })) {
                images.push({ file: d.file, alt: '', is_primary: images.length === 0 });
              }
              i++; next();
            }).catch(function () { i++; next(); });
        })();
      });

      function loadDir(d) {
        mstatus.textContent = 'Loading...';
        var fd = new FormData(); fd.append('csrf_token', CSRF); fd.append('action', 'list'); fd.append('dir', d);
        fetch(MEDIA, { method: 'POST', body: fd, credentials: 'same-origin' })
          .then(function (r) { return r.text(); })
          .then(function (t) {
            var res; try { res = JSON.parse(t); } catch (e) { mstatus.textContent = 'Could not load images.'; return; }
            if (!res.ok) { mstatus.textContent = res.error || 'Could not load images.'; return; }
            mstatus.textContent = '';
            // breadcrumb: full path from media home
            mcrumb.innerHTML = '';
            var home = el('button', 'nce-cl', 'media'); home.addEventListener('click', function () { loadDir(''); }); mcrumb.appendChild(home);
            (res.crumbs || []).forEach(function (c) {
              mcrumb.appendChild(document.createTextNode(' / '));
              var b = el('button', 'nce-cl', esc(c.name)); b.addEventListener('click', function () { loadDir(c.path); }); mcrumb.appendChild(b);
            });
            mgrid.innerHTML = '';
            (res.folders || []).forEach(function (f) {
              var fc = el('button', 'nce-mfolder', '[+] ' + esc(f.name));
              fc.addEventListener('click', function () { loadDir(f.path); });
              mgrid.appendChild(fc);
            });
            if (!res.files.length && (!res.folders || !res.folders.length)) {
              mgrid.appendChild(el('p', 'nce-empty', 'Nothing here. Upload images in the Media tab.'));
            }
            (res.files || []).forEach(function (f) {
              var p = f.path;
              var cell = el('button', 'nce-mcell' + (picked[p] ? ' nce-mcell-on' : ''));
              cell.innerHTML = '<div class="nce-thumb"><img src="' + esc(f.thumb) + '" alt=""></div><span>' + esc(f.name) + '</span>';
              cell.querySelector('img').addEventListener('error', function () { cell.querySelector('.nce-thumb').classList.add('nce-broken'); });
              cell.addEventListener('click', function () {
                if (picked[p]) { delete picked[p]; cell.classList.remove('nce-mcell-on'); }
                else {
                  if (single) { picked = {}; mgrid.querySelectorAll('.nce-mcell-on').forEach(function (x) { x.classList.remove('nce-mcell-on'); }); }
                  picked[p] = true; cell.classList.add('nce-mcell-on');
                }
                var n = Object.keys(picked).length; muse.disabled = !n; muse.textContent = n ? ('Use ' + n + ' selected') : 'Use selected';
              });
              mgrid.appendChild(cell);
            });
          });
      }
      loadDir(owner);   // start in this product/category's own folder
    }
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
