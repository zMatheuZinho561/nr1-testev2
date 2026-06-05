document.addEventListener('DOMContentLoaded', async () => {
  const urlParams = new URLSearchParams(window.location.search);
  const clienteId = urlParams.get('cliente') || 'padrao';

  try {
    const response = await fetch(`/api/theme.php?cliente=${clienteId}`);
    if (!response.ok) throw new Error(`Status ${response.status}`);
    const data = await response.json();
    if (data.success) {
      document.getElementById('empresa_id').value = clienteId;
      document.getElementById('cnpj_empresa').value = data.cnpj;
    }
  } catch (e) { console.warn('Tema não carregado:', e); }

  const saved = localStorage.getItem('nr1-theme');
  if (saved === 'light') {
    document.documentElement.classList.remove('dark');
    document.getElementById('theme-icon').className = 'ti ti-sun text-base';
  }

  updateIdentityFields();
  setupUpload();
});

// ── Tema ──────────────────────────────────────────────
document.getElementById('theme-btn').addEventListener('click', () => {
  const html = document.documentElement;
  const icon = document.getElementById('theme-icon');
  const dark = html.classList.toggle('dark');
  icon.className = dark ? 'ti ti-moon text-base' : 'ti ti-sun text-base';
  localStorage.setItem('nr1-theme', dark ? 'dark' : 'light');
});

// ── Identificação ─────────────────────────────────────
function updateIdentityFields() {
  const wrap = document.getElementById('identity-wrap');
  const sel  = document.querySelector('[name="identificacao"]:checked');
  if (!wrap || !sel) return;
  const isId = sel.value === 'identificado';
  wrap.classList.toggle('open', isId);
  const nome    = document.getElementById('nome');
  const contato = document.getElementById('contato');
  if (nome)    { nome.required    = isId; if (!isId) nome.value    = ''; }
  if (contato) { contato.required = isId; if (!isId) contato.value = ''; }
}

document.addEventListener('change', e => {
  if (e.target?.name === 'identificacao') updateIdentityFields();
  if (e.target?.id === 'categoria') {
    const banner = document.getElementById('alert-banner');
    if (banner) banner.style.display = e.target.value === 'grave_iminente' ? 'flex' : 'none';
  }
});

// ── Contador ──────────────────────────────────────────
document.addEventListener('input', e => {
  if (e.target?.id !== 'descricao') return;
  const n = e.target.value.length;
  const el = document.getElementById('char-count');
  if (!el) return;
  el.textContent = `${n} / 1000`;
  el.className = n >= 1000
    ? 'text-right text-[11px] font-mono text-red-500'
    : n >= 900
    ? 'text-right text-[11px] font-mono text-amber-500'
    : 'text-right text-[11px] text-gray-400 font-mono';
});

// ── Upload ────────────────────────────────────────────
function setupUpload() {
  const zone  = document.getElementById('upload-zone');
  const input = document.getElementById('foto');
  const disp  = document.getElementById('nome-arquivo');
  if (!zone || !input || !disp) return;

  zone.addEventListener('click', () => input.click());

  function showFile(file) {
    disp.textContent = '✓ ' + file.name;
    disp.classList.remove('hidden');
    zone.style.borderStyle = 'solid';
    zone.style.borderColor = '#10B981';
  }

  input.addEventListener('change', () => {
    if (input.files?.[0]) showFile(input.files[0]);
    else { disp.classList.add('hidden'); zone.style.borderStyle = ''; zone.style.borderColor = ''; }
  });

  zone.addEventListener('dragover', e => { e.preventDefault(); zone.style.borderColor = '#E53E3E'; });
  zone.addEventListener('dragleave', () => { zone.style.borderColor = ''; });
  zone.addEventListener('drop', e => {
    e.preventDefault();
    zone.style.borderColor = '';
    const file = e.dataTransfer.files[0];
    if (file?.type.startsWith('image/')) {
      const dt = new DataTransfer(); dt.items.add(file); input.files = dt.files;
      showFile(file);
    }
  });
}

// ── Envio ─────────────────────────────────────────────
document.getElementById('nr1-form').addEventListener('submit', async function(e) {
  e.preventDefault();

  if (!document.getElementById('termos').checked) {
    const row = document.getElementById('consent-row');
    row.classList.add('shake');
    setTimeout(() => row.classList.remove('shake'), 400);
    return;
  }

  const btn     = document.getElementById('submit-btn');
  const content = document.getElementById('btn-content');
  const loading = document.getElementById('btn-loading');
  btn.disabled = true;
  content.classList.add('hidden');
  loading.classList.remove('hidden');

  const fd = new FormData();
  const id = document.getElementById('empresa_id').value;
  fd.append('empresa_id',    id);
  fd.append('empresa_token', id);
  fd.append('cnpj_empresa',  document.getElementById('cnpj_empresa').value);
  fd.append('identificacao', document.querySelector('[name="identificacao"]:checked').value);
  fd.append('nome',          document.getElementById('nome').value || 'Anônimo');
  fd.append('contato',       document.getElementById('contato').value || 'Não informado');
  fd.append('categoria',     document.getElementById('categoria').value);
  fd.append('local',         document.getElementById('local').value);
  fd.append('urgencia',      document.querySelector('[name="urgencia"]:checked').value);
  fd.append('descricao',     document.getElementById('descricao').value);
  const foto = document.getElementById('foto');
  if (foto?.files.length) fd.append('foto', foto.files[0]);

  try {
    const res = await fetch('/api/submit.php', { method: 'POST', body: fd });
    if (!res.ok) throw new Error(`Status ${res.status}`);
    const result = await res.json();
    if (result.success) {
      document.getElementById('nr1-form').style.display = 'none';
      document.getElementById('alert-banner').style.display = 'none';
      const ss = document.getElementById('success-state');
      document.getElementById('protocol-code').textContent = 'Protocolo: ' + result.protocolo;
      ss.classList.remove('success-hidden');
      ss.classList.add('success-visible');
      launchParticles();
      if (result.upload_debug) {
        console.log('📸 Upload debug:', JSON.stringify(result.upload_debug, null, 2));
        if (result.upload_debug.error) {
          console.error('❌ Upload error:', result.upload_debug.error);
        }
      }
    } else {
      alert('Erro: ' + result.message);
      resetBtn();
    }
  } catch (err) {
    console.error(err);
    alert('Erro de conexão.');
    resetBtn();
  }
});

function resetBtn() {
  const btn     = document.getElementById('submit-btn');
  const content = document.getElementById('btn-content');
  const loading = document.getElementById('btn-loading');
  btn.disabled = false;
  content.classList.remove('hidden');
  loading.classList.add('hidden');
}

// ── Partículas ────────────────────────────────────────
function launchParticles() {
  const c = document.getElementById('success-particles');
  if (!c) return;
  const cols = ['#10B981','#34D399','#6EE7B7','#F59E0B','#FBBF24','#E53E3E','#FCA5A5'];
  for (let i = 0; i < 24; i++) {
    const p = document.createElement('div');
    const size = Math.random() * 8 + 5;
    p.className = 'particle';
    p.style.cssText = `
      width:${size}px;height:${size}px;
      left:${Math.random()*100}%;bottom:30%;
      background:${cols[Math.floor(Math.random()*cols.length)]};
      --tx:${(Math.random()-.5)*90}px;
      --dur:${Math.random()*.6+.7}s;
      --delay:${Math.random()*.5}s;
      border-radius:${Math.random()>.5?'50%':'3px'};
    `;
    c.appendChild(p);
  }
  setTimeout(() => c.innerHTML = '', 2200);
}

// ── Reset ─────────────────────────────────────────────
function resetForm() {
  document.getElementById('nr1-form').reset();
  resetBtn();
  document.getElementById('nr1-form').style.display = 'block';
  const ss = document.getElementById('success-state');
  ss.classList.remove('success-visible');
  ss.classList.add('success-hidden');

  const arq = document.getElementById('nome-arquivo');
  if (arq) { arq.classList.add('hidden'); arq.textContent = ''; }
  const zone = document.getElementById('upload-zone');
  if (zone) { zone.style.borderStyle = ''; zone.style.borderColor = ''; }

  const cc = document.getElementById('char-count');
  if (cc) { cc.textContent = '0 / 1000'; cc.className = 'text-right text-[11px] text-gray-400 font-mono'; }

  updateIdentityFields();
  window.scrollTo({ top: 0, behavior: 'smooth' });
}