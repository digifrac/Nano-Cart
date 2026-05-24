/* Nano Cart admin - vanilla JS.
   Markdown toolbar, preview toggle, delete confirmations. No
   framework, no build step. */

(function () {
  'use strict';

  function wrapSelection(textarea, before, after) {
    var start = textarea.selectionStart;
    var end   = textarea.selectionEnd;
    var sel   = textarea.value.substring(start, end);
    var replacement = before + sel + after;
    textarea.setRangeText(replacement, start, end, 'select');
    textarea.focus();
  }

  function insertAtCursor(textarea, text) {
    var start = textarea.selectionStart;
    textarea.setRangeText(text, start, start, 'end');
    textarea.focus();
  }

  function prefixLines(textarea, prefix) {
    var start = textarea.selectionStart;
    var end   = textarea.selectionEnd;
    var before = textarea.value.substring(0, start);
    var sel    = textarea.value.substring(start, end);
    var after  = textarea.value.substring(end);
    var lines  = (sel || 'List item').split('\n');
    var prefixed = lines.map(function (l) { return prefix + l; }).join('\n');
    textarea.value = before + prefixed + after;
    textarea.selectionStart = start;
    textarea.selectionEnd = start + prefixed.length;
    textarea.focus();
  }

  function handleAction(textarea, action) {
    switch (action) {
      case 'bold':      wrapSelection(textarea, '**', '**'); break;
      case 'italic':    wrapSelection(textarea, '*', '*');   break;
      case 'link':
        var url = window.prompt('Link URL', 'https://');
        if (url) wrapSelection(textarea, '[', '](' + url + ')');
        break;
      case 'bullet':    prefixLines(textarea, '- ');         break;
      case 'paragraph': insertAtCursor(textarea, '\n\n');     break;
    }
  }

  function setupMarkdownEditor(editor) {
    var textarea = editor.querySelector('textarea');
    var toolbar  = editor.querySelector('.nano-cart-admin-md-toolbar');
    var preview  = editor.querySelector('.nano-cart-admin-md-preview');
    if (!textarea || !toolbar) return;

    toolbar.querySelectorAll('button[data-action]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        handleAction(textarea, btn.getAttribute('data-action'));
      });
    });

    var toggle = toolbar.querySelector('[data-toggle-preview]');
    if (toggle && preview) {
      toggle.addEventListener('click', function () {
        if (preview.hidden) {
          preview.innerHTML = naiveMarkdownToHtml(textarea.value);
          preview.hidden = false;
          toggle.textContent = 'Edit';
        } else {
          preview.hidden = true;
          toggle.textContent = 'Preview';
        }
      });
    }
  }

  // Very simple client-side preview: paragraphs, bold, italic, links, bullet
  // lists. The shop renders through Parsedown server-side; this is just a
  // sanity-check view, not authoritative.
  function naiveMarkdownToHtml(src) {
    var html = src
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;');
    html = html
      .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
      .replace(/\*(.+?)\*/g, '<em>$1</em>')
      .replace(/\[(.+?)\]\((.+?)\)/g, '<a href="$2" target="_blank" rel="noopener">$1</a>');
    var blocks = html.split(/\n{2,}/).map(function (block) {
      if (/^\s*-\s+/.test(block)) {
        var items = block.split('\n').filter(function (l) { return /^\s*-\s+/.test(l); })
          .map(function (l) { return '<li>' + l.replace(/^\s*-\s+/, '') + '</li>'; });
        return '<ul>' + items.join('') + '</ul>';
      }
      return '<p>' + block.replace(/\n/g, '<br>') + '</p>';
    });
    return blocks.join('');
  }

  function setupConfirms() {
    document.querySelectorAll('.nano-cart-admin-button-danger').forEach(function (btn) {
      btn.addEventListener('click', function (e) {
        if (btn.dataset.confirmed === '1') return;
        if (!window.confirm('This is permanent. Are you sure?')) {
          e.preventDefault();
        } else {
          btn.dataset.confirmed = '1';
        }
      });
    });
  }

  function init() {
    document.querySelectorAll('.nano-cart-admin-markdown-editor').forEach(setupMarkdownEditor);
    setupConfirms();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
