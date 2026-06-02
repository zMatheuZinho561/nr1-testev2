// ========================================================
// 1. CARREGAMENTO DO TEMA E INICIALIZAÇÃO
// ========================================================

document.addEventListener('DOMContentLoaded', async () => {
  // Pega o parâmetro ?cliente=XXXX da URL para carregar o tema
  const urlParams = new URLSearchParams(window.location.search);
  const clienteId = urlParams.get('cliente') || 'padrao';

  try {
    // Faz a chamada para a nossa API dinâmica do PHP
    const response = await fetch(`/api/theme.php?cliente=${clienteId}`);
    
    if (!response.ok) {
      throw new Error(`Erro no servidor: Status ${response.status}`);
    }

    const data = await response.json();

    if (data.success) {
      // Injeta as variáveis CSS dinamicamente na página
      const root = document.documentElement;
      root.style.setProperty('--primary', data.tema.primary);
      root.style.setProperty('--primary-dark', data.tema.primary_dark);
      root.style.setProperty('--primary-light', data.tema.primary_light);
      root.style.setProperty('--primary-focus-shadow', data.tema.primary_focus_shadow);

      // Preenche os campos ocultos do formulário HTML
      document.getElementById('empresa_id').value = clienteId;
      document.getElementById('cnpj_empresa').value = data.cnpj;
      
      console.log("Tema aplicado com sucesso para:", data.nome);
    } else {
      console.error('Erro retornado pela API de Temas:', data.message);
    }

  } catch (error) {
    console.error('Erro crítico ao carregar o tema personalizado:', error);
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

// Delegação de eventos para os botões de rádio (Anônimo / Identificado)
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
// 4. ENVIO DO FORMULÁRIO VIA AJAX (FETCH)
// ========================================================

document.getElementById('nr1-form').addEventListener('submit', async function(e) {
  e.preventDefault();

  if (!document.getElementById('termos').checked) {
    alert('Você precisa aceitar os termos antes de prosseguir.');
    return;
  }

  // Trava o botão de envio para evitar duplo clique
  const submitBtn = this.querySelector('.submit-btn');
  submitBtn.disabled = true;
  submitBtn.innerHTML = '<i class="ti ti-loader animate-spin"></i> Enviando...';

  const formData = new FormData();
  
  // Captura o ID do cliente atual na tela
  const idEmpresa = document.getElementById('empresa_id').value;
  
  // Vincula as chaves que o submit.php precisa ler dinamicamente
  formData.append('empresa_id', idEmpresa);
  formData.append('empresa_token', idEmpresa); 
  formData.append('cnpj_empresa', document.getElementById('cnpj_empresa').value);
  
  // Captura dos demais campos
  formData.append('identificacao', document.querySelector('input[name="identificacao"]:checked').value);
  formData.append('nome', document.getElementById('nome').value || 'Anônimo');
  formData.append('contato', document.getElementById('contato').value || 'Não informado');
  formData.append('categoria', document.getElementById('categoria').value);
  formData.append('local', document.getElementById('local').value);
  formData.append('urgencia', document.querySelector('input[name="urgencia"]:checked').value);
  formData.append('descricao', document.getElementById('descricao').value);

  // Captura da foto/evidência anexada
  const fotoInput = document.getElementById('foto');
  if (fotoInput && fotoInput.files.length > 0) {
    formData.append('foto', fotoInput.files[0]);
  }

  try {
    const response = await fetch('/api/submit.php', {
      method: 'POST',
      body: formData
    });

    if (!response.ok) {
      throw new Error(`Erro na rede: Status ${response.status}`);
    }

    const result = await response.json();

    if (result.success) {
      // Oculta o formulário principal e banners auxiliares
      document.getElementById('nr1-form').style.display = 'none';
      const alertBanner = document.getElementById('alert-banner');
      if (alertBanner) alertBanner.style.display = 'none';
      
      // Exibe a tela de sucesso com o número do protocolo gerado
      const successState = document.getElementById('success-state');
      document.getElementById('protocol-code').textContent = 'Protocolo: ' + result.protocolo;
      successState.style.display = 'flex'; 
    } else {
      alert('Erro ao enviar relato: ' + result.message);
      // Destrava o botão em caso de erro retornado pela API
      submitBtn.disabled = false;
      submitBtn.innerHTML = '<i class="ti ti-send"></i> Protocolar relato de segurança';
    }
  } catch (error) {
    console.error('Erro na requisição de envio:', error);
    alert('Erro de conexão com o servidor ao tentar protocolar.');
    // Destrava o botão em caso de queda de conexão
    submitBtn.disabled = false;
    submitBtn.innerHTML = '<i class="ti ti-send"></i> Protocolar relato de segurança';
  }
});

// ========================================================
// 5. RESET E RETORNO DO FORMULÁRIO (NOVO RELATO)
// ========================================================

function resetForm() {
  // 1. Limpa todas as informações preenchidas nos inputs e textareas
  document.getElementById('nr1-form').reset();
  
  // 2. ✨ CORREÇÃO DO BUG: Localiza o botão de enviar, reativa ele e volta o texto padrão
  const submitBtn = document.querySelector('#nr1-form .submit-btn');
  if (submitBtn) {
    submitBtn.disabled = false;
    submitBtn.innerHTML = '<i class="ti ti-send"></i> Protocolar relato de segurança';
  }
  
  // 3. Alterna a exibição visual voltando para o formulário limpo
  document.getElementById('nr1-form').style.display = 'block';
  document.getElementById('success-state').style.display = 'none';
  
  // 4. Limpa os avisos de uploads anteriores e contadores
  const nomeArquivo = document.getElementById('nome-arquivo');
  if (nomeArquivo) nomeArquivo.style.display = 'none';
  
  const charCount = document.getElementById('char-count');
  if (charCount) charCount.textContent = '0 / 1000';
  
  // 5. Reseta os campos visuais de identidade de volta para o modo Anônimo obrigatório
  updateIdentityFields();
}