// ========================================================
// 1. ALTERNADOR DOS CAMPOS DE IDENTIFICAÇÃO (CORRIGIDO)
// ========================================================

function updateIdentityFields() {
  const identityWrap = document.getElementById('identity-wrap');
  const selected = document.querySelector('[name="identificacao"]:checked');
  
  if (!identityWrap || !selected) return;
  
  // Adiciona/remove a classe '.open' do seu CSS para transição suave
  if (selected.value === 'identificado') {
    identityWrap.classList.add('open');
  } else {
    identityWrap.classList.remove('open');
  }
}

// Delegação global de eventos: ativa imediatamente a transição suave ao clicar
document.addEventListener('change', function(e) {
  if (e.target && e.target.name === 'identificacao') {
    updateIdentityFields();
  }
});

// Inicialização segura ao carregar a página
document.addEventListener('DOMContentLoaded', updateIdentityFields);
updateIdentityFields();

document.getElementById('nr1-form').addEventListener('submit', async function(e) {
  e.preventDefault();

  // Validação simples dos termos
  if (!document.getElementById('termos').checked) {
    alert('Você precisa aceitar os termos antes de prosseguir.');
    return;
  }

  const submitBtn = this.querySelector('.submit-btn');
  submitBtn.disabled = true;
  submitBtn.innerHTML = '<i class="ti ti-loader animate-spin"></i> Enviando...';

  const formData = new FormData();
  
  // Pegando dados dos campos normais
  formData.append('empresa_id', document.getElementById('empresa_id').value);
  formData.append('cnpj_empresa', document.getElementById('cnpj_empresa').value);
  formData.append('identificacao', document.querySelector('input[name="identificacao"]:checked').value);
  formData.append('nome', document.getElementById('nome').value || 'Anônimo');
  formData.append('contato', document.getElementById('contato').value || 'Não informado');
  formData.append('categoria', document.getElementById('categoria').value);
  formData.append('local', document.getElementById('local').value);
  formData.append('urgencia', document.querySelector('input[name="urgencia"]:checked').value);
  formData.append('descricao', document.getElementById('descricao').value);

  // Pegando o arquivo de imagem (se houver)
  const fotoInput = document.getElementById('foto');
  if (fotoInput.files.length > 0) {
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
      document.getElementById('alert-banner').style.display = 'none';
      
      const successState = document.getElementById('success-state');
      document.getElementById('protocol-code').textContent = 'Protocolo: ' + result.protocolo;
      successState.style.display = 'block';
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
  document.getElementById('nome-arquivo').style.display = 'none';
}
