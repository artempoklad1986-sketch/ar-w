'use strict';

// ════════════════════════════════════════════════════════════
// API CLIENT
// ════════════════════════════════════════════════════════════
const Api = {

  async call(endpoint, method = 'GET', body = null, params = {}) {
    const qs  = new URLSearchParams({ key: API_KEY, ...params }).toString();
    const url = `${API_URL}${endpoint}?${qs}`;
    const opts = { method, headers: apiHeaders };
    if (body) opts.body = JSON.stringify(body);
    try {
      const res  = await fetch(url, opts);
      const text = await res.text();
      if (!text || text.trim() === '') return { ok: false, error: 'empty' };
      return JSON.parse(text);
    } catch (e) {
      console.warn(`Api.call(${endpoint}) error:`, e.message);
      return { ok: false, error: e.message };
    }
  },

  get:    (ep, params)       => Api.call(ep, 'GET',    null, params),
  post:   (ep, body, params) => Api.call(ep, 'POST',   body, params),
  put:    (ep, body, params) => Api.call(ep, 'PUT',    body, params),
  delete: (ep, params)       => Api.call(ep, 'DELETE', null, params),

  orders: {
    list:   (p)     => Api.get('orders', p),
    create: (b)     => Api.post('orders', b),
    update: (id, b) => Api.put('orders', b, { id }),
    remove: (id)    => Api.delete('orders', { id }),
  },
  finance: {
    list:    (p)    => Api.get('finance', p),
    create:  (b)    => Api.post('finance', b),
    remove:  (id)   => Api.delete('finance', { id }),
    summary: (p)    => Api.get('finance/summary', p),
  },
  clients: {
    list:   (p)     => Api.get('clients', p),
    create: (b)     => Api.post('clients', b),
    update: (id, b) => Api.put('clients', b, { id }),
    remove: (id)    => Api.delete('clients', { id }),
  },
  warehouse: {
    list:    (p)  => Api.get('warehouse', p),
    create:  (b)  => Api.post('warehouse', b),
    restock: (b)  => Api.post('warehouse/restock', b),
    deduct:  (b)  => Api.post('warehouse/deduct', b),
    remove:  (id) => Api.delete('warehouse', { id }),
    history: ()   => Api.get('warehouse/history'),
  },
  notes: {
    list:   ()    => Api.get('notes'),
    create: (b)   => Api.post('notes', b),
    remove: (id)  => Api.delete('notes', { id }),
  },
  calendar: {
    list:   (p)   => Api.get('calendar', p),
    create: (b)   => Api.post('calendar', b),
    remove: (id)  => Api.delete('calendar', { id }),
  },
  settings: {
    get:  ()  => Api.get('settings'),
    save: (b) => Api.post('settings', b),
  },
  stats:  (p)                    => Api.get('stats', p),
  ping:   ()                     => Api.get('ping'),
  notify: (event, data)          => Api.post('notify', { event, data }),
  module: (id, action, body, p)  =>
    Api.call('module', body ? 'POST' : 'GET', body, { module: id, action, ...p }),

  upload: (formData) => {
    const qs = new URLSearchParams({ key: API_KEY }).toString();
    return fetch(`${API_URL}upload?${qs}`, { method: 'POST', body: formData }).then(r => r.json());
  },

  integrations: {
    list: ()      => Api.get('integrations'),
    test: (id)    => Api.post('integrations/test', { id }),
  },
};

// ════════════════════════════════════════════════════════════
// STATE
// ════════════════════════════════════════════════════════════
const State = {
  orders:    [],
  finance:   [],
  clients:   [],
  notes:     [],
  warehouse: [],
  calendar:  [],
  settings:  {},
  loaded:    false,
  syncing:   false,

  set(key, data) { this[key] = data; },
  order(id)      { return this.orders.find(o => String(o.id) === String(id)); },
  client(name)   { return this.clients.find(c => c.name === name); },
};

// ════════════════════════════════════════════════════════════
// SYNC
// ════════════════════════════════════════════════════════════
const Sync = {

  _pollInterval: null,

  async loadAll() {
    Ui.syncStatus('loading');
    try {
      const [ordRes, finRes, cliRes, setRes, whRes, notRes] = await Promise.all([
        Api.orders.list(),
        Api.finance.list(),
        Api.clients.list(),
        Api.settings.get(),
        Api.warehouse.list(),
        Api.notes.list(),
      ]);

      if (ordRes.ok) State.set('orders',    ordRes.data || []);
      if (finRes.ok) State.set('finance',   finRes.data || []);
      if (cliRes.ok) State.set('clients',   cliRes.data || []);
      if (setRes.ok) State.set('settings',  setRes.data || {});
      if (whRes.ok)  State.set('warehouse', whRes.data  || []);
      if (notRes.ok) State.set('notes',     notRes.data || []);

      State.loaded = true;
      Ui.syncStatus('ok');
      App.renderCurrent();
      App.updateBadges();
      Ui.updateDbInfo();

    } catch (e) {
      Ui.syncStatus('error');
      notify('Ошибка загрузки: ' + e.message, 'error');
    }
  },

  startPolling() {
    this._pollInterval = setInterval(async () => {
      if (State.syncing) return;
      try {
        const [ordRes, finRes] = await Promise.all([
          Api.orders.list(),
          Api.finance.list(),
        ]);
        if (ordRes.ok) {
          const incoming = JSON.stringify(ordRes.data);
          const current  = JSON.stringify(State.orders);
          if (incoming !== current) {
            State.set('orders', ordRes.data || []);
            App.renderCurrent();
            App.updateBadges();
            Dashboard._checkCritical();
          }
        }
        if (finRes.ok) State.set('finance', finRes.data || []);
        Ui.syncStatus('ok');
      } catch { Ui.syncStatus('error'); }
    }, 30000);
  },

  stopPolling() {
    if (this._pollInterval) clearInterval(this._pollInterval);
  },
};

// ════════════════════════════════════════════════════════════
// APP
// ════════════════════════════════════════════════════════════
const App = {

  currentPage: 'dashboard',

  showPage(name, btn) {
    document.querySelectorAll('.page').forEach(p => p.classList.remove('active'));
    document.querySelectorAll('.nav-btn').forEach(b => b.classList.remove('active'));

    const page = document.getElementById('page-' + name);
    if (page) page.classList.add('active');
    if (btn)  btn.classList.add('active');

    this.currentPage = name;

    const renders = {
      dashboard:  () => Dashboard.refresh(),
      orders:     () => Kanban.render(),
      finance:    () => Finance.renderTable(),
      stats:      () => Stats.render(),
      accounting: () => Accounting.render(),
      clients:    () => Clients.render(),
      notes:      () => Notes.render(),
      warehouse:  () => Warehouse.render(),
      calendar:   () => Calendar.render(),
      settings:   () => Settings.load(),
    };

    if (renders[name]) { renders[name](); return; }

    const mod = CRM._modules[name];
    if (mod && typeof mod.render === 'function') {
      Promise.resolve().then(() => mod.render())
        .catch(e => notify('Ошибка модуля: ' + e.message, 'error'));
    }
  },

  renderCurrent() {
    const renders = {
      dashboard:  () => Dashboard.refresh(),
      orders:     () => Kanban.render(),
      finance:    () => Finance.renderTable(),
      stats:      () => Stats.render(),
      accounting: () => Accounting.render(),
      clients:    () => Clients.render(),
      notes:      () => Notes.render(),
      warehouse:  () => Warehouse.render(),
      calendar:   () => Calendar.render(),
    };
    if (renders[this.currentPage]) renders[this.currentPage]();
  },

  updateBadges() {
    const active = State.orders.filter(o => o.status === 'new' || o.status === 'work').length;
    const ob = document.getElementById('ordersNavBadge');
    if (ob) { ob.textContent = active; ob.style.display = active > 0 ? '' : 'none'; }

    const urgent = State.notes.filter(n => n.priority === 'urgent' || n.priority === 'important').length;
    const nb = document.getElementById('notesNavBadge');
    if (nb) nb.style.display = urgent > 0 ? '' : 'none';

    const low = State.warehouse.filter(w => parseFloat(w.qty) <= parseFloat(w.min_qty)).length;
    const wb  = document.getElementById('warehouseLowBadge');
    if (wb) wb.style.display = low > 0 ? '' : 'none';
  },
};

window.showPage = (n, b) => App.showPage(n, b);

// ════════════════════════════════════════════════════════════
// UI
// ════════════════════════════════════════════════════════════
const Ui = {

  syncStatus(status) {
    const dot  = document.getElementById('syncDot');
    const text = document.getElementById('syncText');
    const map  = {
      loading: ['var(--accent4)',  '⟳ Загрузка...'],
      saving:  ['var(--accent4)',  '⟳ Сохранение...'],
      ok:      ['var(--accent3)',  '● Онлайн'],
      error:   ['var(--danger)',   '⚠ Ошибка'],
    };
    const [color, label] = map[status] || ['var(--text-muted)', ''];
    if (dot)  dot.style.background = color;
    if (text) { text.textContent = label; text.style.color = color; }
  },

  updateDbInfo() {
    Api.get('db/info').then(res => {
      if (!res.ok) return;
      const d = res.data || {};
      const el = id => document.getElementById(id);
      if (el('dbVersion'))    el('dbVersion').textContent    = d.version    || '—';
      if (el('dbSize'))       el('dbSize').textContent       = d.size_mb ? d.size_mb + ' МБ' : '—';
      if (el('dbLastUpdate')) el('dbLastUpdate').textContent = d.updated_at || '—';
      if (el('dbSizeInfo'))   el('dbSizeInfo').textContent   = d.size_kb ? `БД: ${d.size_kb} КБ` : '';
    }).catch(() => {});
  },
};

// ════════════════════════════════════════════════════════════
// МОДАЛКИ
// ════════════════════════════════════════════════════════════
function openModal(id) {
  const m = document.getElementById(id);
  if (!m) return;
  m.classList.add('open');
  if (id === 'orderModal')  Order.initModal();
  if (id === 'incomeModal')  { const el = document.getElementById('inc_date'); if (el) el.value = nowDTLocal(); }
  if (id === 'expenseModal') { const el = document.getElementById('exp_date'); if (el) el.value = nowDTLocal(); }
}

function closeModal(id) {
  const m = document.getElementById(id);
  if (m) m.classList.remove('open');
}

document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('.modal-overlay').forEach(o => {
    o.addEventListener('click', e => {
      if (e.target === o) o.classList.remove('open');
    });
  });
});

// ════════════════════════════════════════════════════════════
// ORDER MODULE
// ════════════════════════════════════════════════════════════
const Order = {

  currentTab:   'photo',
  editingId:    null,
  currentFiles: [],
  _pendingDoneOrder: null,

  initModal() {
    if (this.editingId) return;

    const num = 'ORD-' + String(State.orders.length + 1).padStart(5, '0');

    ['ord_total','ord_prepay','ord_comment','ord_client','ord_phone','ord_manager']
      .forEach(id => { const el = document.getElementById(id); if (el) el.value = ''; });

    const set = (id, v) => { const e = document.getElementById(id); if (e) e.value = v; };
    set('ord_num',      num);
    set('ord_date',     nowDTLocal());
    set('ord_deadline', '');

    const disp = document.getElementById('ordTotalDisplay');
    if (disp) disp.textContent = '0 ₽';

    this.currentFiles = [];
    this.currentTab   = 'photo';

    // Сброс чекбоксов
    document.querySelectorAll('.checkbox-item.checked').forEach(el => {
      el.classList.remove('checked');
      const dot   = el.querySelector('.checkbox-dot');
      const input = el.querySelector('input');
      if (dot)   dot.textContent = '';
      if (input) input.checked   = false;
    });

    // Сброс размеров
    document.querySelectorAll('.size-btn.selected').forEach(b => b.classList.remove('selected'));

    // Сброс файлов
    document.getElementById('fileList') && (document.getElementById('fileList').innerHTML = '');

    switchServiceTab('photo', document.querySelector('.order-service-tab'));

    const dl = document.getElementById('clientsList');
    if (dl) dl.innerHTML = State.clients.map(c => `<option value="${escHtml(c.name)}">`).join('');

    const title = document.getElementById('orderModalTitle');
    if (title) title.textContent = 'Создание заказа';

    document.getElementById('ord_edit_id').value = '';
  },

  // ── Сохранить заказ ────────────────────────────────────
  async save() {
    const editId   = document.getElementById('ord_edit_id')?.value || '';
    const num      = document.getElementById('ord_num')?.value     || '';
    const client   = document.getElementById('ord_client')?.value.trim() || 'Без имени';
    const phone    = document.getElementById('ord_phone')?.value    || '';
    const manager  = document.getElementById('ord_manager')?.value  || '';
    const date     = document.getElementById('ord_date')?.value     || nowDTLocal();
    const deadline = document.getElementById('ord_deadline')?.value || '';
    const status   = document.getElementById('ord_status')?.value   || 'new';
    const payment  = document.getElementById('ord_payment')?.value  || 'Наличные';
    const comment  = document.getElementById('ord_comment')?.value  || '';
    const bizcat   = document.getElementById('ord_bizcat')?.value   || '';
    const total    = parseFloat(document.getElementById('ord_total')?.value)  || 0;
    const prepay   = parseFloat(document.getElementById('ord_prepay')?.value) || 0;

    if (total <= 0) { notify('Укажите сумму заказа', 'error'); return; }

    const extra   = this._collectExtra();
    const options = this._collectChecked();
    const size    = document.querySelector('.size-btn.selected')?.textContent.trim() || '';

    const orderData = {
      num, client, phone, manager, date, deadline,
      service:       this.currentTab,
      service_label: getServiceLabel(this.currentTab),
      size, status, payment, comment, bizcat,
      total, prepay,
      options: JSON.stringify(options),
      extra:   JSON.stringify(extra),
      files:   '[]',
    };

    if (this.currentFiles.length) {
      notify('⏳ Загружаем файлы...', 'info');
      const uploaded    = await this._uploadFiles(editId || Date.now());
      orderData.files   = JSON.stringify(uploaded);
    }

    Ui.syncStatus('saving');

    let res;
    if (editId) {
      res = await Api.orders.update(editId, orderData);
    } else {
      res = await Api.orders.create(orderData);
    }

    if (!res || !res.ok) {
      notify('Ошибка сохранения заказа: ' + (res?.error || ''), 'error');
      Ui.syncStatus('error');
      return;
    }

    const savedOrder = res.data || { ...orderData, id: res.id };

    // ── Финансовая логика — только при создании ──────────
    if (!editId) {
      await this._handleFinanceOnCreate(savedOrder, total, prepay, payment, client, num, date);
    }

    if (editId) {
      const idx = State.orders.findIndex(o => String(o.id) === String(editId));
      if (idx !== -1) State.orders[idx] = savedOrder;
      notify(`Заказ ${num} обновлён`, 'success');
    } else {
      State.orders.unshift(savedOrder);
      notify(`Заказ ${num} создан`, 'success');
    }

    this.editingId    = null;
    this.currentFiles = [];

    closeModal('orderModal');
    Kanban.render();
    Dashboard.refresh();
    App.updateBadges();
    Ui.syncStatus('ok');

    if (!editId) Api.notify('order_new', savedOrder).catch(() => {});
  },

  // ── Финансовая логика при СОЗДАНИИ заказа ──────────────
  async _handleFinanceOnCreate(order, total, prepay, payment, client, num, date) {
    const label = order.service_label || 'Заказ';
    const desc  = `Заказ ${num} — ${client}`;

    // В кредит и Безнал счёт — ничего не пишем автоматически
    if (payment === 'В кредит' || payment === 'Безнал (счёт)') {
      notify(`ℹ️ Заказ ${num}: оплата будет внесена позже`, 'info');
      return;
    }

    if (prepay > 0 && prepay < total) {
      // Частичная предоплата — записываем только предоплату
      await Api.finance.create({
        type: 'income', date, category: label,
        description: desc + ' (предоплата)',
        amount: prepay, method: payment, client, order_id: order.id,
      });
      const remain = total - prepay;
      notify(`💰 Предоплата ${fmt(prepay)} записана. К доплате: ${fmt(remain)}`, 'info');

    } else if (prepay >= total || prepay === 0) {
      // Полная оплата
      await Api.finance.create({
        type: 'income', date, category: label,
        description: desc, amount: total,
        method: payment, client, order_id: order.id,
      });
      notify(`💰 Оплата ${fmt(total)} записана в финансы`, 'success');
    }

    // Обновить State.finance
    const finRes = await Api.finance.list();
    if (finRes.ok) State.set('finance', finRes.data || []);
  },

  // ── При смене статуса на ВЫДАН — проверяем оплату ──────
  async _handleDonePayment(order) {
    const total  = parseFloat(order.total)  || 0;
    const prepay = parseFloat(order.prepay) || 0;
    const remain = total - prepay;

    if (remain <= 0 || order.payment === 'В кредит') {
      // Всё оплачено или кредит — просто завершаем
      this._finalizeDone(order, false);
      return;
    }

    // Есть остаток — показываем модал
    this._pendingDoneOrder = order;

    const info = document.getElementById('paymentModalInfo');
    if (info) {
      info.innerHTML = `
        <div class="payment-table">
          <div class="payment-row"><span>Заказ:</span><strong>${escHtml(order.num)} — ${escHtml(order.client)}</strong></div>
          <div class="payment-row"><span>Итого:</span><strong>${fmt(total)}</strong></div>
          <div class="payment-row"><span>Внесено (предоплата):</span><strong>${fmt(prepay)}</strong></div>
          <div class="payment-row highlight"><span>К доплате:</span><strong>${fmt(remain)}</strong></div>
        </div>
      `;
    }

    // Метод оплаты из заказа
    const methodSel = document.getElementById('paymentModalMethod');
    if (methodSel && order.payment) methodSel.value = order.payment;

    openModal('paymentModal');
  },

  // Вызывается из кнопок модала оплаты при выдаче
  async _confirmDone(doRecord) {
    closeModal('paymentModal');
    const order = this._pendingDoneOrder;
    if (!order) return;

    if (doRecord) {
      const total   = parseFloat(order.total)  || 0;
      const prepay  = parseFloat(order.prepay) || 0;
      const remain  = total - prepay;
      const method  = document.getElementById('paymentModalMethod')?.value || order.payment;

      if (remain > 0) {
        await Api.finance.create({
          type: 'income',
          date: nowDTLocal(),
          category: order.service_label || 'Заказ',
          description: `Доплата по заказу ${order.num} — ${order.client}`,
          amount:   remain,
          method,
          client:   order.client,
          order_id: order.id,
        });
        const finRes = await Api.finance.list();
        if (finRes.ok) State.set('finance', finRes.data || []);
        notify(`💰 Доплата ${fmt(remain)} записана`, 'success');
      }
    }

    this._finalizeDone(order, true);
    this._pendingDoneOrder = null;
  },

  async _finalizeDone(order, fromModal) {
    const res = await Api.orders.update(order.id, { status: 'done' });
    if (!res.ok) { notify('Ошибка смены статуса', 'error'); return; }
    order.status = 'done';
    Kanban.render();
    App.updateBadges();
    Dashboard.refresh();
    notify(`Заказ ${order.num} выдан ✅`, 'success');
    Api.notify('order_done', order).catch(() => {});
  },

  // ── Редактировать заказ ────────────────────────────────
  edit(id) {
    const order = State.order(id);
    if (!order) { notify('Заказ не найден', 'error'); return; }

    this.editingId = id;
    openModal('orderModal');

    setTimeout(() => {
      const set = (elId, val) => { const e = document.getElementById(elId); if (e) e.value = val ?? ''; };

      set('ord_edit_id', order.id);
      set('ord_num',     order.num);
      set('ord_date',    order.date || order.created_at || '');
      set('ord_deadline',order.deadline || '');
      set('ord_client',  order.client);
      set('ord_phone',   order.phone    || '');
      set('ord_manager', order.manager  || '');
      set('ord_status',  order.status);
      set('ord_payment', order.payment  || 'Наличные');
      set('ord_comment', order.comment  || '');
      set('ord_total',   order.total);
      set('ord_prepay',  order.prepay   || 0);
      set('ord_bizcat',  order.bizcat   || '');

      updateTotalDisplay();

      const btn = document.querySelector(`.order-service-tab[onclick*="'${order.service}'"]`);
      if (btn) switchServiceTab(order.service, btn);

      const title = document.getElementById('orderModalTitle');
      if (title) title.textContent = `Редактирование ${order.num}`;

      this.currentFiles = [];
    }, 100);
  },

  // ── Удалить ────────────────────────────────────────────
  async delete(id) {
    if (!confirm('Удалить заказ?')) return;
    const res = await Api.orders.remove(id);
    if (!res.ok) { notify('Ошибка удаления', 'error'); return; }
    State.orders = State.orders.filter(o => String(o.id) !== String(id));
    Kanban.render();
    Dashboard.refresh();
    App.updateBadges();
    closeOrderDetail();
    notify('Заказ удалён', 'info');
  },

  // ── Изменить статус ────────────────────────────────────
  async setStatus(id, newStatus) {
    const order = State.order(id);
    if (!order) return;

    if (newStatus === 'done') {
      // Проверяем оплату перед выдачей
      await this._handleDonePayment(order);
      return;
    }

    const res = await Api.orders.update(id, { status: newStatus });
    if (!res.ok) { notify('Ошибка смены статуса', 'error'); return; }

    order.status = newStatus;
    Kanban.render();
    App.updateBadges();
    notify(`Заказ ${order.num} → ${KB_STATUS_LABELS[newStatus]}`, 'success');
    Api.notify('order_status', order).catch(() => {});
  },

  // ── Сбор доп. параметров ───────────────────────────────
  _collectExtra() {
    const tab = this.currentTab;
    const v   = id => document.getElementById(id)?.value || '';
    const map = {
      photo:    { photo_size: document.querySelector('#sizeMatrix-photo .size-btn.selected')?.textContent.trim() || '', photo_qty: v('photo_qty'), photo_material: v('photo_material'), photo_price: v('photo_price') },
      copy:     { copy_size: document.querySelector('#sizeMatrix-copy .size-btn.selected')?.textContent.trim() || '', copy_qty: v('copy_qty'), copy_sides: v('copy_sides'), copy_price: v('copy_price') },
      banner:   { ban_w: v('ban_w'), ban_h: v('ban_h'), ban_area: v('ban_area'), ban_price: v('ban_price'), ban_qty: v('ban_qty') },
      wide:     { wide_w: v('wide_w'), wide_h: v('wide_h'), wide_area: v('wide_area'), wide_price: v('wide_price') },
      business: { biz_size: document.querySelector('#sizeMatrix-business .size-btn.selected')?.textContent.trim() || '', biz_qty: v('biz_qty'), biz_price: v('biz_price') },
      design:   { des_revisions: v('des_revisions'), des_price: v('des_price'), des_format: v('des_format') },
      promo:    { promo_qty: v('promo_qty'), promo_price: v('promo_price') },
      other:    { other_desc: v('other_desc'), other_qty: v('other_qty'), other_price: v('other_price') },
    };
    return map[tab] || {};
  },

  _collectChecked() {
    const items = [];
    document.querySelector('.service-tab-content.active')
      ?.querySelectorAll('.checkbox-item.checked')
      .forEach(c => items.push(c.textContent.trim().replace('✓', '').trim()));
    return items;
  },

  async _uploadFiles(orderId) {
    const uploaded = [];
    for (const file of this.currentFiles) {
      const fd = new FormData();
      fd.append('file', file);
      fd.append('order_id', orderId);
      try {
        const res = await Api.upload(fd);
        uploaded.push(res.ok
          ? { name: file.name, size: file.size, type: file.type, url: res.url, filename: res.filename }
          : { name: file.name, size: file.size, type: file.type, url: '' }
        );
      } catch {
        uploaded.push({ name: file.name, size: file.size, type: file.type, url: '' });
      }
    }
    return uploaded;
  },
};

window.saveOrder      = ()     => Order.save();
window.editOrder      = id     => Order.edit(id);
window.deleteOrder    = id     => Order.delete(id);
window.editOrderKb    = (e,id) => { if (e) e.stopPropagation(); Order.edit(id); };
window.deleteOrderKb  = id     => Order.delete(id);

// Файлы в форме заказа
window.handleFileSelect = (files) => {
  Order.currentFiles = [...Order.currentFiles, ...Array.from(files)];
  renderFileList();
};
window.handleFileDrop = (e) => {
  e.preventDefault();
  document.getElementById('fileDropZone')?.classList.remove('dragover');
  Order.currentFiles = [...Order.currentFiles, ...Array.from(e.dataTransfer.files)];
  renderFileList();
};
function renderFileList() {
  const list = document.getElementById('fileList');
  if (!list) return;
  list.innerHTML = Order.currentFiles.map((f, i) => `
    <div class="file-item">
      <span class="file-icon">${f.type.startsWith('image/') ? '🖼️' : '📄'}</span>
      <span class="file-name">${escHtml(f.name)}</span>
      <span class="file-size">${formatSize(f.size)}</span>
      <button class="file-remove" onclick="removeFile(${i})">✕</button>
    </div>
  `).join('');
}
window.removeFile = (i) => {
  Order.currentFiles.splice(i, 1);
  renderFileList();
};

// ════════════════════════════════════════════════════════════
// KANBAN
// ════════════════════════════════════════════════════════════
const KB_STATUSES     = ['new','work','ready','done','cancel'];
const KB_SERVICE_LABELS = {
  photo:'📸 Фото', copy:'🖨️ Копи', banner:'🏳️ Баннер',
  design:'🎨 Дизайн', business:'💼 Бизнес',
  wide:'🖼️ Широкий', promo:'🎁 Сувенирка', other:'⚙️ Прочее',
};
const KB_STATUS_LABELS = {
  new:'🆕 Новый', work:'⚙️ В работе',
  ready:'✅ Готов', done:'📦 Выдан', cancel:'❌ Отменён',
};
let draggedOrderId = null;

const Kanban = {

  render() {
    const search = (document.getElementById('orderSearch')?.value || '').toLowerCase();
    const svcF   = document.getElementById('orderServiceFilter')?.value || '';

    let orders = [...State.orders].filter(o => {
      const ms = !search ||
        (o.num     || '').toLowerCase().includes(search) ||
        (o.client  || '').toLowerCase().includes(search) ||
        (o.comment || '').toLowerCase().includes(search);
      const mv = !svcF || o.service === svcF;
      return ms && mv;
    }).sort((a, b) => new Date(b.date || b.created_at || 0) - new Date(a.date || a.created_at || 0));

    KB_STATUSES.forEach(st => {
      const col = document.getElementById('kbCol_' + st);
      if (col) col.innerHTML = '';
    });

    const counts = Object.fromEntries(KB_STATUSES.map(s => [s, 0]));
    let totalSum = 0;

    orders.forEach(order => {
      const st  = order.status || 'new';
      const col = document.getElementById('kbCol_' + st);
      if (!col) return;
      counts[st]++;
      totalSum += Number(order.total) || 0;
      col.appendChild(this._buildCard(order));
    });

    KB_STATUSES.forEach(st => {
      const col = document.getElementById('kbCol_' + st);
      if (col && col.children.length === 0) {
        col.innerHTML = '<div class="kb-empty">Нет заказов</div>';
      }
      const badge = document.getElementById('kbBadge_' + st);
      if (badge) badge.textContent = counts[st] || 0;
    });

    const totalEl = document.getElementById('kbTotalSum');
    if (totalEl) totalEl.textContent = fmt(totalSum);
  },

  _buildCard(order) {
    const card = document.createElement('div');
    card.className = 'kb-card';
    card.draggable  = true;
    card.dataset.id = order.id;

    const svc    = order.service || 'other';
    const svcLbl = KB_SERVICE_LABELS[svc] || svc;

    let deadlineBadge = '';
    if (order.deadline) {
      const diff = new Date(order.deadline) - new Date();
      const h    = diff / 3600000;
      if (h < 0)   deadlineBadge = `<span class="deadline-badge overdue">⚠ Просрочен</span>`;
      else if (h < 24) deadlineBadge = `<span class="deadline-badge soon">⏰ ${Math.ceil(h)}ч</span>`;
      else         deadlineBadge = `<span class="deadline-badge ok">📅 ${Math.ceil(h/24)}д</span>`;
    }

    const total  = parseFloat(order.total)  || 0;
    const prepay = parseFloat(order.prepay) || 0;
    const remain = total - prepay;
    const paidPct = total > 0 ? Math.min(100, Math.round(((total - remain) / total) * 100)) : 0;
    const paidColor = paidPct === 100 ? 'var(--accent3)' : paidPct > 0 ? 'var(--accent4)' : 'var(--danger)';

    const extra = (() => { try { return JSON.parse(order.extra || '{}'); } catch { return {}; } })();
    const desc  = this._buildDesc(order, extra);

    card.innerHTML = `
      <div class="kb-card-header">
        <span class="kb-card-num">${escHtml(order.num || '#—')}</span>
        <span class="kb-card-svc">${svcLbl}</span>
        <div class="kb-card-actions">
          <button class="kb-act-btn" onclick="openOrderDetail(event,'${order.id}')" title="Просмотр">👁</button>
          <button class="kb-act-btn" onclick="editOrderKb(event,'${order.id}')" title="Редактировать">✏</button>
          <button class="kb-act-btn" onclick="toggleKbStatusMenu(event,'${order.id}')" title="Статус">⇄</button>
        </div>
      </div>
      <div class="kb-status-menu" id="kbMenu_${order.id}">
        ${KB_STATUSES.map(s => `
          <button class="kb-status-item ${s === order.status ? 'current' : ''}"
            onclick="changeKbOrderStatus('${order.id}','${s}',event)">
            ${KB_STATUS_LABELS[s]}
          </button>`).join('')}
      </div>
      <div class="kb-card-client">${escHtml(order.client || '👤 Анонимный')}</div>
      <div class="kb-card-desc">${escHtml(desc)}</div>
      <div class="kb-card-footer">
        <div class="kb-pay-row">
          ${prepay > 0 ? `<span class="kb-prepay">Пред: ${fmt(prepay)}</span>` : `<span class="kb-method">${escHtml(order.payment || '')}</span>`}
          <span class="kb-paid-pct" style="color:${paidColor}">${paidPct}%</span>
        </div>
        <div class="kb-card-total">${fmt(order.total)}</div>
        <div class="kb-card-date">🕐 ${fmtDateShort(order.date || order.created_at)}</div>
        ${deadlineBadge}
      </div>
    `;

    card.addEventListener('dragstart', e => {
      draggedOrderId = order.id;
      setTimeout(() => card.classList.add('dragging'), 0);
      e.dataTransfer.setData('text/plain', String(order.id));
    });
    card.addEventListener('dragend', () => {
      card.classList.remove('dragging');
      draggedOrderId = null;
    });
    card.addEventListener('click', e => {
      if (e.target.closest('.kb-card-actions') || e.target.closest('.kb-status-menu')) return;
      openOrderDetail(e, order.id);
    });

    return card;
  },

  _buildDesc(order, extra) {
    const parts = [];
    const svc   = order.service;
    if (svc === 'photo'   && extra.photo_size) parts.push(extra.photo_size);
    if (svc === 'copy'    && extra.copy_size)  parts.push(extra.copy_size);
    if (svc === 'banner'  && extra.ban_w)      parts.push(`${extra.ban_w}×${extra.ban_h}м`);
    if (svc === 'wide'    && extra.wide_w)     parts.push(`${extra.wide_w}×${extra.wide_h}см`);
    if (svc === 'other'   && extra.other_desc) parts.push(extra.other_desc);
    if (order.bizcat)  parts.push(order.bizcat);
    if (order.comment) parts.push(order.comment.substring(0, 50));
    return parts.join(' · ') || '—';
  },
};

window.highlightDrop   = el          => el.classList.add('drag-over');
window.unhighlightDrop = el          => el.classList.remove('drag-over');
window.dropCard        = async (event, newStatus) => {
  event.preventDefault();
  window.unhighlightDrop(event.currentTarget);
  const id = event.dataTransfer.getData('text/plain') || draggedOrderId;
  if (!id) return;
  await Order.setStatus(id, newStatus);
};

window.toggleKbStatusMenu = (e, id) => {
  e.stopPropagation();
  document.querySelectorAll('.kb-status-menu.open').forEach(m => {
    if (m.id !== 'kbMenu_' + id) m.classList.remove('open');
  });
  document.getElementById('kbMenu_' + id)?.classList.toggle('open');
};

window.changeKbOrderStatus = async (id, status, e) => {
  if (e) e.stopPropagation();
  document.querySelectorAll('.kb-status-menu.open').forEach(m => m.classList.remove('open'));
  await Order.setStatus(id, status);
  const overlay = document.getElementById('orderDetailOverlay');
  if (overlay?.classList.contains('open')) openOrderDetail(null, id);
};

document.addEventListener('click', () => {
  document.querySelectorAll('.kb-status-menu.open').forEach(m => m.classList.remove('open'));
});

window.renderKanban = () => Kanban.render();

// ════════════════════════════════════════════════════════════
// ORDER DETAIL OVERLAY
// ════════════════════════════════════════════════════════════
window.openOrderDetail = function(e, id) {
  if (e) e.stopPropagation();
  const order = State.order(id);
  if (!order) return;

  let overlay = document.getElementById('orderDetailOverlay');
  if (!overlay) {
    overlay = document.createElement('div');
    overlay.className = 'order-detail-overlay';
    overlay.id        = 'orderDetailOverlay';
    overlay.innerHTML = '<div id="orderDetailModal" class="order-detail-modal"></div>';
    overlay.addEventListener('click', ev => { if (ev.target === overlay) closeOrderDetail(); });
    document.body.appendChild(overlay);
  }

  const modal  = document.getElementById('orderDetailModal');
  const svc    = order.service || 'other';
  const st     = order.status  || 'new';
  const extra  = (() => { try { return JSON.parse(order.extra   || '{}'); } catch { return {}; } })();
  const opts   = (() => { try { return JSON.parse(order.options || '[]'); } catch { return [];  } })();
  const total  = parseFloat(order.total)  || 0;
  const prepay = parseFloat(order.prepay) || 0;
  const remain = total - prepay;

  const svcIcon = { photo:'📸', copy:'🖨️', banner:'🏳️', design:'🎨', business:'💼', wide:'🖼️', promo:'🎁', other:'⚙️' }[svc] || '📋';

  const chips = [
    ...Object.entries(extra).filter(([,v]) => v).map(([k,v]) => `${k.replace(/_/g,' ')}: ${escHtml(String(v))}`),
    ...opts,
  ].map(p => `<span class="detail-chip">${escHtml(p)}</span>`).join('');

  const stepsHtml = KB_STATUSES.map(s => `
    <button class="detail-step ${s === st ? 'active' : ''}"
      onclick="changeKbOrderStatus('${order.id}','${s}',event)">
      ${KB_STATUS_LABELS[s]}
    </button>`).join('');

  modal.innerHTML = `
    <div class="detail-header">
      <div class="detail-icon">${svcIcon}</div>
      <div class="detail-title-block">
        <h2>Заказ ${escHtml(order.num || '#—')}</h2>
        <div class="detail-client">${escHtml(order.client || 'Анонимный')}</div>
        <div class="detail-sub">${KB_SERVICE_LABELS[svc] || svc} • ${formatDate(order.date || order.created_at)}</div>
      </div>
      <button class="detail-close" onclick="closeOrderDetail()">✕</button>
    </div>

    <div class="detail-status-row">${stepsHtml}</div>

    <div class="detail-grid">
      <div class="detail-item"><span class="di-label">💰 Итого</span><strong>${fmt(total)}</strong></div>
      <div class="detail-item"><span class="di-label">💳 Предоплата</span><strong>${prepay > 0 ? fmt(prepay) : '—'}</strong></div>
      <div class="detail-item ${remain > 0 ? 'di-warn' : 'di-ok'}">
        <span class="di-label">💵 Остаток</span>
        <strong>${remain > 0 ? fmt(remain) : '✅ Оплачено'}</strong>
      </div>
      <div class="detail-item"><span class="di-label">📞 Телефон</span><span>${escHtml(order.phone || '—')}</span></div>
      <div class="detail-item"><span class="di-label">💳 Оплата</span><span>${escHtml(order.payment || '—')}</span></div>
      <div class="detail-item"><span class="di-label">👔 Менеджер</span><span>${escHtml(order.manager || '—')}</span></div>
      <div class="detail-item"><span class="di-label">🕐 Принят</span><span>${formatDate(order.date || order.created_at)}</span></div>
      <div class="detail-item"><span class="di-label">⏰ Дедлайн</span><span>${order.deadline ? formatDate(order.deadline) : '—'}</span></div>
      <div class="detail-item"><span class="di-label">🏷️ Категория</span><span>${escHtml(order.bizcat || '—')}</span></div>
    </div>

    ${chips ? `<div class="detail-chips">${chips}</div>` : ''}
    ${order.comment ? `<div class="detail-comment">💬 ${escHtml(order.comment)}</div>` : ''}

    <div class="detail-actions">
      <button class="btn-outline" onclick="editOrder('${order.id}');closeOrderDetail()">✏️ Редактировать</button>
      <button class="btn-outline" onclick="printReceipt('${order.id}')">🖨️ Чек</button>
      ${st !== 'done'   ? `<button class="btn-primary" onclick="changeKbOrderStatus('${order.id}','done',event)">✅ Выдать</button>` : ''}
      ${st !== 'work'   ? `<button class="btn-outline" onclick="changeKbOrderStatus('${order.id}','work',event)">⚙️ В работу</button>` : ''}
      ${st !== 'ready'  ? `<button class="btn-outline" onclick="changeKbOrderStatus('${order.id}','ready',event)">✅ Готов</button>` : ''}
      <button class="btn-danger" onclick="deleteOrder('${order.id}')">🗑️ Удалить</button>
    </div>
  `;

  requestAnimationFrame(() => overlay.classList.add('open'));
};

window.closeOrderDetail = function() {
  document.getElementById('orderDetailOverlay')?.classList.remove('open');
};

// ════════════════════════════════════════════════════════════
// FINANCE MODULE
// ════════════════════════════════════════════════════════════
const Finance = {

  async saveIncome() {
    const amount = parseFloat(document.getElementById('inc_amount')?.value);
    if (!amount || amount <= 0) { notify('Укажите сумму', 'error'); return; }

    const data = {
      type:        'income',
      date:        document.getElementById('inc_date')?.value    || nowDTLocal(),
      category:    document.getElementById('inc_cat')?.value     || '',
      description: document.getElementById('inc_desc')?.value    || '',
      amount,
      method:      document.getElementById('inc_method')?.value  || '',
      client:      document.getElementById('inc_client')?.value  || '',
    };

    const res = await Api.finance.create(data);
    if (!res.ok) { notify('Ошибка записи дохода', 'error'); return; }

    State.finance.unshift(res.data || { ...data, id: res.id });
    closeModal('incomeModal');
    notify(`💰 Доход ${fmt(amount)} записан`, 'success');
    Dashboard.refresh();
    if (App.currentPage === 'finance') this.renderTable();
  },

  async saveExpense() {
    const amount = parseFloat(document.getElementById('exp_amount')?.value);
    if (!amount || amount <= 0) { notify('Укажите сумму', 'error'); return; }

    const data = {
      type:        'expense',
      date:        document.getElementById('exp_date')?.value    || nowDTLocal(),
      category:    document.getElementById('exp_cat')?.value     || '',
      description: document.getElementById('exp_desc')?.value    || '',
      amount,
      method:      document.getElementById('exp_method')?.value  || '',
    };

    const res = await Api.finance.create(data);
    if (!res.ok) { notify('Ошибка записи расхода', 'error'); return; }

    State.finance.unshift(res.data || { ...data, id: res.id });
    closeModal('expenseModal');
    notify(`📤 Расход ${fmt(amount)} записан`, 'info');
    Dashboard.refresh();
    if (App.currentPage === 'finance') this.renderTable();
  },

  async delete(id) {
    if (!confirm('Удалить запись?')) return;
    const res = await Api.finance.remove(id);
    if (!res.ok) { notify('Ошибка удаления', 'error'); return; }
    State.finance = State.finance.filter(f => String(f.id) !== String(id));
    this.renderTable();
    Dashboard.refresh();
    notify('Запись удалена', 'info');
  },

  renderTable() {
    const search = (document.getElementById('finSearch')?.value  || '').toLowerCase();
    const type   = document.getElementById('finTypeFilter')?.value || '';
    const month  = document.getElementById('finMonthFilter')?.value || '';

    let items = [...State.finance];
    if (search) items = items.filter(i =>
      (i.description || i.desc || '').toLowerCase().includes(search) ||
      (i.category    || '').toLowerCase().includes(search)
    );
    if (type)  items = items.filter(i => i.type === type);
    if (month) items = items.filter(i => (i.date || '').startsWith(month));

    const now      = new Date();
    const curMonth = `${now.getFullYear()}-${String(now.getMonth()+1).padStart(2,'0')}`;
    const mItems   = State.finance.filter(i => (i.date || '').startsWith(curMonth));
    const income   = mItems.filter(i => i.type === 'income') .reduce((a,b) => a + (b.amount||0), 0);
    const expense  = mItems.filter(i => i.type === 'expense').reduce((a,b) => a + (b.amount||0), 0);

    const s = (id, v) => { const e = document.getElementById(id); if (e) e.textContent = v; };
    s('finIncomeTotal',  fmt(income));
    s('finExpenseTotal', fmt(expense));
    s('finProfitTotal',  fmt(income - expense));

    const tbody = document.getElementById('financeTableBody');
    if (!tbody) return;

    if (!items.length) {
      tbody.innerHTML = `<tr><td colspan="7" class="table-empty"><div class="empty-state"><span>💰</span><p>Операций нет</p></div></td></tr>`;
      return;
    }

    tbody.innerHTML = items.map(i => `
      <tr class="fin-row ${i.type}">
        <td>${formatDate(i.date)}</td>
        <td><span class="fin-type-badge ${i.type}">${i.type === 'income' ? '↑ Доход' : '↓ Расход'}</span></td>
        <td>${escHtml(i.category || '—')}</td>
        <td>${escHtml(i.description || i.desc || '—')}</td>
        <td class="fin-amount ${i.type}">${i.type === 'income' ? '+' : '−'}${fmt(i.amount)}</td>
        <td>${escHtml(i.method || '—')}</td>
        <td><button class="btn-icon-danger" onclick="deleteFinance('${i.id}')">🗑️</button></td>
      </tr>
    `).join('');
  },
};

window.saveIncome         = () => Finance.saveIncome();
window.saveExpense        = () => Finance.saveExpense();
window.deleteFinance      = id => Finance.delete(id);
window.renderFinanceTable = () => Finance.renderTable();

// ════════════════════════════════════════════════════════════
// DASHBOARD
// ════════════════════════════════════════════════════════════
const Dashboard = {

  async refresh() {
    const now   = new Date();
    const month = `${now.getFullYear()}-${String(now.getMonth()+1).padStart(2,'0')}`;
    const today = now.toDateString();

    const finance = State.finance;
    const incMonth = finance.filter(f => f.type==='income'  && (f.date||'').startsWith(month)).reduce((a,b)=>a+(b.amount||0),0);
    const expMonth = finance.filter(f => f.type==='expense' && (f.date||'').startsWith(month)).reduce((a,b)=>a+(b.amount||0),0);
    const incToday = finance.filter(f => f.type==='income'  && new Date(f.date).toDateString()===today).reduce((a,b)=>a+(b.amount||0),0);
    const expToday = finance.filter(f => f.type==='expense' && new Date(f.date).toDateString()===today).reduce((a,b)=>a+(b.amount||0),0);
    const profit   = incMonth - expMonth;

    const ordersToday = State.orders.filter(o => new Date(o.date || o.created_at).toDateString() === today).length;

    const s = (id, v) => { const e = document.getElementById(id); if (e) e.textContent = v; };
    s('kpiOrdersToday',  ordersToday);
    s('kpiIncomeMonth',  fmt(incMonth));
    s('kpiExpenseMonth', fmt(expMonth));
    s('kpiProfitMonth',  fmt(profit));
    s('kpiIncomeToday',  'сегодня: ' + fmt(incToday));
    s('kpiExpenseToday', 'сегодня: ' + fmt(expToday));
    s('kpiProfitStatus', profit >= 0 ? '📈 Прибыльно' : '📉 Убыток');

    // Последние заказы
    const ro = document.getElementById('dashRecentOrders');
    if (ro) {
      const recent = State.orders.slice(0, 5);
      ro.innerHTML = recent.length
        ? `<div class="recent-orders-list">
            ${recent.map(o => `
              <div class="ro-item" onclick="openOrderDetail(event,'${o.id}')">
                <div class="ro-left">
                  <span class="ro-num">${escHtml(o.num)}</span>
                  <span class="ro-client">${escHtml(o.client)}</span>
                  <span class="ro-svc">${escHtml(o.service_label || '')}</span>
                </div>
                <div class="ro-right">
                  <span class="ro-total">${fmt(o.total)}</span>
                  <span class="ro-status status-${o.status}">${KB_STATUS_LABELS[o.status] || o.status}</span>
                </div>
              </div>
            `).join('')}
           </div>
           <div style="text-align:center;margin-top:8px">
             <button class="btn-xs" onclick="showPage('orders',document.getElementById('nav-orders'))">Все заказы →</button>
           </div>`
        : `<div class="empty-state"><span>📋</span><p>Заказов пока нет</p><p>Нажмите «Внести заказ»</p></div>`;
    }

    this._renderExtended(today, incToday, expToday, finance);
    this._renderCritical();
    this._renderEvents();
    App.updateBadges();
  },

  _renderExtended(today, sumInc, sumExp, finance) {
    const now    = new Date();
    const profit = sumInc - sumExp;
    const days   = ['Вс','Пн','Вт','Ср','Чт','Пт','Сб'];
    const months = ['января','февраля','марта','апреля','мая','июня','июля','августа','сентября','октября','ноября','декабря'];

    const dateEl = document.getElementById('dashTodayDate');
    if (dateEl) dateEl.textContent = `${days[now.getDay()]}, ${now.getDate()} ${months[now.getMonth()]}`;

    const s = (id, v) => { const e = document.getElementById(id); if (e) e.textContent = v; };
    s('dashTodayIncome',  fmt(sumInc));
    s('dashTodayExpense', fmt(sumExp));
    s('dashTodayProfit',  fmt(profit));

    const total = sumInc + sumExp;
    const pct   = total > 0 ? Math.round((sumInc / total) * 100) : 0;
    const barEl = document.getElementById('dashTodayBar');
    if (barEl) {
      barEl.style.width = pct + '%';
      barEl.style.background = pct >= 60
        ? 'linear-gradient(to right,var(--accent3),var(--accent2))'
        : pct >= 40
          ? 'linear-gradient(to right,var(--accent4),var(--accent2))'
          : 'linear-gradient(to right,var(--danger),var(--accent4))';
    }
    const ratioEl = document.getElementById('dashTodayRatio');
    if (ratioEl) ratioEl.textContent = total > 0 ? `${pct}% доход` : '—';

    // Последние операции
    const rfEl = document.getElementById('dashRecentFinance');
    if (rfEl) {
      const last4 = [...finance].slice(0, 4);
      rfEl.innerHTML = last4.length
        ? last4.map(f => `
            <div class="rf-item">
              <div class="rf-left">
                <span class="rf-icon">${f.type === 'income' ? '💚' : '🔴'}</span>
                <span class="rf-cat">${escHtml(f.category || f.description || '—')}</span>
                <span class="rf-date">${formatDate(f.date)}</span>
              </div>
              <span class="rf-amount ${f.type}">${f.type === 'income' ? '+' : '−'}${fmt(f.amount)}</span>
            </div>`).join('')
        : '<div class="empty-state-sm">Операций пока нет</div>';
    }

    // Почасовой график
    const hourlyEl = document.getElementById('dashHourlyChart');
    if (hourlyEl) {
      const hours = Array(24).fill(0);
      finance.filter(f => f.type==='income' && new Date(f.date).toDateString()===today)
             .forEach(f => { hours[new Date(f.date).getHours()] += f.amount || 0; });
      const bars   = [];
      for (let h = 0; h < 24; h += 2) bars.push({ h, val: hours[h] + (hours[h+1]||0) });
      const maxBar = Math.max(...bars.map(b => b.val), 1);
      const nowH   = now.getHours();

      hourlyEl.innerHTML = bars.map(({ h, val }) => {
        const pct   = Math.max(4, Math.round((val / maxBar) * 100));
        const isNow = h <= nowH && nowH < h + 2;
        return `
          <div class="hourly-bar-wrap">
            ${val > 0 ? `<div class="hourly-bar-label">${val>=1000?Math.round(val/1000)+'к':Math.round(val)}</div>` : ''}
            <div class="hourly-bar ${isNow ? 'current' : ''}" style="height:${pct}%"></div>
          </div>`;
      }).join('');
    }

    // Топ категорий
    const topEl = document.getElementById('dashTopIncome');
    if (topEl) {
      const todayInc = finance.filter(f => f.type==='income' && new Date(f.date).toDateString()===today);
      const cats     = {};
      todayInc.forEach(f => { cats[f.category||'Прочее'] = (cats[f.category||'Прочее']||0) + (f.amount||0); });
      const sorted = Object.entries(cats).sort((a,b) => b[1]-a[1]).slice(0, 5);
      const maxV   = sorted[0]?.[1] || 1;
      const colors = ['#10b981','#06b6d4','#7c3aed','#f59e0b','#ef4444'];
      topEl.innerHTML = sorted.length
        ? sorted.map(([cat, val], i) => `
            <div class="top-cat-item">
              <div class="top-cat-bar" style="background:${colors[i]};width:${Math.round((val/maxV)*100)}%"></div>
              <div class="top-cat-info"><span>${escHtml(cat)}</span><span>${fmt(val)}</span></div>
            </div>`).join('')
        : '<div class="empty-state-sm">Нет доходов сегодня</div>';
    }

    // Итог дня
    const emojiEl = document.getElementById('dashDayEmoji');
    const labelEl = document.getElementById('dashDayLabel');
    if (emojiEl && labelEl) {
      const cases = [
        [sumInc === 0 && sumExp === 0, '😴', 'День не начат', 'var(--text-muted)'],
        [profit > 10000,  '🤑', 'Отличный день!',    'var(--accent3)'],
        [profit > 5000,   '😊', 'Хороший день',       'var(--accent3)'],
        [profit > 1000,   '🙂', 'Небольшой плюс',     'var(--accent2)'],
        [profit === 0 && sumInc > 0, '😐', 'В ноль', 'var(--accent4)'],
        [profit < 0,      '😟', 'Расходы > доходов', 'var(--danger)'],
      ];
      const [, emoji, label, color] = cases.find(([cond]) => cond) || ['','🙂','Работаем','var(--text)'];
      emojiEl.textContent  = emoji;
      labelEl.textContent  = label;
      labelEl.style.color  = color;
    }

    const today2     = new Date().toDateString();
    const todayOrd   = State.orders.filter(o => new Date(o.date || o.created_at).toDateString() === today2);
    const todayFin   = finance.filter(f => new Date(f.date).toDateString() === today2);
    const avgCheck   = todayOrd.length ? Math.round(todayOrd.reduce((a,b)=>a+(b.total||0),0)/todayOrd.length) : 0;
    s('dashTodayOpsCount',    todayFin.length);
    s('dashTodayOrdersCount', todayOrd.length);
    s('dashTodayAvgCheck',    fmt(avgCheck));
  },

  // Критические (просроченные) заказы
  _renderCritical() {
    const now      = new Date();
    const critical = State.orders.filter(o => {
      if (!o.deadline || o.status === 'done' || o.status === 'cancel') return false;
      return new Date(o.deadline) < now;
    });

    const cnt = document.getElementById('dashCriticalCount');
    if (cnt) { cnt.textContent = critical.length; cnt.style.display = critical.length > 0 ? '' : 'none'; }

    const el = document.getElementById('dashCriticalOrders');
    if (!el) return;

    if (!critical.length) {
      el.innerHTML = '<div class="empty-state"><span>✅</span><p>Просроченных нет</p></div>';
      return;
    }

    el.innerHTML = critical.map(o => {
      const overdue = Math.ceil((now - new Date(o.deadline)) / 3600000);
      return `
        <div class="critical-item" onclick="openOrderDetail(event,'${o.id}')">
          <div class="critical-left">
            <span class="critical-num">${escHtml(o.num)}</span>
            <span class="critical-client">${escHtml(o.client)}</span>
          </div>
          <div class="critical-right">
            <span class="critical-overdue">⚠️ +${overdue}ч</span>
            <span class="critical-total">${fmt(o.total)}</span>
          </div>
        </div>`;
    }).join('');

    // Добавить уведомление на дашборд
    if (critical.length > 0) {
      this._addDashNotif(`⚠️ Просрочено ${critical.length} заказ(ов)!`, 'danger');
    }
  },

  // Новые события (веб-заказы, уведомления)
  _renderEvents() {
    const el = document.getElementById('dashNewEvents');
    if (!el) return;

    const events = [];

    // Новые заказы за последний час
    const hourAgo = new Date(Date.now() - 3600000);
    State.orders
      .filter(o => o.status === 'new' && new Date(o.created_at || o.date) > hourAgo)
      .forEach(o => events.push({
        icon: '📋', text: `Новый заказ ${o.num} — ${o.client}`, time: o.created_at || o.date, type: 'order'
      }));

    // Склад заканчивается
    State.warehouse
      .filter(w => parseFloat(w.qty) <= parseFloat(w.min_qty))
      .slice(0, 3)
      .forEach(w => events.push({
        icon: '📦', text: `Заканчивается: ${w.name} (${w.qty} ${w.unit})`, time: null, type: 'warehouse'
      }));

    const cnt = document.getElementById('dashEventsCount');
    if (cnt) { cnt.textContent = events.length; cnt.style.display = events.length > 0 ? '' : 'none'; }

    if (!events.length) {
      el.innerHTML = '<div class="empty-state"><span>📬</span><p>Новых событий нет</p></div>';
      return;
    }

    el.innerHTML = events.map(ev => `
      <div class="event-item event-${ev.type}">
        <span class="event-icon">${ev.icon}</span>
        <div class="event-body">
          <div class="event-text">${escHtml(ev.text)}</div>
          ${ev.time ? `<div class="event-time">${formatDate(ev.time)}</div>` : ''}
        </div>
      </div>`).join('');
  },

  // Уведомления на панели дашборда
  _addDashNotif(text, type = 'info') {
    const panel = document.getElementById('dashNotifPanel');
    const list  = document.getElementById('dashNotifList');
    if (!panel || !list) return;

    panel.style.display = '';

    const item = document.createElement('div');
    item.className = `dash-notif-item ${type}`;
    item.innerHTML = `
      <span>${escHtml(text)}</span>
      <span class="dash-notif-time">${new Date().toLocaleTimeString('ru-RU', {hour:'2-digit',minute:'2-digit'})}</span>
    `;
    list.prepend(item);

    // Максимум 10 уведомлений
    while (list.children.length > 10) list.removeChild(list.lastChild);
  },

  _checkCritical() {
    const now      = new Date();
    const critical = State.orders.filter(o =>
      o.deadline && o.status !== 'done' && o.status !== 'cancel' && new Date(o.deadline) < now
    );
    if (critical.length > 0) {
      this._addDashNotif(`⚠️ Просрочено ${critical.length} заказ(ов)`, 'danger');
    }
  },
};

window.refreshDashboard = () => Dashboard.refresh();
window.clearDashNotifs  = () => {
  const list  = document.getElementById('dashNotifList');
  const panel = document.getElementById('dashNotifPanel');
  if (list)  list.innerHTML     = '';
  if (panel) panel.style.display = 'none';
};

// ════════════════════════════════════════════════════════════
// CLIENTS MODULE
// ════════════════════════════════════════════════════════════
const Clients = {

  async save() {
    const name = document.getElementById('cli_name')?.value.trim();
    if (!name) { notify('Введите имя клиента', 'error'); return; }

    const editId = document.getElementById('cli_edit_id')?.value || '';
    const data   = {
      name,
      type:     document.getElementById('cli_type')?.value     || '',
      phone:    document.getElementById('cli_phone')?.value    || '',
      email:    document.getElementById('cli_email')?.value    || '',
      address:  document.getElementById('cli_address')?.value  || '',
      inn:      document.getElementById('cli_inn')?.value      || '',
      discount: parseFloat(document.getElementById('cli_discount')?.value) || 0,
      notes:    document.getElementById('cli_notes')?.value    || '',
    };

    let res;
    if (editId) {
      res = await Api.clients.update(editId, data);
    } else {
      res = await Api.clients.create(data);
    }

    if (!res.ok) { notify('Ошибка сохранения клиента', 'error'); return; }

    if (editId) {
      const idx = State.clients.findIndex(c => String(c.id) === String(editId));
      if (idx !== -1) State.clients[idx] = { ...State.clients[idx], ...data };
      notify('Клиент обновлён', 'success');
    } else {
      State.clients.unshift(res.data || { ...data, id: res.id });
      notify(`Клиент ${name} добавлен`, 'success');
    }

    closeModal('clientModal');
    this.render();
  },

  async delete(id) {
    if (!confirm('Удалить клиента?')) return;
    const res = await Api.clients.remove(id);
    if (!res.ok) { notify('Ошибка удаления', 'error'); return; }
    State.clients = State.clients.filter(c => String(c.id) !== String(id));
    this.render();
    notify('Клиент удалён', 'info');
  },

  render() {
    const search = (document.getElementById('clientSearch')?.value || '').toLowerCase();
    let clients  = [...State.clients];
    if (search) clients = clients.filter(c =>
      (c.name  || '').toLowerCase().includes(search) ||
      (c.phone || '').includes(search) ||
      (c.email || '').toLowerCase().includes(search)
    );

    const grid = document.getElementById('clientsGrid');
    if (!grid) return;

    if (!clients.length) {
      grid.innerHTML = `<div class="empty-state"><span>👥</span><p>Клиентов нет</p></div>`;
      return;
    }

    const orderCount = name => State.orders.filter(o => o.client === name).length;
    const totalSpent = name => State.orders
      .filter(o => o.client === name && o.status === 'done')
      .reduce((a,b) => a + (b.total||0), 0);

    grid.innerHTML = clients.map(c => `
      <div class="client-card">
        <div class="client-avatar">${(c.name||'?').charAt(0).toUpperCase()}</div>
        <div class="client-info">
          <div class="client-name">${escHtml(c.name)}</div>
          <div class="client-type">${escHtml(c.type || '')}</div>
          ${c.discount > 0 ? `<span class="discount-badge">−${c.discount}%</span>` : ''}
        </div>
        <div class="client-contacts">
          ${c.phone ? `<div class="client-contact">📞 ${escHtml(c.phone)}</div>` : ''}
          ${c.email ? `<div class="client-contact">✉️ ${escHtml(c.email)}</div>` : ''}
        </div>
        <div class="client-stats">
          <div class="cs-item"><strong>${orderCount(c.name)}</strong><span>заказов</span></div>
          <div class="cs-item"><strong>${fmt(totalSpent(c.name))}</strong><span>потрачено</span></div>
        </div>
        <div class="client-actions">
          <button class="btn-icon-danger" onclick="deleteClient('${c.id}')">🗑️</button>
          <button class="btn-xs btn-primary" onclick="newOrderForClient('${escHtml(c.name)}','${escHtml(c.phone || '')}')">+ Заказ</button>
        </div>
      </div>`).join('');
  },
};

window.saveClient       = ()        => Clients.save();
window.deleteClient     = id        => Clients.delete(id);
window.renderClients    = ()        => Clients.render();
window.newOrderForClient = (name, phone) => {
  openModal('orderModal');
  setTimeout(() => {
    const cn = document.getElementById('ord_client');
    const cp = document.getElementById('ord_phone');
    if (cn) cn.value = name;
    if (cp) cp.value = phone;
  }, 100);
};

// ── CRM Notify хелпер ─────────────────────────────────
const Notify = {
    _url: '/bot/crm_notify.php',
    _key: 'crm2025notify',

    async send(event, data = {}) {
        try {
            await fetch(`${this._url}?key=${this._key}&event=${event}`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ event, data }),
            });
        } catch (e) {
            console.warn('Notify.send error:', e);
        }
    },

    orderNew(order)    { return this.send('order_new',      order); },
    orderStatus(order) { return this.send('order_status',   order); },
    orderDone(order)   { return this.send('order_done',     order); },
    orderDelete(order) { return this.send('order_delete',   order); },
    income(fin)        { return this.send('finance_income', fin);   },
    expense(fin)       { return this.send('finance_expense',fin);   },
    salary(rec)        { return this.send('salary_pay',     rec);   },
    empAdd(emp)        { return this.send('employee_add',   emp);   },
    empUpdate(emp)     { return this.send('employee_update',emp);   },
    empDelete(emp)     { return this.send('employee_delete',emp);   },
    shiftOpen(s)       { return this.send('shift_open',     s);     },
    shiftClose(s)      { return this.send('shift_close',    s);     },
    warehouseLow(item) { return this.send('warehouse_low',  item);  },
    warehouseAdd(item) { return this.send('warehouse_add',  item);  },
    debtAdd(debt)      { return this.send('debt_add',       debt);  },
    daySummary(sum)    { return this.send('day_summary',    sum);   },
};

// ════════════════════════════════════════════════════════════
// NOTES MODULE
// ════════════════════════════════════════════════════════════
const Notes = {

  async save() {
    const title = document.getElementById('note_title')?.value.trim() || '';
    const body  = document.getElementById('note_body')?.value.trim()  || '';
    if (!title && !body) { notify('Введите текст заметки', 'error'); return; }

    const data = {
      title:    title || 'Без заголовка',
      body,
      priority: document.getElementById('note_priority')?.value || 'normal',
      shift:    document.getElementById('note_shift')?.value    || '',
    };

    const res = await Api.notes.create(data);
    if (!res.ok) { notify('Ошибка сохранения заметки', 'error'); return; }

    State.notes.unshift(res.data || { ...data, id: res.id });
    closeModal('noteModal');
    this.render();
    App.updateBadges();
    notify('Заметка сохранена', 'success');
  },

  async delete(id) {
    if (!confirm('Удалить заметку?')) return;
    const res = await Api.notes.remove(id);
    if (!res.ok) { notify('Ошибка', 'error'); return; }
    State.notes = State.notes.filter(n => String(n.id) !== String(id));
    this.render();
    App.updateBadges();
  },

  render() {
    const grid = document.getElementById('notesGrid');
    if (!grid) return;

    if (!State.notes.length) {
      grid.innerHTML = `<div class="empty-state"><span>📝</span><p>Заметок нет</p></div>`;
      return;
    }

    const labels = { normal:'Обычная', info:'Информация', important:'⚠️ Важная', urgent:'🚨 Срочно!' };
    const colors = { normal:'var(--text-muted)', info:'var(--accent2)', important:'var(--accent4)', urgent:'var(--danger)' };

    grid.innerHTML = State.notes.map(n => `
      <div class="note-card priority-${n.priority}">
        <div class="note-header">
          <span class="note-title">${escHtml(n.title)}</span>
          <span class="note-priority" style="color:${colors[n.priority]||''}">
            ${labels[n.priority]||n.priority}
          </span>
        </div>
        <div class="note-body">${escHtml(n.body || '')}</div>
        <div class="note-footer">
          <span>🕐 ${formatDate(n.created_at||n.created)}</span>
          ${n.shift ? `<span>👤 ${escHtml(n.shift)}</span>` : ''}
          <button class="btn-icon-danger" onclick="deleteNote('${n.id}')">🗑️</button>
        </div>
      </div>`).join('');
  },
};

window.saveNote    = () => Notes.save();
window.renderNotes = () => Notes.render();
window.deleteNote  = id => Notes.delete(id);

// ════════════════════════════════════════════════════════════
// WAREHOUSE MODULE
// ════════════════════════════════════════════════════════════
const Warehouse = {

  async load() {
    const res = await Api.warehouse.list();
    if (res.ok) State.set('warehouse', res.data || []);
  },

  async render() {
    await this.load();

    const search = (document.getElementById('whSearch')?.value    || '').toLowerCase();
    const cat    = document.getElementById('whCatFilter')?.value  || '';
    const status = document.getElementById('whStatusFilter')?.value || '';
    const all    = State.warehouse;
    let items    = [...all];

    if (search) items = items.filter(i => (i.name||'').toLowerCase().includes(search));
    if (cat)    items = items.filter(i => i.category === cat);

    items = items.map(i => ({ ...i, isLow: parseFloat(i.qty) <= parseFloat(i.min_qty) }));

    if (status === 'low') items = items.filter(i => i.isLow);
    if (status === 'ok')  items = items.filter(i => !i.isLow);

    const low = all.filter(i => parseFloat(i.qty) <= parseFloat(i.min_qty)).length;
    const sum = all.reduce((a,b) => a + (parseFloat(b.qty)||0) * (parseFloat(b.price)||0), 0);

    const s = (id, v) => { const e = document.getElementById(id); if (e) e.textContent = v; };
    s('wh_total', all.length);
    s('wh_low',   low);
    s('wh_ok',    all.length - low);
    s('wh_sum',   fmt(sum));

    const badge = document.getElementById('warehouseLowBadge');
    if (badge) badge.style.display = low > 0 ? '' : 'none';

    const tbody = document.getElementById('warehouseTableBody');
    if (!tbody) return;

    if (!items.length) {
      tbody.innerHTML = `<tr><td colspan="8" class="table-empty"><div class="empty-state"><span>📦</span><p>Склад пуст</p></div></td></tr>`;
      return;
    }

    tbody.innerHTML = items.map(i => `
      <tr class="${i.isLow ? 'row-warning' : ''}">
        <td>${escHtml(i.name)}</td>
        <td>${escHtml(i.category)}</td>
        <td><strong>${i.qty}</strong> ${escHtml(i.unit)}</td>
        <td>${i.min_qty} ${escHtml(i.unit)}</td>
        <td>${i.isLow ? '<span class="badge-warn">⚠️ Мало</span>' : '<span class="badge-ok">✅ Норма</span>'}</td>
        <td>${fmt(i.price)}</td>
        <td>${fmt(parseFloat(i.qty) * parseFloat(i.price))}</td>
        <td>
          <div class="btn-group-sm">
            <button class="btn-xs btn-success" onclick="openWhAction('restock','${i.id}','${escHtml(i.name)}',${i.qty})">+</button>
            <button class="btn-xs btn-warning" onclick="openWhAction('deduct','${i.id}','${escHtml(i.name)}',${i.qty})">−</button>
            <button class="btn-xs btn-danger"  onclick="deleteWarehouseItem('${i.id}')">🗑️</button>
          </div>
        </td>
      </tr>`).join('');
  },

  async add() {
    const name = document.getElementById('wh_name')?.value.trim();
    if (!name) { notify('Введите наименование', 'error'); return; }

    const data = {
      name,
      category: document.getElementById('wh_cat')?.value   || 'Прочее',
      unit:     document.getElementById('wh_unit')?.value  || 'шт',
      qty:      parseFloat(document.getElementById('wh_qty')?.value)    || 0,
      min_qty:  parseFloat(document.getElementById('wh_minqty')?.value) || 0,
      price:    parseFloat(document.getElementById('wh_price')?.value)  || 0,
    };

    const res = await Api.warehouse.create(data);
    if (!res.ok) { notify('Ошибка добавления', 'error'); return; }

    notify(`✅ ${name} добавлен`, 'success');
    closeModal('warehouseAddModal');
    this.render();
  },

  async action(type, id, qty) {
    const res = await Api.warehouse[type]({ id, qty });
    if (!res.ok) { notify('Ошибка: ' + (res.error || ''), 'error'); return; }
    if (res.alert) notify('⚠️ ' + res.alert, 'error');
    else notify(type === 'restock' ? '✅ Пополнено' : '✅ Списано', 'success');
    closeModal('warehouseActionModal');
    this.render();
    App.updateBadges();
  },

  async delete(id) {
    if (!confirm('Удалить позицию?')) return;
    const res = await Api.warehouse.remove(id);
    if (!res.ok) { notify('Ошибка', 'error'); return; }
    this.render();
    notify('Позиция удалена', 'info');
  },

  async showMovements() {
    const res  = await Api.warehouse.history();
    const movs = res.data || [];
    if (!movs.length) { notify('История движений пуста', 'info'); return; }
    const text = movs.slice(0, 20).map(m =>
      `${formatDate(m.created_at)} | ${m.type === 'restock' ? '▲' : '▼'} ${m.name} — ${m.qty} ${m.unit}`
    ).join('\n');
    alert('📋 Последние движения:\n\n' + text);
  },
};

window.saveWarehouseItem      = ()        => Warehouse.add();
window.renderWarehouse        = ()        => Warehouse.render();
window.showWarehouseMovements = ()        => Warehouse.showMovements();
window.deleteWarehouseItem    = id        => Warehouse.delete(id);
window.openWhAction = (type, id, name, currentQty) => {
  const set = (elId, v) => { const e = document.getElementById(elId); if (e) e.value = v; };
  set('wh_action_id',   id);
  set('wh_action_type', type);
  set('wh_action_qty',  '');
  const nm  = document.getElementById('whActionItemName');
  const cur = document.getElementById('whActionCurrentQty');
  const tit = document.getElementById('whActionTitle');
  if (nm)  nm.textContent  = name;
  if (cur) cur.textContent = currentQty;
  if (tit) tit.textContent = type === 'restock' ? '📥 Пополнить склад' : '📤 Списать со склада';
  openModal('warehouseActionModal');
};
window.executeWarehouseAction = () => {
  const id   = document.getElementById('wh_action_id')?.value   || '';
  const type = document.getElementById('wh_action_type')?.value || '';
  const qty  = parseFloat(document.getElementById('wh_action_qty')?.value);
  if (!qty || qty <= 0) { notify('Введите количество', 'error'); return; }
  Warehouse.action(type, id, qty);
};

// ════════════════════════════════════════════════════════════
// CALENDAR MODULE
// ════════════════════════════════════════════════════════════
const Calendar = {

  currentDate: new Date(),

  async render() {
    const year  = this.currentDate.getFullYear();
    const month = this.currentDate.getMonth();
    const from  = `${year}-${String(month+1).padStart(2,'0')}-01`;
    const to    = `${year}-${String(month+1).padStart(2,'0')}-${new Date(year, month+1, 0).getDate()}`;

    const label = document.getElementById('calMonthLabel');
    if (label) label.textContent = this.currentDate.toLocaleDateString('ru', { month: 'long', year: 'numeric' });

    const res    = await Api.calendar.list({ from, to });
    let events   = res.ok ? (res.data || []) : [];

    State.orders.forEach(o => {
      if (!o.deadline || o.status === 'done' || o.status === 'cancel') return;
      const d = (o.deadline || '').slice(0, 10);
      if (d >= from && d <= to) {
        events.push({ id: 'ord_'+o.id, title: `${o.num} — ${o.client}`, date: d, type: 'deadline', color: '#ef4444' });
      }
    });

    const grid = document.getElementById('calendarGrid');
    if (!grid) return;

    const dayNames   = ['Пн','Вт','Ср','Чт','Пт','Сб','Вс'];
    const firstDay   = new Date(year, month, 1).getDay();
    const offset     = firstDay === 0 ? 6 : firstDay - 1;
    const daysInMon  = new Date(year, month + 1, 0).getDate();
    const today      = new Date().toDateString();

    let html = dayNames.map(d => `<div class="cal-day-name">${d}</div>`).join('');
    for (let i = 0; i < offset; i++) html += `<div class="cal-day empty"></div>`;

    for (let d = 1; d <= daysInMon; d++) {
      const dateStr  = `${year}-${String(month+1).padStart(2,'0')}-${String(d).padStart(2,'0')}`;
      const dayEvs   = events.filter(e => e.date === dateStr);
      const isToday  = new Date(year, month, d).toDateString() === today;

      html += `
        <div class="cal-day ${isToday ? 'today' : ''}" onclick="quickAddCalEvent('${dateStr}')">
          <div class="cal-day-num">${d}${isToday ? ' ←' : ''}</div>
          ${dayEvs.map(e => `
            <div class="cal-event" style="background:${e.color||'#7c3aed'}">
              ${escHtml(e.title)}
            </div>`).join('')}
        </div>`;
    }

    grid.innerHTML = html;
  },

  prev() { this.currentDate.setMonth(this.currentDate.getMonth() - 1); this.render(); },
  next() { this.currentDate.setMonth(this.currentDate.getMonth() + 1); this.render(); },
};

window.calPrevMonth  = () => Calendar.prev();
window.calNextMonth  = () => Calendar.next();
window.renderCalendar = () => Calendar.render();
window.quickAddCalEvent = date => {
  const set = (id, v) => { const e = document.getElementById(id); if (e) e.value = v; };
  set('cal_date', date); set('cal_time', ''); set('cal_title', '');
  openModal('calEventModal');
};
window.saveCalEvent = async () => {
  const title = document.getElementById('cal_title')?.value.trim();
  if (!title) { notify('Введите заголовок', 'error'); return; }
  const data = {
    title,
    date:  document.getElementById('cal_date')?.value  || '',
    time:  document.getElementById('cal_time')?.value  || '',
    type:  document.getElementById('cal_type')?.value  || 'task',
    color: document.getElementById('cal_color')?.value || '#7c3aed',
    note:  document.getElementById('cal_note')?.value  || '',
  };
  const res = await Api.calendar.create(data);
  if (!res.ok) { notify('Ошибка', 'error'); return; }
  closeModal('calEventModal');
  Calendar.render();
  notify('✅ Задача добавлена', 'success');
};

// ════════════════════════════════════════════════════════════
// STATS MODULE
// ════════════════════════════════════════════════════════════
const Stats = {

  render() {
    const period = document.getElementById('statsPeriod')?.value || 'month';
    const now    = new Date();
    let orders   = [...State.orders];

    if (period === 'month') orders = orders.filter(o => {
      const d = new Date(o.date || o.created_at);
      return d.getMonth() === now.getMonth() && d.getFullYear() === now.getFullYear();
    });
    if (period === 'week') {
      const weekAgo = new Date(now - 7 * 86400000);
      orders = orders.filter(o => new Date(o.date || o.created_at) >= weekAgo);
    }

    const s = (id, v) => { const e = document.getElementById(id); if (e) e.textContent = v; };
    s('statTotalOrders', orders.length);
    s('statDoneOrders',  orders.filter(o => o.status === 'done').length);
    s('statClients',     State.clients.length);
    s('statAvgCheck',    fmt(orders.length ? Math.round(orders.reduce((a,b) => a+(b.total||0),0)/orders.length) : 0));

    const byService = {};
    orders.forEach(o => { byService[o.service_label||'Прочее'] = (byService[o.service_label||'Прочее']||0) + 1; });
    this._renderBarChart('statsByService', byService, '#7c3aed');

    const byBiz = {};
    orders.forEach(o => { const k = o.bizcat||'Не указано'; byBiz[k] = (byBiz[k]||0) + 1; });
    this._renderBarChart('statsByCategory', byBiz, '#06b6d4');
    this._renderServiceBars(orders);
  },

  _renderBarChart(id, data, color) {
    const el = document.getElementById(id);
    if (!el) return;
    const entries = Object.entries(data).sort((a,b) => b[1]-a[1]).slice(0, 8);
    if (!entries.length) { el.innerHTML = '<div class="empty-state-sm">Нет данных</div>'; return; }
    const max = Math.max(...entries.map(e => e[1]));
    el.innerHTML = entries.map(([k, v]) => `
      <div class="bar-item">
        <div class="bar-label">${escHtml(k)}<span class="bar-val">${v}</span></div>
        <div class="bar-track"><div class="bar-fill" style="width:${Math.round((v/max)*100)}%;background:${color}"></div></div>
      </div>`).join('');
  },

  _renderServiceBars(orders) {
    const el = document.getElementById('statsServiceBars');
    if (!el) return;
    const data    = {};
    orders.forEach(o => { data[o.service_label||'Прочее'] = (data[o.service_label||'Прочее']||0) + 1; });
    const entries = Object.entries(data).sort((a,b) => b[1]-a[1]).slice(0, 8);
    if (!entries.length) { el.innerHTML = '<div class="empty-state-sm">Нет данных</div>'; return; }
    const max    = Math.max(...entries.map(e => e[1]));
    const colors = ['#7c3aed','#06b6d4','#10b981','#f59e0b','#ef4444','#8b5cf6','#0ea5e9','#14b8a6'];
    el.innerHTML = `
      <div class="service-bars">
        ${entries.map(([k, v], i) => `
          <div class="svc-bar-item">
            <div class="svc-bar-track">
              <div class="svc-bar-fill" style="height:${Math.round((v/max)*100)}%;background:${colors[i]}">
                <span class="svc-bar-val">${v}</span>
              </div>
            </div>
            <div class="svc-bar-label">${escHtml(k.split(' ')[0])}</div>
          </div>`).join('')}
      </div>`;
  },
};

window.renderStats = () => Stats.render();

// ════════════════════════════════════════════════════════════
// ACCOUNTING MODULE
// ════════════════════════════════════════════════════════════
const Accounting = {

  render() {
    const months = {};
    State.finance.forEach(f => {
      const d   = new Date(f.date);
      const key = `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}`;
      if (!months[key]) months[key] = { income: 0, expense: 0 };
      if (f.type === 'income')  months[key].income  += f.amount || 0;
      else                      months[key].expense += f.amount || 0;
    });

    const ordersByMonth = {};
    State.orders.forEach(o => {
      const d   = new Date(o.date || o.created_at);
      const key = `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}`;
      ordersByMonth[key] = (ordersByMonth[key] || 0) + 1;
    });

    const MONTHS = ['Январь','Февраль','Март','Апрель','Май','Июнь','Июль','Август','Сентябрь','Октябрь','Ноябрь','Декабрь'];
    const tbody  = document.getElementById('accountingTable');
    if (!tbody) return;

    const arr = Object.entries(months).sort((a,b) => b[0].localeCompare(a[0]));
    tbody.innerHTML = !arr.length
      ? `<tr><td colspan="6" class="table-empty"><div class="empty-state"><span>📊</span><p>Нет данных</p></div></td></tr>`
      : arr.map(([k, v]) => {
          const profit = v.income - v.expense;
          const margin = v.income > 0 ? ((profit/v.income)*100).toFixed(1) : '0';
          const [yr, mn] = k.split('-');
          return `
            <tr>
              <td>${MONTHS[parseInt(mn)-1]} ${yr}</td>
              <td class="income-cell">${fmt(v.income)}</td>
              <td class="expense-cell">${fmt(v.expense)}</td>
              <td class="${profit >= 0 ? 'profit-cell' : 'loss-cell'}">${fmt(profit)}</td>
              <td>${ordersByMonth[k] || 0}</td>
              <td>${margin}%</td>
            </tr>`;
        }).join('');

    const expByCat = {}, incByCat = {};
    State.finance.forEach(f => {
      const cat = f.category || 'Прочее';
      if (f.type === 'expense') expByCat[cat] = (expByCat[cat]||0) + (f.amount||0);
      else                      incByCat[cat] = (incByCat[cat]||0) + (f.amount||0);
    });
    Stats._renderBarChart('expenseByCategory', expByCat, '#ef4444');
    Stats._renderBarChart('incomeByCategory',  incByCat, '#10b981');
  },
};

window.renderAccounting = () => Accounting.render();

// ════════════════════════════════════════════════════════════
// SETTINGS MODULE
// ════════════════════════════════════════════════════════════
const Settings = {

  // Все поля: [id в HTML → ключ в settings]
  _fields: {
    setCompany:'company', setInn:'inn', setOgrn:'ogrn',
    setAddress:'address', setPhone:'phone', setEmail:'email',
    setWebsite:'website', setBankAcc:'bankAcc', setBik:'bik',
    setBankName:'bankName', setKorAcc:'korAcc', setKpp:'kpp',
    setReceiptHeader:'receiptHeader', setReceiptFooter:'receiptFooter',
    setSignatory:'signatory', setSignatoryTitle:'signatoryTitle',
    setVat:'vat', setCurrency:'currency',
    setApiKey:'apiKey', setApiModel:'apiModel',
    // Telegram
    setTgToken:'tgToken', setTgBossId:'tgBossId',
    // VK
    setVkToken:'vkToken', setVkWebhookUrl:'vkWebhookUrl',
    setVkConfirmCode:'vkConfirmCode', setVkApiKey:'vkApiKey',
    // МАКс
    setMaxWebhookUrl:'maxWebhookUrl', setMaxApiKey:'maxApiKey',
    // Сбербанк
    setSberLogin:'sberLogin', setSberPassword:'sberPassword',
    setSberReturnUrl:'sberReturnUrl', setSberFailUrl:'sberFailUrl',
    setSberMode:'sberMode',
    // Тинькофф
    setTinkTerminalKey:'tinkTerminalKey', setTinkSecretKey:'tinkSecretKey',
    setTinkNotifyUrl:'tinkNotifyUrl', setTinkMode:'tinkMode',
    // Ozon
    setOzonAccessKey:'acquiring_access_key', setOzonSecretKey:'acquiring_secret_key',
    setOzonApiUrl:'acquiring_api_url', setOzonSuccessUrl:'acquiring_success_url',
    setOzonFailUrl:'acquiring_fail_url', setOzonNotifyUrl:'acquiring_notify_url',
    // СБП
    setSbpBank:'sbpBank', setSbpMerchantId:'sbpMerchantId',
    setSbpApiKey:'sbpApiKey', setSbpQrUrl:'sbpQrUrl',
  },

  // Чекбоксы-переключатели интеграций
  _toggles: {
    setTgEnabled:'tgEnabled', setVkEnabled:'vkEnabled',
    setMaxEnabled:'maxEnabled', setSberEnabled:'sberEnabled',
    setTinkEnabled:'tinkEnabled', setOzonEnabled:'ozonEnabled',
    setSbpEnabled:'sbpEnabled',
  },

  async load() {
    const res = await Api.settings.get();
    if (res.ok) State.set('settings', res.data || {});

    const s = State.settings;

    // Текстовые поля
    Object.entries(this._fields).forEach(([id, key]) => {
      const el = document.getElementById(id);
      if (el) el.value = s[key] || '';
    });

    // Чекбоксы
    Object.entries(this._toggles).forEach(([id, key]) => {
      const el = document.getElementById(id);
      if (el) el.checked = !!(s[key]);
    });

    // Превью подписей/печати/логотипа
    this._loadPreviews(s);

    Ui.updateDbInfo();
    this._renderModulesGrid();
  },

  async save() {
    const data = {};

    Object.entries(this._fields).forEach(([id, key]) => {
      const el = document.getElementById(id);
      if (el) data[key] = el.value;
    });

    Object.entries(this._toggles).forEach(([id, key]) => {
      const el = document.getElementById(id);
      if (el) data[key] = el.checked ? '1' : '';
    });

    const res = await Api.settings.save(data);
    if (!res.ok) { notify('Ошибка сохранения настроек', 'error'); return; }

    State.set('settings', { ...State.settings, ...data });
    notify('✅ Настройки сохранены', 'success');
  },

  _loadPreviews(s) {
    const imgs = { signature: 'signaturePreview', stamp: 'stampPreview', logo: 'logoPreview' };
    const plhs = { signature: 'signaturePlaceholder', stamp: 'stampPlaceholder', logo: 'logoPlaceholder' };
    Object.entries(imgs).forEach(([key, imgId]) => {
      const img = document.getElementById(imgId);
      const plh = document.getElementById(plhs[key]);
      const url = s[key + '_url'] || '';
      if (img && plh) {
        if (url) {
          img.src = url; img.style.display = 'block'; plh.style.display = 'none';
        } else {
          img.style.display = 'none'; plh.style.display = 'block';
        }
      }
    });
  },

  _renderModulesGrid() {
    const grid = document.getElementById('modulesGrid');
    if (!grid) return;
    const mods = Object.values(CRM._modules || {});
    if (!mods.length) {
      grid.innerHTML = '<div class="empty-state-sm">Нет подключённых модулей</div>';
      return;
    }
    grid.innerHTML = mods.map(m => `
      <div class="module-item">
        <span class="module-icon">${m.icon || '🔧'}</span>
        <div class="module-info"><strong>${escHtml(m.name)}</strong><small>${escHtml(m.id)}</small></div>
        <span class="module-status">Активен</span>
      </div>`).join('');
  },
};

window.loadSettings      = ()           => Settings.load();
window.saveSettings      = ()           => Settings.save();
window.renderModulesGrid = ()           => Settings._renderModulesGrid();
window.toggleIntegration = (key, val)  => {
  // Визуальная реакция — сохранение при клике на тоггл
  notify(val ? `✅ Интеграция активирована` : `⏸️ Интеграция отключена`, 'info');
};

// Загрузка изображений (подпись, печать, логотип)
window.uploadStamp = async (type, input) => {
  const file = input.files[0];
  if (!file) return;

  notify(`⏳ Загружаем ${type}...`, 'info');
  const fd = new FormData();
  fd.append('file', file);
  fd.append('order_id', type);

  const res = await Api.upload(fd);
  if (!res.ok) { notify('Ошибка загрузки: ' + (res.error || ''), 'error'); return; }

  // Сохранить URL в настройках
  const key  = type + '_url';
  const data = { [key]: res.url };
  await Api.settings.save(data);
  State.settings[key] = res.url;

  // Обновить превью
  const imgId = type + 'Preview';
  const plhId = type + 'Placeholder';
  const img   = document.getElementById(imgId);
  const plh   = document.getElementById(plhId);
  if (img) { img.src = res.url; img.style.display = 'block'; }
  if (plh) plh.style.display = 'none';

  notify(`✅ ${type === 'signature' ? 'Подпись' : type === 'stamp' ? 'Печать' : 'Логотип'} загружен`, 'success');
};

window.removeStamp = async (type) => {
  const key  = type + '_url';
  await Api.settings.save({ [key]: '' });
  State.settings[key] = '';

  const img = document.getElementById(type + 'Preview');
  const plh = document.getElementById(type + 'Placeholder');
  if (img) img.style.display = 'none';
  if (plh) plh.style.display = 'block';

  notify('🗑️ Удалено', 'info');
};

// ════════════════════════════════════════════════════════════
// CRM FRAMEWORK
// ════════════════════════════════════════════════════════════
window.CRM = {
  _modules: {},

  registerModule(cfg) {
    this._modules[cfg.id] = cfg;
    this._injectPage(cfg);
    this._injectNav(cfg);
    console.log(`✅ Модуль «${cfg.name}» зарегистрирован`);
  },

  _injectPage(cfg) {
    if (document.getElementById('page-' + cfg.id)) return;
    const div = document.createElement('div');
    div.className = 'page';
    div.id        = 'page-' + cfg.id;
    const inner = document.createElement('div');
    inner.id = 'module-container-' + cfg.id;
    inner.style.cssText = 'height:100%;overflow-y:auto';
    inner.innerHTML = '<div style="text-align:center;padding:40px;color:#94a3b8">&#8987; Загрузка...</div>';
    div.appendChild(inner);
    const main = document.getElementById('mainContent');
    if (main) main.appendChild(div);
    if (typeof cfg.init === 'function') {
      Promise.resolve().then(function() { return cfg.init(inner); }).catch(function(e) {
        console.error('[CRM] init error', cfg.id, e);
        inner.innerHTML = '<div style="padding:20px;color:#ef4444">Ошибка: ' + e.message + '</div>';
      });
    }
  },

  _injectNav(cfg) {
    const section = document.getElementById('modules-sidebar-section');
    if (!section) return;
    if (document.getElementById('nav-' + cfg.id)) return;
    const btn     = document.createElement('button');
    btn.className = 'nav-btn';
    btn.id        = 'nav-' + cfg.id;
    btn.innerHTML = `${cfg.icon||'🔧'} ${cfg.name}`;
    btn.onclick   = () => App.showPage(cfg.id, btn);
    section.appendChild(btn);
  },

  api: (module, action, body, params) => Api.module(module, action, body, params),
};

// ════════════════════════════════════════════════════════════
// EXPORT / IMPORT / CLEAR
// ════════════════════════════════════════════════════════════
window.exportDB = () => {
  const data = { orders: State.orders, finance: State.finance, clients: State.clients, settings: State.settings, notes: State.notes };
  const blob  = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' });
  const url   = URL.createObjectURL(blob);
  const a     = document.createElement('a');
  a.href      = url;
  a.download  = `printcrm_${new Date().toISOString().slice(0,10)}.json`;
  a.click();
  URL.revokeObjectURL(url);
  notify('База экспортирована', 'success');
};

window.importDB = () => document.getElementById('importFile')?.click();

window.loadImportFile = async (e) => {
  const file = e.target.files[0];
  if (!file) return;
  try {
    const text = await file.text();
    const data = JSON.parse(text);
    if (!confirm('Загрузить базу? Текущие данные будут заменены.')) return;
    const res  = await Api.post('import', data);
    if (res.ok) { notify('База импортирована', 'success'); await Sync.loadAll(); }
    else notify('Ошибка импорта: ' + (res.error||''), 'error');
  } catch { notify('Ошибка: неверный файл', 'error'); }
};

window.clearDB = async () => {
  if (!confirm('УДАЛИТЬ ВСЕ ДАННЫЕ?'))    return;
  if (!confirm('Вы точно уверены?'))      return;
  const res = await Api.post('db/clear', {});
  if (res.ok) { await Sync.loadAll(); notify('База очищена', 'info'); }
  else notify('Ошибка очистки', 'error');
};

// ════════════════════════════════════════════════════════════
// PRINT
// ════════════════════════════════════════════════════════════
window.printOrderForm = (forWhom) => {
  const s        = State.settings || {};
  const num      = document.getElementById('ord_num')?.value      || '';
  const client   = document.getElementById('ord_client')?.value   || 'Без имени';
  const phone    = document.getElementById('ord_phone')?.value    || '';
  const manager  = document.getElementById('ord_manager')?.value  || '';
  const date     = document.getElementById('ord_date')?.value     || '';
  const deadline = document.getElementById('ord_deadline')?.value || '';
  const total    = parseFloat(document.getElementById('ord_total')?.value)  || 0;
  const prepay   = parseFloat(document.getElementById('ord_prepay')?.value) || 0;
  const comment  = document.getElementById('ord_comment')?.value  || '';
  const payment  = document.getElementById('ord_payment')?.value  || '';
  const service  = getServiceLabel(Order.currentTab);
  const size     = document.querySelector('.size-btn.selected')?.textContent.trim() || '';
  const remain   = total - prepay;
  const isMan    = forWhom === 'manager';

  const checked = [];
  document.querySelector('.service-tab-content.active')
    ?.querySelectorAll('.checkbox-item.checked')
    .forEach(c => checked.push(c.textContent.trim().replace('✓','').trim()));

  const logoHtml = s.logo_url ? `<img src="${s.logo_url}" style="max-height:60px;max-width:200px;object-fit:contain">` : '';
  const signHtml = s.signature_url ? `<img src="${s.signature_url}" style="max-height:50px">` : '_______________';
  const stampHtml = s.stamp_url ? `<img src="${s.stamp_url}" style="max-height:80px;opacity:0.8;position:absolute;bottom:20px;right:20px">` : '';

  const html = `
    <div style="font-family:Arial,sans-serif;max-width:800px;margin:0 auto;padding:20px;position:relative">
      ${stampHtml}
      <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:16px;border-bottom:2px solid #333;padding-bottom:12px">
        <div>
          ${logoHtml}
          <h2 style="margin:4px 0">${escHtml(s.company||'Фотокопицентр')}</h2>
          ${s.address ? `<div>${escHtml(s.address)}</div>` : ''}
          ${s.phone   ? `<div>Тел: ${escHtml(s.phone)}</div>` : ''}
          ${s.inn     ? `<div>ИНН: ${escHtml(s.inn)}</div>` : ''}
        </div>
        <div style="text-align:right">
          <h3>${isMan ? 'БЛАНК МЕНЕДЖЕРА' : 'КВИТАНЦИЯ КЛИЕНТА'}</h3>
          <div style="font-size:24px;font-weight:bold;color:#7c3aed">№ ${escHtml(num)}</div>
        </div>
      </div>
      <table style="width:100%;border-collapse:collapse;margin-bottom:16px">
        <tr><td style="padding:4px;border:1px solid #ddd;width:25%;background:#f5f5f5"><b>Дата приёма</b></td><td style="padding:4px;border:1px solid #ddd">${formatDate(date)}</td>
            <td style="padding:4px;border:1px solid #ddd;background:#f5f5f5"><b>Срок выдачи</b></td><td style="padding:4px;border:1px solid #ddd">${deadline ? formatDate(deadline) : '—'}</td></tr>
        <tr><td style="padding:4px;border:1px solid #ddd;background:#f5f5f5"><b>Клиент</b></td><td style="padding:4px;border:1px solid #ddd">${escHtml(client)}</td>
            <td style="padding:4px;border:1px solid #ddd;background:#f5f5f5"><b>Телефон</b></td><td style="padding:4px;border:1px solid #ddd">${escHtml(phone)}</td></tr>
        <tr><td style="padding:4px;border:1px solid #ddd;background:#f5f5f5"><b>Услуга</b></td><td style="padding:4px;border:1px solid #ddd">${escHtml(service)}</td>
            <td style="padding:4px;border:1px solid #ddd;background:#f5f5f5"><b>Формат</b></td><td style="padding:4px;border:1px solid #ddd">${escHtml(size)||'—'}</td></tr>
        <tr><td style="padding:4px;border:1px solid #ddd;background:#f5f5f5"><b>Менеджер</b></td><td style="padding:4px;border:1px solid #ddd">${escHtml(manager)}</td>
            <td style="padding:4px;border:1px solid #ddd;background:#f5f5f5"><b>Оплата</b></td><td style="padding:4px;border:1px solid #ddd">${escHtml(payment)}</td></tr>
      </table>
      ${checked.length ? `<div style="margin-bottom:12px"><b>Параметры:</b> ${checked.map(c=>`✓ ${escHtml(c)}`).join(', ')}</div>` : ''}
      ${comment ? `<div style="margin-bottom:12px;padding:8px;background:#f9f9f9;border-left:3px solid #7c3aed"><b>Комментарий:</b> ${escHtml(comment)}</div>` : ''}
      <table style="width:100%;border-collapse:collapse;margin-bottom:16px">
        <tr style="background:#7c3aed;color:#fff"><td colspan="2" style="padding:8px;text-align:center"><b>РАСЧЁТ</b></td></tr>
        <tr><td style="padding:6px;border:1px solid #ddd"><b>ИТОГО:</b></td><td style="padding:6px;border:1px solid #ddd;font-size:20px;font-weight:bold">${fmt(total)}</td></tr>
        ${prepay > 0 ? `<tr><td style="padding:6px;border:1px solid #ddd">Внесена предоплата:</td><td style="padding:6px;border:1px solid #ddd">${fmt(prepay)}</td></tr>` : ''}
        <tr style="background:${remain > 0 ? '#fff3cd' : '#d4edda'}">
          <td style="padding:6px;border:1px solid #ddd"><b>К ДОПЛАТЕ:</b></td>
          <td style="padding:6px;border:1px solid #ddd;font-size:18px;font-weight:bold">${remain > 0 ? fmt(remain) : '✅ Полностью оплачено'}</td>
        </tr>
      </table>
      ${s.receiptHeader ? `<div style="margin-bottom:12px;color:#666">${escHtml(s.receiptHeader)}</div>` : ''}
      <div style="display:flex;justify-content:space-between;margin-top:20px">
        <div>${escHtml(s.signatoryTitle||'Менеджер')}: ${signHtml}</div>
        <div>Клиент: _______________</div>
      </div>
      ${s.receiptFooter ? `<div style="margin-top:12px;text-align:center;color:#999;font-size:12px">${escHtml(s.receiptFooter)}</div>` : ''}
    </div>`;

  const pa = document.getElementById('printArea');
  if (pa) {
    pa.innerHTML = html;
    pa.style.display = 'block';
    window.print();
    setTimeout(() => { pa.style.display = 'none'; }, 1000);
  }
};

window.printReceipt = (id) => {
  const order = State.order(id);
  if (!order) return;
  // Заполняем поля из заказа и печатаем
  Order.edit(id);
  setTimeout(() => { printOrderForm('client'); closeModal('orderModal'); }, 200);
};

// ════════════════════════════════════════════════════════════
// ТЕСТ POS ИНТЕГРАЦИЙ
// ════════════════════════════════════════════════════════════
window.testPosIntegration = async (provider) => {
  notify(`🔄 Тестирую ${provider}...`, 'info');
  const res = await Api.post('pos/payment', { provider, amount: 1, description: 'Тест' });
  if (res.ok && res.status !== 'error') {
    notify(`✅ ${provider}: соединение установлено`, 'success');
  } else {
    notify(`❌ ${provider}: ${res.error || res.description || 'ошибка'}`, 'error');
  }
};

// ════════════════════════════════════════════════════════════
// DEEPSEEK AI CHAT
// ════════════════════════════════════════════════════════════
let chatHistory = [];

async function sendChatMessage() {
  const input = document.getElementById('chatInput');
  const text  = input?.value.trim();
  if (!text) return;
  if (input) { input.value = ''; input.style.height = 'auto'; }
  await _processChatMessage(text);
}

window.sendQuickChat = async (text) => await _processChatMessage(text);

async function _processChatMessage(text) {
  const msgs = document.getElementById('chatMessages');
  if (!msgs) return;

  _appendChatMsg(msgs, 'user', text);
  chatHistory.push({ role: 'user', content: text });

  const typingId = _appendTyping(msgs);

  try {
    const apiKey = State.settings.apiKey || '';
    const model  = State.settings.apiModel || 'deepseek-chat';
    if (!apiKey) {
      _removeTyping(typingId, msgs);
      _appendChatMsg(msgs, 'ai', '⚠️ Добавьте API ключ DeepSeek в Настройках.');
      return;
    }

    const res = await fetch('https://api.deepseek.com/v1/chat/completions', {
      method:  'POST',
      headers: { 'Content-Type': 'application/json', 'Authorization': `Bearer ${apiKey}` },
      body: JSON.stringify({
        model,
        messages: [
          { role: 'system', content: 'Ты Валера — эксперт по типографии и печатному бизнесу. Помогаешь управлять фотокопицентром. Отвечаешь кратко, по-русски, по делу.' },
          ...chatHistory.slice(-10),
        ],
        max_tokens: 1000,
      }),
    });

    const data = await res.json();
    _removeTyping(typingId, msgs);

    const reply = data.choices?.[0]?.message?.content || '⚠️ Нет ответа от AI';
    chatHistory.push({ role: 'assistant', content: reply });
    _appendChatMsg(msgs, 'ai', reply);

  } catch (e) {
    _removeTyping(typingId, msgs);
    _appendChatMsg(msgs, 'ai', '❌ Ошибка: ' + e.message);
  }
}

function _appendChatMsg(container, type, text) {
  const div  = document.createElement('div');
  div.className = `chat-msg ${type}`;
  const time = new Date().toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });
  div.innerHTML = `
    <div class="chat-msg-avatar">${type === 'ai' ? '🤖' : '👤'}</div>
    <div class="chat-msg-body">
      <div class="chat-msg-text">${text.replace(/\n/g, '<br>')}</div>
      <div class="chat-msg-time">${time}</div>
    </div>`;
  container.appendChild(div);
  container.scrollTop = container.scrollHeight;
}

function _appendTyping(container) {
  const id  = 'typing_' + Date.now();
  const div = document.createElement('div');
  div.className = 'chat-msg ai';
  div.id        = id;
  div.innerHTML = `<div class="chat-msg-avatar">🤖</div><div class="chat-msg-body"><div class="typing-dots"><span></span><span></span><span></span></div></div>`;
  container.appendChild(div);
  container.scrollTop = container.scrollHeight;
  return id;
}

function _removeTyping(id, container) {
  document.getElementById(id)?.remove();
}

window.autoResizeTextarea = el => {
  el.style.height = 'auto';
  el.style.height = Math.min(el.scrollHeight, 120) + 'px';
};

async function testApiKey() {
  const key = document.getElementById('setApiKey')?.value;
  if (!key) { notify('Введите API ключ', 'error'); return; }
  notify('Проверяю...', 'info');
  try {
    const res  = await fetch('https://api.deepseek.com/v1/chat/completions', {
      method:  'POST',
      headers: { 'Content-Type': 'application/json', 'Authorization': `Bearer ${key}` },
      body: JSON.stringify({ model: 'deepseek-chat', messages: [{ role: 'user', content: 'ping' }], max_tokens: 5 }),
    });
    const data = await res.json();
    if (data.choices) notify('✅ API ключ работает!', 'success');
    else notify('❌ Ключ не работает: ' + JSON.stringify(data), 'error');
  } catch (e) { notify('❌ Ошибка: ' + e.message, 'error'); }
}

window.testApiKey = testApiKey;

window.testTelegram = async () => {
  const res = await Api.notify('test', { message: '🔔 Тест уведомление из PrintCRM' });
  if (res.ok) notify('📤 Тест отправлен в Telegram', 'success');
  else notify('❌ Ошибка: ' + (res.error||''), 'error');
};

// ════════════════════════════════════════════════════════════
// УТИЛИТЫ
// ════════════════════════════════════════════════════════════
function nowDTLocal() {
  const n = new Date(), p = v => String(v).padStart(2,'0');
  return `${n.getFullYear()}-${p(n.getMonth()+1)}-${p(n.getDate())}T${p(n.getHours())}:${p(n.getMinutes())}`;
}

function formatDate(str) {
  if (!str) return '—';
  try {
    const d    = new Date(str);
    const base = d.toLocaleDateString('ru-RU', { day:'2-digit', month:'2-digit', year:'numeric' });
    return (str.includes('T') || str.includes(' '))
      ? base + ' ' + d.toLocaleTimeString('ru-RU', { hour:'2-digit', minute:'2-digit' })
      : base;
  } catch { return str; }
}

function fmt(val) {
  const cur = State.settings?.currency || '₽';
  return (parseFloat(val)||0).toLocaleString('ru-RU') + ' ' + cur;
}

function fmtDateShort(iso) {
  if (!iso) return '—';
  const d = new Date(iso);
  return d.toLocaleString('ru-RU', { day:'2-digit', month:'2-digit', hour:'2-digit', minute:'2-digit' });
}

function escHtml(str) {
  if (!str) return '';
  return String(str)
    .replace(/&/g,'&amp;').replace(/"/g,'&quot;')
    .replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

window.escapeHtml  = escHtml;
window.formatDate  = formatDate;
window.formatMoney = fmt;
window.formatSize  = (bytes) => {
  if (!bytes)     return '0 Б';
  if (bytes < 1024)    return bytes + ' Б';
  if (bytes < 1048576) return (bytes/1024).toFixed(1) + ' КБ';
  return (bytes/1048576).toFixed(1) + ' МБ';
};

function getServiceLabel(tab) {
  return {
    photo:'Фотопечать', copy:'Копирование/Распечатка', banner:'Баннерная печать',
    design:'Дизайн', business:'Бизнес-полиграфия', wide:'Широкоформатная печать',
    promo:'Сувенирная продукция', other:'Прочее',
  }[tab] || tab;
}

// ════════════════════════════════════════════════════════════
// ФОРМЫ — ГЛОБАЛЬНЫЕ ОБЁРТКИ
// ════════════════════════════════════════════════════════════
window.switchServiceTab = (tab, btn) => {
  Order.currentTab = tab;
  document.querySelectorAll('.order-service-tab').forEach(b => b.classList.remove('active'));
  if (btn) btn.classList.add('active');
  document.querySelectorAll('.service-tab-content').forEach(t => t.classList.remove('active'));
  const tabEl = document.getElementById('stab-' + tab);
  if (tabEl) tabEl.classList.add('active');
};

window.toggleCheck = label => {
  label.classList.toggle('checked');
  const dot   = label.querySelector('.checkbox-dot');
  const input = label.querySelector('input');
  const on    = label.classList.contains('checked');
  if (dot)   dot.textContent = on ? '✓' : '';
  if (input) input.checked   = on;
};

// FIX: selectSize — ищем .size-buttons внутри правильного .size-matrix
window.selectSize = (btn, type) => {
  // Снимаем выделение со всех кнопок в ЭТОЙ матрице
  const matrix = btn.closest('.size-matrix');
  if (matrix) {
    matrix.querySelectorAll('.size-btn').forEach(b => b.classList.remove('selected'));
  }
  btn.classList.add('selected');

  // Автозаполнение для баннера
  if (type === 'banner') {
    const m = btn.textContent.trim().match(/(\d+(?:\.\d+)?)×(\d+(?:\.\d+)?)/);
    if (m) {
      const bw = document.getElementById('ban_w');
      const bh = document.getElementById('ban_h');
      if (bw) bw.value = m[1];
      if (bh) bh.value = m[2];
      calcBannerArea();
    }
  }
  // Автозаполнение для широкоформата
  if (type === 'wide') {
    const m = btn.textContent.trim().match(/(\d+)×(\d+)/);
    if (m) {
      const ww = document.getElementById('wide_w');
      const wh = document.getElementById('wide_h');
      if (ww) ww.value = m[1];
      if (wh) wh.value = m[2];
      calcWideArea();
    }
  }
};

window.calcBannerArea = () => {
  const w    = parseFloat(document.getElementById('ban_w')?.value)    || 0;
  const h    = parseFloat(document.getElementById('ban_h')?.value)    || 0;
  const p    = parseFloat(document.getElementById('ban_price')?.value) || 0;
  const q    = parseInt(document.getElementById('ban_qty')?.value)     || 1;
  const area = (w * h).toFixed(2);
  const aEl  = document.getElementById('ban_area');
  const tEl  = document.getElementById('ord_total');
  if (aEl) aEl.value = area;
  if (tEl) tEl.value = (parseFloat(area) * p * q).toFixed(0);
  updateTotalDisplay();
};

window.calcWideArea = () => {
  const w    = (parseFloat(document.getElementById('wide_w')?.value) || 0) / 100;
  const h    = (parseFloat(document.getElementById('wide_h')?.value) || 0) / 100;
  const p    = parseFloat(document.getElementById('wide_price')?.value) || 0;
  const area = (w * h).toFixed(4);
  const aEl  = document.getElementById('wide_area');
  const tEl  = document.getElementById('ord_total');
  if (aEl) aEl.value = parseFloat(area).toFixed(2);
  if (tEl) tEl.value = (parseFloat(area) * p).toFixed(0);
  updateTotalDisplay();
};

window.calcTotal = () => {
  const fields = {
    photo:    ['photo_qty',  'photo_price'],
    copy:     ['copy_qty',   'copy_price'],
    design:   [null,         'des_price'],
    business: ['biz_qty',    'biz_price'],
    promo:    ['promo_qty',  'promo_price'],
    other:    ['other_qty',  'other_price'],
  };
  const pair = fields[Order.currentTab];
  if (!pair) return;
  const qty   = pair[0] ? (parseInt(document.getElementById(pair[0])?.value) || 0) : 1;
  const price = parseFloat(document.getElementById(pair[1])?.value) || 0;
  const total = qty * price;
  if (total > 0) {
    const tEl = document.getElementById('ord_total');
    if (tEl) tEl.value = total.toFixed(0);
    updateTotalDisplay();
  }
};

window.updateTotalDisplay = () => {
  const val = parseFloat(document.getElementById('ord_total')?.value) || 0;
  const el  = document.getElementById('ordTotalDisplay');
  if (el) el.textContent = fmt(val);
};

// ════════════════════════════════════════════════════════════
// ЧАСЫ
// ════════════════════════════════════════════════════════════
function updateClock() {
  const n = new Date(), p = v => String(v).padStart(2,'0');
  const days   = ['Вс','Пн','Вт','Ср','Чт','Пт','Сб'];
  const months = ['янв','фев','мар','апр','май','июн','июл','авг','сен','окт','ноя','дек'];
  const tEl = document.getElementById('clockTime');
  const dEl = document.getElementById('clockDate');
  if (tEl) tEl.textContent = `${p(n.getHours())}:${p(n.getMinutes())}:${p(n.getSeconds())}`;
  if (dEl) dEl.textContent = `${days[n.getDay()]}, ${n.getDate()} ${months[n.getMonth()]}`;
}
setInterval(updateClock, 1000);
updateClock();

// ════════════════════════════════════════════════════════════
// УВЕДОМЛЕНИЯ (всплывающие)
// ════════════════════════════════════════════════════════════
window.notify = (msg, type = 'info') => {
  const icons  = { success:'✅', error:'❌', info:'💡' };
  const stack  = document.getElementById('notifStack');
  if (!stack) return;
  const el     = document.createElement('div');
  el.className = `notification ${type}`;
  el.innerHTML = `${icons[type]||'ℹ️'} ${msg}`;
  stack.appendChild(el);
  setTimeout(() => {
    el.style.cssText += 'opacity:0;transform:translateX(20px);transition:all 0.3s;';
    setTimeout(() => el.remove(), 300);
  }, 3500);
};

// ════════════════════════════════════════════════════════════
// BEFOREUNLOAD
// ════════════════════════════════════════════════════════════
window.addEventListener('beforeunload', () => Sync.stopPolling());

// ════════════════════════════════════════════════════════════
// СТАРТ
// ════════════════════════════════════════════════════════════
document.addEventListener('DOMContentLoaded', async () => {
  const monthFilter = document.getElementById('finMonthFilter');
  if (monthFilter) {
    const now = new Date();
    monthFilter.value = `${now.getFullYear()}-${String(now.getMonth()+1).padStart(2,'0')}`;
  }

  await Sync.loadAll();
  Sync.startPolling();
  App.showPage('dashboard', document.getElementById('nav-dashboard'));

  console.log('🚀 PrintCRM v3.0 запущен');
});
// Инициализация внешних модулей
if (window.CRM?.initModules) {
  window.CRM.initModules();
}