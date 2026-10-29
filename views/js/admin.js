/**
 * Cloudflare integration for PrestaShop 9 — back-office behaviour.
 * Plain JS, no dependencies.
 */
(function () {
  'use strict';

  function onReady(fn) {
    if (document.readyState !== 'loading') {
      fn();
    } else {
      document.addEventListener('DOMContentLoaded', fn);
    }
  }

  function escapeHtml(text) {
    var div = document.createElement('div');
    div.textContent = String(text);
    return div.innerHTML;
  }

  // Confirmation for destructive buttons.
  function initConfirms() {
    document.querySelectorAll('.ppcf form.ppcf-confirm').forEach(function (form) {
      form.addEventListener('submit', function (event) {
        var message = form.getAttribute('data-confirm');
        if (message && !window.confirm(message)) {
          event.preventDefault();
        }
      });
    });
  }

  // Prevent double submits on every module POST form.
  function initSubmitLock() {
    document.querySelectorAll('.ppcf form[method="post"]').forEach(function (form) {
      form.addEventListener('submit', function (event) {
        if (event.defaultPrevented) {
          return;
        }
        form.querySelectorAll('button[type="submit"]').forEach(function (button) {
          window.setTimeout(function () { button.disabled = true; }, 0);
        });
      });
    });
  }

  // Shops & zones: clicking a "Suggested" badge fills the zone + scope selects.
  function initSuggestions() {
    document.querySelectorAll('.ppcf .ppcf-apply-suggestion').forEach(function (button) {
      button.addEventListener('click', function () {
        var shop = button.getAttribute('data-shop');
        var zone = document.querySelector('.ppcf-zone-select[data-shop="' + shop + '"]');
        var scope = document.querySelector('.ppcf-scope-select[data-shop="' + shop + '"]');
        if (zone) {
          zone.value = button.getAttribute('data-zone');
        }
        if (scope && button.getAttribute('data-scope')) {
          scope.value = button.getAttribute('data-scope');
        }
      });
    });
  }

  // Tools: custom purge placeholder follows the selected type.
  function initCustomPurge() {
    var type = document.getElementById('ppcf-custom-type');
    var items = document.getElementById('ppcf-custom-items');
    if (!type || !items) {
      return;
    }
    var update = function () {
      var option = type.options[type.selectedIndex];
      items.placeholder = option ? (option.getAttribute('data-placeholder') || '') : '';
    };
    type.addEventListener('change', update);
    update();
  }

  // Tools: lazy read of Development Mode / security level for each zone.
  function initZoneStatus() {
    var i18n = window.ppcloudflareI18n || {};
    var levels = i18n.levels || {};

    function render(container, setting, data) {
      var target = container.querySelector('.ppcf-state[data-setting="' + setting + '"] .ppcf-state-value');
      if (!target) {
        return;
      }
      if (!data || data.error) {
        target.className = 'ppcf-state-value text-danger small';
        target.innerHTML = escapeHtml((i18n.error || '') + ' ' + (data && data.error ? data.error : '?'));
        return;
      }
      var value = data.value;
      var badge = 'badge-secondary';
      var label = String(value);
      if (setting === 'development_mode') {
        badge = value === 'on' ? 'badge-warning' : 'badge-success';
        label = value === 'on' ? (i18n.on || 'On') : (i18n.off || 'Off');
      } else {
        badge = value === 'under_attack' ? 'badge-danger' : 'badge-info';
        label = levels[value] || label;
      }
      target.className = 'ppcf-state-value';
      target.innerHTML = escapeHtml(i18n.current || '') + ' <span class="badge ' + badge + '">' + escapeHtml(label) + '</span>';
    }

    document.querySelectorAll('.ppcf .ppcf-zone[data-status-url]').forEach(function (container) {
      fetch(container.getAttribute('data-status-url'), {
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' }
      })
        .then(function (response) {
          return response.json().catch(function () {
            return { error: 'HTTP ' + response.status };
          });
        })
        .then(function (json) {
          if (json && json.error && !json.development_mode) {
            render(container, 'development_mode', json);
            render(container, 'security_level', json);
            return;
          }
          render(container, 'development_mode', json.development_mode);
          render(container, 'security_level', json.security_level);
        })
        .catch(function (error) {
          var data = { error: error && error.message ? error.message : 'network error' };
          render(container, 'development_mode', data);
          render(container, 'security_level', data);
        });
    });
  }

  onReady(function () {
    initConfirms();
    initSubmitLock();
    initSuggestions();
    initCustomPurge();
    initZoneStatus();
  });
})();
