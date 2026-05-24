/* Nano Cart admin - image manager.
   Drag-and-drop upload, multi-image gallery, drag-reorder, alt-text
   editing, primary selection, delete with confirm, subfolder picker.
   No framework. Initialised from product-edit.php / category-edit.php
   data attributes on .nano-cart-admin-image-manager elements. */

(function () {
  'use strict';

  function init() {
    document.querySelectorAll('.nano-cart-admin-image-manager').forEach(setup);
  }

  function setup(root) {
    var endpoint  = root.dataset.endpoint;
    var csrf      = root.dataset.csrf;
    var type      = root.dataset.targetType;        // "product" or "category"
    var id        = root.dataset.targetId;          // SKU or slug
    var mediaBase = root.dataset.mediaBase;         // e.g. "/shop/media"
    var relRoot   = root.dataset.relRoot;           // e.g. "product-images/sku-001"
    var single    = root.dataset.singleImage === '1';
    var initial   = [];
    try { initial = JSON.parse(root.dataset.images || '[]'); } catch (e) { initial = []; }

    var state = {
      images: initial.map(normaliseImage),
      subfolder: ''
    };

    root.innerHTML = template(single);
    var dropZone   = root.querySelector('.nano-cart-admin-upload-zone');
    var fileInput  = root.querySelector('input[type=file]');
    var browseBtn  = root.querySelector('.nano-cart-admin-browse-btn');
    var grid       = root.querySelector('.nano-cart-admin-gallery-grid');
    var progress   = root.querySelector('.nano-cart-admin-upload-progress');
    var subfolderSelect = root.querySelector('.nano-cart-admin-subfolder-select');
    var subfolderNew    = root.querySelector('.nano-cart-admin-subfolder-new');

    // Load existing subfolders for the picker.
    if (subfolderSelect) loadSubfolders(subfolderSelect);

    browseBtn.addEventListener('click', function () { fileInput.click(); });
    fileInput.addEventListener('change', function () {
      if (fileInput.files && fileInput.files.length) uploadFiles(fileInput.files);
      fileInput.value = '';
    });

    dropZone.addEventListener('dragover', function (e) {
      e.preventDefault();
      dropZone.classList.add('nano-cart-admin-upload-zone-active');
    });
    dropZone.addEventListener('dragleave', function () {
      dropZone.classList.remove('nano-cart-admin-upload-zone-active');
    });
    dropZone.addEventListener('drop', function (e) {
      e.preventDefault();
      dropZone.classList.remove('nano-cart-admin-upload-zone-active');
      if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files.length) {
        uploadFiles(e.dataTransfer.files);
      }
    });

    render();

    /* ----- helpers ------------------------------------------------------ */

    function normaliseImage(img) {
      return {
        file:       String(img.file || ''),
        alt:        String(img.alt  || ''),
        is_primary: !!img.is_primary
      };
    }

    function loadSubfolders(select) {
      var fd = new FormData();
      fd.append('csrf_token', csrf);
      fd.append('action',     'subfolders');
      fd.append('target_type', type);
      fd.append('target_id',   id);
      fetch(endpoint, { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          if (!data.ok || !Array.isArray(data.subfolders)) return;
          data.subfolders.forEach(function (sf) {
            var opt = document.createElement('option');
            opt.value = sf;
            opt.textContent = sf + '/';
            select.appendChild(opt);
          });
        })
        .catch(function () { /* non-fatal */ });
    }

    function uploadFiles(fileList) {
      var files = Array.prototype.slice.call(fileList);
      var subfolderValue = '';
      if (subfolderSelect && subfolderSelect.value === '__new__') {
        subfolderValue = (subfolderNew.value || '').trim().toLowerCase();
      } else if (subfolderSelect) {
        subfolderValue = subfolderSelect.value;
      }
      uploadNext(files, 0, subfolderValue);
    }

    function uploadNext(files, idx, subfolder) {
      if (idx >= files.length) {
        progress.textContent = '';
        return;
      }
      var file = files[idx];
      progress.textContent = 'Uploading ' + (idx + 1) + ' of ' + files.length + ': ' + file.name;

      var fd = new FormData();
      fd.append('csrf_token', csrf);
      fd.append('action',     'upload');
      fd.append('target_type', type);
      fd.append('target_id',   id);
      if (subfolder) fd.append('subfolder', subfolder);
      fd.append('images[]', file);

      fetch(endpoint, { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          if (data.ok && Array.isArray(data.files)) {
            data.files.forEach(function (f) {
              if (f.ok) {
                var path = (subfolder ? subfolder + '/' : '') + f.file;
                if (!state.images.some(function (i) { return i.file === path; })) {
                  state.images.push({
                    file: path,
                    alt: '',
                    is_primary: state.images.length === 0
                  });
                }
              } else {
                console.error('Upload failed for ' + (f.name || 'file') + ': ' + f.error);
              }
            });
          } else if (data.error) {
            alert('Upload failed: ' + data.error);
          }
          enforceSingleImage();
          render();
          persist();
          uploadNext(files, idx + 1, subfolder);
        })
        .catch(function (err) {
          alert('Upload error: ' + err.message);
          uploadNext(files, idx + 1, subfolder);
        });
    }

    function enforceSingleImage() {
      if (!single) return;
      if (state.images.length > 1) {
        state.images = state.images.slice(-1);
        state.images[0].is_primary = true;
      }
    }

    function makePrimary(idx) {
      state.images.forEach(function (img, i) { img.is_primary = (i === idx); });
      render();
      persist();
    }

    function updateAlt(idx, value) {
      if (!state.images[idx]) return;
      state.images[idx].alt = value;
      persist();
    }

    function deleteImage(idx) {
      var img = state.images[idx];
      if (!img) return;
      if (!confirm('Delete this image? Variant files will be removed from disk.')) return;
      var fd = new FormData();
      fd.append('csrf_token', csrf);
      fd.append('action',     'delete');
      fd.append('target_type', type);
      fd.append('target_id',   id);
      fd.append('file',        img.file);
      fetch(endpoint, { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          if (data.ok) {
            state.images.splice(idx, 1);
            if (!state.images.some(function (i) { return i.is_primary; }) && state.images.length) {
              state.images[0].is_primary = true;
            }
            render();
            persist();
          } else {
            alert('Delete failed: ' + (data.error || 'unknown'));
          }
        })
        .catch(function (err) { alert('Delete error: ' + err.message); });
    }

    var persistTimer = null;
    function persist() {
      if (persistTimer) clearTimeout(persistTimer);
      persistTimer = setTimeout(doPersist, 500);
    }

    function doPersist() {
      var fd = new FormData();
      fd.append('csrf_token', csrf);
      fd.append('action',     'update');
      fd.append('target_type', type);
      fd.append('target_id',   id);
      fd.append('images',     JSON.stringify(state.images));
      fetch(endpoint, { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          if (!data.ok) console.error('Save failed: ' + (data.error || 'unknown'));
        })
        .catch(function () { /* surfaced on next save */ });
    }

    function thumbUrl(file) {
      return mediaBase + '/' + relRoot + '/' + file + '-thumb-120.jpg';
    }

    function render() {
      grid.innerHTML = '';
      state.images.forEach(function (img, idx) {
        var item = document.createElement('div');
        item.className = 'nano-cart-admin-thumb-item';
        item.draggable = true;
        item.dataset.idx = String(idx);

        item.innerHTML = ''
          + '<div class="nano-cart-admin-thumb-image">'
          +   '<img src="' + thumbUrl(img.file) + '" alt="">'
          + '</div>'
          + '<button type="button" class="nano-cart-admin-thumb-primary' + (img.is_primary ? ' nano-cart-admin-thumb-primary-on' : '') + '" title="Set as primary image" aria-label="Primary">'
          +   (img.is_primary ? '★' : '☆')
          + '</button>'
          + '<button type="button" class="nano-cart-admin-thumb-delete" title="Delete image" aria-label="Delete">×</button>'
          + '<input type="text" class="nano-cart-admin-thumb-alt" placeholder="alt text (required)" value="' + escapeAttr(img.alt) + '">'
          + '<div class="nano-cart-admin-thumb-path">' + escapeText(img.file) + '</div>';

        item.querySelector('.nano-cart-admin-thumb-primary').addEventListener('click', function () { makePrimary(idx); });
        item.querySelector('.nano-cart-admin-thumb-delete').addEventListener('click', function () { deleteImage(idx); });
        var altInput = item.querySelector('.nano-cart-admin-thumb-alt');
        altInput.addEventListener('blur', function () { updateAlt(idx, altInput.value.trim()); });

        item.addEventListener('dragstart', onDragStart);
        item.addEventListener('dragover',  onDragOver);
        item.addEventListener('drop',      onDrop);
        item.addEventListener('dragend',   onDragEnd);

        grid.appendChild(item);
      });
      if (!state.images.length) {
        var empty = document.createElement('p');
        empty.className = 'nano-cart-admin-gallery-empty';
        empty.textContent = single
          ? 'No banner image yet. Drop one above or browse.'
          : 'No images yet. Drop one or more above, or browse.';
        grid.appendChild(empty);
      }
    }

    /* ----- drag-to-reorder --------------------------------------------- */

    var dragSrc = null;
    function onDragStart(e) {
      dragSrc = this;
      this.classList.add('nano-cart-admin-thumb-dragging');
      try { e.dataTransfer.setData('text/plain', this.dataset.idx); } catch (err) {}
      e.dataTransfer.effectAllowed = 'move';
    }
    function onDragOver(e) {
      e.preventDefault();
      e.dataTransfer.dropEffect = 'move';
      this.classList.add('nano-cart-admin-thumb-drop-target');
      return false;
    }
    function onDragEnd() {
      grid.querySelectorAll('.nano-cart-admin-thumb-item').forEach(function (el) {
        el.classList.remove('nano-cart-admin-thumb-dragging', 'nano-cart-admin-thumb-drop-target');
      });
    }
    function onDrop(e) {
      e.preventDefault();
      this.classList.remove('nano-cart-admin-thumb-drop-target');
      if (!dragSrc || dragSrc === this) return false;
      var from = parseInt(dragSrc.dataset.idx, 10);
      var to   = parseInt(this.dataset.idx, 10);
      if (isNaN(from) || isNaN(to)) return false;
      var moved = state.images.splice(from, 1)[0];
      state.images.splice(to, 0, moved);
      // First in order becomes primary if nothing is explicitly primary.
      if (!state.images.some(function (i) { return i.is_primary; })) {
        state.images[0].is_primary = true;
      }
      render();
      persist();
      return false;
    }

    function escapeAttr(s) {
      return String(s).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }
    function escapeText(s) {
      return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }
  }

  function template(single) {
    return ''
      + '<div class="nano-cart-admin-image-manager-inner">'
      +   '<div class="nano-cart-admin-subfolder">'
      +     '<label>Subfolder (optional, one level deep)'
      +       '<select class="nano-cart-admin-subfolder-select">'
      +         '<option value="">- (root) -</option>'
      +         '<option value="__new__">+ Create new subfolder</option>'
      +       '</select>'
      +     '</label>'
      +     '<input type="text" class="nano-cart-admin-subfolder-new" placeholder="new-subfolder-name" pattern="[a-z0-9][a-z0-9-]*[a-z0-9]">'
      +   '</div>'
      +   '<div class="nano-cart-admin-upload-zone" tabindex="0">'
      +     '<p>Drop ' + (single ? 'an image' : 'images') + ' here or '
      +       '<button type="button" class="nano-cart-admin-browse-btn nano-cart-admin-link">browse</button>'
      +     '</p>'
      +     '<p class="nano-cart-admin-help">JPEG, PNG, or WebP. Up to 10 MB per file. Variants in 3 sizes (JPEG + WebP) generated automatically.</p>'
      +     '<input type="file" accept="image/jpeg,image/png,image/webp"' + (single ? '' : ' multiple') + ' hidden>'
      +   '</div>'
      +   '<div class="nano-cart-admin-upload-progress" aria-live="polite"></div>'
      +   '<div class="nano-cart-admin-gallery-grid"></div>'
      + '</div>';
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
