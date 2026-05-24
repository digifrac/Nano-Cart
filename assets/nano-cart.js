/* Nano Cart - minimal vanilla JS.
   Lazy image loading, broken-image fallback, gallery keyboard nav,
   sticky buy button on mobile scroll. No framework, no build step. */

(function () {
  'use strict';

  /* --- Lazy image loading via IntersectionObserver ----------------- */

  function loadImage(img) {
    var src = img.getAttribute('data-src');
    if (!src) return;
    img.addEventListener('load', function () {
      img.classList.add('nano-cart-loaded');
    }, { once: true });
    img.addEventListener('error', function () {
      img.classList.add('nano-cart-error');
      img.removeAttribute('data-src');
    }, { once: true });
    img.src = src;
    img.removeAttribute('data-src');
  }

  function setupLazyLoading(root) {
    var imgs = root.querySelectorAll('img[data-src]');
    if (!imgs.length) return;
    if (!('IntersectionObserver' in window)) {
      imgs.forEach(loadImage);
      return;
    }
    var observer = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) {
          loadImage(entry.target);
          observer.unobserve(entry.target);
        }
      });
    }, { rootMargin: '200px' });
    imgs.forEach(function (img) { observer.observe(img); });
  }

  /* --- Mobile gallery keyboard nav --------------------------------- */

  function setupGalleryKeyboardNav() {
    var thumbs = document.querySelectorAll('.nano-cart-gallery-thumb-img');
    if (!thumbs.length) return;
    thumbs.forEach(function (img, i) {
      img.tabIndex = 0;
      img.addEventListener('keydown', function (e) {
        if (e.key === 'ArrowRight' && i + 1 < thumbs.length) {
          e.preventDefault();
          thumbs[i + 1].focus();
          thumbs[i + 1].scrollIntoView({ block: 'nearest', inline: 'center', behavior: 'smooth' });
        } else if (e.key === 'ArrowLeft' && i > 0) {
          e.preventDefault();
          thumbs[i - 1].focus();
          thumbs[i - 1].scrollIntoView({ block: 'nearest', inline: 'center', behavior: 'smooth' });
        }
      });
    });
  }

  /* --- Sticky buy button on mobile --------------------------------- */

  function setupStickyBuy() {
    var btn = document.querySelector('.nano-cart-product .nano-cart-buy-button');
    if (!btn) return;
    if (window.matchMedia('(min-width: 768px)').matches) return;

    var fold = btn.getBoundingClientRect().top + window.pageYOffset;
    function update() {
      if (window.pageYOffset > fold + 100) {
        btn.classList.add('nano-cart-sticky');
      } else {
        btn.classList.remove('nano-cart-sticky');
      }
    }
    update();
    window.addEventListener('scroll', update, { passive: true });
    window.addEventListener('resize', function () {
      fold = btn.getBoundingClientRect().top + window.pageYOffset;
      update();
    });
  }

  /* --- Boot -------------------------------------------------------- */

  function init() {
    var root = document.querySelector('.nano-cart-main') || document.body;
    setupLazyLoading(root);
    setupGalleryKeyboardNav();
    setupStickyBuy();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
