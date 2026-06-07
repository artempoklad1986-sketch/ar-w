// ============================================================
// PrintCRM v3.0 — js/modules.js
// ============================================================

(function () {
  'use strict';

  const KEY = '12345';

  async function loadModuleJS(id) {
    try {
      const url  = `/api/module/?action=__getjs__&module=${id}&key=${KEY}`;
      const resp = await fetch(url, { headers: { 'X-Api-Key': KEY } });

      if (!resp.ok) {
        console.warn('[Modules] не найден:', id, resp.status);
        return;
      }

      const html = await resp.text();
      if (!html.trim()) {
        console.warn('[Modules] пустой ответ:', id);
        return;
      }

      const tmp = document.createElement('div');
      tmp.innerHTML = html;
      const scripts = tmp.querySelectorAll('script');

      if (scripts.length === 0) {
        console.warn('[Modules] нет <script> тегов для:', id);
        return;
      }

      for (const s of scripts) {
        await new Promise((resolve) => {
          // Ждём CRM готовности
          function waitCRM() {
            if (window.CRM && window.CRM.registerModule) {
              // Грузим через Blob URL — никаких проблем с кавычками
              const blob = new Blob([s.textContent], { type: 'text/javascript' });
              const blobUrl = URL.createObjectURL(blob);
              const el = document.createElement('script');
              el.src = blobUrl;
              el.onload = () => { URL.revokeObjectURL(blobUrl); resolve(); };
              el.onerror = (e) => { console.error('[Modules] ошибка скрипта:', id, e); URL.revokeObjectURL(blobUrl); resolve(); };
              document.head.appendChild(el);
            } else {
              setTimeout(waitCRM, 50);
            }
          }
          waitCRM();
        });
      }

      console.log('[Modules] загружен:', id, '| скриптов:', scripts.length);

    } catch (e) {
      console.error('[Modules] ошибка:', id, e.message);
    }
  }

  async function loadAll() {
    try {
      const resp = await fetch(`/api/registry/?key=${KEY}`, {
        headers: { 'X-Api-Key': KEY },
      });
      const data = await resp.json();

      if (data.ok && Array.isArray(data.modules) && data.modules.length > 0) {
        console.log('[Modules] registry:', data.modules.length, 'модулей →', data.modules.map(m => m.id));
        for (const mod of data.modules) {
          await loadModuleJS(mod.id);
        }
      } else {
        console.warn('[Modules] registry пуст, fallback → shift');
        await loadModuleJS('shift');
      }

    } catch (e) {
      console.error('[Modules] registry error:', e.message);
      await loadModuleJS('shift');
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', loadAll);
  } else {
    loadAll();
  }

})();