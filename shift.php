<?php
// @name          Касса смены
// @icon          🧾
// @description   Кассовая смена — операции, Z-отчёт, история, зарплата, QR
// @version       3.0
// @sidebar       true
// @color         #f59e0b

if (isset($moduleDB)) {
    $cols = array_column($moduleDB->query("PRAGMA table_info(shifts)"), 'name');
    if (!in_array('operations', $cols)) {
        try { $moduleDB->execute("ALTER TABLE shifts ADD COLUMN operations TEXT DEFAULT '[]'"); } catch (Throwable) {}
    }
}
?>
</php>
<script>
(function () {
  'use strict';

  const API = {
    get:  (e, p)  => fetch('/api/' + e + '/?' + new URLSearchParams(Object.assign({ key: '12345' }, p || {})), { headers: { 'X-Api-Key': '12345' } }).then(r => r.json()),
    post: (e, b)  => fetch('/api/' + e + '/?key=12345', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-Api-Key': '12345' }, body: JSON.stringify(b) }).then(r => r.json()),
    put:  (e, b)  => fetch('/api/' + e + '/?key=12345', { method: 'PUT',  headers: { 'Content-Type': 'application/json', 'X-Api-Key': '12345' }, body: JSON.stringify(b) }).then(r => r.json()),
    del:  (e, p)  => fetch('/api/' + e + '/?' + new URLSearchParams(Object.assign({ key: '12345' }, p || {})), { method: 'DELETE', headers: { 'X-Api-Key': '12345' } }).then(r => r.json()),
  };

  let _el       = null;
  let _shift    = null;
  let _staff    = [];
  let _history  = [];
  let _opSearch = '';
  let _view     = 'main'; // main | reports | settings
  let _buttons  = [
    { id: 'b1', label: 'Фото 10×15',    amount: 15,  type: 'income',  icon: '📸', color: '#f59e0b' },
    { id: 'b2', label: 'Копия А4',      amount: 10,  type: 'income',  icon: '📄', color: '#10b981' },
    { id: 'b3', label: 'Печать А4 цв.', amount: 20,  type: 'income',  icon: '🖨️', color: '#3b82f6' },
  ];

  // ── Утилиты ───────────────────────────────────────────────
  const fmt    = n  => Number(n || 0).toLocaleString('ru-RU', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  const fmtT   = dt => dt ? new Date(dt).toLocaleString('ru-RU', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' }) : '—';
  const fmtDur = (a, b) => {
    if (!a) return '—';
    const sec = Math.floor((new Date(b || Date.now()) - new Date(a)) / 1000);
    const h   = Math.floor(sec / 3600);
    const m   = Math.floor((sec % 3600) / 60);
    return h + 'ч ' + String(m).padStart(2, '0') + 'м';
  };
  const esc  = s => String(s || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  const $    = s => _el ? _el.querySelector(s) : document.querySelector(s);
  const $$   = s => _el ? [..._el.querySelectorAll(s)] : [...document.querySelectorAll(s)];
  const ntf  = (m, t) => window.notify ? window.notify(m, t || 'info') : alert(m);

  // ── Загрузка ──────────────────────────────────────────────
  async function loadAll() {
    await Promise.all([loadShift(), loadStaff()]);
    loadButtons();
  }

  async function loadShift() {
    try {
      const r = await API.get('shifts', {});
      if (r.ok && r.data) {
        _shift   = r.data.current || null;
        _history = r.data.history || [];
      }
    } catch (e) { console.error('[Shift]', e); }
  }

  async function loadStaff() {
    try {
      const r = await API.get('staff', {});
      if (r.ok) _staff = r.data || [];
    } catch (e) {}
  }

  function loadButtons() {
    try {
      const s = localStorage.getItem('crm_shift_buttons_v3');
      if (s) _buttons = JSON.parse(s);
    } catch (e) {}
  }

  function saveButtons() {
    localStorage.setItem('crm_shift_buttons_v3', JSON.stringify(_buttons));
  }

  // ── Открыть смену ─────────────────────────────────────────
  async function openShift() {
    const empId     = $('[data-emp]')?.value || '';
    const startCash = parseFloat($('[data-start-cash]')?.value || 0);
    const emp       = _staff.find(s => s.id === empId);
    const manager   = emp?.name || 'Менеджер';

    const r = await API.post('shifts', { action: 'open', empId, manager, startCash });
    if (r.ok) {
      _shift = r.data;
      ntf('🔓 Смена открыта — ' + manager, 'success');
      render();
    } else {
      ntf('Ошибка: ' + (r.error || ''), 'error');
    }
  }

  // ── Операция ──────────────────────────────────────────────
  async function addOperation() {
    if (!_shift) { ntf('Смена не открыта', 'error'); return; }

    const type   = $('[data-op-type]')?.value   || 'income';
    const price  = parseFloat($('[data-op-price]')?.value  || 0);
    const qty    = Math.max(1, parseInt($('[data-op-qty]')?.value || 1));
    const desc   = $('[data-op-desc]')?.value?.trim() || '';
    const method = $('[data-op-method]')?.dataset?.method || 'Наличные';

    if (price <= 0) { ntf('Введите цену за 1 шт.', 'error'); return; }
    if (!desc)      { ntf('Введите описание', 'error'); return; }

    const r = await API.put('shifts', { action: 'operation', type, amount: price, desc, method, qty });
    if (r.ok) {
      _shift = r.data;
      // Сброс формы
      const priceEl = $('[data-op-price]'); if (priceEl) priceEl.value = '';
      const descEl  = $('[data-op-desc]');  if (descEl)  descEl.value  = '';
      const qtyEl   = $('[data-op-qty]');   if (qtyEl)   qtyEl.value   = '1';
      renderOps();
      renderHeader();
    } else {
      ntf('Ошибка: ' + (r.error || ''), 'error');
    }
  }

  async function deleteOp(opId) {
    if (!confirm('Удалить операцию?')) return;
    const r = await API.put('shifts', { action: 'deleteOperation', opId });
    if (r.ok) { _shift = r.data; renderOps(); renderHeader(); }
    else ntf('Ошибка: ' + (r.error || ''), 'error');
  }

  async function editOp(opId) {
    const ops = Array.isArray(_shift?.operations) ? _shift.operations : [];
    const op  = ops.find(o => String(o.id) === String(opId));
    if (!op) return;
    const newDesc   = prompt('Описание:', op.desc   || ''); if (newDesc === null) return;
    const newAmount = parseFloat(prompt('Сумма:', op.amount || 0));
    if (!newAmount || newAmount <= 0) { ntf('Неверная сумма', 'error'); return; }
    const newMethod = prompt('Метод:', op.method || 'Наличные') || 'Наличные';
    const r = await API.put('shifts', { action: 'editOperation', opId, amount: newAmount, desc: newDesc, method: newMethod, qty: op.qty || 1, price: newAmount });
    if (r.ok) { _shift = r.data; renderOps(); renderHeader(); }
    else ntf('Ошибка: ' + (r.error || ''), 'error');
  }

  // ── Быстрые кнопки ────────────────────────────────────────
  function quickBtn(btn) {
    if (!_shift) { ntf('Сначала откройте смену', 'error'); return; }
    const amount = parseFloat(btn.dataset.amount);
    const type   = btn.dataset.type;
    const label  = btn.dataset.label;

    // Заполняем форму
    const typeEl  = $('[data-op-type]');
    const priceEl = $('[data-op-price]');
    const descEl  = $('[data-op-desc]');
    if (typeEl)  typeEl.value  = type;
    if (priceEl) priceEl.value = amount > 0 ? amount : '';
    if (descEl)  descEl.value  = label;

    // Подсветка активной кнопки
    $$('[data-quick]').forEach(b => b.style.borderColor = '');
    btn.style.borderColor = '#f59e0b';

    // Если сумма > 0 — сразу фокус на кол-во
    if (amount > 0) {
      $('[data-op-qty]')?.focus();
    } else {
      priceEl?.focus();
    }

    // Обновляем подсветку метода
    renderMethodTabs(type);
  }

  // ── QR-код для оплаты ─────────────────────────────────────
  function showQR() {
    const amount = parseFloat($('[data-op-price]')?.value || 0);
    const qty    = Math.max(1, parseInt($('[data-op-qty]')?.value || 1));
    const total  = Math.round(amount * qty * 100) / 100;
    const s      = window.State?.settings || {};
    const sbpPhone = s.sbpPhone || s.phone || '';
    const bank     = s.sbpBank  || 'СБП';
    const company  = s.company  || 'Фотокопицентр';

    if (total <= 0) { ntf('Введите сумму для QR', 'error'); return; }

    // Формируем СБП deep-link
    const sbpUrl = sbpPhone
      ? 'https://qr.nspk.ru/AS100003N9QZSJSLFXW0UUB6L23E0UDB?type=01&bank=' + encodeURIComponent(bank) + '&sum=' + Math.round(total * 100) + '&cur=RUB&crc=A3DD'
      : null;

    // QR через бесплатный API
    const qrData  = sbpUrl || ('Оплата ' + company + ' ' + total + ' руб.');
    const qrUrl   = 'https://api.qrserver.com/v1/create-qr-code/?size=220x220&data=' + encodeURIComponent(qrData);

    const html =
      '<div style="position:fixed;inset:0;background:rgba(0,0,0,.8);z-index:9999;display:flex;align-items:center;justify-content:center" id="qrOverlay" onclick="if(event.target.id===\'qrOverlay\')this.remove()">' +
        '<div style="background:#141828;border-radius:16px;padding:28px;text-align:center;max-width:320px;width:90%">' +
          '<div style="font-size:13px;color:#64748b;margin-bottom:8px">СБП · ' + esc(bank) + '</div>' +
          '<div style="font-size:28px;font-weight:700;color:#10b981;margin-bottom:16px">' + fmt(total) + ' ₽</div>' +
          '<img src="' + qrUrl + '" style="width:220px;height:220px;border-radius:12px;background:#fff;padding:8px" alt="QR">' +
          (sbpPhone ? '<div style="font-size:12px;color:#64748b;margin-top:10px">' + esc(sbpPhone) + '</div>' : '') +
          '<div style="font-size:11px;color:#64748b;margin-top:6px">' + esc(company) + '</div>' +
          '<button onclick="document.getElementById(\'qrOverlay\').remove()" style="margin-top:16px;width:100%;padding:11px;background:#1e2a3a;color:#e2e8f0;border:none;border-radius:8px;cursor:pointer">Закрыть</button>' +
          '<button onclick="window._shiftQRPaid && window._shiftQRPaid(' + total + ')" style="margin-top:8px;width:100%;padding:11px;background:#10b981;color:#fff;border:none;border-radius:8px;cursor:pointer;font-weight:700">✅ Оплачено — провести приход</button>' +
        '</div>' +
      '</div>';

    document.body.insertAdjacentHTML('beforeend', html);
  }

  window._shiftQRPaid = function (total) {
    document.getElementById('qrOverlay')?.remove();
    const descEl = $('[data-op-desc]');
    const desc   = descEl?.value?.trim() || 'Оплата СБП';
    API.put('shifts', { action: 'operation', type: 'income', amount: total, desc, method: 'СБП', qty: 1 })
      .then(r => {
        if (r.ok) { _shift = r.data; renderOps(); renderHeader(); ntf('✅ Приход ' + fmt(total) + ' ₽ записан', 'success'); }
        else ntf('Ошибка: ' + (r.error || ''), 'error');
      });
  };

  // ── Закрыть смену ─────────────────────────────────────────
  async function closeShift() {
    if (!_shift) return;
    const endCash    = parseFloat($('[data-end-cash]')?.value    || _shift.cash || 0);
    const baseSalary = parseFloat($('[data-base-salary]')?.value || 0);
    const note       = $('[data-shift-note]')?.value             || '';
    const calc       = parseFloat(_shift.cash || 0);
    const diff       = Math.round((endCash - calc) * 100) / 100;

    if (!confirm(
      'Закрыть смену и сформировать Z-отчёт?\n\n' +
      'Расчётный остаток: ' + fmt(calc) + ' ₽\n' +
      'Фактический: ' + fmt(endCash) + ' ₽\n' +
      'Расхождение: ' + (diff >= 0 ? '+' : '') + fmt(diff) + ' ₽'
    )) return;

    const r = await API.put('shifts', { action: 'close', endCash, baseSalary, note });
    if (r.ok) {
      _shift = null;
      await loadShift();
      ntf('🔒 Смена закрыта', 'success');
      showZReport(r.report);
      render();
    } else {
      ntf('Ошибка: ' + (r.error || ''), 'error');
    }
  }

  // ── Z-отчёт ───────────────────────────────────────────────
  function showZReport(rep) {
    if (!rep) return;
    const diffColor = Math.abs(rep.cashDiff) < 0.01 ? '#10b981' : (rep.cashDiff < 0 ? '#ef4444' : '#f59e0b');
    const methRows  = Object.entries(rep.methodTotals || {}).map(([m, v]) =>
      '<tr><td style="padding:5px 10px;color:#94a3b8">' + esc(m) + '</td>' +
      '<td style="padding:5px 10px;text-align:right;color:#10b981">+' + fmt(v.income || 0) + ' ₽</td>' +
      '<td style="padding:5px 10px;text-align:right;color:#ef4444">−' + fmt(v.expense || 0) + ' ₽</td></tr>'
    ).join('');

    document.body.insertAdjacentHTML('beforeend',
      '<div id="zRep" style="position:fixed;inset:0;background:rgba(0,0,0,.8);z-index:9999;display:flex;align-items:center;justify-content:center;padding:16px">' +
        '<div style="background:#0d0f1a;border:1px solid #1e2a3a;border-radius:16px;padding:0;max-width:560px;width:100%;max-height:90vh;overflow-y:auto">' +

          // Шапка
          '<div style="background:linear-gradient(135deg,#141828,#1a1f35);padding:20px 24px;border-radius:16px 16px 0 0;display:flex;justify-content:space-between;align-items:center">' +
            '<div>' +
              '<div style="font-size:11px;color:#64748b;text-transform:uppercase;letter-spacing:.5px">Z-Отчёт · Смена закрыта</div>' +
              '<div style="font-size:18px;font-weight:700;color:#f59e0b;margin-top:4px">' + esc(rep.manager || '—') + '</div>' +
              '<div style="font-size:12px;color:#64748b">' + fmtT(rep.openTime) + ' → ' + fmtT(rep.closeTime) + '</div>' +
            '</div>' +
            '<button onclick="document.getElementById(\'zRep\').remove()" style="background:none;border:none;color:#64748b;font-size:24px;cursor:pointer;line-height:1">✕</button>' +
          '</div>' +

          '<div style="padding:20px 24px">' +

            // Итоги 4 карточки
            '<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:20px">' +
              _zCard('Доход', '+' + fmt(rep.totalIncome) + ' ₽', '#10b981') +
              _zCard('Расход', '−' + fmt(rep.totalExpense) + ' ₽', '#ef4444') +
              _zCard('Прибыль', fmt((rep.totalIncome || 0) - (rep.totalExpense || 0)) + ' ₽', '#7c3aed') +
              _zCard('Операций', rep.operationsCount || 0, '#06b6d4') +
            '</div>' +

            // Таблица
            '<table style="width:100%;border-collapse:collapse;font-size:13px;margin-bottom:16px">' +
              _zRow('Нач. остаток', fmt(rep.startCash) + ' ₽') +
              _zRow('Расч. остаток', fmt(rep.calcCash) + ' ₽') +
              _zRow('Факт. остаток', fmt(rep.endCash) + ' ₽') +
              '<tr style="background:' + (Math.abs(rep.cashDiff) < 0.01 ? '#0a2018' : '#1a0a0a') + '">' +
                '<td style="padding:8px 10px;font-weight:600">Расхождение</td>' +
                '<td style="padding:8px 10px;text-align:right;font-weight:700;color:' + diffColor + '">' +
                  (rep.cashDiff >= 0 ? '+' : '') + fmt(rep.cashDiff) + ' ₽' +
                '</td>' +
              '</tr>' +
              _zRow('Оклад за день', fmt(rep.baseSalary) + ' ₽') +
              _zRow('Бонус ' + Math.round((rep.bonusPct || 0) * 100) + '% с дохода', fmt(rep.accruedBonus) + ' ₽') +
              '<tr style="background:#1a1205"><td style="padding:8px 10px;color:#f59e0b;font-weight:700">Итого зарплата</td>' +
                '<td style="padding:8px 10px;text-align:right;color:#f59e0b;font-weight:700;font-size:16px">' + fmt(rep.totalSalary) + ' ₽</td></tr>' +
            '</table>' +

            // По методам
            (methRows
              ? '<div style="margin-bottom:16px"><div style="font-size:11px;color:#64748b;text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px">По методам оплаты</div>' +
                '<table style="width:100%;border-collapse:collapse;font-size:12px">' +
                  '<tr style="color:#64748b"><th style="padding:4px 10px;text-align:left;font-weight:400">Метод</th><th style="padding:4px 10px;text-align:right;font-weight:400">Приход</th><th style="padding:4px 10px;text-align:right;font-weight:400">Расход</th></tr>' +
                  methRows + '</table></div>'
              : '') +

            (rep.note ? '<div style="background:#141828;border-radius:8px;padding:10px 14px;margin-bottom:16px;font-size:13px;color:#94a3b8">📝 ' + esc(rep.note) + '</div>' : '') +

            '<div style="display:flex;gap:10px">' +
              '<button onclick="document.getElementById(\'zRep\').remove()" style="flex:1;padding:12px;background:#1e2a3a;color:#e2e8f0;border:none;border-radius:8px;cursor:pointer;font-size:14px">Закрыть</button>' +
              '<button onclick="window._shiftPrint && window._shiftPrint()" style="flex:1;padding:12px;background:linear-gradient(135deg,#f59e0b,#d97706);color:#000;border:none;border-radius:8px;cursor:pointer;font-size:14px;font-weight:700">🖨️ Печать</button>' +
            '</div>' +
          '</div>' +
        '</div>' +
      '</div>'
    );

    window._shiftPrint = () => printZReport(rep);
  }

  function _zCard(label, value, color) {
    return '<div style="background:#141828;border-radius:10px;padding:12px;text-align:center">' +
      '<div style="font-size:11px;color:#64748b;margin-bottom:4px">' + label + '</div>' +
      '<div style="font-size:16px;font-weight:700;color:' + color + '">' + value + '</div>' +
      '</div>';
  }

  function _zRow(label, value) {
    return '<tr style="border-bottom:1px solid #1e2a3a">' +
      '<td style="padding:7px 10px;color:#94a3b8">' + label + '</td>' +
      '<td style="padding:7px 10px;text-align:right">' + value + '</td>' +
      '</tr>';
  }

  // ── Печать Z-отчёта ───────────────────────────────────────
  function printZReport(rep) {
    const s = window.State?.settings || {};
    const w = window.open('', '_blank', 'width=420,height=700');
    const methHtml = Object.entries(rep.methodTotals || {}).map(([m, v]) =>
      '<tr><td>' + esc(m) + '</td><td style="text-align:right">+' + fmt(v.income || 0) + '</td><td style="text-align:right">−' + fmt(v.expense || 0) + '</td></tr>'
    ).join('');
    w.document.write(
      '<html><head><title>Z-Отчёт ' + esc(rep.shiftDate || '') + '</title>' +
      '<style>*{box-sizing:border-box}body{font-family:Arial,sans-serif;font-size:12px;padding:12px;max-width:400px;margin:0 auto}' +
      'h2,h3{text-align:center;margin:4px 0}table{width:100%;border-collapse:collapse}td,th{padding:4px 6px;border-bottom:1px solid #eee}' +
      '.r{text-align:right}.sep{border-top:2px solid #333;font-weight:bold}.logo{text-align:center;margin-bottom:8px}</style></head><body>' +
      (s.logo_url ? '<div class="logo"><img src="' + s.logo_url + '" style="max-height:50px"></div>' : '') +
      '<h2>' + esc(s.company || 'Фотокопицентр') + '</h2>' +
      '<h3>Z-ОТЧЁТ</h3>' +
      '<p style="text-align:center;font-size:11px;color:#666">' + esc(rep.shiftDate || '') + ' · ' + esc(rep.manager || '') + '</p>' +
      '<p style="text-align:center;font-size:11px;color:#666">' + fmtT(rep.openTime) + ' → ' + fmtT(rep.closeTime) + '</p>' +
      '<hr>' +
      '<table>' +
        '<tr><td>Доход</td><td class="r">+' + fmt(rep.totalIncome) + ' ₽</td></tr>' +
        '<tr><td>Расход</td><td class="r">−' + fmt(rep.totalExpense) + ' ₽</td></tr>' +
        '<tr><td>Прибыль</td><td class="r">' + fmt((rep.totalIncome || 0) - (rep.totalExpense || 0)) + ' ₽</td></tr>' +
        '<tr class="sep"><td>Нач. остаток</td><td class="r">' + fmt(rep.startCash) + ' ₽</td></tr>' +
        '<tr><td>Расч. остаток</td><td class="r">' + fmt(rep.calcCash) + ' ₽</td></tr>' +
        '<tr><td>Факт. остаток</td><td class="r">' + fmt(rep.endCash) + ' ₽</td></tr>' +
        '<tr class="sep"><td>Расхождение</td><td class="r">' + (rep.cashDiff >= 0 ? '+' : '') + fmt(rep.cashDiff) + ' ₽</td></tr>' +
        '<tr><td>Оклад</td><td class="r">' + fmt(rep.baseSalary) + ' ₽</td></tr>' +
        '<tr><td>Бонус ' + Math.round((rep.bonusPct || 0) * 100) + '%</td><td class="r">' + fmt(rep.accruedBonus) + ' ₽</td></tr>' +
        '<tr class="sep"><td><b>Зарплата итого</b></td><td class="r"><b>' + fmt(rep.totalSalary) + ' ₽</b></td></tr>' +
        '<tr><td>Операций</td><td class="r">' + (rep.operationsCount || 0) + '</td></tr>' +
      '</table>' +
      (methHtml
        ? '<hr><h3 style="font-size:11px">По методам</h3>' +
          '<table><tr><th style="text-align:left">Метод</th><th>Приход</th><th>Расход</th></tr>' + methHtml + '</table>'
        : '') +
      (rep.note ? '<p>Заметка: ' + esc(rep.note) + '</p>' : '') +
      (s.signature_url ? '<div style="margin-top:20px"><img src="' + s.signature_url + '" style="max-height:40px"></div><div style="font-size:11px;color:#666">' + esc(s.signatory || '') + '</div>' : '') +
      '<p style="text-align:center;font-size:10px;color:#999;margin-top:16px">Напечатано: ' + new Date().toLocaleString('ru-RU') + '</p>' +
      '</body></html>'
    );
    w.document.close(); w.focus(); w.print();
  }

  // ── Метод оплаты — табы ───────────────────────────────────
  function renderMethodTabs(type) {
    const methods   = type === 'income'
      ? ['Наличные', 'Карта', 'СБП', 'Безнал']
      : ['Наличные', 'Карта', 'Безнал'];
    const methodBar = $('[data-method-bar]');
    if (!methodBar) return;

    methodBar.innerHTML = methods.map((m, i) =>
      '<button data-method-btn="' + esc(m) + '" onclick="window._shiftSetMethod(this)"' +
        ' style="padding:6px 14px;border-radius:6px;border:1px solid ' + (i === 0 ? '#10b981' : '#1e2a3a') + ';' +
        'background:' + (i === 0 ? '#10b981' : 'transparent') + ';' +
        'color:' + (i === 0 ? '#fff' : '#94a3b8') + ';cursor:pointer;font-size:12px;font-weight:' + (i === 0 ? '600' : '400') + '">' +
        esc(m) +
      '</button>'
    ).join('');

    // Устанавливаем скрытый input
    const hidden = $('[data-op-method]');
    if (hidden) { hidden.dataset.method = methods[0]; }

    // Показываем/скрываем кнопку QR
    const qrBtn = $('[data-qr-btn]');
    if (qrBtn) qrBtn.style.display = type === 'income' ? '' : 'none';
  }

  window._shiftSetMethod = function (btn) {
    const method = btn.dataset.methodBtn;
    // Визуал
    $$('[data-method-btn]').forEach(b => {
      b.style.background   = 'transparent';
      b.style.borderColor  = '#1e2a3a';
      b.style.color        = '#94a3b8';
      b.style.fontWeight   = '400';
    });
    btn.style.background  = '#10b981';
    btn.style.borderColor = '#10b981';
    btn.style.color       = '#fff';
    btn.style.fontWeight  = '600';
    // Сохраняем
    const hidden = $('[data-op-method]');
    if (hidden) hidden.dataset.method = method;

    // QR только для СБП
    const qrBtn = $('[data-qr-btn]');
    if (qrBtn) qrBtn.style.opacity = method === 'СБП' ? '1' : '.5';
  };

  // ── Частичный ре-рендер ───────────────────────────────────
  function renderHeader() {
    if (!_shift) return;
    const income  = parseFloat(_shift.total_income  || _shift.income  || 0);
    const expense = parseFloat(_shift.total_expense || _shift.expense || 0);
    const cash    = parseFloat(_shift.cash || 0);
    const bonus   = parseFloat(_shift.accrued_bonus || 0);

    const hdr = $('[data-shift-header]');
    if (!hdr) return;

    const s1 = $('[data-hdr-income]');  if (s1) s1.textContent = fmt(income)  + ' ₽';
    const s2 = $('[data-hdr-expense]'); if (s2) s2.textContent = fmt(expense) + ' ₽';
    const s3 = $('[data-hdr-cash]');    if (s3) s3.textContent = fmt(cash)    + ' ₽';
    const s4 = $('[data-hdr-bonus]');   if (s4) s4.textContent = fmt(bonus)   + ' ₽';

    // Ожидаемая касса в форме закрытия
    const ecEl = $('[data-expected-cash]');
    if (ecEl) ecEl.textContent = 'Ожидается: ' + fmt(cash) + ' ₽';

    // Итого зарплата
    const base = parseFloat($('[data-base-salary]')?.value || 0);
    _updateSalaryPreview(base, bonus, parseFloat(_shift.bonus_pct || 0.1));
  }

  function _updateSalaryPreview(base, bonus, bonusPct) {
    const p1 = $('[data-sal-base]');   if (p1) p1.textContent = fmt(base)  + ' ₽';
    const p2 = $('[data-sal-bonus]');  if (p2) p2.textContent = fmt(bonus) + ' ₽ (бонус ' + Math.round(bonusPct * 100) + '%)';
    const p3 = $('[data-sal-total]');  if (p3) p3.textContent = fmt(base + bonus) + ' ₽';
  }

  function renderOps() {
    const el = $('[data-ops-list]');
    if (!el) return;

    const ops    = Array.isArray(_shift?.operations) ? _shift.operations : [];
    const search = _opSearch.toLowerCase();
    const income  = parseFloat(_shift?.total_income  || _shift?.income  || 0);
    const expense = parseFloat(_shift?.total_expense || _shift?.expense || 0);
    const avg     = ops.length ? ((income + expense) / ops.length) : 0;

    // Счётчики
    const cntEl = $('[data-ops-count]'); if (cntEl) cntEl.textContent = ops.length + ' шт.';
    const avgEl = $('[data-ops-avg]');   if (avgEl) avgEl.textContent = fmt(income) + ' / ' + fmt(expense) + ' / ' + fmt(avg);

    const filtered = ops.slice().reverse().filter(op =>
      !search || (op.desc || '').toLowerCase().includes(search)
    );

    if (!filtered.length) {
      el.innerHTML = '<div style="text-align:center;color:#64748b;padding:20px;font-size:13px">Операций нет</div>';
      return;
    }

    el.innerHTML = filtered.map(op =>
      '<div style="display:flex;align-items:center;gap:8px;padding:9px 14px;border-bottom:1px solid #0d0f1a;transition:background .15s" ' +
        'onmouseenter="this.style.background=\'#141828\'" onmouseleave="this.style.background=\'transparent\'">' +
        '<div style="width:28px;height:28px;border-radius:50%;background:' + (op.type === 'income' ? '#10b98122' : '#ef444422') + ';display:flex;align-items:center;justify-content:center;font-size:14px;flex-shrink:0">' +
          (op.type === 'income' ? '▲' : '▼') +
        '</div>' +
        '<div style="flex:1;min-width:0">' +
          '<div style="font-size:13px;font-weight:500;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">' + esc(op.desc || '—') + '</div>' +
          '<div style="font-size:11px;color:#64748b;margin-top:1px">' + fmtT(op.time) + ' · ' + esc(op.method || 'Наличные') + (op.qty > 1 ? ' · ×' + op.qty + ' по ' + fmt(op.price || op.amount / op.qty) : '') + '</div>' +
        '</div>' +
        '<div style="font-weight:700;font-size:14px;color:' + (op.type === 'income' ? '#10b981' : '#ef4444') + ';white-space:nowrap;flex-shrink:0">' +
          (op.type === 'income' ? '+' : '−') + fmt(op.amount) + ' ₽' +
        '</div>' +
        '<div style="display:flex;gap:4px;flex-shrink:0">' +
          '<button onclick="window._shiftEditOp(\'' + esc(op.id) + '\')" style="background:none;border:1px solid #1e2a3a;border-radius:5px;color:#64748b;cursor:pointer;padding:3px 8px;font-size:12px">✏️</button>' +
          '<button onclick="window._shiftDelOp(\'' + esc(op.id) + '\')"  style="background:none;border:1px solid #1e2a3a;border-radius:5px;color:#ef4444;cursor:pointer;padding:3px 8px;font-size:12px">×</button>' +
        '</div>' +
      '</div>'
    ).join('');
  }

  // ── Настройки кнопок ──────────────────────────────────────
  function renderBtnSettings() {
    const el = $('[data-btn-settings]');
    if (!el) return;

    el.innerHTML =
      '<div style="font-size:12px;color:#64748b;font-weight:600;text-transform:uppercase;letter-spacing:.5px;margin-bottom:12px">Быстрые кнопки</div>' +
      _buttons.map(b =>
        '<div style="display:flex;align-items:center;gap:10px;padding:8px 0;border-bottom:1px solid #1e2a3a">' +
          '<span style="font-size:20px">' + esc(b.icon) + '</span>' +
          '<div style="flex:1">' +
            '<div style="font-size:13px;font-weight:500">' + esc(b.label) + '</div>' +
            '<div style="font-size:11px;color:#64748b">' + (b.type === 'income' ? 'Приход' : 'Расход') + ' · ' + (b.amount > 0 ? fmt(b.amount) + ' ₽' : 'ввод вручную') + '</div>' +
          '</div>' +
          '<input type="color" value="' + esc(b.color) + '" onchange="window._shiftBtnColor(\'' + esc(b.id) + '\',this.value)"' +
            ' style="width:28px;height:28px;border:none;border-radius:4px;cursor:pointer;background:none;padding:0">' +
          '<button onclick="window._shiftDelBtn(\'' + esc(b.id) + '\')" style="background:none;border:1px solid #ef444444;border-radius:6px;color:#ef4444;cursor:pointer;padding:4px 8px;font-size:12px">Удалить</button>' +
        '</div>'
      ).join('') +
      '<button onclick="window._shiftAddBtn()" style="margin-top:14px;width:100%;padding:10px;background:#1e2a3a;border:1px solid #2d3f50;border-radius:8px;color:#94a3b8;cursor:pointer;font-size:13px">+ Добавить кнопку</button>';
  }

  // ── Главный рендер ────────────────────────────────────────
  function render() {
    if (!_el) return;
    if (_view === 'settings') { renderSettingsView(); return; }
    if (_view === 'reports')  { renderReportsView();  return; }
    _shift ? renderMainOpen() : renderMainClosed();
  }

  // ── Шапка модуля (всегда видна) ───────────────────────────
  function _topBar(activeBtn) {
    return (
      '<div style="display:flex;align-items:center;justify-content:space-between;padding:14px 20px;border-bottom:1px solid #1e2a3a;flex-wrap:wrap;gap:8px">' +
        '<div>' +
          '<h2 style="margin:0;font-size:18px;color:#f59e0b;display:flex;align-items:center;gap:8px">🧾 Касса смены</h2>' +
          '<div style="font-size:11px;color:#64748b;margin-top:2px">Синхронизация с финансами и зарплатой в реальном времени</div>' +
        '</div>' +
        '<div style="display:flex;gap:8px">' +
          ['main', 'reports', 'settings'].map((v, i) => {
            const labels = ['💵 Касса', '📊 Отчёты', '⚙️ Смены'];
            const active = activeBtn === v;
            return '<button onclick="window._shiftView(\'' + v + '\')" style="padding:7px 14px;border-radius:8px;border:1px solid ' +
              (active ? '#f59e0b' : '#1e2a3a') + ';background:' + (active ? '#f59e0b18' : 'transparent') +
              ';color:' + (active ? '#f59e0b' : '#64748b') + ';cursor:pointer;font-size:13px;font-weight:' + (active ? '600' : '400') + '">' +
              labels[i] + '</button>';
          }).join('') +
        '</div>' +
      '</div>'
    );
  }

  // ── Закрытая смена ────────────────────────────────────────
  function renderMainClosed() {
    const staffOpts = _staff.map(s =>
      '<option value="' + esc(s.id) + '">' + esc(s.name) + ' · ' + esc(s.role || 'Менеджер') + '</option>'
    ).join('');

    const lastRows = _history.slice(0, 5).map(h => {
      const dur = fmtDur(h.opened_at || h.open_time, h.closed_at || h.close_time);
      return (
        '<tr style="border-bottom:1px solid #0d0f1a;font-size:13px">' +
          '<td style="padding:8px 12px">' + esc(new Date(h.opened_at || h.open_time || '').toLocaleDateString('ru-RU')) + '</td>' +
          '<td style="padding:8px 12px">' + esc(h.manager || h.staff_name || '—') + '</td>' +
          '<td style="padding:8px 12px;color:#64748b">' + dur + '</td>' +
          '<td style="padding:8px 12px;color:#10b981;text-align:right">+' + fmt(h.total_income || h.income || 0) + ' ₽</td>' +
          '<td style="padding:8px 12px;color:#ef4444;text-align:right">−' + fmt(h.total_expense || h.expense || 0) + ' ₽</td>' +
          '<td style="padding:8px 12px;color:#f59e0b;text-align:right">' + fmt(h.end_cash || 0) + ' ₽</td>' +
          '<td style="padding:8px 12px;color:' + ((h.cash_diff || 0) >= 0 ? '#10b981' : '#ef4444') + ';text-align:right">' +
            ((h.cash_diff || 0) >= 0 ? '+' : '') + fmt(h.cash_diff || 0) + ' ₽' +
          '</td>' +
          '<td style="padding:8px 12px;color:#64748b;text-align:right">' + (h.orders_count || 0) + '</td>' +
        '</tr>'
      );
    }).join('');

    _el.innerHTML =
      _topBar('main') +
      '<div style="padding:20px;max-width:1100px;margin:0 auto">' +

        // Форма открытия
        '<div style="background:#141828;border-radius:14px;padding:24px;margin-bottom:20px;max-width:560px">' +
          '<div style="font-size:13px;color:#64748b;font-weight:600;text-transform:uppercase;letter-spacing:.5px;margin-bottom:16px">Открыть новую смену</div>' +

          '<label style="display:block;margin-bottom:12px;font-size:13px;color:#94a3b8">Сотрудник' +
            '<select data-emp style="display:block;width:100%;margin-top:5px;padding:10px 12px;background:#0d0f1a;border:1px solid #1e2a3a;border-radius:8px;color:#e2e8f0;font-size:13px">' +
              '<option value="">— Выберите сотрудника —</option>' + staffOpts +
            '</select>' +
          '</label>' +

          '<label style="display:block;margin-bottom:20px;font-size:13px;color:#94a3b8">Начальный остаток в кассе (₽)' +
            '<input data-start-cash type="number" value="0" min="0" placeholder="0"' +
            ' style="display:block;width:100%;margin-top:5px;padding:10px 12px;background:#0d0f1a;border:1px solid #1e2a3a;border-radius:8px;color:#e2e8f0;font-size:14px;box-sizing:border-box">' +
          '</label>' +

          '<button data-open-shift style="width:100%;padding:13px;background:linear-gradient(135deg,#f59e0b,#d97706);color:#000;border:none;border-radius:10px;cursor:pointer;font-size:15px;font-weight:700">' +
            '🔓 Открыть смену' +
          '</button>' +
        '</div>' +

        // История
        (_history.length
          ? '<div style="background:#141828;border-radius:14px;overflow:hidden">' +
              '<div style="padding:14px 16px;border-bottom:1px solid #0d0f1a;font-size:12px;color:#64748b;font-weight:600;text-transform:uppercase;letter-spacing:.5px">История смен</div>' +
              '<div style="overflow-x:auto">' +
                '<table style="width:100%;border-collapse:collapse;min-width:600px">' +
                  '<tr style="font-size:11px;color:#64748b;text-transform:uppercase">' +
                    '<th style="padding:8px 12px;text-align:left;font-weight:400">Дата</th>' +
                    '<th style="padding:8px 12px;text-align:left;font-weight:400">Менеджер</th>' +
                    '<th style="padding:8px 12px;text-align:left;font-weight:400">Длит.</th>' +
                    '<th style="padding:8px 12px;text-align:right;font-weight:400">Доход</th>' +
                    '<th style="padding:8px 12px;text-align:right;font-weight:400">Расход</th>' +
                    '<th style="padding:8px 12px;text-align:right;font-weight:400">Касса факт</th>' +
                    '<th style="padding:8px 12px;text-align:right;font-weight:400">Расхожд.</th>' +
                    '<th style="padding:8px 12px;text-align:right;font-weight:400">Оп.</th>' +
                  '</tr>' +
                  lastRows +
                '</table>' +
              '</div>' +
            '</div>'
          : '') +

      '</div>';

    $('[data-open-shift]')?.addEventListener('click', openShift);
  }

  // ── Открытая смена ────────────────────────────────────────
  function renderMainOpen() {
    const income   = parseFloat(_shift.total_income  || _shift.income  || 0);
    const expense  = parseFloat(_shift.total_expense || _shift.expense || 0);
    const cash     = parseFloat(_shift.cash          || 0);
    const bonus    = parseFloat(_shift.accrued_bonus || 0);
    const bonusPct = parseFloat(_shift.bonus_pct     || 0.1);
    const manager  = _shift.manager || _shift.staff_name || '—';
    const openTime = _shift.opened_at || _shift.open_time;

    // Быстрые кнопки
    const btns = _buttons.map(b =>
      '<div style="position:relative;flex:1;min-width:90px;max-width:160px">' +
        '<button data-quick data-id="' + esc(b.id) + '" data-amount="' + b.amount + '" data-type="' + esc(b.type) + '" data-label="' + esc(b.label) + '"' +
          ' onclick="window._shiftQuickBtn(this)"' +
          ' style="width:100%;padding:12px 8px;background:' + esc(b.color) + '18;border:1px solid ' + esc(b.color) + '44;border-radius:10px;color:' + esc(b.color) + ';cursor:pointer;display:flex;flex-direction:column;align-items:center;gap:4px;min-height:80px;justify-content:center">' +
          '<span style="font-size:24px">' + esc(b.icon) + '</span>' +
          '<span style="font-size:12px;font-weight:600;text-align:center;line-height:1.2">' + esc(b.label) + '</span>' +
          '<span style="font-size:11px;opacity:.7">' + (b.amount > 0 ? '+' + fmt(b.amount) + ' ₽' : 'ввод') + '</span>' +
        '</button>' +
      '</div>'
    ).join('');

    _el.innerHTML =
      _topBar('main') +

      // Активная смена — шапка
      '<div style="background:linear-gradient(135deg,#141828,#1a1f35);padding:14px 20px;border-bottom:1px solid #1e2a3a" data-shift-header>' +
        '<div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:12px">' +

          '<div>' +
            '<div style="font-size:11px;color:#64748b;text-transform:uppercase;letter-spacing:.5px;margin-bottom:2px">Активная смена</div>' +
            '<div style="font-size:16px;font-weight:700;color:#e2e8f0;display:flex;align-items:center;gap:8px">' +
              '<span style="color:#10b981;font-size:10px">●</span>' + esc(manager) +
            '</div>' +
            '<div style="font-size:12px;color:#64748b;margin-top:2px">Открыта: ' + fmtT(openTime) + '</div>' +
            '<button onclick="window._shiftShowSalary()" style="margin-top:8px;padding:5px 12px;background:#f59e0b18;border:1px solid #f59e0b44;border-radius:6px;color:#f59e0b;cursor:pointer;font-size:12px">💰 Показать мой заработок</button>' +
          '</div>' +

          '<div style="display:flex;gap:16px;flex-wrap:wrap">' +
            '<div style="text-align:right">' +
              '<div style="font-size:11px;color:#64748b">Касса</div>' +
              '<div data-hdr-cash style="font-size:22px;font-weight:700;color:#e2e8f0">' + fmt(cash) + ' ₽</div>' +
            '</div>' +
            '<div style="text-align:right">' +
              '<div style="font-size:11px;color:#10b981">Доход</div>' +
              '<div data-hdr-income style="font-size:22px;font-weight:700;color:#10b981">+' + fmt(income) + ' ₽</div>' +
            '</div>' +
            '<div style="text-align:right">' +
              '<div style="font-size:11px;color:#ef4444">Расход</div>' +
              '<div data-hdr-expense style="font-size:22px;font-weight:700;color:#ef4444">−' + fmt(expense) + ' ₽</div>' +
            '</div>' +
            '<div style="text-align:right">' +
              '<div style="font-size:11px;color:#f59e0b">Бонус ' + Math.round(bonusPct * 100) + '%</div>' +
              '<div data-hdr-bonus style="font-size:22px;font-weight:700;color:#f59e0b">' + fmt(bonus) + ' ₽</div>' +
            '</div>' +
          '</div>' +

        '</div>' +
      '</div>' +

      // Основной контент — 2 колонки
      '<div style="display:grid;grid-template-columns:1fr 380px;gap:0;height:calc(100vh - 220px);min-height:400px">' +

        // ── Левая колонка ──
        '<div style="overflow-y:auto;padding:16px;border-right:1px solid #1e2a3a">' +

          // Быстрые кнопки
          '<div style="margin-bottom:16px">' +
            '<div style="font-size:11px;color:#64748b;text-transform:uppercase;letter-spacing:.5px;margin-bottom:10px">⚡ Быстрые операции · Нажмите — заполнит форму, задайте количество и проведите</div>' +
            '<div style="display:flex;gap:8px;flex-wrap:wrap">' + btns + '</div>' +
          '</div>' +

          // Форма операции
          '<div style="background:#141828;border-radius:12px;padding:16px;margin-bottom:16px">' +

            // Тип + переключатель приход/расход
            '<div style="display:flex;gap:8px;margin-bottom:12px">' +
              '<button onclick="window._shiftSetType(\'income\',this)" data-type-btn="income"' +
                ' style="flex:1;padding:9px;border-radius:8px;border:1px solid #10b98166;background:#10b98118;color:#10b981;cursor:pointer;font-size:13px;font-weight:600">▲ Приход</button>' +
              '<button onclick="window._shiftSetType(\'expense\',this)" data-type-btn="expense"' +
                ' style="flex:1;padding:9px;border-radius:8px;border:1px solid #1e2a3a;background:transparent;color:#64748b;cursor:pointer;font-size:13px;font-weight:400">▼ Расход</button>' +
            '</div>' +

            '<input type="hidden" data-op-type value="income">' +

            // Цена + Количество
            '<div style="display:flex;gap:8px;margin-bottom:12px;align-items:flex-end">' +
              '<label style="flex:1;font-size:12px;color:#94a3b8">Цена за 1 шт. ₽' +
                '<input data-op-price type="number" min="0.01" step="0.01" placeholder="0"' +
                ' style="display:block;width:100%;margin-top:4px;padding:10px 12px;background:#0d0f1a;border:1px solid #1e2a3a;border-radius:8px;color:#e2e8f0;font-size:16px;font-weight:700;box-sizing:border-box">' +
              '</label>' +
              '<div style="flex-shrink:0">' +
                '<div style="font-size:12px;color:#94a3b8;margin-bottom:4px">Количество</div>' +
                '<div style="display:flex;align-items:center;gap:6px">' +
                  '<button onclick="window._shiftQtyChange(-1)" style="width:32px;height:40px;background:#0d0f1a;border:1px solid #1e2a3a;border-radius:8px;color:#e2e8f0;cursor:pointer;font-size:18px;font-weight:700">−</button>' +
                  '<input data-op-qty type="number" min="1" value="1"' +
                    ' style="width:56px;padding:9px 8px;background:#0d0f1a;border:1px solid #1e2a3a;border-radius:8px;color:#e2e8f0;font-size:15px;font-weight:700;text-align:center">' +
                  '<button onclick="window._shiftQtyChange(1)"  style="width:32px;height:40px;background:#0d0f1a;border:1px solid #1e2a3a;border-radius:8px;color:#e2e8f0;cursor:pointer;font-size:18px;font-weight:700">+</button>' +
                '</div>' +
              '</div>' +
            '</div>' +

            // Описание
            '<input data-op-desc placeholder="Описание / За что (напр. Фотопечать 10×15, копирование А4...)"' +
              ' style="width:100%;margin-bottom:12px;padding:10px 12px;background:#0d0f1a;border:1px solid #1e2a3a;border-radius:8px;color:#e2e8f0;font-size:13px;box-sizing:border-box">' +

            // Метод оплаты
            '<div style="font-size:12px;color:#94a3b8;margin-bottom:6px">Метод оплаты</div>' +
            '<div data-method-bar style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:4px">' +
              '<button data-method-btn="Наличные" onclick="window._shiftSetMethod(this)" style="padding:6px 14px;border-radius:6px;border:1px solid #10b981;background:#10b981;color:#fff;cursor:pointer;font-size:12px;font-weight:600">Наличные</button>' +
              '<button data-method-btn="Карта" onclick="window._shiftSetMethod(this)" style="padding:6px 14px;border-radius:6px;border:1px solid #1e2a3a;background:transparent;color:#94a3b8;cursor:pointer;font-size:12px">Карта</button>' +
              '<button data-method-btn="СБП" onclick="window._shiftSetMethod(this)" style="padding:6px 14px;border-radius:6px;border:1px solid #1e2a3a;background:transparent;color:#94a3b8;cursor:pointer;font-size:12px">СБП</button>' +
              '<button data-method-btn="Безнал" onclick="window._shiftSetMethod(this)" style="padding:6px 14px;border-radius:6px;border:1px solid #1e2a3a;background:transparent;color:#94a3b8;cursor:pointer;font-size:12px">Безнал</button>' +
            '</div>' +
            '<input type="hidden" data-op-method data-method="Наличные">' +

            // СБП QR подсказка
            '<div style="font-size:11px;color:#06b6d4;margin-bottom:12px;cursor:pointer" data-qr-hint onclick="showQR()">' +
              '📱 После выбора СБП — создать QR-код для оплаты' +
            '</div>' +

            // Кнопки провести
            '<div style="display:flex;gap:8px">' +
              '<button data-qr-btn onclick="showQR()" style="padding:11px 16px;background:#06b6d418;border:1px solid #06b6d444;border-radius:8px;color:#06b6d4;cursor:pointer;font-size:13px;font-weight:600">' +
                '📱 QR-код' +
              '</button>' +
              '<button data-add-op style="flex:1;padding:11px;background:linear-gradient(135deg,#10b981,#059669);color:#fff;border:none;border-radius:8px;cursor:pointer;font-size:14px;font-weight:700">' +
                '▲ Провести приход' +
              '</button>' +
            '</div>' +

            // Очистить
            '<button onclick="window._shiftClearForm()" style="margin-top:8px;width:100%;padding:7px;background:transparent;border:none;color:#64748b;cursor:pointer;font-size:12px">✕ Очистить</button>' +

          '</div>' +

          // Блок закрытия смены
          '<div style="background:#141828;border-radius:12px;padding:16px;border:1px solid #ef444422">' +
            '<div style="font-size:13px;color:#ef4444;font-weight:600;text-transform:uppercase;letter-spacing:.5px;margin-bottom:14px">🔒 Закрыть смену</div>' +

            '<div style="display:flex;gap:10px;margin-bottom:10px;flex-wrap:wrap">' +
              '<label style="font-size:12px;color:#94a3b8;flex:1;min-width:140px">Фактическая сумма в кассе ₽' +
                '<input data-end-cash type="number" value="' + (Math.round(parseFloat(_shift.cash || 0) * 100) / 100) + '" min="0"' +
                ' style="display:block;margin-top:4px;width:100%;padding:10px 12px;background:#0d0f1a;border:1px solid #1e2a3a;border-radius:8px;color:#e2e8f0;font-size:15px;font-weight:700;box-sizing:border-box">' +
                '<div data-expected-cash style="font-size:11px;color:#64748b;margin-top:3px">Ожидается: ' + fmt(parseFloat(_shift.cash || 0)) + ' ₽</div>' +
              '</label>' +
              '<label style="font-size:12px;color:#94a3b8;flex:1;min-width:140px">Оклад за день ₽ + Бонус ' + Math.round(bonusPct * 100) + '% = <span style="color:#f59e0b" data-sal-bonus>' + fmt(bonus) + ' ₽</span>' +
                '<input data-base-salary type="number" value="0" min="0" oninput="window._shiftSalInput(this)"' +
                ' style="display:block;margin-top:4px;width:100%;padding:10px 12px;background:#0d0f1a;border:1px solid #1e2a3a;border-radius:8px;color:#e2e8f0;font-size:15px;font-weight:700;box-sizing:border-box">' +
              '</label>' +
            '</div>' +

            '<input data-shift-note placeholder="Заметки смены, необязательно..."' +
              ' style="width:100%;margin-bottom:12px;padding:9px 12px;background:#0d0f1a;border:1px solid #1e2a3a;border-radius:8px;color:#e2e8f0;font-size:13px;box-sizing:border-box">' +

            // Итого зарплата
            '<div style="background:#0d0f1a;border-radius:8px;padding:10px 14px;margin-bottom:14px;font-size:13px">' +
              '<div style="display:flex;justify-content:space-between;color:#64748b;margin-bottom:4px"><span>Оклад за день</span><span data-sal-base>0 ₽</span></div>' +
              '<div style="display:flex;justify-content:space-between;color:#64748b;margin-bottom:6px"><span>Бонус</span><span data-sal-bonus>' + fmt(bonus) + ' ₽</span></div>' +
              '<div style="display:flex;justify-content:space-between;font-weight:700;font-size:15px;color:#f59e0b;border-top:1px solid #1e2a3a;padding-top:6px"><span>Итого к выплате</span><span data-sal-total>' + fmt(bonus) + ' ₽</span></div>' +
            '</div>' +

            '<button data-close-shift style="width:100%;padding:13px;background:linear-gradient(135deg,#ef4444,#dc2626);color:#fff;border:none;border-radius:10px;cursor:pointer;font-size:15px;font-weight:700">' +
              '🔒 Закрыть смену и сформировать Z-отчёт' +
            '</button>' +
          '</div>' +

        '</div>' +

        // ── Правая колонка — операции ──
        '<div style="display:flex;flex-direction:column;background:#0d0f1a">' +

          '<div style="padding:12px 14px;border-bottom:1px solid #1e2a3a;display:flex;flex-direction:column;gap:6px">' +
            '<div style="display:flex;justify-content:space-between;align-items:center">' +
              '<span style="font-size:12px;color:#64748b;font-weight:600;text-transform:uppercase;letter-spacing:.5px">Операции смены</span>' +
              '<span data-ops-count style="font-size:12px;color:#64748b">0 шт.</span>' +
            '</div>' +
            '<input data-op-search placeholder="🔍 Поиск операций..." oninput="window._shiftSearch(this.value)"' +
              ' style="width:100%;padding:7px 10px;background:#141828;border:1px solid #1e2a3a;border-radius:7px;color:#e2e8f0;font-size:12px;box-sizing:border-box">' +
            '<div data-ops-avg style="font-size:11px;color:#64748b;text-align:center"></div>' +
          '</div>' +

          '<div data-ops-list style="flex:1;overflow-y:auto"></div>' +

        '</div>' +

      '</div>';

    // Заполняем операции
    renderOps();
    renderHeader();
    bindMainEvents();
  }

  function bindMainEvents() {
    $('[data-open-shift]')?.addEventListener('click', openShift);
    $('[data-close-shift]')?.addEventListener('click', closeShift);
    $('[data-add-op]')?.addEventListener('click', addOperation);

    // Enter в полях формы
    $('[data-op-price]')?.addEventListener('keydown', e => { if (e.key === 'Enter') $('[data-op-qty]')?.focus(); });
    $('[data-op-qty]')?.addEventListener('keydown',   e => { if (e.key === 'Enter') $('[data-op-desc]')?.focus(); });
    $('[data-op-desc]')?.addEventListener('keydown',  e => { if (e.key === 'Enter') $('[data-add-op]')?.click(); });

    // Обновляем кнопку провести при смене типа
    window._shiftSetType = function (type, btn) {
      const hidden = $('[data-op-type]'); if (hidden) hidden.value = type;
      $$('[data-type-btn]').forEach(b => {
        const isInc = b.dataset.typeBtn === 'income';
        const active = b.dataset.typeBtn === type;
        b.style.background  = active ? (isInc ? '#10b98118' : '#ef444418') : 'transparent';
        b.style.borderColor = active ? (isInc ? '#10b98166' : '#ef444466') : '#1e2a3a';
        b.style.color       = active ? (isInc ? '#10b981' : '#ef4444') : '#64748b';
        b.style.fontWeight  = active ? '600' : '400';
      });
      const addBtn = $('[data-add-op]');
      if (addBtn) {
        addBtn.textContent = type === 'income' ? '▲ Провести приход' : '▼ Провести расход';
        addBtn.style.background = type === 'income'
          ? 'linear-gradient(135deg,#10b981,#059669)'
          : 'linear-gradient(135deg,#ef4444,#dc2626)';
      }
      renderMethodTabs(type);
    };

    // Зарплата — пересчёт превью
    window._shiftSalInput = function (inp) {
      const base  = parseFloat(inp.value || 0);
      const bonus = parseFloat(_shift?.accrued_bonus || 0);
      const pct   = parseFloat(_shift?.bonus_pct || 0.1);
      _updateSalaryPreview(base, bonus, pct);
      // Обновляем expected cash
      const ecEl = $('[data-expected-cash]');
      if (ecEl) ecEl.textContent = 'Ожидается: ' + fmt(parseFloat(_shift?.cash || 0)) + ' ₽';
    };
  }

  // ── Отчёты ────────────────────────────────────────────────
  function renderReportsView() {
    const rows = _history.slice(0, 30).map(h => {
      const dur   = fmtDur(h.opened_at || h.open_time, h.closed_at || h.close_time);
      const inc   = parseFloat(h.total_income  || h.income  || 0);
      const exp   = parseFloat(h.total_expense || h.expense || 0);
      const diff  = parseFloat(h.cash_diff || 0);
      return (
        '<tr style="border-bottom:1px solid #0d0f1a;font-size:13px">' +
          '<td style="padding:9px 14px">' + esc(new Date(h.opened_at || h.open_time || '').toLocaleDateString('ru-RU')) + '</td>' +
          '<td style="padding:9px 14px">' + esc(h.manager || h.staff_name || '—') + '</td>' +
          '<td style="padding:9px 14px;color:#64748b">' + dur + '</td>' +
          '<td style="padding:9px 14px;color:#10b981;text-align:right">+' + fmt(inc) + ' ₽</td>' +
          '<td style="padding:9px 14px;color:#ef4444;text-align:right">−' + fmt(exp) + ' ₽</td>' +
          '<td style="padding:9px 14px;color:#7c3aed;text-align:right">' + fmt(inc - exp) + ' ₽</td>' +
          '<td style="padding:9px 14px;text-align:right">' + fmt(h.end_cash || 0) + ' ₽</td>' +
          '<td style="padding:9px 14px;color:' + (diff >= 0 ? '#10b981' : '#ef4444') + ';text-align:right">' + (diff >= 0 ? '+' : '') + fmt(diff) + ' ₽</td>' +
          '<td style="padding:9px 14px;color:#f59e0b;text-align:right">' + fmt(h.total_salary || 0) + ' ₽</td>' +
          '<td style="padding:9px 14px;color:#64748b;text-align:right">' + (h.orders_count || 0) + '</td>' +
        '</tr>'
      );
    }).join('');

    _el.innerHTML =
      _topBar('reports') +
      '<div style="padding:20px;max-width:1200px;margin:0 auto">' +
        '<div style="background:#141828;border-radius:14px;overflow:hidden">' +
          '<div style="padding:14px 18px;border-bottom:1px solid #0d0f1a">' +
            '<div style="font-size:14px;font-weight:600;color:#e2e8f0">📋 История смен</div>' +
          '</div>' +
          (!rows
            ? '<div style="padding:40px;text-align:center;color:#64748b">История смен пуста</div>'
            : '<div style="overflow-x:auto"><table style="width:100%;border-collapse:collapse;min-width:700px">' +
              '<tr style="font-size:11px;color:#64748b;text-transform:uppercase;background:#0d0f1a">' +
                '<th style="padding:9px 14px;text-align:left;font-weight:400">Дата</th>' +
                '<th style="padding:9px 14px;text-align:left;font-weight:400">Менеджер</th>' +
                '<th style="padding:9px 14px;text-align:left;font-weight:400">Длит.</th>' +
                '<th style="padding:9px 14px;text-align:right;font-weight:400">Доход</th>' +
                '<th style="padding:9px 14px;text-align:right;font-weight:400">Расход</th>' +
                '<th style="padding:9px 14px;text-align:right;font-weight:400">Прибыль</th>' +
                '<th style="padding:9px 14px;text-align:right;font-weight:400">Касса факт</th>' +
                '<th style="padding:9px 14px;text-align:right;font-weight:400">Расхожд.</th>' +
                '<th style="padding:9px 14px;text-align:right;font-weight:400">ЗП</th>' +
                '<th style="padding:9px 14px;text-align:right;font-weight:400">Оп.</th>' +
              '</tr>' +
              rows +
              '</table></div>'
          ) +
        '</div>' +
      '</div>';
  }

  // ── Настройки ─────────────────────────────────────────────
  function renderSettingsView() {
    _el.innerHTML =
      _topBar('settings') +
      '<div style="padding:20px;max-width:700px;margin:0 auto">' +
        '<div style="background:#141828;border-radius:14px;padding:20px">' +
          '<div data-btn-settings></div>' +
        '</div>' +
      '</div>';
    renderBtnSettings();
  }

  // ── Глобальные хелперы ────────────────────────────────────
  window._shiftView       = v  => { _view = v; render(); };
  window._shiftQuickBtn   = btn => quickBtn(btn);
  window._shiftDelOp      = id  => deleteOp(id);
  window._shiftEditOp     = id  => editOp(id);
  window._shiftSearch     = v  => { _opSearch = v; renderOps(); };

  window._shiftQtyChange  = function (delta) {
    const el = $('[data-op-qty]');
    if (el) el.value = Math.max(1, (parseInt(el.value) || 1) + delta);
  };

  window._shiftClearForm  = function () {
    const p = $('[data-op-price]'); if (p) p.value = '';
    const d = $('[data-op-desc]');  if (d) d.value = '';
    const q = $('[data-op-qty]');   if (q) q.value = '1';
    $$('[data-quick]').forEach(b => b.style.borderColor = '');
  };

  window._shiftAddBtn = function () {
    const label  = prompt('Название кнопки:'); if (!label?.trim()) return;
    const amount = parseFloat(prompt('Сумма ₽ (0 = ввод вручную):', '0') || '0');
    const type   = confirm('Это ПРИХОД? (Отмена = расход)') ? 'income' : 'expense';
    const icon   = prompt('Иконка (эмодзи):', '💳') || '💳';
    const color  = prompt('Цвет (hex):', '#f59e0b') || '#f59e0b';
    _buttons.push({ id: 'b' + Date.now(), label: label.trim(), amount, type, icon, color });
    saveButtons();
    if (_view === 'settings') renderBtnSettings();
    else if (_shift) renderMainOpen();
    ntf('✅ Кнопка добавлена', 'success');
  };

  window._shiftDelBtn = function (id) {
    if (!confirm('Удалить кнопку?')) return;
    _buttons = _buttons.filter(b => b.id !== id);
    saveButtons();
    if (_view === 'settings') renderBtnSettings();
    else if (_shift) renderMainOpen();
  };

  window._shiftBtnColor = function (id, color) {
    const btn = _buttons.find(b => b.id === id);
    if (btn) { btn.color = color; saveButtons(); }
  };

  window._shiftShowSalary = function () {
    if (!_shift) return;
    const bonus    = parseFloat(_shift.accrued_bonus || 0);
    const bonusPct = parseFloat(_shift.bonus_pct || 0.1);
    const income   = parseFloat(_shift.total_income || _shift.income || 0);
    ntf('💰 Бонус ' + Math.round(bonusPct * 100) + '% с дохода ' + fmt(income) + ' ₽ = ' + fmt(bonus) + ' ₽', 'info');
  };

  window.showQR = showQR;

  // ── Регистрация модуля ────────────────────────────────────
  window.CRM.registerModule({
    id:    'shift',
    name:  'Касса смены',
    icon:  '🧾',
    color: '#f59e0b',
    order: 5,

    async init(container) {
      _el = container;
      _el.innerHTML = '<div style="text-align:center;padding:60px;color:#64748b"><div style="font-size:40px;margin-bottom:12px">🧾</div><div style="font-size:14px">Загрузка кассы...</div></div>';
      await loadAll();
      _view = 'main';
      render();
    },

    async refresh() {
      await loadAll();
      render();
    },
  });

})();
</script>