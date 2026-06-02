<?php
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Método não permitido']);
    exit;
}

// Lendo as variáveis cadastradas no painel do Vercel
$spreadsheetId       = getenv('GOOGLE_SHEET_ID');
$serviceAccountEmail = getenv('GOOGLE_SERVICE_ACCOUNT_EMAIL');
$privateKey          = getenv('GOOGLE_PRIVATE_KEY');
$driveFolderId       = getenv('GOOGLE_DRIVE_FOLDER_ID'); // Nova variável adicionada

if (empty($spreadsheetId) || empty($serviceAccountEmail) || empty($privateKey)) {
    echo json_encode(['success' => false, 'message' => 'Configurações do Google incompletas no Vercel.']);
    exit;
}

$privateKey = str_replace(['"', "'"], '', $privateKey);
$privateKey = str_replace('\n', "\n", $privateKey);

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
// GERAR TOKEN OAUTH2 MANUAL PARA CONTA DE SERVIÇO (PHP PURO)
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

$accessToken = getGoogleAccessToken($serviceAccountEmail, $privateKey);

if (!$accessToken) {
    echo json_encode(['success' => false, 'message' => 'Falha na autenticação com o Google.']);
    exit;
}

// ============================================================
// 1. ENVIAR FOTO PARA O GOOGLE DRIVE
// ============================================================
if (!empty($driveFolderId) && isset($_FILES['foto']) && $_FILES['foto']['error'] === UPLOAD_ERR_OK) {
    $fileTmpPath = $_FILES['foto']['tmp_name']; // Corrigido de tmp_path para tmp_name
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
    curl_setopt($ch, sprintf(CURLOPT_POST, true));
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
    } else {
        $link_foto = 'Erro ao fazer upload para o Drive: ' . ($resDriveJson['error']['message'] ?? 'Erro desconhecido');
    }
} elseif (empty($driveFolderId)) {
    $link_foto = 'Upload pulado: GOOGLE_DRIVE_FOLDER_ID não configurado no Vercel.';
}

// ============================================================
// 2. SALVAR DADOS NO GOOGLE SHEETS
// ============================================================
$values = [
    [$protocolo, $data_hora, 'Empresa de Teste Geral', $identificacao, $nome, $contato, $categoria, $local, $urgencia, $descricao, $link_foto]
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

echo json_encode([
    'success' => true,
    'protocolo' => $protocolo
]);