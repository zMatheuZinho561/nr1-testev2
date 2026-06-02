// ========================================================
// 1. CARREGAMENTO DO TEMA E INICIALIZAÇÃO
// ========================================================

document.addEventListener('DOMContentLoaded', async () => {
  // Pega o parâmetro ?cliente=XXXX da URL para carregar o tema
  const urlParams = new URLSearchParams(window.location.search);
  const clienteId = urlParams.get('cliente') || 'padrao';

  try {
    const response = await fetch(`/api/theme.php?cliente=${clienteId}`);
    const data = await response.json();

    // Injeta as variáveis CSS dinamicamente
    const root = document.documentElement;
    root.style.setProperty('--primary', data.tema.primary);
    root.style.setProperty('--primary-dark', data.tema.primary_dark);
    root.style.setProperty('--primary-light', data.tema.primary_light);
    root.style.setProperty('--primary-focus-shadow', data.tema.primary_focus_shadow);

    // Preenche os campos ocultos de controle
    document.getElementById('empresa_id').value = clienteId;
    document.getElementById('cnpj_empresa').value = data.cnpj;
  } catch (error) {
    console.error('Erro ao carregar o tema personalizado:', error);
  }

  // Garante que os campos de identidade iniciem no estado correto (Anônimo)
  updateIdentityFields();
});

// ========================================================
// 2. ALTERNADOR DOS CAMPOS DE IDENTIFICAÇÃO
// ========================================================

function updateIdentityFields() {
  const identityWrap = document.getElementById('identity-wrap');
  const selected = document.querySelector('[name="identificacao"]:checked');
  
  if (!identityWrap || !selected) return;
  
  const nomeInput = document.getElementById('nome');
  const contatoInput = document.getElementById('contato');

  if (selected.value === 'identificado') {
    identityWrap.classList.add('open');
    if(nomeInput) nomeInput.required = true;
    if(contatoInput) contatoInput.required = true;
  } else {
    identityWrap.classList.remove('open');
    if(nomeInput) nomeInput.required = false;
    if(contatoInput) contatoInput.required = false;
  }
}

// Delegação de eventos para os botões de rádio
document.addEventListener('change', function(e) {
  if (e.target && e.target.name === 'identificacao') {
    updateIdentityFields();
  }
});

// ========================================================
// 3. CONTADOR DE CARACTERES & BANNER DE ALERTA
// ========================================================

// Monitora a seleção da categoria para exibir o Banner de Risco Grave
document.addEventListener('change', function(e) {
  if (e.target && e.target.id === 'categoria') {
    const banner = document.getElementById('alert-banner');
    if (banner) {
      banner.style.display = (e.target.value === 'grave_iminente') ? 'flex' : 'none';
    }
  }
});

// Monitora o campo de texto para atualizar o contador de caracteres
document.addEventListener('input', function(e) {
  if (e.target && e.target.id === 'descricao') {
    const charCount = document.getElementById('char-count');
    if (!charCount) return;

    const len = e.target.value.length;
    charCount.textContent = `${len} / 1000`;
    
    if (len >= 900 && len < 1000) {
      charCount.className = 'char-counter warn';
    } else if (len >= 1000) {
      charCount.className = 'char-counter over';
    } else {
      charCount.className = 'char-counter';
    }
  }
});

// ========================================================
// 4. ENVIO DO FORMULÁRIO VIA AJAX (FÉCH)
// ========================================================

document.getElementById('nr1-form').addEventListener('submit', async function(e) {
  e.preventDefault();

  if (!document.getElementById('termos').checked) {
    alert('Você precisa aceitar os termos antes de prosseguir.');
    return;
  }

  const submitBtn = this.querySelector('.submit-btn');
  submitBtn.disabled = true;
  submitBtn.innerHTML = '<i class="ti ti-loader animate-spin"></i> Enviando...';

  const formData = new FormData();
  
  formData.append('empresa_id', document.getElementById('empresa_id').value);
  formData.append('cnpj_empresa', document.getElementById('cnpj_empresa').value);
  formData.append('identificacao', document.querySelector('input[name="identificacao"]:checked').value);
  formData.append('nome', document.getElementById('nome').value || 'Anônimo');
  formData.append('contato', document.getElementById('contato').value || 'Não informado');
  formData.append('categoria', document.getElementById('categoria').value);
  formData.append('local', document.getElementById('local').value);
  formData.append('urgencia', document.querySelector('input[name="urgencia"]:checked').value);
  formData.append('descricao', document.getElementById('descricao').value);

  const fotoInput = document.getElementById('foto');
  if (fotoInput && fotoInput.files.length > 0) {
    formData.append('foto', fotoInput.files[0]);
  }

  try {
    const response = await fetch('/api/submit.php', {
      method: 'POST',
      body: formData
    });

    const result = await response.json();

    if (result.success) {
      document.getElementById('nr1-form').style.display = 'none';
      const alertBanner = document.getElementById('alert-banner');
      if (alertBanner) alertBanner.style.display = 'none';
      
      const successState = document.getElementById('success-state');
      document.getElementById('protocol-code').textContent = 'Protocolo: ' + result.protocolo;
      successState.style.display = 'flex'; // Mudado para flex condizente com seu CSS de centralização
    } else {
      alert('Erro ao enviar: ' + result.message);
      submitBtn.disabled = false;
      submitBtn.innerHTML = '<i class="ti ti-send"></i> Protocolar relato de segurança';
    }
  } catch (error) {
    console.error('Erro na requisição:', error);
    alert('Erro de conexão com o servidor.');
    submitBtn.disabled = false;
    submitBtn.innerHTML = '<i class="ti ti-send"></i> Protocolar relato de segurança';
  }
});

function resetForm() {
  document.getElementById('nr1-form').reset();
  document.getElementById('nr1-form').style.display = 'block';
  document.getElementById('success-state').style.display = 'none';
  
  const nomeArquivo = document.getElementById('nome-arquivo');
  if (nomeArquivo) nomeArquivo.style.display = 'none';
  
  const charCount = document.getElementById('char-count');
  if (charCount) charCount.textContent = '0 / 1000';
  
  updateIdentityFields();
}