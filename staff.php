<?php
// @name          Сотрудники
// @icon          👥
// @description   Управление сотрудниками, зарплата, смены, аналитика
// @version       3.0
// @sidebar       true
// @color         #7c3aed

if (isset($moduleDB)) {
    $staffCols = array_column($moduleDB->query("PRAGMA table_info(staff)"), 'name');
    $migrations = [
        'position'      => "ALTER TABLE staff ADD COLUMN position TEXT DEFAULT ''",
        'email'         => "ALTER TABLE staff ADD COLUMN email TEXT DEFAULT ''",
        'salary'        => "ALTER TABLE staff ADD COLUMN salary REAL DEFAULT 0",
        'bonus_pct'     => "ALTER TABLE staff ADD COLUMN bonus_pct REAL DEFAULT 0.1",
        'birth_date'    => "ALTER TABLE staff ADD COLUMN birth_date TEXT DEFAULT ''",
        'address'       => "ALTER TABLE staff ADD COLUMN address TEXT DEFAULT ''",
        'passport_note' => "ALTER TABLE staff ADD COLUMN passport_note TEXT DEFAULT ''",
        'photo'         => "ALTER TABLE staff ADD COLUMN photo TEXT DEFAULT ''",
        'schedule'      => "ALTER TABLE staff ADD COLUMN schedule TEXT DEFAULT '5/2'",
        'start_date'    => "ALTER TABLE staff ADD COLUMN start_date TEXT DEFAULT ''",
        'status'        => "ALTER TABLE staff ADD COLUMN status TEXT DEFAULT 'active'",
        'notes'         => "ALTER TABLE staff ADD COLUMN notes TEXT DEFAULT ''",
    ];
    foreach ($migrations as $col => $sql) {
        if (!in_array($col, $staffCols)) {
            try { $moduleDB->execute($sql); } catch (Throwable) {}
        }
    }
}
?>
</php>
<script>
(function () {
'use strict';

const API = {
  get:  (e, p)   => fetch('/api/' + e + '/?' + new URLSearchParams(Object.assign({ key: '12345' }, p || {})), { headers: { 'X-Api-Key': '12345' } }).then(r => r.json()),
  post: (e, b)   => fetch('/api/' + e + '/?key=12345', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-Api-Key': '12345' }, body: JSON.stringify(b) }).then(r => r.json()),
  put:  (e, b)   => fetch('/api/' + e + '/?key=12345', { method: 'PUT',  headers: { 'Content-Type': 'application/json', 'X-Api-Key': '12345' }, body: JSON.stringify(b) }).then(r => r.json()),
  del:  (e, p)   => fetch('/api/' + e + '/?' + new URLSearchParams(Object.assign({ key: '12345' }, p || {})), { method: 'DELETE', headers: { 'X-Api-Key': '12345' } }).then(r => r.json()),
};

let _el          = null;
let _view        = 'staff';
let _staff       = [];
let _salary      = [];
let _shifts      = [];
let _salPeriod   = new Date().toISOString().slice(0, 7);
let _shiftFrom   = new Date().toISOString().slice(0, 7) + '-01';
let _shiftTo     = new Date().toISOString().slice(0, 10);
let _staffSearch = '';
let _kanbanDrag  = null;

// ── Утилиты ───────────────────────────────────────────────
const fmt  = n  => Number(n || 0).toLocaleString('ru-RU', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const fmtD = dt => dt ? new Date(dt).toLocaleDateString('ru-RU') : '—';
const fmtT = dt => dt ? new Date(dt).toLocaleString('ru-RU', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' }) : '—';
const esc  = s  => String(s || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
const $    = s  => _el ? _el.querySelector(s) : document.querySelector(s);
const $$   = s  => _el ? [..._el.querySelectorAll(s)] : [...document.querySelectorAll(s)];
const ntf  = (m, t) => window.notify ? window.notify(m, t || 'info') : alert(m);

const workDays = sd => {
  if (!sd) return '—';
  const diff = Math.floor((Date.now() - new Date(sd)) / 86400000);
  if (diff < 0)  return 'Ещё не начал';
  if (diff === 0) return '🎉 Первый день!';
  if (diff < 30)  return diff + ' дн.';
  if (diff < 365) return Math.floor(diff / 30) + ' мес.';
  return Math.floor(diff / 365) + ' лет ' + Math.floor((diff % 365) / 30) + ' мес.';
};

const statusColor = s => ({ active: '#10b981', vacation: '#f59e0b', fired: '#ef4444' }[s] || '#64748b');
const statusLabel = s => ({ active: 'Активен', vacation: 'В отпуске', fired: 'Уволен' }[s] || s);
const statusBg    = s => ({ active: '#10b98122', vacation: '#f59e0b22', fired: '#ef444422' }[s] || '#64748b22');

const PALETTE = ['#7c3aed','#06b6d4','#10b981','#f59e0b','#ef4444','#3b82f6','#ec4899','#8b5cf6','#14b8a6'];
const avatarColor = name => {
  let h = 0;
  for (let i = 0; i < (name || '').length; i++) h = (h * 31 + name.charCodeAt(i)) & 0xffffffff;
  return PALETTE[Math.abs(h) % PALETTE.length];
};
const initials = name => {
  const p = (name || '').trim().split(/\s+/);
  return p.length >= 2 ? (p[0][0] + p[1][0]).toUpperCase() : (name || '?').slice(0, 2).toUpperCase();
};

// ── CSS один раз ──────────────────────────────────────────
function injectCSS() {
  if (document.getElementById('staff-module-css')) return;
  const style = document.createElement('style');
  style.id = 'staff-module-css';
  style.textContent = `
    .stf-card {
      background: linear-gradient(135deg, rgba(20,24,40,0.95), rgba(26,31,53,0.9));
      backdrop-filter: blur(20px);
      -webkit-backdrop-filter: blur(20px);
      border: 1px solid rgba(255,255,255,0.07);
      border-radius: 18px;
      overflow: hidden;
      transition: transform .2s, box-shadow .2s, border-color .2s;
      cursor: pointer;
      position: relative;
    }
    .stf-card:hover {
      transform: translateY(-4px);
      box-shadow: 0 20px 60px rgba(0,0,0,.5);
    }
    .stf-card-glow {
      position: absolute;
      inset: 0;
      border-radius: 18px;
      opacity: 0;
      transition: opacity .3s;
      pointer-events: none;
    }
    .stf-card:hover .stf-card-glow { opacity: 1; }

    .stf-avatar {
      position: relative;
      width: 72px;
      height: 72px;
      border-radius: 50%;
      overflow: hidden;
      flex-shrink: 0;
      border: 3px solid rgba(255,255,255,0.15);
      box-shadow: 0 8px 24px rgba(0,0,0,.4);
    }
    .stf-avatar img { width:100%; height:100%; object-fit:cover; }
    .stf-avatar-placeholder {
      width: 100%; height: 100%;
      display: flex; align-items: center; justify-content: center;
      font-size: 24px; font-weight: 800; color: #fff;
    }
    .stf-avatar-upload {
      position: absolute; inset: 0;
      background: rgba(0,0,0,.6);
      display: flex; align-items: center; justify-content: center;
      opacity: 0; transition: opacity .2s; cursor: pointer;
      font-size: 20px;
    }
    .stf-avatar:hover .stf-avatar-upload { opacity: 1; }

    .stf-status-dot {
      width: 10px; height: 10px; border-radius: 50%;
      display: inline-block; margin-right: 5px;
      box-shadow: 0 0 6px currentColor;
    }

    .stf-metric {
      background: rgba(255,255,255,0.04);
      border: 1px solid rgba(255,255,255,0.06);
      border-radius: 10px;
      padding: 8px 10px;
      text-align: center;
      transition: background .2s;
    }
    .stf-metric:hover { background: rgba(255,255,255,0.08); }

    .stf-btn {
      border: none; border-radius: 8px; cursor: pointer;
      font-size: 12px; font-weight: 600; padding: 7px 12px;
      transition: all .15s; display: inline-flex; align-items: center; gap: 5px;
    }
    .stf-btn:hover { filter: brightness(1.2); transform: translateY(-1px); }
    .stf-btn:active { transform: translateY(0); }

    .stf-kanban-col {
      background: rgba(255,255,255,0.03);
      border: 1px solid rgba(255,255,255,0.06);
      border-radius: 16px;
      padding: 14px;
      min-height: 200px;
      transition: background .2s;
    }
    .stf-kanban-col.drag-over {
      background: rgba(124,58,237,0.1);
      border-color: rgba(124,58,237,0.4);
    }
    .stf-kanban-item {
      background: linear-gradient(135deg, rgba(20,24,40,0.98), rgba(26,31,53,0.95));
      backdrop-filter: blur(10px);
      border: 1px solid rgba(255,255,255,0.07);
      border-radius: 12px;
      padding: 12px;
      margin-bottom: 8px;
      cursor: grab;
      transition: transform .15s, box-shadow .15s;
      display: flex; align-items: center; gap: 10px;
    }
    .stf-kanban-item:hover {
      transform: translateX(3px);
      box-shadow: 0 8px 24px rgba(0,0,0,.3);
    }
    .stf-kanban-item.dragging { opacity: .4; cursor: grabbing; }

    .stf-photo-area {
      width: 80px; height: 80px; border-radius: 50%;
      background: rgba(255,255,255,0.05);
      border: 2px dashed rgba(255,255,255,0.2);
      display: flex; align-items: center; justify-content: center;
      cursor: pointer; font-size: 28px; transition: all .2s;
      overflow: hidden; flex-shrink: 0;
    }
    .stf-photo-area:hover { border-color: #7c3aed; background: rgba(124,58,237,0.1); }
    .stf-photo-area img { width:100%; height:100%; object-fit:cover; border-radius:50%; }

    .stf-input {
      width: 100%; padding: 9px 12px; box-sizing: border-box;
      background: rgba(255,255,255,0.05);
      border: 1px solid rgba(255,255,255,0.1);
      border-radius: 8px; color: #e2e8f0; font-size: 13px;
      transition: border-color .2s, background .2s;
      outline: none;
    }
    .stf-input:focus {
      border-color: #7c3aed;
      background: rgba(124,58,237,0.08);
    }
    .stf-label {
      font-size: 11px; color: #64748b; display: block;
      margin-bottom: 5px; text-transform: uppercase; letter-spacing: .5px;
    }
    .stf-modal-overlay {
      position: fixed; inset: 0;
      background: rgba(0,0,0,.8);
      backdrop-filter: blur(8px);
      z-index: 9999;
      display: flex; align-items: center; justify-content: center; padding: 16px;
    }
    .stf-modal {
      background: linear-gradient(135deg, #0d0f1a, #141828);
      border: 1px solid rgba(255,255,255,0.1);
      border-radius: 20px;
      width: 100%; max-width: 600px;
      max-height: 92vh; overflow-y: auto;
      box-shadow: 0 40px 100px rgba(0,0,0,.7);
    }
    .stf-modal::-webkit-scrollbar { width: 4px; }
    .stf-modal::-webkit-scrollbar-track { background: transparent; }
    .stf-modal::-webkit-scrollbar-thumb { background: #1e2a3a; border-radius: 2px; }

    .stf-tab-btn {
      padding: 7px 16px; border-radius: 8px; border: 1px solid transparent;
      background: transparent; color: #64748b; cursor: pointer;
      font-size: 13px; font-weight: 500; transition: all .15s;
    }
    .stf-tab-btn.active {
      background: rgba(124,58,237,0.15);
      border-color: rgba(124,58,237,0.4);
      color: #7c3aed; font-weight: 700;
    }
    .stf-tab-btn:hover:not(.active) { color: #94a3b8; background: rgba(255,255,255,0.04); }

    .stf-progress {
      height: 4px; border-radius: 2px;
      background: rgba(255,255,255,0.08);
      overflow: hidden; margin-top: 6px;
    }
    .stf-progress-bar {
      height: 100%; border-radius: 2px;
      transition: width .6s ease;
    }

    @keyframes stf-fadein {
      from { opacity:0; transform: translateY(10px); }
      to   { opacity:1; transform: translateY(0); }
    }
    .stf-fadein { animation: stf-fadein .3s ease forwards; }

    @keyframes stf-pulse {
      0%,100% { box-shadow: 0 0 0 0 currentColor; }
      50%      { box-shadow: 0 0 0 6px transparent; }
    }
    .stf-pulse { animation: stf-pulse 2s infinite; }
  `;
  document.head.appendChild(style);
}

// ── Загрузка ──────────────────────────────────────────────
async function loadAll() {
  await Promise.all([loadStaff(), loadSalary(), loadShifts()]);
}
async function loadStaff() {
  try { const r = await API.get('staff', {}); if (r.ok) _staff = r.data || []; } catch(e) {}
}
async function loadSalary() {
  try { const r = await API.get('salary', { period: _salPeriod }); if (r.ok) _salary = r.data || []; } catch(e) {}
}
async function loadShifts() {
  try {
    const r = await API.get('shifts', { from: _shiftFrom, to: _shiftTo });
    if (r.ok) _shifts = r.data?.history || r.data || [];
  } catch(e) {}
}

// ── Топбар ────────────────────────────────────────────────
function _topBar(active) {
  const tabs = [
    { id: 'staff',     icon: '👥', label: 'Команда'    },
    { id: 'kanban',    icon: '📋', label: 'Канбан'     },
    { id: 'salary',    icon: '💰', label: 'Зарплата'   },
    { id: 'shifts',    icon: '📅', label: 'Смены'      },
    { id: 'analytics', icon: '📊', label: 'Аналитика'  },
  ];
  return (
    '<div style="display:flex;align-items:center;justify-content:space-between;padding:14px 20px;border-bottom:1px solid rgba(255,255,255,0.06);flex-wrap:wrap;gap:10px;background:linear-gradient(135deg,rgba(13,15,26,.98),rgba(20,24,40,.95));backdrop-filter:blur(20px);position:sticky;top:0;z-index:100">' +
      '<div style="display:flex;align-items:center;gap:12px">' +
        '<div style="width:38px;height:38px;border-radius:12px;background:linear-gradient(135deg,#7c3aed,#6d28d9);display:flex;align-items:center;justify-content:center;font-size:18px;box-shadow:0 4px 16px #7c3aed44">👥</div>' +
        '<div>' +
          '<div style="font-size:16px;font-weight:800;color:#e2e8f0;letter-spacing:-.3px">Сотрудники</div>' +
          '<div style="font-size:11px;color:#64748b">Команда · Зарплата · Смены</div>' +
        '</div>' +
      '</div>' +
      '<div style="display:flex;gap:6px;flex-wrap:wrap">' +
        tabs.map(t =>
          '<button class="stf-tab-btn ' + (active === t.id ? 'active' : '') + '" onclick="window._staffView(\'' + t.id + '\')">' +
            t.icon + ' ' + t.label +
          '</button>'
        ).join('') +
      '</div>' +
    '</div>'
  );
}

// ── ВКЛАДКА: КОМАНДА (карточки) ───────────────────────────
function renderStaffView() {
  const search = _staffSearch.toLowerCase();
  const list   = _staff.filter(s =>
    !search ||
    (s.name     || '').toLowerCase().includes(search) ||
    (s.position || s.role || '').toLowerCase().includes(search) ||
    (s.phone    || '').includes(search)
  );
  const cntActive  = _staff.filter(s => (s.status || 'active') === 'active').length;
  const cntVac     = _staff.filter(s => s.status === 'vacation').length;
  const cntFired   = _staff.filter(s => s.status === 'fired').length;
  const totalSal   = _staff.reduce((a, s) => a + parseFloat(s.salary || 0), 0);

  _el.innerHTML =
    _topBar('staff') +
    '<div style="padding:20px;max-width:1300px;margin:0 auto">' +

      // Статы
      '<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:22px">' +
        _heroCard('👥', _staff.length, 'Всего', '#7c3aed', 'Сотрудников в команде') +
        _heroCard('✅', cntActive,     'Активных', '#10b981', 'Работают сейчас') +
        _heroCard('🏖️', cntVac,        'В отпуске', '#f59e0b', 'Временно недоступны') +
        _heroCard('💵', fmt(totalSal) + ' ₽', 'ФОТ/мес', '#06b6d4', 'Фонд оплаты труда') +
      '</div>' +

      // Поиск + добавить
      '<div style="display:flex;gap:10px;margin-bottom:20px;flex-wrap:wrap;align-items:center">' +
        '<div style="flex:1;min-width:220px;position:relative">' +
          '<span style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#64748b;font-size:14px">🔍</span>' +
          '<input class="stf-input" placeholder="Поиск по имени, должности, телефону..." oninput="window._staffSearch(this.value)"' +
            ' style="padding-left:36px;font-size:13px">' +
        '</div>' +
        '<button class="stf-btn" onclick="window._staffAdd()"' +
          ' style="background:linear-gradient(135deg,#7c3aed,#6d28d9);color:#fff;padding:9px 20px;font-size:13px;border-radius:10px;box-shadow:0 4px 16px #7c3aed44">' +
          '＋ Добавить сотрудника' +
        '</button>' +
      '</div>' +

      // Карточки
      '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:16px">' +
        (list.length
          ? list.map((s, i) => _staffCard(s, i)).join('')
          : '<div style="grid-column:1/-1;text-align:center;padding:80px;color:#64748b">' +
              '<div style="font-size:48px;margin-bottom:12px">👥</div>' +
              '<div style="font-size:15px">Сотрудников нет — добавьте первого!</div>' +
            '</div>'
        ) +
      '</div>' +

    '</div>';
}

function _heroCard(icon, value, label, color, sub) {
  return (
    '<div style="background:linear-gradient(135deg,rgba(20,24,40,.95),rgba(26,31,53,.9));' +
      'border:1px solid rgba(255,255,255,0.07);border-radius:16px;padding:18px;' +
      'display:flex;align-items:center;gap:14px;transition:transform .2s,box-shadow .2s" ' +
      'onmouseenter="this.style.transform=\'translateY(-2px)\';this.style.boxShadow=\'0 12px 40px rgba(0,0,0,.4)\'" ' +
      'onmouseleave="this.style.transform=\'\';this.style.boxShadow=\'\'">' +
      '<div style="width:50px;height:50px;border-radius:14px;background:' + color + '22;' +
        'display:flex;align-items:center;justify-content:center;font-size:22px;flex-shrink:0;' +
        'box-shadow:0 4px 16px ' + color + '33">' + icon + '</div>' +
      '<div>' +
        '<div style="font-size:22px;font-weight:800;color:' + color + ';line-height:1">' + value + '</div>' +
        '<div style="font-size:13px;font-weight:600;color:#e2e8f0;margin-top:2px">' + label + '</div>' +
        '<div style="font-size:11px;color:#64748b;margin-top:1px">' + sub + '</div>' +
      '</div>' +
    '</div>'
  );
}

function _staffCard(s, idx) {
  const color      = s.color || avatarColor(s.name);
  const status     = s.status || (s.is_active == 1 ? 'active' : 'fired');
  const sal        = _salary.filter(r => (r.staff_id || r.staffId) === s.id);
  const salTotal   = sal.reduce((a, r) => a + parseFloat(r.amount || 0), 0);
  const shiftsCnt  = _shifts.filter(sh =>
    (sh.emp_id || sh.empId || sh.staff_id) === s.id ||
    (sh.manager || sh.staff_name) === s.name
  ).length;
  const totalInc   = _shifts
    .filter(sh => (sh.manager || sh.staff_name) === s.name)
    .reduce((a, sh) => a + parseFloat(sh.total_income || sh.income || 0), 0);

  // ДР
  let bdayHtml = '';
  const bd = s.birth_date || s.birthDate;
  if (bd) {
    const bdate = new Date(bd);
    const now   = new Date();
    const next  = new Date(now.getFullYear(), bdate.getMonth(), bdate.getDate());
    if (next < now) next.setFullYear(now.getFullYear() + 1);
    const diff = Math.ceil((next - now) / 86400000);
    if (diff <= 14) {
      bdayHtml = '<div style="position:absolute;top:10px;right:10px;background:linear-gradient(135deg,#f59e0b,#d97706);' +
        'color:#000;font-size:10px;font-weight:700;padding:3px 8px;border-radius:20px;box-shadow:0 2px 8px #f59e0b44">' +
        (diff === 0 ? '🎂 Сегодня ДР!' : diff === 1 ? '🎂 Завтра ДР!' : '🎂 ДР через ' + diff + ' дн.') +
        '</div>';
    }
  }

  // Прогресс выплат
  const salaryPlan = parseFloat(s.salary || 0);
  const payPct     = salaryPlan > 0 ? Math.min(100, Math.round(salTotal / salaryPlan * 100)) : 0;

  return (
    '<div class="stf-card stf-fadein" style="animation-delay:' + (idx * 0.05) + 's;--card-color:' + color + '" ' +
      'onclick="window._staffOpenCard(\'' + esc(s.id) + '\')">' +

      // Свечение
      '<div class="stf-card-glow" style="background:radial-gradient(circle at 50% 0%, ' + color + '18, transparent 70%)"></div>' +

      bdayHtml +

      // Шапка с фото
      '<div style="background:linear-gradient(135deg,' + color + '18,' + color + '05);padding:20px;display:flex;align-items:center;gap:14px;border-bottom:1px solid rgba(255,255,255,0.05)">' +

        // Аватар
        '<div class="stf-avatar" style="border-color:' + color + '66">' +
          (s.photo
            ? '<img src="' + esc(s.photo) + '" alt="' + esc(s.name) + '">'
            : '<div class="stf-avatar-placeholder" style="background:linear-gradient(135deg,' + color + '44,' + color + '22)">' + initials(s.name) + '</div>'
          ) +
        '</div>' +

        '<div style="flex:1;min-width:0">' +
          '<div style="font-size:15px;font-weight:700;color:#f1f5f9;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">' + esc(s.name || '—') + '</div>' +
          '<div style="font-size:12px;color:#94a3b8;margin-top:3px">' + esc(s.position || s.role || 'Должность не указана') + '</div>' +
          '<div style="display:flex;align-items:center;gap:6px;margin-top:7px;flex-wrap:wrap">' +
            '<span style="display:inline-flex;align-items:center;gap:4px;background:' + statusBg(status) + ';' +
              'color:' + statusColor(status) + ';font-size:10px;padding:3px 9px;border-radius:20px;font-weight:700;' +
              'border:1px solid ' + statusColor(status) + '33">' +
              '<span class="stf-status-dot stf-pulse" style="color:' + statusColor(status) + ';background:' + statusColor(status) + '"></span>' +
              statusLabel(status) +
            '</span>' +
            (s.schedule ? '<span style="font-size:10px;color:#64748b;background:rgba(255,255,255,0.05);padding:3px 8px;border-radius:20px">🗓 ' + esc(s.schedule) + '</span>' : '') +
          '</div>' +
        '</div>' +
      '</div>' +

      // Метрики
      '<div style="padding:14px 16px">' +
        '<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-bottom:12px">' +
          _metric('Оклад', fmt(s.salary || 0) + ' ₽', color) +
          _metric('Выплачено', fmt(salTotal) + ' ₽', '#10b981') +
          _metric('Смен', shiftsCnt, '#06b6d4') +
        '</div>' +

        // Прогресс выплат
        (salaryPlan > 0
          ? '<div style="margin-bottom:12px">' +
              '<div style="display:flex;justify-content:space-between;font-size:10px;color:#64748b;margin-bottom:3px">' +
                '<span>Выплачено в периоде</span><span>' + payPct + '%</span>' +
              '</div>' +
              '<div class="stf-progress"><div class="stf-progress-bar" style="width:' + payPct + '%;background:linear-gradient(90deg,' + color + ',' + color + '88)"></div></div>' +
            '</div>'
          : ''
        ) +

        // Контакты
        '<div style="display:flex;flex-direction:column;gap:4px;margin-bottom:12px">' +
          (s.phone ? '<div style="font-size:12px;color:#94a3b8;display:flex;align-items:center;gap:7px">' +
            '<span style="color:' + color + '">📞</span>' +
            '<a href="tel:' + esc(s.phone) + '" onclick="event.stopPropagation()" style="color:#06b6d4;text-decoration:none">' + esc(s.phone) + '</a>' +
          '</div>' : '') +
          (s.email ? '<div style="font-size:12px;color:#94a3b8;display:flex;align-items:center;gap:7px">' +
            '<span style="color:' + color + '">✉️</span>' +
            '<a href="mailto:' + esc(s.email) + '" onclick="event.stopPropagation()" style="color:#06b6d4;text-decoration:none">' + esc(s.email) + '</a>' +
          '</div>' : '') +
          '<div style="font-size:11px;color:#64748b;display:flex;align-items:center;gap:7px">' +
            '<span>📆</span><span>Стаж: <b style="color:#94a3b8">' + workDays(s.start_date || s.startDate) + '</b></span>' +
            '<span style="margin-left:auto">Бонус: <b style="color:#f59e0b">' + Math.round((parseFloat(s.bonus_pct || 0.1)) * 100) + '%</b></span>' +
          '</div>' +
        '</div>' +

        // Кнопки
        '<div style="display:flex;gap:6px">' +
          '<button class="stf-btn" onclick="event.stopPropagation();window._staffEdit(\'' + esc(s.id) + '\')"' +
            ' style="flex:1;background:rgba(124,58,237,0.15);color:#7c3aed;border:1px solid rgba(124,58,237,0.3)">✏️ Изменить</button>' +
          '<button class="stf-btn" onclick="event.stopPropagation();window._staffPayModal(\'' + esc(s.id) + '\')"' +
            ' style="flex:1;background:rgba(16,185,129,0.15);color:#10b981;border:1px solid rgba(16,185,129,0.3)">💰 Выплата</button>' +
          '<button class="stf-btn" onclick="event.stopPropagation();window._staffDelete(\'' + esc(s.id) + '\')"' +
            ' style="background:rgba(239,68,68,0.1);color:#ef444488;border:1px solid rgba(239,68,68,0.15);padding:7px 10px" title="Удалить">🗑</button>' +
        '</div>' +

      '</div>' +
    '</div>'
  );
}

function _metric(label, value, color) {
  return (
    '<div class="stf-metric">' +
      '<div style="font-size:13px;font-weight:700;color:' + color + '">' + value + '</div>' +
      '<div style="font-size:10px;color:#64748b;margin-top:2px">' + label + '</div>' +
    '</div>'
  );
}

// ── ВКЛАДКА: КАНБАН ───────────────────────────────────────
function renderKanbanView() {
  const cols = [
    { id: 'active',   label: '✅ Активные',   color: '#10b981' },
    { id: 'vacation', label: '🏖️ В отпуске',  color: '#f59e0b' },
    { id: 'fired',    label: '❌ Уволенные',   color: '#ef4444' },
  ];

  const byStatus = { active: [], vacation: [], fired: [] };
  _staff.forEach(s => {
    const st = s.status || (s.is_active == 1 ? 'active' : 'fired');
    if (!byStatus[st]) byStatus[st] = [];
    byStatus[st].push(s);
  });

  _el.innerHTML =
    _topBar('kanban') +
    '<div style="padding:20px;max-width:1300px;margin:0 auto">' +

      '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px">' +
        '<div style="font-size:14px;color:#64748b">Перетащите карточку чтобы изменить статус сотрудника</div>' +
        '<button class="stf-btn" onclick="window._staffAdd()"' +
          ' style="background:linear-gradient(135deg,#7c3aed,#6d28d9);color:#fff;padding:8px 18px;border-radius:10px">＋ Добавить</button>' +
      '</div>' +

      '<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:16px">' +
        cols.map(col => {
          const items = byStatus[col.id] || [];
          return (
            '<div class="stf-kanban-col" id="kanban-col-' + col.id + '"' +
              ' ondragover="event.preventDefault();this.classList.add(\'drag-over\')"' +
              ' ondragleave="this.classList.remove(\'drag-over\')"' +
              ' ondrop="window._staffKanbanDrop(\'' + col.id + '\',this)">' +

              '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px">' +
                '<div style="display:flex;align-items:center;gap:8px">' +
                  '<div style="width:10px;height:10px;border-radius:50%;background:' + col.color + ';box-shadow:0 0 8px ' + col.color + '"></div>' +
                  '<span style="font-size:13px;font-weight:700;color:#e2e8f0">' + col.label + '</span>' +
                '</div>' +
                '<span style="background:' + col.color + '22;color:' + col.color + ';font-size:11px;font-weight:700;padding:3px 9px;border-radius:20px">' + items.length + '</span>' +
              '</div>' +

              items.map(s => _kanbanItem(s, col.color)).join('') +

              (items.length === 0
                ? '<div style="text-align:center;padding:30px 10px;color:#64748b;font-size:12px;border:2px dashed rgba(255,255,255,0.06);border-radius:10px">Перетащи сюда</div>'
                : ''
              ) +

            '</div>'
          );
        }).join('') +
      '</div>' +
    '</div>';
}

function _kanbanItem(s, color) {
  const c = s.color || avatarColor(s.name);
  return (
    '<div class="stf-kanban-item" draggable="true"' +
      ' ondragstart="window._staffKanbanDragStart(\'' + esc(s.id) + '\',this)"' +
      ' ondragend="this.classList.remove(\'dragging\')"' +
      ' onclick="window._staffOpenCard(\'' + esc(s.id) + '\')">' +

      '<div style="width:40px;height:40px;border-radius:50%;overflow:hidden;flex-shrink:0;border:2px solid ' + c + '44">' +
        (s.photo
          ? '<img src="' + esc(s.photo) + '" style="width:100%;height:100%;object-fit:cover">'
          : '<div style="width:100%;height:100%;background:linear-gradient(135deg,' + c + '44,' + c + '22);display:flex;align-items:center;justify-content:center;font-size:14px;font-weight:700;color:' + c + '">' + initials(s.name) + '</div>'
        ) +
      '</div>' +

      '<div style="flex:1;min-width:0">' +
        '<div style="font-size:13px;font-weight:600;color:#e2e8f0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">' + esc(s.name) + '</div>' +
        '<div style="font-size:11px;color:#64748b;margin-top:2px">' + esc(s.position || s.role || '—') + '</div>' +
        (s.phone ? '<div style="font-size:11px;color:#06b6d488;margin-top:1px">' + esc(s.phone) + '</div>' : '') +
      '</div>' +

      '<div style="display:flex;flex-direction:column;align-items:flex-end;gap:4px;flex-shrink:0">' +
        '<div style="font-size:11px;font-weight:700;color:#f59e0b">' + fmt(s.salary || 0) + ' ₽</div>' +
        '<div style="font-size:10px;color:#64748b">оклад</div>' +
      '</div>' +

    '</div>'
  );
}

// ── Drag & Drop канбан ────────────────────────────────────
window._staffKanbanDragStart = function(id, el) {
  _kanbanDrag = id;
  el.classList.add('dragging');
};
window._staffKanbanDrop = async function(newStatus, colEl) {
  colEl.classList.remove('drag-over');
  if (!_kanbanDrag) return;
  const s = _staff.find(x => x.id === _kanbanDrag);
  if (!s || s.status === newStatus) { _kanbanDrag = null; return; }
  const r = await API.put('staff', { id: _kanbanDrag, status: newStatus });
  if (r.ok) {
    ntf('✅ Статус изменён: ' + statusLabel(newStatus), 'success');
    await loadStaff();
    renderKanbanView();
  } else {
    ntf('Ошибка: ' + (r.error || ''), 'error');
  }
  _kanbanDrag = null;
};

// ── Карточка сотрудника (детали) ─────────────────────────
window._staffOpenCard = function(id) {
  const s = _staff.find(e => String(e.id) === String(id));
  if (!s) return;
  const color   = s.color || avatarColor(s.name);
  const status  = s.status || (s.is_active == 1 ? 'active' : 'fired');
  const sal     = _salary.filter(r => (r.staff_id || r.staffId) === s.id);
  const salTotal = sal.reduce((a, r) => a + parseFloat(r.amount || 0), 0);
  const empShifts = _shifts.filter(sh =>
    (sh.emp_id || sh.empId || sh.staff_id) === s.id ||
    (sh.manager || sh.staff_name) === s.name
  );
  const totalInc = empShifts.reduce((a, sh) => a + parseFloat(sh.total_income || sh.income || 0), 0);

  const salRows = sal.slice(0, 5).map(r =>
    '<tr style="border-bottom:1px solid rgba(255,255,255,0.04);font-size:12px">' +
      '<td style="padding:6px 10px;color:#64748b">' + fmtT(r.created_at) + '</td>' +
      '<td style="padding:6px 10px;color:#94a3b8">' + (r.type || '—') + '</td>' +
      '<td style="padding:6px 10px;text-align:right;font-weight:700;color:#10b981">+' + fmt(r.amount) + ' ₽</td>' +
      '<td style="padding:6px 10px;color:#64748b">' + esc(r.comment || r.note || '—') + '</td>' +
    '</tr>'
  ).join('');

  const html =
    '<div class="stf-modal-overlay" id="staffCardModal" onclick="if(event.target.id===\'staffCardModal\')this.remove()">' +
      '<div class="stf-modal">' +

        // Шапка с фото
        '<div style="background:linear-gradient(135deg,' + color + '22,' + color + '08);padding:24px;border-radius:20px 20px 0 0;position:relative;overflow:hidden">' +
          '<div style="position:absolute;inset:0;background:radial-gradient(circle at 30% 50%,' + color + '18,transparent 70%)"></div>' +
          '<button onclick="document.getElementById(\'staffCardModal\').remove()" style="position:absolute;top:14px;right:14px;background:rgba(255,255,255,0.08);border:none;color:#94a3b8;width:32px;height:32px;border-radius:50%;cursor:pointer;font-size:16px;display:flex;align-items:center;justify-content:center">✕</button>' +
          '<div style="display:flex;align-items:center;gap:18px;position:relative">' +

            // Большое фото
            '<div style="width:88px;height:88px;border-radius:50%;overflow:hidden;border:3px solid ' + color + '66;box-shadow:0 8px 32px ' + color + '44;flex-shrink:0">' +
              (s.photo
                ? '<img src="' + esc(s.photo) + '" style="width:100%;height:100%;object-fit:cover">'
                : '<div style="width:100%;height:100%;background:linear-gradient(135deg,' + color + '66,' + color + '33);display:flex;align-items:center;justify-content:center;font-size:30px;font-weight:800;color:#fff">' + initials(s.name) + '</div>'
              ) +
            '</div>' +

            '<div style="flex:1">' +
              '<div style="font-size:20px;font-weight:800;color:#f1f5f9">' + esc(s.name) + '</div>' +
              '<div style="font-size:13px;color:#94a3b8;margin-top:3px">' + esc(s.position || s.role || '—') + '</div>' +
              '<div style="margin-top:8px;display:flex;align-items:center;gap:8px">' +
                '<span style="background:' + statusBg(status) + ';color:' + statusColor(status) + ';font-size:11px;font-weight:700;padding:4px 12px;border-radius:20px;border:1px solid ' + statusColor(status) + '44">' + statusLabel(status) + '</span>' +
                '<span style="font-size:11px;color:#64748b">Стаж: <b style="color:#94a3b8">' + workDays(s.start_date || s.startDate) + '</b></span>' +
              '</div>' +
            '</div>' +
          '</div>' +
        '</div>' +

        '<div style="padding:20px;display:flex;flex-direction:column;gap:16px">' +

          // 4 метрики
          '<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:10px">' +
            _metric('Оклад', fmt(s.salary || 0) + ' ₽', color) +
            _metric('Бонус', Math.round((parseFloat(s.bonus_pct || 0.1)) * 100) + '%', '#f59e0b') +
            _metric('Выплачено', fmt(salTotal) + ' ₽', '#10b981') +
            _metric('Смен', empShifts.length, '#06b6d4') +
          '</div>' +

          // Контакты
          '<div style="background:rgba(255,255,255,0.03);border:1px solid rgba(255,255,255,0.06);border-radius:12px;padding:14px">' +
            '<div style="font-size:11px;color:#64748b;text-transform:uppercase;letter-spacing:.5px;margin-bottom:10px">Контакты</div>' +
            '<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;font-size:13px">' +
              (s.phone ? '<div style="color:#94a3b8">📞 <a href="tel:' + esc(s.phone) + '" style="color:#06b6d4;text-decoration:none">' + esc(s.phone) + '</a></div>' : '<div style="color:#64748b">📞 —</div>') +
              (s.email ? '<div style="color:#94a3b8">✉️ <a href="mailto:' + esc(s.email) + '" style="color:#06b6d4;text-decoration:none">' + esc(s.email) + '</a></div>' : '<div style="color:#64748b">✉️ —</div>') +
              (s.birth_date ? '<div style="color:#94a3b8">🎂 ' + fmtD(s.birth_date) + '</div>' : '<div style="color:#64748b">🎂 —</div>') +
              (s.schedule ? '<div style="color:#94a3b8">🗓 ' + esc(s.schedule) + '</div>' : '<div style="color:#64748b">🗓 —</div>') +
              (s.address ? '<div style="color:#94a3b8;grid-column:1/-1">📍 ' + esc(s.address) + '</div>' : '') +
            '</div>' +
          '</div>' +

          // История выплат
          (sal.length
            ? '<div style="background:rgba(255,255,255,0.03);border:1px solid rgba(255,255,255,0.06);border-radius:12px;overflow:hidden">' +
                '<div style="padding:10px 14px;border-bottom:1px solid rgba(255,255,255,0.06);font-size:11px;color:#64748b;text-transform:uppercase;letter-spacing:.5px">Последние выплаты</div>' +
                '<table style="width:100%;border-collapse:collapse">' +
                  '<tr style="font-size:10px;color:#64748b;background:rgba(0,0,0,.2)">' +
                    '<th style="padding:6px 10px;text-align:left;font-weight:400">Дата</th>' +
                    '<th style="padding:6px 10px;text-align:left;font-weight:400">Тип</th>' +
                    '<th style="padding:6px 10px;text-align:right;font-weight:400">Сумма</th>' +
                    '<th style="padding:6px 10px;text-align:left;font-weight:400">Комментарий</th>' +
                  '</tr>' + salRows +
                '</table>' +
              '</div>'
            : ''
          ) +

          // Кнопки
          '<div style="display:flex;gap:10px">' +
            '<button class="stf-btn" onclick="document.getElementById(\'staffCardModal\').remove();window._staffEdit(\'' + esc(s.id) + '\')"' +
              ' style="flex:1;background:rgba(124,58,237,0.15);color:#7c3aed;border:1px solid rgba(124,58,237,0.3);padding:11px;border-radius:10px;justify-content:center">✏️ Редактировать</button>' +
            '<button class="stf-btn" onclick="document.getElementById(\'staffCardModal\').remove();window._staffPayModal(\'' + esc(s.id) + '\')"' +
              ' style="flex:1;background:rgba(16,185,129,0.15);color:#10b981;border:1px solid rgba(16,185,129,0.3);padding:11px;border-radius:10px;justify-content:center">💰 Выплата</button>' +
          '</div>' +

        '</div>' +
      '</div>' +
    '</div>';

  document.body.insertAdjacentHTML('beforeend', html);
};

// ── МОДАЛ: Добавить / Редактировать ──────────────────────
function openStaffModal(emp) {
  const isEdit = !!emp;
  const e      = emp || {};
  const color  = e.color || '#7c3aed';
  let   photo  = e.photo || '';

  const html =
    '<div class="stf-modal-overlay" id="staffModal" onclick="if(event.target.id===\'staffModal\')window._staffCloseModal()">' +
      '<div class="stf-modal">' +

        '<div style="display:flex;justify-content:space-between;align-items:center;padding:20px 22px;border-bottom:1px solid rgba(255,255,255,0.07);background:linear-gradient(135deg,rgba(20,24,40,.98),rgba(26,31,53,.95));border-radius:20px 20px 0 0">' +
          '<div style="font-size:16px;font-weight:800;color:#f1f5f9">' + (isEdit ? '✏️ Редактировать сотрудника' : '➕ Новый сотрудник') + '</div>' +
          '<button onclick="window._staffCloseModal()" style="background:rgba(255,255,255,0.08);border:none;color:#94a3b8;width:32px;height:32px;border-radius:50%;cursor:pointer;font-size:16px">✕</button>' +
        '</div>' +

        '<div style="padding:22px;display:flex;flex-direction:column;gap:16px">' +

          // Фото + имя
          '<div style="display:flex;align-items:center;gap:16px">' +
            '<div id="sm-photo-area" class="stf-photo-area" onclick="document.getElementById(\'sm-photo-input\').click()" title="Нажмите для загрузки фото">' +
              (photo ? '<img id="sm-photo-preview" src="' + esc(photo) + '">' : '<span id="sm-photo-icon" style="font-size:28px">📷</span>') +
            '</div>' +
            '<input type="file" id="sm-photo-input" accept="image/*" style="display:none" onchange="window._staffPhotoChange(this)">' +
            '<div style="flex:1">' +
              '<label class="stf-label">Имя и фамилия *</label>' +
              '<input class="stf-input" type="text" id="sm-name" value="' + esc(e.name || '') + '" placeholder="Иванов Иван" autofocus>' +
            '</div>' +
          '</div>' +

          // Должность + телефон
          '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">' +
            _formField('Должность', 'text', 'sm-position', e.position || e.role || '', 'Оператор, менеджер...') +
            _formField('Телефон', 'tel', 'sm-phone', e.phone || '', '+7 (999) 000-00-00') +
          '</div>' +

          // Email + График
          '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">' +
            _formField('Email', 'email', 'sm-email', e.email || '', 'ivan@example.com') +
            _formField('График работы', 'text', 'sm-schedule', e.schedule || '5/2', '5/2, 2/2, 6/1...') +
          '</div>' +

          // Оклад + Бонус
          '<div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px">' +
            _formField('Оклад ₽', 'number', 'sm-salary', e.salary || 0, '0') +
            _formField('Бонус % с дохода', 'number', 'sm-bonus', Math.round((parseFloat(e.bonus_pct || 0.1)) * 100), '10') +
            _formField('PIN (касса)', 'text', 'sm-pin', e.pin || '', '1234') +
          '</div>' +

          // Даты
          '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">' +
            _formField('Начало работы', 'date', 'sm-start', (e.start_date || e.startDate || '').slice(0, 10), '') +
            _formField('Дата рождения', 'date', 'sm-birth', (e.birth_date || e.birthDate || '').slice(0, 10), '') +
          '</div>' +

          // Статус + Цвет
          '<div style="display:grid;grid-template-columns:1fr auto;gap:12px;align-items:end">' +
            '<div>' +
              '<label class="stf-label">Статус</label>' +
              '<select id="sm-status" class="stf-input">' +
                ['active','vacation','fired'].map(st =>
                  '<option value="' + st + '"' + ((e.status || 'active') === st ? ' selected' : '') + '>' + statusLabel(st) + '</option>'
                ).join('') +
              '</select>' +
            '</div>' +
            '<div>' +
              '<label class="stf-label">Цвет</label>' +
              '<input type="color" id="sm-color" value="' + esc(color) + '" style="width:48px;height:40px;border:1px solid rgba(255,255,255,0.1);border-radius:8px;cursor:pointer;background:transparent;padding:2px">' +
            '</div>' +
          '</div>' +

          // Адрес
          _formField('Адрес проживания', 'text', 'sm-address', e.address || '', 'г. Город, ул. Улица, д. 1') +

          // Заметки
          '<div>' +
            '<label class="stf-label">Паспортные данные / Заметки</label>' +
            '<textarea id="sm-notes" rows="2" placeholder="Серия, номер, кем выдан..." class="stf-input" style="resize:vertical">' +
              esc(e.passport_note || e.passportNote || e.notes || '') +
            '</textarea>' +
          '</div>' +

          // Кнопки
          '<div style="display:flex;gap:10px;margin-top:4px">' +
            '<button class="stf-btn" onclick="window._staffCloseModal()"' +
              ' style="flex:1;background:rgba(255,255,255,0.06);color:#94a3b8;border:1px solid rgba(255,255,255,0.08);padding:11px;border-radius:10px;justify-content:center">Отмена</button>' +
            '<button class="stf-btn" onclick="window._staffSave(\'' + esc(e.id || '') + '\')"' +
              ' style="flex:2;background:linear-gradient(135deg,#7c3aed,#6d28d9);color:#fff;padding:11px;border-radius:10px;justify-content:center;font-size:14px;box-shadow:0 4px 16px #7c3aed44">' +
              (isEdit ? '💾 Сохранить' : '✅ Добавить сотрудника') +
            '</button>' +
          '</div>' +

        '</div>' +
      '</div>' +
    '</div>';

  document.body.insertAdjacentHTML('beforeend', html);
}

function _formField(label, type, id, value, placeholder) {
  return (
    '<div>' +
      '<label class="stf-label">' + label + '</label>' +
      '<input type="' + type + '" id="' + id + '" value="' + esc(value) + '" placeholder="' + esc(placeholder) + '" class="stf-input">' +
    '</div>'
  );
}

// Загрузка фото
window._staffPhotoChange = function(input) {
  const file = input.files[0];
  if (!file) return;
  const reader = new FileReader();
  reader.onload = function(e) {
    const area = document.getElementById('sm-photo-area');
    if (area) {
      area.innerHTML = '<img src="' + e.target.result + '" style="width:100%;height:100%;object-fit:cover;border-radius:50%">';
    }
    window._staffPhotoBase64 = e.target.result;
  };
  reader.readAsDataURL(file);
};

// ── МОДАЛ: Выплата ────────────────────────────────────────
function openPayModal(staffId) {
  const s = _staff.find(e => String(e.id) === String(staffId));
  if (!s) return;
  const color   = s.color || avatarColor(s.name);
  const recs    = _salary.filter(r => (r.staff_id || r.staffId) === s.id);
  const totals  = { salary: 0, advance: 0, bonus: 0, revenue_bonus: 0, fine: 0 };
  recs.forEach(r => { totals[r.type] = (totals[r.type] || 0) + parseFloat(r.amount || 0); });
  const totalPaid = Object.entries(totals).filter(([k]) => k !== 'fine').reduce((a, [, v]) => a + v, 0);

  const typeLabels = { salary: '💵 Зарплата', advance: '⏩ Аванс', bonus: '🎁 Премия', revenue_bonus: '📈 Бонус с выручки', fine: '⚠️ Штраф' };
  const typeColors = { salary: '#7c3aed', advance: '#06b6d4', bonus: '#10b981', revenue_bonus: '#f59e0b', fine: '#ef4444' };

  const histRows = recs.slice().reverse().slice(0, 8).map(r =>
    '<tr style="border-bottom:1px solid rgba(255,255,255,0.04);font-size:12px">' +
      '<td style="padding:7px 10px;color:#64748b">' + fmtT(r.created_at) + '</td>' +
      '<td style="padding:7px 10px;color:' + (typeColors[r.type] || '#94a3b8') + '">' + (typeLabels[r.type] || r.type) + '</td>' +
      '<td style="padding:7px 10px;text-align:right;font-weight:700;color:' + (r.type === 'fine' ? '#ef4444' : '#10b981') + '">' +
        (r.type === 'fine' ? '−' : '+') + fmt(r.amount) + ' ₽' +
      '</td>' +
      '<td style="padding:7px 10px;color:#64748b">' + esc(r.comment || r.note || '—') + '</td>' +
      '<td style="padding:7px 10px">' +
        '<button onclick="window._staffDelPay(\'' + esc(r.id) + '\')" style="background:none;border:1px solid rgba(239,68,68,0.3);border-radius:4px;color:#ef4444;cursor:pointer;padding:2px 7px;font-size:11px">×</button>' +
      '</td>' +
    '</tr>'
  ).join('');

  const html =
    '<div class="stf-modal-overlay" id="payModal" onclick="if(event.target.id===\'payModal\')this.remove()">' +
      '<div class="stf-modal" style="max-width:500px">' +

        '<div style="padding:18px 22px;border-bottom:1px solid rgba(255,255,255,0.07);background:linear-gradient(135deg,' + color + '18,' + color + '05);border-radius:20px 20px 0 0;display:flex;justify-content:space-between;align-items:center">' +
          '<div style="display:flex;align-items:center;gap:12px">' +
            '<div style="width:44px;height:44px;border-radius:50%;overflow:hidden;border:2px solid ' + color + '44">' +
              (s.photo
                ? '<img src="' + esc(s.photo) + '" style="width:100%;height:100%;object-fit:cover">'
                : '<div style="width:100%;height:100%;background:linear-gradient(135deg,' + color + '44,' + color + '22);display:flex;align-items:center;justify-content:center;font-size:16px;font-weight:700;color:#fff">' + initials(s.name) + '</div>'
              ) +
            '</div>' +
            '<div>' +
              '<div style="font-size:15px;font-weight:700;color:#f1f5f9">💰 Выплата</div>' +
              '<div style="font-size:13px;color:' + color + ';font-weight:600">' + esc(s.name) + ' · ' + _salPeriod + '</div>' +
            '</div>' +
          '</div>' +
          '<button onclick="document.getElementById(\'payModal\').remove()" style="background:rgba(255,255,255,0.08);border:none;color:#94a3b8;width:30px;height:30px;border-radius:50%;cursor:pointer;font-size:14px">✕</button>' +
        '</div>' +

        '<div style="padding:18px 22px;display:flex;flex-direction:column;gap:14px">' +

          // Итог по типам
          '<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:8px">' +
            Object.entries(totals).filter(([, v]) => v > 0).map(([k, v]) =>
              '<div class="stf-metric">' +
                '<div style="font-size:12px;font-weight:700;color:' + (typeColors[k] || '#94a3b8') + '">' + fmt(v) + ' ₽</div>' +
                '<div style="font-size:10px;color:#64748b;margin-top:2px">' + (typeLabels[k] || k) + '</div>' +
              '</div>'
            ).join('') +
          '</div>' +

          // Форма
          '<div style="background:rgba(255,255,255,0.03);border:1px solid rgba(255,255,255,0.07);border-radius:12px;padding:14px">' +
            '<div style="font-size:11px;color:#64748b;text-transform:uppercase;letter-spacing:.5px;margin-bottom:12px">Новая выплата</div>' +
            '<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px">' +
              '<div>' +
                '<label class="stf-label">Тип</label>' +
                '<select id="pay-type" class="stf-input">' +
                  Object.entries(typeLabels).map(([k, v]) => '<option value="' + k + '">' + v + '</option>').join('') +
                '</select>' +
              '</div>' +
              '<div>' +
                '<label class="stf-label">Сумма ₽</label>' +
                '<input type="number" id="pay-amount" placeholder="0" min="0.01" class="stf-input" style="font-size:15px;font-weight:700">' +
              '</div>' +
            '</div>' +
            '<input type="text" id="pay-note" placeholder="Комментарий..." class="stf-input" style="margin-bottom:10px">' +
            '<div style="display:flex;gap:8px;margin-bottom:10px">' +
              '<button class="stf-btn" onclick="window._staffAutoCalc(\'' + esc(s.id) + '\')"' +
                ' style="flex:1;background:rgba(124,58,237,0.1);color:#7c3aed;border:1px solid rgba(124,58,237,0.3);padding:8px;border-radius:8px;justify-content:center">🧮 Авторасчёт</button>' +
            '</div>' +
            '<button class="stf-btn" onclick="window._staffSavePay(\'' + esc(s.id) + '\')"' +
              ' style="width:100%;background:linear-gradient(135deg,#10b981,#059669);color:#fff;padding:11px;border-radius:10px;justify-content:center;font-size:14px;box-shadow:0 4px 16px #10b98144">✅ Записать выплату</button>' +
          '</div>' +

          // История
          (recs.length
            ? '<div style="background:rgba(255,255,255,0.02);border:1px solid rgba(255,255,255,0.06);border-radius:12px;overflow:hidden">' +
                '<div style="padding:8px 14px;font-size:11px;color:#64748b;text-transform:uppercase;letter-spacing:.5px;border-bottom:1px solid rgba(255,255,255,0.05)">История выплат · ' + _salPeriod + '</div>' +
                '<div style="overflow-x:auto"><table style="width:100%;border-collapse:collapse;min-width:360px">' +
                  histRows +
                '</table></div>' +
              '</div>'
            : '<div style="text-align:center;color:#64748b;font-size:13px;padding:16px">Выплат за этот период нет</div>'
          ) +

        '</div>' +
      '</div>' +
    '</div>';

  document.body.insertAdjacentHTML('beforeend', html);
}

// ── ВКЛАДКА: ЗАРПЛАТА ─────────────────────────────────────
function renderSalaryView() {
  const byStaff = {};
  _staff.forEach(s => { byStaff[s.id] = { staff: s, records: [], total: 0, fine: 0 }; });
  _salary.forEach(r => {
    const sid = r.staff_id || r.staffId;
    if (!byStaff[sid]) byStaff[sid] = { staff: { id: sid, name: r.staff_name || r.staffName || 'Неизвестно', color: '#64748b', salary: 0 }, records: [], total: 0, fine: 0 };
    byStaff[sid].records.push(r);
    if (r.type === 'fine') byStaff[sid].fine += parseFloat(r.amount || 0);
    else byStaff[sid].total += parseFloat(r.amount || 0);
  });

  const totalAll = Object.values(byStaff).reduce((a, v) => a + v.total, 0);
  const fineAll  = Object.values(byStaff).reduce((a, v) => a + v.fine, 0);
  const fotPlan  = _staff.reduce((a, s) => a + parseFloat(s.salary || 0), 0);

  const rows = Object.values(byStaff).filter(v => v.records.length || (v.staff.status || 'active') === 'active').map(v => {
    const s   = v.staff;
    const col = s.color || avatarColor(s.name);
    const types = { salary: 0, advance: 0, bonus: 0, revenue_bonus: 0, fine: 0 };
    v.records.forEach(r => { types[r.type] = (types[r.type] || 0) + parseFloat(r.amount || 0); });
    const planPct = parseFloat(s.salary || 0) > 0
      ? Math.min(100, Math.round(v.total / parseFloat(s.salary) * 100)) : 0;

    return (
      '<tr style="border-bottom:1px solid rgba(255,255,255,0.04);transition:background .15s" ' +
        'onmouseenter="this.style.background=\'rgba(255,255,255,0.03)\'" onmouseleave="this.style.background=\'\'">' +
        '<td style="padding:12px 16px">' +
          '<div style="display:flex;align-items:center;gap:10px">' +
            '<div style="width:38px;height:38px;border-radius:50%;overflow:hidden;border:2px solid ' + esc(col) + '44;flex-shrink:0">' +
              (s.photo
                ? '<img src="' + esc(s.photo) + '" style="width:100%;height:100%;object-fit:cover">'
                : '<div style="width:100%;height:100%;background:linear-gradient(135deg,' + esc(col) + '44,' + esc(col) + '22);display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:700;color:' + esc(col) + '">' + initials(s.name) + '</div>'
              ) +
            '</div>' +
            '<div>' +
              '<div style="font-size:13px;font-weight:600;color:#e2e8f0">' + esc(s.name) + '</div>' +
              '<div style="font-size:11px;color:#64748b">' + esc(s.position || s.role || '—') + '</div>' +
              '<div class="stf-progress" style="width:80px;margin-top:4px"><div class="stf-progress-bar" style="width:' + planPct + '%;background:' + esc(col) + '"></div></div>' +
            '</div>' +
          '</div>' +
        '</td>' +
        '<td style="padding:12px 16px;text-align:right;color:#7c3aed;font-size:13px">' + (types.salary > 0 ? fmt(types.salary) + ' ₽' : '—') + '</td>' +
        '<td style="padding:12px 16px;text-align:right;color:#06b6d4;font-size:13px">' + (types.advance > 0 ? fmt(types.advance) + ' ₽' : '—') + '</td>' +
        '<td style="padding:12px 16px;text-align:right;color:#10b981;font-size:13px">' + (types.bonus + types.revenue_bonus > 0 ? fmt(types.bonus + types.revenue_bonus) + ' ₽' : '—') + '</td>' +
        '<td style="padding:12px 16px;text-align:right;color:#ef4444;font-size:13px">' + (types.fine > 0 ? '−' + fmt(types.fine) + ' ₽' : '—') + '</td>' +
        '<td style="padding:12px 16px;text-align:right;font-weight:700;font-size:14px;color:' + (v.total > 0 ? '#10b981' : '#64748b') + '">' + (v.total > 0 ? fmt(v.total) + ' ₽' : '—') + '</td>' +
        '<td style="padding:12px 16px">' +
          '<button class="stf-btn" onclick="window._staffPayModal(\'' + esc(s.id) + '\')"' +
            ' style="background:rgba(124,58,237,0.1);color:#7c3aed;border:1px solid rgba(124,58,237,0.3)">+ Выплата</button>' +
        '</td>' +
      '</tr>'
    );
  }).join('');

  _el.innerHTML =
    _topBar('salary') +
    '<div style="padding:20px;max-width:1100px;margin:0 auto">' +

      '<div style="display:flex;align-items:center;gap:14px;margin-bottom:20px;flex-wrap:wrap">' +
        '<div>' +
          '<label class="stf-label">Период</label>' +
          '<input type="month" value="' + esc(_salPeriod) + '" onchange="window._staffSalPeriod(this.value)" class="stf-input" style="width:auto">' +
        '</div>' +
        '<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;flex:1;min-width:300px">' +
          _heroCard('💰', fmt(totalAll) + ' ₽', 'Выплачено', '#10b981', 'Всего за период') +
          _heroCard('⚠️', fmt(fineAll)  + ' ₽', 'Штрафы',    '#ef4444', 'Удержано') +
          _heroCard('📊', fmt(fotPlan)  + ' ₽', 'ФОТ план',  '#7c3aed', 'Плановый фонд') +
        '</div>' +
      '</div>' +

      '<div style="background:linear-gradient(135deg,rgba(20,24,40,.95),rgba(26,31,53,.9));border:1px solid rgba(255,255,255,0.07);border-radius:16px;overflow:hidden">' +
        '<div style="overflow-x:auto"><table style="width:100%;border-collapse:collapse;min-width:600px">' +
          '<tr style="font-size:11px;color:#64748b;text-transform:uppercase;background:rgba(0,0,0,.3)">' +
            '<th style="padding:10px 16px;text-align:left;font-weight:400">Сотрудник</th>' +
            '<th style="padding:10px 16px;text-align:right;font-weight:400">Зарплата</th>' +
            '<th style="padding:10px 16px;text-align:right;font-weight:400">Аванс</th>' +
            '<th style="padding:10px 16px;text-align:right;font-weight:400">Бонусы</th>' +
            '<th style="padding:10px 16px;text-align:right;font-weight:400">Штраф</th>' +
            '<th style="padding:10px 16px;text-align:right;font-weight:400">Итого</th>' +
            '<th style="padding:10px 16px;font-weight:400"></th>' +
          '</tr>' +
          (rows || '<tr><td colspan="7" style="padding:50px;text-align:center;color:#64748b">Нет данных за этот период</td></tr>') +
        '</table></div>' +
      '</div>' +

    '</div>';
}

// ── ВКЛАДКА: СМЕНЫ ────────────────────────────────────────
function renderShiftsView() {
  const shiftsByEmp = {};
  _staff.forEach(s => { shiftsByEmp[s.id] = []; });
  _shifts.forEach(sh => {
    const name  = sh.manager || sh.staff_name;
    const found = _staff.find(s => s.id === (sh.emp_id || sh.empId || sh.staff_id) || s.name === name);
    if (found) { shiftsByEmp[found.id] = shiftsByEmp[found.id] || []; shiftsByEmp[found.id].push(sh); }
  });

  const staffRows = _staff.filter(s => (s.status || 'active') !== 'fired').map(s => {
    const sh  = shiftsByEmp[s.id] || [];
    const col = s.color || avatarColor(s.name);
    const inc = sh.reduce((a, x) => a + parseFloat(x.total_income || x.income || 0), 0);
    const sal = sh.reduce((a, x) => a + parseFloat(x.total_salary || 0), 0);
    return (
      '<tr style="border-bottom:1px solid rgba(255,255,255,0.04);font-size:13px;transition:background .15s" ' +
        'onmouseenter="this.style.background=\'rgba(255,255,255,0.03)\'" onmouseleave="this.style.background=\'\'">' +
        '<td style="padding:10px 14px">' +
          '<div style="display:flex;align-items:center;gap:9px">' +
            '<div style="width:34px;height:34px;border-radius:50%;overflow:hidden;border:2px solid ' + esc(col) + '44;flex-shrink:0">' +
              (s.photo
                ? '<img src="' + esc(s.photo) + '" style="width:100%;height:100%;object-fit:cover">'
                : '<div style="width:100%;height:100%;background:' + esc(col) + '33;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;color:' + esc(col) + '">' + initials(s.name) + '</div>'
              ) +
            '</div>' +
            '<div>' +
              '<div style="font-weight:600;color:#e2e8f0">' + esc(s.name) + '</div>' +
              '<div style="font-size:11px;color:#64748b">' + esc(s.position || s.role || '—') + '</div>' +
            '</div>' +
          '</div>' +
        '</td>' +
        '<td style="padding:10px 14px;text-align:center;font-weight:700;color:#06b6d4;font-size:18px">' + sh.length + '</td>' +
        '<td style="padding:10px 14px;text-align:right;color:#10b981">' + (inc > 0 ? fmt(inc) + ' ₽' : '—') + '</td>' +
        '<td style="padding:10px 14px;text-align:right;color:#f59e0b">' + (sal > 0 ? fmt(sal) + ' ₽' : '—') + '</td>' +
      '</tr>'
    );
  }).join('');

  const lastShifts = _shifts.slice(0, 20).map(sh => {
    const mgr  = sh.manager || sh.staff_name || '—';
    const emp  = _staff.find(s => s.name === mgr);
    const col  = emp?.color || avatarColor(mgr);
    const inc  = parseFloat(sh.total_income  || sh.income  || 0);
    const exp  = parseFloat(sh.total_expense || sh.expense || 0);
    const diff = parseFloat(sh.cash_diff || 0);
    return (
      '<tr style="border-bottom:1px solid rgba(255,255,255,0.04);font-size:12px;transition:background .15s" ' +
        'onmouseenter="this.style.background=\'rgba(255,255,255,0.03)\'" onmouseleave="this.style.background=\'\'">' +
        '<td style="padding:8px 12px;color:#64748b">' + fmtD(sh.opened_at || sh.open_time) + '</td>' +
        '<td style="padding:8px 12px">' +
          '<div style="display:flex;align-items:center;gap:7px">' +
            '<div style="width:26px;height:26px;border-radius:50%;background:' + esc(col) + '33;display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:700;color:' + esc(col) + '">' + initials(mgr) + '</div>' +
            '<span style="color:#e2e8f0">' + esc(mgr) + '</span>' +
          '</div>' +
        '</td>' +
        '<td style="padding:8px 12px;color:#10b981;text-align:right">+' + fmt(inc) + ' ₽</td>' +
        '<td style="padding:8px 12px;color:#ef4444;text-align:right">−' + fmt(exp) + ' ₽</td>' +
        '<td style="padding:8px 12px;text-align:right;font-weight:600;color:' + (diff >= 0 ? '#10b981' : '#ef4444') + '">' + (diff >= 0 ? '+' : '') + fmt(diff) + ' ₽</td>' +
        '<td style="padding:8px 12px;text-align:right;color:#f59e0b">' + fmt(sh.total_salary || 0) + ' ₽</td>' +
      '</tr>'
    );
  }).join('');

  _el.innerHTML =
    _topBar('shifts') +
    '<div style="padding:20px;max-width:1200px;margin:0 auto">' +

      '<div style="display:flex;gap:12px;align-items:flex-end;margin-bottom:20px;flex-wrap:wrap">' +
        '<div><label class="stf-label">С</label><input type="date" value="' + esc(_shiftFrom) + '" onchange="window._staffShiftFrom(this.value)" class="stf-input" style="width:auto"></div>' +
        '<div><label class="stf-label">По</label><input type="date" value="' + esc(_shiftTo) + '" onchange="window._staffShiftTo(this.value)" class="stf-input" style="width:auto"></div>' +
        '<button class="stf-btn" onclick="window._staffLoadShifts()" style="background:rgba(255,255,255,0.06);color:#94a3b8;border:1px solid rgba(255,255,255,0.08);padding:9px 16px;border-radius:8px">🔄 Обновить</button>' +
      '</div>' +

      '<div style="display:grid;grid-template-columns:1fr 1.6fr;gap:16px">' +

        '<div style="background:linear-gradient(135deg,rgba(20,24,40,.95),rgba(26,31,53,.9));border:1px solid rgba(255,255,255,0.07);border-radius:16px;overflow:hidden">' +
          '<div style="padding:12px 16px;border-bottom:1px solid rgba(255,255,255,0.06);font-size:11px;color:#64748b;font-weight:600;text-transform:uppercase;letter-spacing:.5px">По сотрудникам</div>' +
          '<table style="width:100%;border-collapse:collapse">' +
            '<tr style="font-size:10px;color:#64748b;text-transform:uppercase;background:rgba(0,0,0,.2)">' +
              '<th style="padding:8px 14px;text-align:left;font-weight:400">Сотрудник</th>' +
              '<th style="padding:8px 14px;text-align:center;font-weight:400">Смен</th>' +
              '<th style="padding:8px 14px;text-align:right;font-weight:400">Доход</th>' +
              '<th style="padding:8px 14px;text-align:right;font-weight:400">ЗП</th>' +
            '</tr>' +
            (staffRows || '<tr><td colspan="4" style="padding:30px;text-align:center;color:#64748b">Нет данных</td></tr>') +
          '</table>' +
        '</div>' +

        '<div style="background:linear-gradient(135deg,rgba(20,24,40,.95),rgba(26,31,53,.9));border:1px solid rgba(255,255,255,0.07);border-radius:16px;overflow:hidden">' +
          '<div style="padding:12px 16px;border-bottom:1px solid rgba(255,255,255,0.06);font-size:11px;color:#64748b;font-weight:600;text-transform:uppercase;letter-spacing:.5px">Последние смены</div>' +
          '<div style="overflow-x:auto"><table style="width:100%;border-collapse:collapse;min-width:380px">' +
            '<tr style="font-size:10px;color:#64748b;text-transform:uppercase;background:rgba(0,0,0,.2)">' +
              '<th style="padding:8px 12px;text-align:left;font-weight:400">Дата</th>' +
              '<th style="padding:8px 12px;text-align:left;font-weight:400">Менеджер</th>' +
              '<th style="padding:8px 12px;text-align:right;font-weight:400">Доход</th>' +
              '<th style="padding:8px 12px;text-align:right;font-weight:400">Расход</th>' +
              '<th style="padding:8px 12px;text-align:right;font-weight:400">Расхожд.</th>' +
              '<th style="padding:8px 12px;text-align:right;font-weight:400">ЗП</th>' +
            '</tr>' +
            (lastShifts || '<tr><td colspan="6" style="padding:30px;text-align:center;color:#64748b">Смен нет</td></tr>') +
          '</table></div>' +
        '</div>' +

      '</div>' +
    '</div>';
}

// ── ВКЛАДКА: АНАЛИТИКА ────────────────────────────────────
function renderAnalyticsView() {
  const active  = _staff.filter(s => (s.status || 'active') === 'active');
  const avgSal  = active.length ? active.reduce((a, s) => a + parseFloat(s.salary || 0), 0) / active.length : 0;
  const empTotals = _staff.map(s => ({
    ...s,
    _total: _salary.filter(r => (r.staff_id || r.staffId) === s.id && r.type !== 'fine').reduce((a, r) => a + parseFloat(r.amount || 0), 0),
    _shifts: _shifts.filter(sh => (sh.manager || sh.staff_name) === s.name).length,
  })).sort((a, b) => b._total - a._total);

  const now = new Date();
  const birthdays = _staff.filter(s => {
    const bd = s.birth_date || s.birthDate;
    if (!bd) return false;
    return new Date(bd).getMonth() === now.getMonth();
  }).sort((a, b) => new Date(a.birth_date || a.birthDate).getDate() - new Date(b.birth_date || b.birthDate).getDate());

  const byPos = {};
  _staff.forEach(s => { const p = s.position || s.role || 'Без должности'; byPos[p] = (byPos[p] || 0) + 1; });
  const maxPos = Math.max(...Object.values(byPos), 1);

  _el.innerHTML =
    _topBar('analytics') +
    '<div style="padding:20px;max-width:1100px;margin:0 auto">' +

      '<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:20px">' +
        _heroCard('👥', _staff.length,               'Сотрудников',   '#7c3aed', 'Всего в команде') +
        _heroCard('💵', fmt(avgSal) + ' ₽',          'Средний оклад', '#06b6d4', 'По активным') +
        _heroCard('📋', _salary.length + ' шт.',     'Выплат',        '#10b981', 'За текущий период') +
        _heroCard('📅', _shifts.length + ' смен',    'Смен',          '#f59e0b', 'За выбранный период') +
      '</div>' +

      '<div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px">' +

        // Топ
        '<div style="background:linear-gradient(135deg,rgba(20,24,40,.95),rgba(26,31,53,.9));border:1px solid rgba(255,255,255,0.07);border-radius:16px;padding:18px">' +
          '<div style="font-size:12px;color:#64748b;font-weight:600;text-transform:uppercase;letter-spacing:.5px;margin-bottom:14px">🏆 Топ по выплатам</div>' +
          (empTotals.slice(0, 5).map((s, i) => {
            const col     = s.color || avatarColor(s.name);
            const medals  = ['🥇','🥈','🥉','4️⃣','5️⃣'];
            return (
              '<div style="display:flex;align-items:center;gap:10px;padding:9px 0;border-bottom:1px solid rgba(255,255,255,0.05)">' +
                '<span style="font-size:18px;width:24px;text-align:center">' + (medals[i] || (i+1)) + '</span>' +
                '<div style="width:32px;height:32px;border-radius:50%;overflow:hidden;border:2px solid ' + col + '44;flex-shrink:0">' +
                  (s.photo ? '<img src="' + esc(s.photo) + '" style="width:100%;height:100%;object-fit:cover">'
                           : '<div style="width:100%;height:100%;background:' + col + '33;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;color:' + col + '">' + initials(s.name) + '</div>') +
                '</div>' +
                '<div style="flex:1;min-width:0">' +
                  '<div style="font-size:12px;font-weight:600;color:#e2e8f0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">' + esc(s.name) + '</div>' +
                  '<div style="font-size:10px;color:#64748b">' + s._shifts + ' смен</div>' +
                '</div>' +
                '<div style="font-size:13px;font-weight:700;color:#10b981">' + fmt(s._total) + ' ₽</div>' +
              '</div>'
            );
          }).join('') || '<div style="color:#64748b;font-size:13px;text-align:center;padding:20px">Нет данных</div>') +
        '</div>' +

        // ДР
        '<div style="background:linear-gradient(135deg,rgba(20,24,40,.95),rgba(26,31,53,.9));border:1px solid rgba(255,255,255,0.07);border-radius:16px;padding:18px">' +
          '<div style="font-size:12px;color:#64748b;font-weight:600;text-transform:uppercase;letter-spacing:.5px;margin-bottom:14px">🎂 Дни рождения в ' + ['январе','феврале','марте','апреле','мае','июне','июле','августе','сентябре','октябре','ноябре','декабре'][now.getMonth()] + '</div>' +
          (birthdays.slice(0, 6).map(s => {
            const bd   = new Date(s.birth_date || s.birthDate);
            const next = new Date(now.getFullYear(), bd.getMonth(), bd.getDate());
            const diff = Math.ceil((next - now) / 86400000);
            const col  = s.color || avatarColor(s.name);
            const isToday = diff === 0 || diff < 0;
            return (
              '<div style="display:flex;align-items:center;gap:10px;padding:9px 0;border-bottom:1px solid rgba(255,255,255,0.05)' + (isToday ? ';background:rgba(245,158,11,0.05);border-radius:8px;padding:9px 6px;margin:0 -6px' : '') + '">' +
                '<div style="width:32px;height:32px;border-radius:50%;overflow:hidden;border:2px solid ' + esc(col) + '44;flex-shrink:0">' +
                  (s.photo ? '<img src="' + esc(s.photo) + '" style="width:100%;height:100%;object-fit:cover">'
                           : '<div style="width:100%;height:100%;background:' + esc(col) + '33;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;color:' + esc(col) + '">' + initials(s.name) + '</div>') +
                '</div>' +
                '<div style="flex:1">' +
                  '<div style="font-size:12px;font-weight:600;color:#e2e8f0">' + esc(s.name) + '</div>' +
                  '<div style="font-size:10px;color:#64748b">' + bd.getDate() + ' ' + ['янв','фев','мар','апр','май','июн','июл','авг','сен','окт','ноя','дек'][bd.getMonth()] + '</div>' +
                '</div>' +
                '<span style="font-size:11px;font-weight:700;color:' + (isToday ? '#f59e0b' : diff <= 3 ? '#ef4444' : '#64748b') + '">' +
                  (isToday ? '🎉 Сегодня!' : diff === 1 ? '🎂 Завтра!' : 'Через ' + diff + ' дн.') +
                '</span>' +
              '</div>'
            );
          }).join('') || '<div style="color:#64748b;font-size:13px;text-align:center;padding:20px">В этом месяце нет ДР</div>') +
        '</div>' +

        // По должностям
        '<div style="background:linear-gradient(135deg,rgba(20,24,40,.95),rgba(26,31,53,.9));border:1px solid rgba(255,255,255,0.07);border-radius:16px;padding:18px">' +
          '<div style="font-size:12px;color:#64748b;font-weight:600;text-transform:uppercase;letter-spacing:.5px;margin-bottom:14px">🗂️ По должностям</div>' +
          (Object.entries(byPos).map(([pos, cnt], i) => {
            const col = PALETTE[i % PALETTE.length];
            const pct = Math.round(cnt / Math.max(_staff.length, 1) * 100);
            return (
              '<div style="margin-bottom:12px">' +
                '<div style="display:flex;justify-content:space-between;margin-bottom:4px">' +
                  '<span style="font-size:12px;color:#94a3b8">' + esc(pos) + '</span>' +
                  '<span style="font-size:12px;font-weight:700;color:' + col + '">' + cnt + ' · ' + pct + '%</span>' +
                '</div>' +
                '<div class="stf-progress"><div class="stf-progress-bar" style="width:' + pct + '%;background:linear-gradient(90deg,' + col + ',' + col + '88)"></div></div>' +
              '</div>'
            );
          }).join('') || '<div style="color:#64748b;font-size:13px;text-align:center;padding:20px">Нет данных</div>') +
        '</div>' +

      '</div>' +
    '</div>';
}

// ── Рендер ────────────────────────────────────────────────
function render() {
  if (!_el) return;
  injectCSS();
  if      (_view === 'staff')     renderStaffView();
  else if (_view === 'kanban')    renderKanbanView();
  else if (_view === 'salary')    renderSalaryView();
  else if (_view === 'shifts')    renderShiftsView();
  else if (_view === 'analytics') renderAnalyticsView();
}

// ── Глобальные хелперы ────────────────────────────────────
window._staffView        = v  => { _view = v; render(); };
window._staffSearch      = v  => { _staffSearch = v; if (_view === 'staff') renderStaffView(); };
window._staffSalPeriod   = async v => { _salPeriod = v; await loadSalary(); renderSalaryView(); };
window._staffShiftFrom   = v  => { _shiftFrom = v; };
window._staffShiftTo     = v  => { _shiftTo   = v; };
window._staffLoadShifts  = async () => { await loadShifts(); renderShiftsView(); };

window._staffAdd   = ()  => openStaffModal(null);
window._staffEdit  = id  => { const s = _staff.find(e => String(e.id) === String(id)); if (s) openStaffModal(s); };
window._staffCloseModal = () => document.getElementById('staffModal')?.remove();
window._staffPayModal = id => openPayModal(id);

window._staffSave = async function(existingId) {
  const name = document.getElementById('sm-name')?.value?.trim();
  if (!name) { ntf('Введите имя сотрудника', 'error'); return; }

  const data = {
    name,
    position:      document.getElementById('sm-position')?.value?.trim() || '',
    phone:         document.getElementById('sm-phone')?.value?.trim()    || '',
    email:         document.getElementById('sm-email')?.value?.trim()    || '',
    salary:        parseFloat(document.getElementById('sm-salary')?.value  || 0),
    bonus_pct:     parseFloat(document.getElementById('sm-bonus')?.value   || 10) / 100,
    schedule:      document.getElementById('sm-schedule')?.value?.trim() || '5/2',
    start_date:    document.getElementById('sm-start')?.value   || '',
    birth_date:    document.getElementById('sm-birth')?.value   || '',
    status:        document.getElementById('sm-status')?.value  || 'active',
    color:         document.getElementById('sm-color')?.value   || '#7c3aed',
    address:       document.getElementById('sm-address')?.value?.trim() || '',
    pin:           document.getElementById('sm-pin')?.value?.trim()     || '',
    passport_note: document.getElementById('sm-notes')?.value?.trim()   || '',
  };

  if (window._staffPhotoBase64) {
    data.photo = window._staffPhotoBase64;
    window._staffPhotoBase64 = null;
  }

  const r = existingId
    ? await API.put('staff', { id: existingId, ...data })
    : await API.post('staff', data);

  if (r.ok) {
    ntf(existingId ? '✅ Сотрудник обновлён' : '✅ Сотрудник добавлен', 'success');
    window._staffCloseModal();
    await loadStaff();
    render();
  } else {
    ntf('Ошибка: ' + (r.error || ''), 'error');
  }
};

window._staffDelete = async function(id) {
  const s = _staff.find(e => String(e.id) === String(id));
  if (!confirm('Удалить сотрудника ' + (s?.name || '') + '?')) return;
  const r = await API.del('staff', { id });
  if (r.ok) { ntf('Сотрудник удалён', 'info'); await loadStaff(); render(); }
  else ntf('Ошибка: ' + (r.error || ''), 'error');
};

window._staffSavePay = async function(staffId) {
  const s      = _staff.find(e => String(e.id) === String(staffId));
  const type   = document.getElementById('pay-type')?.value   || 'salary';
  const amount = parseFloat(document.getElementById('pay-amount')?.value || 0);
  const note   = document.getElementById('pay-note')?.value?.trim() || '';
  if (!amount || amount <= 0) { ntf('Введите сумму', 'error'); return; }
  const r = await API.post('salary', {
    staff_id: staffId, staffId, staff_name: s?.name || '', staffName: s?.name || '',
    type, amount, note, period: _salPeriod,
  });
  if (r.ok) {
    ntf('✅ Выплата: ' + fmt(amount) + ' ₽', 'success');
    document.getElementById('payModal')?.remove();
    await loadSalary();
    if (_view === 'salary') renderSalaryView();
  } else { ntf('Ошибка: ' + (r.error || ''), 'error'); }
};

window._staffDelPay = async function(id) {
  if (!confirm('Удалить выплату?')) return;
  const r = await API.del('salary', { id });
  if (r.ok) {
    ntf('Выплата удалена', 'info');
    document.getElementById('payModal')?.remove();
    await loadSalary();
    if (_view === 'salary') renderSalaryView();
  } else { ntf('Ошибка: ' + (r.error || ''), 'error'); }
};

window._staffAutoCalc = async function(staffId) {
  const s = _staff.find(e => String(e.id) === String(staffId));
  if (!s) return;
  const empShifts  = _shifts.filter(sh => (sh.manager || sh.staff_name) === s.name || (sh.emp_id || sh.empId || sh.staff_id) === s.id);
  const totalInc   = empShifts.reduce((a, sh) => a + parseFloat(sh.total_income || sh.income || 0), 0);
  const bonusPct   = parseFloat(s.bonus_pct || 0.1);
  const base       = parseFloat(s.salary || 0);
  const bonus      = Math.round(totalInc * bonusPct * 100) / 100;
  const total      = base + bonus;
  const amtEl = document.getElementById('pay-amount');
  const noteEl = document.getElementById('pay-note');
  if (amtEl)  amtEl.value  = total;
  if (noteEl) noteEl.value = 'Оклад ' + fmt(base) + ' + бонус ' + Math.round(bonusPct * 100) + '% с ' + fmt(totalInc) + ' = ' + fmt(total) + ' ₽';
  ntf('🧮 Итого: ' + fmt(total) + ' ₽', 'info');
};

// ── Регистрация модуля ────────────────────────────────────
window.CRM.registerModule({
  id:    'staff',
  name:  'Сотрудники',
  icon:  '👥',
  color: '#7c3aed',
  order: 6,

  async init(container) {
    _el = container;
    injectCSS();
    _el.innerHTML =
      '<div style="display:flex;flex-direction:column;align-items:center;justify-content:center;height:300px;gap:16px">' +
        '<div style="width:64px;height:64px;border-radius:20px;background:linear-gradient(135deg,#7c3aed,#6d28d9);display:flex;align-items:center;justify-content:center;font-size:28px;box-shadow:0 8px 32px #7c3aed44">👥</div>' +
        '<div style="font-size:15px;color:#94a3b8;font-weight:500">Загрузка команды...</div>' +
        '<div style="width:200px;height:3px;background:rgba(255,255,255,0.06);border-radius:2px;overflow:hidden">' +
          '<div style="height:100%;background:linear-gradient(90deg,#7c3aed,#06b6d4);border-radius:2px;animation:stf-load 1s infinite alternate"></div>' +
        '</div>' +
      '</div>';
    await loadAll();
    _view = 'staff';
    render();
  },

  async refresh() {
    await loadAll();
    render();
  },
});

})();
</script>