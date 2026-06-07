<?php
// migrate2.php — прямая запись в SQLite, без curl, без API
$pass = $_GET['pass'] ?? '';
if ($pass !== 'migrate2026') die('нет доступа');

set_time_limit(300);
ini_set('memory_limit', '256M');

$dbPath = __DIR__ . '/data/printcrm.sqlite';
$pdo = new PDO('sqlite:' . $dbPath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA journal_mode = WAL');
$pdo->exec('PRAGMA synchronous = OFF');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');

    $raw  = file_get_contents('php://input');
    $data = json_decode($raw, true);

    if (!$data) {
        echo json_encode(['ok' => false, 'error' => 'Нет данных: ' . json_last_error_msg()]);
        exit;
    }

    $section = $data['section'] ?? '';
    $items   = $data['items']   ?? [];
    $ok      = 0;
    $err     = 0;

    $pdo->beginTransaction();
    try {
        foreach ($items as $item) {
            try {
                if ($section === 'orders') {
                    $pdo->prepare('INSERT INTO orders (num,client,phone,date,deadline,manager,service,service_label,size,status,payment,total,prepay,comment,options) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                        ->execute([
                            $item['num'] ?? '',
                            $item['client'] ?? '',
                            $item['phone'] ?? '',
                            $item['date'] ?? '',
                            $item['deadline'] ?? '',
                            $item['manager'] ?? '',
                            $item['service'] ?? 'other',
                            $item['serviceLabel'] ?? $item['service_label'] ?? '',
                            $item['size'] ?? '',
                            mapStatus($item['status'] ?? 'new'),
                            mapPayment($item['payment'] ?? 'cash'),
                            intval($item['total'] ?? 0),
                            intval($item['prepay'] ?? 0),
                            $item['comment'] ?? '',
                            buildOptions($item),
                        ]);
                }
                elseif ($section === 'clients') {
                    $name = $item['name'] ?? $item['client'] ?? '';
                    if (!$name) { $err++; continue; }
                    $pdo->prepare('INSERT INTO clients (name,phone,email,bizcat,address,comment) VALUES (?,?,?,?,?,?)')
                        ->execute([
                            $name,
                            $item['phone'] ?? '',
                            $item['email'] ?? '',
                            $item['bizcat'] ?? $item['category'] ?? '',
                            $item['address'] ?? '',
                            $item['comment'] ?? $item['note'] ?? '',
                        ]);
                }
                elseif ($section === 'finance') {
                    $pdo->prepare('INSERT INTO finance (type,amount,category,desc,method,date) VALUES (?,?,?,?,?,?)')
                        ->execute([
                            mapType($item['type'] ?? 'income'),
                            intval($item['amount'] ?? $item['sum'] ?? 0),
                            $item['category'] ?? $item['cat'] ?? '',
                            $item['desc'] ?? $item['description'] ?? $item['comment'] ?? '',
                            $item['method'] ?? $item['payment'] ?? 'cash',
                            $item['date'] ?? date('Y-m-d'),
                        ]);
                }
                elseif ($section === 'warehouse') {
                    if (empty($item['name'])) { $err++; continue; }
                    $pdo->prepare('INSERT INTO warehouse (name,category,qty,unit,min_qty,price,comment) VALUES (?,?,?,?,?,?,?)')
                        ->execute([
                            $item['name'],
                            $item['category'] ?? '',
                            floatval($item['qty'] ?? $item['quantity'] ?? 0),
                            $item['unit'] ?? 'шт',
                            floatval($item['min_qty'] ?? $item['minQty'] ?? 0),
                            floatval($item['price'] ?? 0),
                            $item['comment'] ?? '',
                        ]);
                }
                elseif ($section === 'staff') {
                    if (empty($item['name'])) { $err++; continue; }
                    $pdo->prepare('INSERT INTO staff (name,role,phone,pin,salary,bonus_pct,active,comment) VALUES (?,?,?,?,?,?,?,?)')
                        ->execute([
                            $item['name'],
                            $item['role'] ?? $item['position'] ?? '',
                            $item['phone'] ?? '',
                            $item['pin'] ?? '',
                            floatval($item['salary'] ?? $item['oklad'] ?? 0),
                            floatval($item['bonus_pct'] ?? $item['bonusPct'] ?? 0),
                            isset($item['active']) ? intval($item['active']) : 1,
                            $item['comment'] ?? '',
                        ]);
                }
                elseif ($section === 'notes') {
                    if (empty($item['text']) && empty($item['content'])) { $err++; continue; }
                    $pdo->prepare('INSERT INTO notes (text,color,date,author) VALUES (?,?,?,?)')
                        ->execute([
                            $item['text'] ?? $item['content'] ?? '',
                            $item['color'] ?? 'yellow',
                            $item['date'] ?? date('Y-m-d'),
                            $item['author'] ?? $item['manager'] ?? '',
                        ]);
                }
                elseif ($section === 'calendar') {
                    $title = $item['title'] ?? $item['name'] ?? '';
                    if (!$title) { $err++; continue; }
                    $pdo->prepare('INSERT INTO cal_events (title,date,time,color,type,comment) VALUES (?,?,?,?,?,?)')
                        ->execute([
                            $title,
                            $item['date'] ?? '',
                            $item['time'] ?? '',
                            $item['color'] ?? 'blue',
                            $item['type'] ?? 'task',
                            $item['comment'] ?? '',
                        ]);
                }
                elseif ($section === 'debts') {
                    $client = $item['client'] ?? $item['name'] ?? '';
                    if (!$client) { $err++; continue; }
                    $pdo->prepare('INSERT INTO debts (client,phone,amount,paid,direction,desc,date,due_date,status) VALUES (?,?,?,?,?,?,?,?,?)')
                        ->execute([
                            $client,
                            $item['phone'] ?? '',
                            floatval($item['amount'] ?? $item['sum'] ?? 0),
                            floatval($item['paid'] ?? 0),
                            $item['direction'] ?? $item['type'] ?? 'they_owe',
                            $item['desc'] ?? $item['comment'] ?? '',
                            $item['date'] ?? date('Y-m-d'),
                            $item['due_date'] ?? $item['dueDate'] ?? '',
                            $item['status'] ?? 'active',
                        ]);
                }
                elseif ($section === 'briefs') {
                    $pdo->prepare('INSERT INTO briefs (client,phone,service,desc,status,date,manager,total) VALUES (?,?,?,?,?,?,?,?)')
                        ->execute([
                            $item['client'] ?? '',
                            $item['phone'] ?? '',
                            $item['service'] ?? '',
                            $item['desc'] ?? $item['description'] ?? $item['text'] ?? '',
                            $item['status'] ?? 'new',
                            $item['date'] ?? date('Y-m-d'),
                            $item['manager'] ?? '',
                            floatval($item['total'] ?? 0),
                        ]);
                }
                elseif ($section === 'pricelist') {
                    if (empty($item['name'])) { $err++; continue; }
                    $pdo->prepare('INSERT INTO pricelist (name,category,price,unit,desc,active) VALUES (?,?,?,?,?,?)')
                        ->execute([
                            $item['name'],
                            $item['category'] ?? $item['cat'] ?? '',
                            floatval($item['price'] ?? 0),
                            $item['unit'] ?? 'шт',
                            $item['desc'] ?? $item['description'] ?? '',
                            isset($item['active']) ? intval($item['active']) : 1,
                        ]);
                }
                elseif ($section === 'savings') {
                    $name = $item['name'] ?? $item['title'] ?? '';
                    if (!$name) { $err++; continue; }
                    $pdo->prepare('INSERT INTO savings (name,goal,current,color,icon,desc,status) VALUES (?,?,?,?,?,?,?)')
                        ->execute([
                            $name,
                            floatval($item['goal'] ?? $item['target'] ?? 0),
                            floatval($item['current'] ?? $item['amount'] ?? 0),
                            $item['color'] ?? '#7c3aed',
                            $item['icon'] ?? '🐷',
                            $item['desc'] ?? $item['comment'] ?? '',
                            $item['status'] ?? 'active',
                        ]);
                }
                elseif ($section === 'delivery') {
                    $pdo->prepare('INSERT INTO delivery (client,phone,address,courier,status,price,date,comment) VALUES (?,?,?,?,?,?,?,?)')
                        ->execute([
                            $item['client'] ?? '',
                            $item['phone'] ?? '',
                            $item['address'] ?? '',
                            $item['courier'] ?? '',
                            $item['status'] ?? 'pending',
                            floatval($item['price'] ?? 0),
                            $item['date'] ?? date('Y-m-d'),
                            $item['comment'] ?? '',
                        ]);
                }
                elseif ($section === 'settings') {
                    foreach ($item as $k => $v) {
                        $pdo->prepare('INSERT OR REPLACE INTO settings (key,value,updated_at) VALUES (?,?,datetime("now"))')
                            ->execute([$k, is_array($v) ? json_encode($v) : $v]);
                    }
                }
                $ok++;
            } catch (Exception $e) {
                $err++;
            }
        }
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        exit;
    }

    echo json_encode(['ok' => true, 'ok_count' => $ok, 'err_count' => $err]);
    exit;
}

function mapStatus($s) {
    $m = ['new'=>'new','work'=>'work','in_work'=>'work','ready'=>'ready','done'=>'done','cancel'=>'cancel','cancelled'=>'cancel'];
    return $m[strtolower($s)] ?? 'new';
}
function mapPayment($p) {
    $m = ['Наличные'=>'cash','Карта'=>'card','Безнал'=>'card','Предоплата'=>'prepay','В кредит'=>'credit','СБП'=>'sbp'];
    return $m[$p] ?? $p ?? 'cash';
}
function mapType($t) {
    $m = ['income'=>'income','expense'=>'expense','in'=>'income','out'=>'expense','+'=>'income','-'=>'expense'];
    return $m[strtolower($t)] ?? 'income';
}
function buildOptions($o) {
    $keys = ['photo_size','photo_qty','photo_material','photo_price','des_revisions','des_price','des_format','other_desc','other_qty','other_price','banner_width','banner_height','source','bizcat'];
    $opts = [];
    foreach ($keys as $k) {
        if (isset($o[$k]) && $o[$k] !== '' && $o[$k] !== null) $opts[$k] = $o[$k];
    }
    return $opts ? json_encode($opts, JSON_UNESCAPED_UNICODE) : '';
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<title>Миграция v2</title>
<style>
* { box-sizing:border-box; margin:0; padding:0; }
body { background:#0d0f1a; color:#e2e8f0; font-family:-apple-system,sans-serif; padding:24px; }
.wrap { max-width:680px; margin:0 auto; }
h1 { color:#7c3aed; margin-bottom:6px; }
.sub { color:#64748b; font-size:.9rem; margin-bottom:24px; }
.card { background:#141828; border:1px solid #1e2a3a; border-radius:12px; padding:20px; margin-bottom:16px; }
.drop { border:2px dashed #2d3a52; border-radius:10px; padding:40px; text-align:center; cursor:pointer; }
.drop:hover { border-color:#7c3aed; background:#130d2e; }
.drop .ic { font-size:2.5rem; margin-bottom:8px; }
.drop p { color:#64748b; }
.drop p b { color:#e2e8f0; }
input[type=file] { display:none; }
.grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(120px,1fr)); gap:8px; margin-top:12px; }
.gi { background:#0d0f1a; border:1px solid #1e2a3a; border-radius:8px; padding:10px; text-align:center; }
.gi .n { font-size:1.6rem; font-weight:700; color:#7c3aed; }
.gi .l { font-size:.72rem; color:#64748b; margin-top:2px; }
.btn { padding:12px 28px; border-radius:8px; border:none; cursor:pointer; font-size:1rem; font-weight:600; }
.btn-p { background:#7c3aed; color:#fff; }
.btn-p:hover:not(:disabled) { background:#6d28d9; }
.btn-p:disabled { background:#3a2a6a; color:#7c3aed; cursor:not-allowed; }
.btn-g { background:#10b981; color:#fff; }
.prog { height:8px; background:#1e2a3a; border-radius:4px; margin:12px 0; overflow:hidden; }
.pb { height:100%; background:linear-gradient(90deg,#7c3aed,#06b6d4); border-radius:4px; transition:width .3s; width:0; }
.log { background:#060810; border:1px solid #1e2a3a; border-radius:8px; padding:14px; font-family:monospace; font-size:.8rem; height:260px; overflow-y:auto; white-space:pre-wrap; }
.ok { color:#10b981; } .er { color:#ef4444; } .inf { color:#06b6d4; } .di { color:#475569; }
.warn { background:#2d1a00; border:1px solid #f59e0b44; border-radius:8px; padding:10px 14px; color:#f59e0b; font-size:.83rem; margin-bottom:12px; }
.srow { display:flex; justify-content:space-between; padding:5px 0; border-bottom:1px solid #1e2a3a; font-size:.85rem; }
.srow:last-child { border:none; }
.hidden { display:none; }
.fn { color:#10b981; font-size:.85rem; font-family:monospace; margin-top:8px; }
</style>
</head>
<body>
<div class="wrap">
<h1>🚀 Миграция → PrintCRM v3.0</h1>
<p class="sub">Прямая запись в SQLite · Без API · Быстро</p>

<div class="warn">⚠️ БД должна быть чистая. Таблицы уже созданы через setup2.php ✅</div>

<div class="card">
  <h2 style="color:#94a3b8;margin-bottom:12px;">📁 Загрузи JSON из старой CRM</h2>
  <div class="drop" onclick="document.getElementById('fi').click()">
    <div class="ic">📂</div>
    <p><b>Нажми или перетащи JSON</b></p>
    <p>Настройки → База данных → Экспорт JSON</p>
  </div>
  <input type="file" id="fi" accept=".json">
  <div id="fn" class="fn hidden"></div>
</div>

<div class="card hidden" id="s2">
  <h2 style="color:#94a3b8;margin-bottom:12px;">🔍 Найдено в файле</h2>
  <div class="grid" id="grid"></div>
  <p style="color:#64748b;font-size:.82rem;margin-top:10px;" id="ptotal"></p>
</div>

<div class="card hidden" id="s3">
  <h2 style="color:#94a3b8;margin-bottom:12px;">📊 Прогресс</h2>
  <div id="slist"></div>
  <div class="prog"><div class="pb" id="pb"></div></div>
  <div style="color:#64748b;font-size:.8rem;" id="ptext">0%</div>
</div>

<div class="card hidden" id="s4">
  <h2 style="color:#94a3b8;margin-bottom:12px;">⚡ Запуск</h2>
  <div class="warn">Данные добавятся к существующим!</div>
  <button class="btn btn-p" id="btnM" onclick="go()">🚀 Запустить миграцию</button>
  <div class="log" id="log" style="margin-top:14px;"><span class="di">Лог появится здесь...</span></div>
  <div id="rb" class="hidden" style="margin-top:12px;"></div>
</div>
</div>

<script>
'use strict';
let jdata = null, sections = [];
const CHUNK = 50;
const LABELS = {orders:'Заказы',clients:'Клиенты',finance:'Финансы',warehouse:'Склад',notes:'Заметки',calendar:'Календарь',calEvents:'Календарь',staff:'Сотрудники',debts:'Долги',briefs:'Брифы',pricelist:'Прайс',savings:'Копилка',delivery:'Доставка'};
const ICONS  = {orders:'📋',clients:'👥',finance:'💰',warehouse:'📦',notes:'📝',calendar:'📅',calEvents:'📅',staff:'👔',debts:'📒',briefs:'🗒️',pricelist:'🏷️',savings:'🐷',delivery:'🚗'};

const dz = document.querySelector('.drop');
dz.addEventListener('dragover', e=>{e.preventDefault();dz.style.borderColor='#7c3aed';});
dz.addEventListener('dragleave', ()=>dz.style.borderColor='');
dz.addEventListener('drop', e=>{e.preventDefault();dz.style.borderColor='';if(e.dataTransfer.files[0])load(e.dataTransfer.files[0]);});
document.getElementById('fi').addEventListener('change', e=>{if(e.target.files[0])load(e.target.files[0]);});

function load(f) {
  document.getElementById('fn').textContent='📄 '+f.name+' ('+(f.size/1024).toFixed(1)+' KB)';
  document.getElementById('fn').classList.remove('hidden');
  const r = new FileReader();
  r.onload = e => {
    try { jdata = JSON.parse(e.target.result); }
    catch(err) { alert('Невалидный JSON: '+err.message); return; }
    preview();
  };
  r.readAsText(f,'utf-8');
}

function preview() {
  const keys = ['settings','orders','clients','finance','warehouse','notes','calendar','calEvents','staff','debts','briefs','pricelist','savings','delivery'];
  let total = 0, html = '';
  sections = [];
  for (const k of keys) {
    if (k === 'settings') {
      if (jdata.settings && typeof jdata.settings==='object') {
        sections.push({key:'settings', label:'Настройки', icon:'⚙️', items:[jdata.settings]});
      }
      continue;
    }
    const arr = jdata[k];
    if (!Array.isArray(arr) || !arr.length) continue;
    total += arr.length;
    html += '<div class="gi"><div class="n">'+arr.length+'</div><div class="l">'+(ICONS[k]||'📌')+' '+(LABELS[k]||k)+'</div></div>';
    sections.push({key:k, label:LABELS[k]||k, icon:ICONS[k]||'📌', items:arr});
  }
  document.getElementById('grid').innerHTML = html;
  document.getElementById('ptotal').textContent = 'Разделов: '+sections.length+' · Записей: '+total;
  document.getElementById('s2').classList.remove('hidden');
  document.getElementById('s3').classList.remove('hidden');
  document.getElementById('s4').classList.remove('hidden');
  document.getElementById('slist').innerHTML = sections.map(s=>
    '<div class="srow" id="sr_'+s.key+'"><span style="color:#94a3b8">'+s.icon+' '+s.label+'</span><span id="sv_'+s.key+'" style="color:#475569">'+s.items.length+' шт</span></div>'
  ).join('');
}

async function go() {
  document.getElementById('btnM').disabled = true;
  const log = document.getElementById('log');
  log.innerHTML = '<span class="inf">🚀 Старт...\n</span>';
  let doneS = 0, totalOk = 0, totalErr = 0;

  for (const sec of sections) {
    const sv = document.getElementById('sv_'+sec.key);
    if(sv) sv.textContent = '⏳ ...';
    addLog('inf','▶ '+sec.icon+' '+sec.label+' ('+sec.items.length+')');
    let sOk=0, sErr=0;
    const chunks=[];
    for(let i=0;i<sec.items.length;i+=CHUNK) chunks.push(sec.items.slice(i,i+CHUNK));
    for(let ci=0;ci<chunks.length;ci++) {
      try {
        const r = await fetch('?pass=migrate2026',{
          method:'POST',
          headers:{'Content-Type':'application/json'},
          body:JSON.stringify({section:sec.key, items:chunks[ci]})
        });
        if(!r.ok) throw new Error('HTTP '+r.status);
        const d = await r.json();
        sOk  += d.ok_count  || 0;
        sErr += d.err_count || 0;
      } catch(e) {
        sErr += chunks[ci].length;
        addLog('er','  ❌ '+e.message);
      }
      setP((doneS + (ci+1)/chunks.length) / sections.length * 100);
    }
    totalOk+=sOk; totalErr+=sErr; doneS++;
    setP(doneS/sections.length*100);
    if(sv){ sv.style.color=sErr?'#f59e0b':'#10b981'; sv.textContent=(sErr?'⚠️':'✅')+' '+sOk+' OK'+(sErr?' / '+sErr+' err':''); }
    addLog(sErr?'er':'ok','  → OK:'+sOk+' Ошибок:'+sErr);
  }

  addLog('ok','\n✅ ГОТОВО! Перенесено: '+totalOk+(totalErr?' | Ошибок: '+totalErr:''));
  setP(100);
  document.getElementById('rb').classList.remove('hidden');
  document.getElementById('rb').innerHTML='<a href="https://saitt.itmag.site/" style="display:inline-block;padding:12px 24px;background:#10b981;color:#fff;border-radius:8px;text-decoration:none;font-weight:600">🚀 Открыть PrintCRM</a> <span style="color:#64748b;font-size:.82rem;margin-left:12px;">Потом удали migrate2.php !</span>';
}

function addLog(c,t){ const log=document.getElementById('log'); const s=document.createElement('span'); s.className=c; s.textContent=t+'\n'; log.appendChild(s); log.scrollTop=log.scrollHeight; }
function setP(p){ document.getElementById('pb').style.width=Math.min(100,p).toFixed(1)+'%'; document.getElementById('ptext').textContent=Math.min(100,p).toFixed(0)+'%'; }
</script>
</body>
</html>