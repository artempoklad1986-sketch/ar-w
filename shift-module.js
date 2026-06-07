// ============================================================
// PrintCRM v3.0 — js/shift-module.js
// Модуль «Кассовая смена»
// ============================================================
(function () {
  'use strict';

  const API = window.CRM.api;
  let _shift = null;
  let _staff = [];
  let _el = null;

  const _buttons = [
    { id:'b1', label:'Фото 10×15',  amount:15,  type:'income',  icon:'📸', color:'#f59e0b' },
    { id:'b2', label:'Копия А4',    amount:10,  type:'income',  icon:'📄', color:'#10b981' },
    { id:'b3', label:'Печать А4',   amount:20,  type:'income',  icon:'🖨️', color:'#3b82f6' },
    { id:'b4', label:'Ламинация',   amount:50,  type:'income',  icon:'✨', color:'#8b5cf6' },
    { id:'b5', label:'Изъятие',     amount:0,   type:'expense', icon:'💸', color:'#ef4444' },
  ];

  window.CRM.registerModule({
    id:      'shifts',
    name:    'Касса / Смена',
    icon:    '🏪',
    color:   '#f59e0b',
    sidebar: true,
    builtIn: false,

    onLoad: async (container) => {
      _el = container;
      await _loadData();
    },
    render: (container) => {
      _el = container;
      _render();
    },
    refresh: async (container) => {
      _el = container;
      await _loadData();
    },
  });

  async function _loadData() {
    const [sr, str] = await Promise.all([
      API('shifts'),
      API('staff'),
    ]);
    if (sr?.ok) {
      const d = sr.data;
      _shift = d?.current || (Array.isArray(d) ? d.find(s => !s.completed && s.status === 'open') : null);
    }
    if (str?.ok && Array.isArray(str.data)) {
      _staff = str.data.filter(s => s.status !== 'fired' && s.is_active != 0);
    }
    _render();
  }

  function _fmt(n) {
    return parseFloat(n || 0).toLocaleString('ru-RU', {
      minimumFractionDigits: 2, maximumFractionDigits: 2
    });
  }

  function _timeOnly(iso) {
    if (!iso) return '—';
    try { return new Date(iso).toLocaleTimeString('ru-RU', {hour:'2-digit', minute:'2-digit'}); }
    catch(_) { return iso; }
  }

  function _toast(msg, type='success') {
    if (typeof window.showToast === 'function') { window.showToast(msg, type); return; }
    const t = document.createElement('div');
    t.textContent = msg;
    t.style.cssText = `position:fixed;bottom:24px;right:24px;z-index:9999;
      background:var(--bg-card);color:#fff;padding:12px 20px;border-radius:8px;
      border-left:4px solid var(--${type==='error'?'danger':type==='warning'?'accent4':'accent3'});
      box-shadow:0 4px 20px rgba(0,0,0,.4);font-size:14px;`;
    document.body.appendChild(t);
    setTimeout(() => t.remove(), 3000);
  }

  // ── РЕНДЕР ──────────────────────────────────────────────
  function _render() {
    if (!_el) return;
    _el.innerHTML = _shift ? _htmlOpen() : _htmlClosed();
    _bind();
  }

  function _htmlClosed() {
    const opts = _staff.map(s =>
      `<option value="${s.id}">${s.name}${s.role ? ' — '+s.role : ''}</option>`
    ).join('');
    return `
      <div style="padding:24px;max-width:600px;margin:0 auto">
        <div style="display:flex;align-items:center;gap:12px;margin-bottom:24px">
          <h2 style="margin:0">🏪 Кассовая смена</h2>
          <span class="badge" style="background:var(--danger);color:#fff;padding:4px 10px;border-radius:20px;font-size:12px">Закрыта</span>
        </div>
        <div class="card" style="padding:20px">
          <h3 style="margin:0 0 16px">Открыть новую смену</h3>
          <div class="form-group" style="margin-bottom:12px">
            <label>Сотрудник</label>
            <select id="s-emp" style="width:100%">
              <option value="">— выбрать —</option>
              ${opts}
              <option value="_manual">Ввести вручную…</option>
            </select>
          </div>
          <div id="s-manual-wrap" style="display:none;margin-bottom:12px">
            <label>ФИО</label>
            <input type="text" id="s-manual" placeholder="Иванов И.И." style="width:100%">
          </div>
          <div class="form-group" style="margin-bottom:16px">
            <label>Остаток в кассе на начало (₽)</label>
            <input type="number" id="s-start" value="0" min="0" step="0.01" style="width:100%">
          </div>
          <button class="btn btn-primary" id="s-open-btn" style="width:100%">🟢 Открыть смену</button>
        </div>
      </div>`;
  }

  function _htmlOpen() {
    const ops     = Array.isArray(_shift.operations) ? _shift.operations : [];
    const income  = parseFloat(_shift.total_income  || _shift.income  || 0);
    const expense = parseFloat(_shift.total_expense || _shift.expense || 0);
    const cash    = parseFloat(_shift.cash || _shift.start_cash || 0);
    const bonus   = parseFloat(_shift.accrued_bonus || 0);
    const opened  = _shift.openTime || _shift.opened_at || '';

    return `
      <div style="padding:24px;max-width:800px;margin:0 auto">

        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;flex-wrap:wrap;gap:8px">
          <div>
            <h2 style="margin:0 0 4px">🏪 Кассовая смена</h2>
            <span style="color:var(--text-muted);font-size:13px">
              👤 ${_shift.manager || _shift.staff_name || '—'} &nbsp;·&nbsp;
              ⏱ ${_timeOnly(opened)}
            </span>
          </div>
          <span class="badge" style="background:var(--accent3);color:#fff;padding:4px 12px;border-radius:20px">Открыта</span>
        </div>

        <!-- Итоги -->
        <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:20px">
          ${[
            ['📥 Приход',  '+'+_fmt(income)+' ₽',  'var(--accent3)'],
            ['📤 Расход',  '−'+_fmt(expense)+' ₽', 'var(--danger)'],
            ['💵 В кассе', _fmt(cash)+' ₽',         'var(--accent2)'],
            ['🎁 Бонус',   _fmt(bonus)+' ₽',         'var(--accent4)'],
          ].map(([l,v,c])=>`
            <div class="card" style="padding:14px;text-align:center">
              <div style="font-size:12px;color:var(--text-muted);margin-bottom:4px">${l}</div>
              <div style="font-size:18px;font-weight:700;color:${c}">${v}</div>
            </div>`).join('')}
        </div>

        <!-- Быстрые кнопки -->
        <div class="card" style="padding:16px;margin-bottom:16px">
          <div style="font-size:13px;color:var(--text-muted);margin-bottom:10px">⚡ Быстрые операции</div>
          <div style="display:flex;flex-wrap:wrap;gap:8px">
            ${_buttons.map(b=>`
              <button class="s-quick" data-id="${b.id}"
                style="border:2px solid ${b.color};color:${b.color};background:transparent;
                       padding:8px 14px;border-radius:8px;cursor:pointer;font-size:13px;
                       display:flex;align-items:center;gap:6px">
                ${b.icon} ${b.label}${b.amount>0?' · '+b.amount+'₽':''}
              </button>`).join('')}
          </div>
        </div>

        <!-- Форма операции -->
        <div class="card" style="padding:16px;margin-bottom:16px">
          <div style="font-size:14px;font-weight:600;margin-bottom:12px">💳 Новая операция</div>
          <div style="display:grid;grid-template-columns:1fr 1fr 80px;gap:10px;margin-bottom:10px">
            <select id="op-type">
              <option value="income">📥 Приход</option>
              <option value="expense">📤 Расход</option>
            </select>
            <input type="number" id="op-amount" placeholder="Сумма ₽" min="0.01" step="0.01">
            <input type="number" id="op-qty" value="1" min="1" title="Кол-во">
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:12px">
            <input type="text" id="op-desc" placeholder="Описание…">
            <select id="op-method">
              <option>Наличные</option>
              <option>Карта</option>
              <option>СБП</option>
              <option>Безнал</option>
            </select>
          </div>
          <div id="op-total" style="display:none;font-size:13px;color:var(--accent4);margin-bottom:10px"></div>
          <button class="btn btn-primary" id="op-add">➕ Добавить</button>
        </div>

        <!-- Операции -->
        <div class="card" style="padding:16px;margin-bottom:16px">
          <div style="font-weight:600;margin-bottom:12px">
            📋 Операции <span style="background:var(--accent);color:#fff;padding:2px 8px;border-radius:10px;font-size:12px">${ops.length}</span>
          </div>
          <div id="s-ops">
            ${_htmlOps(ops)}
          </div>
        </div>

        <!-- Закрытие -->
        <div class="card" style="padding:16px;border:1px solid var(--danger)33">
          <div style="font-weight:600;margin-bottom:12px;color:var(--danger)">🔒 Закрыть смену</div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px">
            <div>
              <label style="font-size:12px;color:var(--text-muted)">Фактически в кассе (₽)</label>
              <input type="number" id="close-cash" value="${(parseFloat(_shift.cash || _shift.start_cash || 0)).toFixed(2)}" min="0" step="0.01" style="width:100%">
            </div>
            <div>
              <label style="font-size:12px;color:var(--text-muted)">Оклад за смену (₽)</label>
              <input type="number" id="close-salary" value="0" min="0" step="0.01" style="width:100%">
            </div>
          </div>
          <input type="text" id="close-note" placeholder="Примечание…" style="width:100%;margin-bottom:12px">
          <button class="btn btn-danger" id="s-close-btn" style="width:100%">🔴 Закрыть смену и сформировать Z-отчёт</button>
        </div>

      </div>`;
  }

  function _htmlOps(ops) {
    if (!ops.length) return `<div style="color:var(--text-muted);text-align:center;padding:20px">Операций нет</div>`;
    return [...ops].reverse().map(op=>`
      <div style="display:flex;justify-content:space-between;align-items:center;
                  padding:10px;border-radius:8px;margin-bottom:6px;
                  background:${op.type==='income'?'rgba(16,185,129,.08)':'rgba(239,68,68,.08)'}">
        <div>
          <div style="font-size:14px">${op.type==='income'?'📥':'📤'} ${op.desc||'—'}</div>
          <div style="font-size:12px;color:var(--text-muted)">
            ${op.method||'Наличные'} · ${op.qty>1?op.qty+' шт · ':''}${_timeOnly(op.time||op.created_at)}
          </div>
        </div>
        <div style="display:flex;align-items:center;gap:10px">
          <span style="font-weight:700;color:${op.type==='income'?'var(--accent3)':'var(--danger)'}">
            ${op.type==='income'?'+':'−'}${_fmt(op.amount)} ₽
          </span>
          <button class="s-del-op" data-id="${op.id}"
            style="background:none;border:none;color:var(--danger);cursor:pointer;font-size:16px;padding:0">✕</button>
        </div>
      </div>`).join('');
  }

  // ── СОБЫТИЯ ─────────────────────────────────────────────
  function _bind() {
    if (!_el) return;

    // Выбор сотрудника
    const emp = _el.querySelector('#s-emp');
    if (emp) emp.addEventListener('change', () => {
      const w = _el.querySelector('#s-manual-wrap');
      if (w) w.style.display = emp.value === '_manual' ? '' : 'none';
    });

    // Открыть
    const ob = _el.querySelector('#s-open-btn');
    if (ob) ob.addEventListener('click', _open);

    // Пересчёт итога
    ['op-amount','op-qty'].forEach(id => {
      const el = _el.querySelector('#'+id);
      if (el) el.addEventListener('input', _updateTotal);
    });

    // Добавить операцию
    const ab = _el.querySelector('#op-add');
    if (ab) ab.addEventListener('click', _addOp);

    // Быстрые кнопки
    _el.querySelectorAll('.s-quick').forEach(btn => {
      btn.addEventListener('click', () => {
        const qb = _buttons.find(b => b.id === btn.dataset.id);
        if (!qb) return;
        const t = _el.querySelector('#op-type');
        const a = _el.querySelector('#op-amount');
        const d = _el.querySelector('#op-desc');
        if (t) t.value = qb.type;
        if (a) a.value = qb.amount > 0 ? qb.amount : '';
        if (d) d.value = qb.label;
        _updateTotal();
        if (qb.amount > 0) _addOp();
        else if (a) a.focus();
      });
    });

    // Удаление операции
    _el.querySelectorAll('.s-del-op').forEach(btn => {
      btn.addEventListener('click', () => _delOp(btn.dataset.id));
    });

    // Закрыть
    const cb = _el.querySelector('#s-close-btn');
    if (cb) cb.addEventListener('click', _close);
  }

  function _updateTotal() {
    const amt = parseFloat(_el.querySelector('#op-amount')?.value || 0);
    const qty = parseInt(_el.querySelector('#op-qty')?.value || 1);
    const el  = _el.querySelector('#op-total');
    if (!el) return;
    if (amt > 0 && qty > 1) {
      el.style.display = '';
      el.textContent   = `Итого: ${_fmt(amt*qty)} ₽ (${qty} шт × ${_fmt(amt)} ₽)`;
    } else {
      el.style.display = 'none';
    }
  }

  // ── API ДЕЙСТВИЯ ─────────────────────────────────────────
  async function _open() {
    const empSel = _el.querySelector('#s-emp');
    const manual = _el.querySelector('#s-manual');
    const startEl = _el.querySelector('#s-start');

    let empId = '', manager = 'Менеджер';
    if (empSel?.value && empSel.value !== '_manual') {
      empId = empSel.value;
      const f = _staff.find(s => String(s.id) === empId);
      if (f) manager = f.name;
    } else if (empSel?.value === '_manual') {
      manager = manual?.value?.trim() || 'Менеджер';
    }

    const btn = _el.querySelector('#s-open-btn');
    btn.disabled = true; btn.textContent = '⏳…';

    const r = await API('shifts', {
      method:'POST',
      body:{ action:'open', empId, manager, startCash: parseFloat(startEl?.value||0) }
    });

    if (r?.ok) {
      _shift = r.data;
      _toast('✅ Смена открыта!');
      _render();
    } else {
      _toast('❌ ' + (r?.error||'Ошибка'), 'error');
      btn.disabled = false; btn.textContent = '🟢 Открыть смену';
    }
  }

  async function _addOp() {
    const type   = _el.querySelector('#op-type')?.value   || 'income';
    const amount = parseFloat(_el.querySelector('#op-amount')?.value || 0);
    const qty    = parseInt(_el.querySelector('#op-qty')?.value || 1);
    const desc   = _el.querySelector('#op-desc')?.value?.trim() || '';
    const method = _el.querySelector('#op-method')?.value || 'Наличные';

    if (amount <= 0) { _toast('❗ Укажите сумму', 'warning'); return; }

    const btn = _el.querySelector('#op-add');
    btn.disabled = true;

    const r = await API('shifts', {
      method:'PUT',
      body:{ action:'operation', type, amount, qty, desc, method, time: new Date().toISOString() }
    });

    if (r?.ok) {
      _shift = r.data;
      if (_el.querySelector('#op-amount')) _el.querySelector('#op-amount').value = '';
      if (_el.querySelector('#op-qty'))    _el.querySelector('#op-qty').value    = '1';
      if (_el.querySelector('#op-desc'))   _el.querySelector('#op-desc').value   = '';
      _updateTotal();
      _toast(type==='income' ? '📥 Приход записан' : '📤 Расход записан');
      _render();
    } else {
      _toast('❌ ' + (r?.error||'Ошибка'), 'error');
    }
    btn.disabled = false;
  }

  async function _delOp(opId) {
    if (!confirm('Удалить операцию?')) return;
    const r = await API('shifts', {
      method:'PUT',
      body:{ action:'deleteOperation', opId }
    });
    if (r?.ok) { _shift = r.data; _toast('🗑 Удалено'); _render(); }
    else _toast('❌ ' + (r?.error||'Ошибка'), 'error');
  }

  async function _close() {
    const endCash    = parseFloat(_el.querySelector('#close-cash')?.value   || 0);
    const baseSalary = parseFloat(_el.querySelector('#close-salary')?.value || 0);
    const note       = _el.querySelector('#close-note')?.value?.trim() || '';
    const ops        = _shift?.operations?.length || 0;

    if (!confirm(`Закрыть смену?\nОпераций: ${ops}\nФакт в кассе: ${endCash} ₽`)) return;

    const btn = _el.querySelector('#s-close-btn');
    btn.disabled = true; btn.textContent = '⏳ Закрываем…';

    const r = await API('shifts', {
      method:'PUT',
      body:{ action:'close', endCash, baseSalary, note }
    });

    if (r?.ok) {
      _shift = null;
      _toast('✅ Смена закрыта!');
      _render();
      if (r.report) setTimeout(() => _zReport(r.report), 400);
    } else {
      _toast('❌ ' + (r?.error||'Ошибка'), 'error');
      btn.disabled = false; btn.textContent = '🔴 Закрыть смену и сформировать Z-отчёт';
    }
  }

  // ── Z-ОТЧЁТ ─────────────────────────────────────────────
  function _zReport(r) {
    const diff     = parseFloat(r.cashDiff || 0);
    const diffText = diff < 0 ? '⚠️ Недостача' : diff > 0 ? '✅ Излишек' : '✓ Сходится';
    const methods  = r.methodTotals || {};

    const html = `
      <div class="modal-overlay active" id="z-modal">
        <div class="modal" style="max-width:520px">
          <div class="modal-header">
            <h3>🧾 Z-Отчёт</h3>
            <button class="modal-close" onclick="document.getElementById('z-modal').remove()">✕</button>
          </div>
          <div class="modal-body">
            <p>👤 <b>${r.manager||'—'}</b> &nbsp; 📅 ${r.shiftDate||'—'} &nbsp; ${_timeOnly(r.openTime)} → ${_timeOnly(r.closeTime)}</p>
            <table class="payment-table">
              <tr><td>Начало кассы</td><td>${_fmt(r.startCash||0)} ₽</td></tr>
              <tr><td>Приход</td><td style="color:var(--accent3)">+${_fmt(r.totalIncome||0)} ₽</td></tr>
              <tr><td>Расход</td><td style="color:var(--danger)">−${_fmt(r.totalExpense||0)} ₽</td></tr>
              <tr style="font-weight:700"><td>Расчёт в кассе</td><td>${_fmt(r.calcCash||0)} ₽</td></tr>
              <tr><td>Фактически</td><td>${_fmt(r.endCash||0)} ₽</td></tr>
              <tr style="color:${diff<0?'var(--danger)':'var(--accent3)'}">
                <td>${diffText}</td><td>${_fmt(Math.abs(diff))} ₽</td>
              </tr>
            </table>
            ${Object.keys(methods).length ? `
              <h4 style="margin:16px 0 8px">По методам оплаты</h4>
              <table class="payment-table">
                <thead><tr><th>Метод</th><th>Приход</th><th>Расход</th></tr></thead>
                <tbody>${Object.entries(methods).map(([m,v])=>
                  `<tr><td>${m}</td>
                       <td style="color:var(--accent3)">+${_fmt(v.income||0)} ₽</td>
                       <td style="color:var(--danger)">−${_fmt(v.expense||0)} ₽</td></tr>`
                ).join('')}</tbody>
              </table>` : ''}
            <table class="payment-table" style="margin-top:16px">
              <tr><td>Операций</td><td>${r.operationsCount||0}</td></tr>
              <tr><td>Оклад</td><td>${_fmt(r.baseSalary||0)} ₽</td></tr>
              <tr><td>Бонус</td><td>${_fmt(r.accruedBonus||0)} ₽</td></tr>
              <tr style="font-weight:700"><td>К выплате</td><td>${_fmt(r.totalSalary||0)} ₽</td></tr>
            </table>
          </div>
          <div class="modal-footer">
            <button class="btn btn-secondary" onclick="window.print()">🖨 Печать</button>
            <button class="btn btn-primary" onclick="document.getElementById('z-modal').remove()">Закрыть</button>
          </div>
        </div>
      </div>`;
    document.body.insertAdjacentHTML('beforeend', html);
  }

})();