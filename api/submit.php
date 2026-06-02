<?php
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Método não permitido']);
    exit;
}

// 1. Tenta carregar o mapa de clientes (se o arquivo existir)
$configPath = __DIR__ . '/config_clientes.php';
$clientes = file_exists($configPath) ? require $configPath : [];

$empresa_id = $_POST['empresa_id'] ?? '';

// 2. Define o destino (Se achar o cliente no arquivo, usa ele. Se não achar, usa as variáveis do Vercel)
if (!empty($empresa_id) && isset($clientes[$empresa_id])) {
    $spreadsheetId  = $clientes[$empresa_id]['spreadsheet_id'];
    $driveFolderId  = $clientes[$empresa_id]['drive_folder_id'] ?? '';
    $emailRH        = $clientes[$empresa_id]['email_rh'] ?? '';
    $nomeEmpresa    = $clientes[$empresa_id]['nome'];
    
    // Para a conta de serviço funcionar no modo multi-empresa, ela precisa usar a chave privada global do Vercel
    $serviceAccountEmail = getenv('GOOGLE_SERVICE_ACCOUNT_EMAIL');
    $privateKey          = getenv('GOOGLE_PRIVATE_KEY');
} else {
    // FALLBACK: Se não achar a empresa, usa tudo direto do painel do Vercel (Perfeito para o seu teste atual)
    $spreadsheetId       = getenv('GOOGLE_SHEET_ID');
    $serviceAccountEmail = getenv('GOOGLE_SERVICE_ACCOUNT_EMAIL');
    $privateKey          = getenv('GOOGLE_PRIVATE_KEY');
    
    $driveFolderId       = ''; // Opcional no teste
    $emailRH             = ''; // Opcional no teste
    $nomeEmpresa         = 'Empresa de Teste Geral';
}

// 3. Validação de segurança das credenciais do Google
if (empty($spreadsheetId) || empty($serviceAccountEmail) || empty($privateKey)) {
    echo json_encode([
        'success' => false, 
        'message' => 'Configurações de credenciais do Google ausentes no painel do Vercel.'
    ]);
    exit;
}

// Tratamento crucial para quebras de linha da Private Key no ambiente serverless da Vercel
$privateKey = str_replace(['"', "'"], '', $privateKey);
$privateKey = str_replace('\n', "\n", $privateKey);

$resendApiKey = getenv('RESEND_API_KEY'); 

// Captura e saneamento básico dos dados do formulário
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
// FUNÇÃO INTERNA: GERAR TOKEN OAUTH2 VIA CONTA DE SERVIÇO
// ============================================================
function getGoogleAccessToken($email, $privateKey) {
    $header = json_encode(['alg' => 'RS256', 'typ' => 'JWT']);
    $t = time();
    $payload = json_encode([
        'iss' => $email,
        'scope' => 'https://www.googleapis.com/auth/spreadsheets https://www.googleapis.com/auth/drive.file',
        'aud' => 'https://oauth2.googleapis.com/token',
        'exp' => $t + 3600,
        'iat' => $t
    ]);

    $base64UrlHeader = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($header));
    $base64UrlPayload = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($payload));
    
    $signatureInput = $base64UrlHeader . "." . $base64UrlPayload;
    
    if (!openssl_sign($signatureInput, $signature, $privateKey, 'SHA256')) {
        return null;
    }
    
    $base64UrlSignature = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));
    $jwt = $signatureInput . "." . $base64UrlSignature;

    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
        'assertion' => $jwt
    ]));
    
    $response = curl_exec($ch);
    curl_close($ch);
    
    $data = json_decode($response, true);
    return $data['access_token'] ?? null;
}

// Solcita o Token de Acesso válido para o Google
$accessToken = getGoogleAccessToken($serviceAccountEmail, $privateKey);

if (!$accessToken) {
    echo json_encode([
        'success' => false,
        'message' => 'Falha na autenticação JWT com a Conta de Serviço do Google. Verifique sua GOOGLE_PRIVATE_KEY.'
    ]);
    exit;
}

// ============================================================
// 1. ENVIAR FOTO PARA O GOOGLE DRIVE (Apenas se houver ID da pasta e arquivo)
// ============================================================
if (!empty($driveFolderId) && isset($_FILES['foto']) && $_FILES['foto']['error'] === UPLOAD_ERR_OK) {
    $fileTmpPath = $_FILES['foto']['tmp_name'];
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
        'Authorization: Bearer ' . $accessToken,
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
// 2. SALVAR DADOS NO GOOGLE SHEETS (Seguro via Bearer Token)
// ============================================================
$values = [
    [$protocolo, $data_hora, $nomeEmpresa, $identificacao, $nome, $contato, $categoria, $local, $urgencia, $descricao, $link_foto]
];

$payload = ['values' => $values];
$urlSheets = "https://sheets.googleapis.com/v4/spreadsheets/{$spreadsheetId}/values/A1:append?valueInputOption=USER_ENTERED";

$ch = curl_init($urlSheets);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Authorization: Bearer ' . $accessToken
]);
$responseSheets = curl_exec($ch);
curl_close($ch);

// ============================================================
// 3. ENVIAR E-MAIL PARA O RH DA EMPRESA (Via API do Resend)
// ============================================================
if (!empty($resendApiKey) && !empty($emailRH)) {
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
        'from' => 'Canal NR-1 <relatos@seu-dominio-resend.com>', 
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
    curl_exec($ch);
    curl_close($ch);
}

// Retorna resposta definitiva de sucesso para o frontend
echo json_encode([
    'success' => true,
    'protocolo' => $protocolo
]);