<?php
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Método não permitido']);
    exit;
}

$clientes = require __DIR__ . '/config_clientes.php';
$empresa_id = $_POST['empresa_id'] ?? '';

if (!empty($empresa_id) && isset($clientes[$empresa_id])) {
    $spreadsheetId = $clientes[$empresa_id]['spreadsheet_id'];
    $driveFolderId = $clientes[$empresa_id]['drive_folder_id'];
    $emailRH       = $clientes[$empresa_id]['email_rh'];
    $nomeEmpresa   = $clientes[$empresa_id]['nome'];
} else {
    echo json_encode(['success' => false, 'message' => 'Empresa inválida ou não identificada.']);
    exit;
}

// Chaves globais vindas do .env do Vercel
$googleApiKey  = getenv('GOOGLE_API_KEY'); 
$resendApiKey  = getenv('RESEND_API_KEY'); // Chave de API do serviço de e-mail

// Captura dos dados do formulário
$identificacao = $_POST['identificacao'] ?? 'anonimo';
$nome          = ($identificacao === 'anonimo') ? 'Anônimo' : ($_POST['nome'] ?? 'Anônimo');
$contato       = ($identificacao === 'anonimo') ? 'Não informado' : ($_POST['contato'] ?? 'Não informado');
$categoria     = $_POST['categoria'] ?? '';
$local         = $_POST['local'] ?? '';
$urgencia      = $_POST['urgencia'] ?? 'media';
$descricao     = $_POST['descricao'] ?? '';
$protocolo     = 'NR1-' . strtoupper(uniqid());
$data_hora     = date('d/m/Y H:i:s');

$link_foto = 'Nenhuma foto enviada';

// ============================================================
// 1. ENVIAR FOTO PARA O GOOGLE DRIVE (Mesmo código anterior)
// ============================================================
if (isset($_FILES['foto']) && $_FILES['foto']['error'] === UPLOAD_ERR_OK) {
    $fileTmpPath = $_FILES['foto']['tmp_path'];
    $fileName    = $_FILES['foto']['name'];
    $fileType    = $_FILES['foto']['type'];
    
    $metadata = [
        'name' => 'Evidencia_' . $protocolo . '_' . $fileName,
        'parents' => [$driveFolderId]
    ];
    
    $boundary = '------------------------' . uniqid();
    $delimiter = "\r\n--" . $boundary . "\r\n";
    
    $postData = $delimiter
        . 'Content-Type: application/json; charset=UTF-8' . "\r\n\r\n"
        . json_encode($metadata) . $delimiter
        . 'Content-Type: ' . $fileType . "\r\n\r\n"
        . file_get_contents($fileTmpPath) . "\r\n--"
        . $boundary . "--\r\n";
        
    $ch = curl_init('https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: multipart/related; boundary=' . $boundary
    ]);
    
    $responseDrive = curl_exec($ch);
    curl_close($ch);
    
    $resDriveJson = json_decode($responseDrive, true);
    if (isset($resDriveJson['id'])) {
        $link_foto = 'https://drive.google.com/open?id=' . $resDriveJson['id'];
    }
}

// ============================================================
// 2. SALVAR DADOS NO GOOGLE SHEETS (Mesmo código anterior)
// ============================================================
$values = [
    [$protocolo, $data_hora, $nomeEmpresa, $identificacao, $nome, $contato, $categoria, $local, $urgencia, $descricao, $link_foto]
];

$payload = ['values' => $values];
$urlSheets = "https://sheets.googleapis.com/v4/spreadsheets/{$spreadsheetId}/values/A1:append?valueInputOption=USER_ENTERED&key={$googleApiKey}";

$ch = curl_init($urlSheets);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
$responseSheets = curl_exec($ch);
curl_close($ch);

// ============================================================
// 3. ENVIAR E-MAIL PARA O RH DA EMPRESA (Via API do Resend)
// ============================================================
if (!empty($resendApiKey)) {
    // Montando o corpo do e-mail formatado em HTML
    $emailHtml = "
    <h2>Novo Relato de Segurança Protocolado (NR-1)</h2>
    <p><strong>Protocolo:</strong> {$protocolo}</p>
    <p><strong>Data/Hora:</strong> {$data_hora}</p>
    <p><strong>Urgência:</strong> " . strtoupper($urgencia) . "</p>
    <hr>
    <p><strong>Identificação:</strong> " . ($identificacao === 'anonimo' ? 'Anônimo' : 'Identificado') . "</p>
    <p><strong>Nome do Relator:</strong> {$nome}</p>
    <p><strong>Contato fornecido:</strong> {$contato}</p>
    <p><strong>Natureza do Risco:</strong> {$categoria}</p>
    <p><strong>Local/Setor do Fato:</strong> {$local}</p>
    <hr>
    <p><strong>Descrição do ocorrido:</strong><br>" . nl2br(htmlspecialchars($descricao)) . "</p>
    <hr>
    <p><strong>Link da Evidência (Google Drive):</strong> <a href='{$link_foto}'>{$link_foto}</a></p>
    <br>
    <p><small>Este é um e-mail automático gerado pelo Canal de Relatos NR-1.</small></p>
    ";

    $emailData = [
        'from' => 'Canal NR-1 <relatos@seudominio.com>', // Seu domínio configurado no Resend
        'to' => [$emailRH],
        'subject' => "[Alerta {$urgencia}] Novo Relato SST - Protocolo {$protocolo}",
        'html' => $emailHtml
    ];

    $ch = curl_init('https://api.resend.com/emails');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($emailData));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $resendApiKey,
        'Content-Type: application/json'
    ]);
    
    // Dispara em background (não travamos a resposta se o envio do e-mail demorar um pouco)
    curl_exec($ch);
    curl_close($ch);
}

// Retorna o sucesso para o frontend JavaScript exibir a tela verde de sucesso
echo json_encode([
    'success' => true,
    'protocolo' => $protocolo
]);