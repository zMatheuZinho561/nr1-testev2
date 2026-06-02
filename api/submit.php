<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
$configPath = __DIR__ . '/config_clientes.php';
ini_set('display_errors', 0);
error_reporting(E_ALL);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Método não permitido']);
    exit;
}

try {
    // 1. Variáveis de Ambiente do Vercel
    $spreadsheetId       = getenv('GOOGLE_SHEET_ID');
    $serviceAccountEmail = getenv('GOOGLE_SERVICE_ACCOUNT_EMAIL');
    $privateKey          = getenv('GOOGLE_PRIVATE_KEY');
    $driveFolderId       = getenv('GOOGLE_DRIVE_FOLDER_ID');
    
    // Variáveis para o SMTP Puro do Gmail
    $smtpEmail           = getenv('SMTP_EMAIL'); 
    $smtpPassword        = getenv('SMTP_PASSWORD');
    $emailRH             = getenv('EMAIL_RH_DESTINO');

    if (empty($spreadsheetId) || empty($serviceAccountEmail) || empty($privateKey)) {
        echo json_encode(['success' => false, 'message' => 'Configurações do Google incompletas no Vercel.']);
        exit;
    }

    $privateKey = str_replace(['"', "'"], '', $privateKey);
    $privateKey = str_replace('\n', "\n", $privateKey);

    // 2. Captura dos dados do formulário
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
    // AUTENTICAÇÃO GOOGLE (OAUTH2 JWT)
    // ============================================================
    $header = json_encode(['alg' => 'RS256', 'typ' => 'JWT']);
    $t = time();
    $payload = json_encode([
        'iss' => $serviceAccountEmail,
        'scope' => 'https://www.googleapis.com/auth/spreadsheets https://www.googleapis.com/auth/drive',
        'aud' => 'https://oauth2.googleapis.com/token',
        'exp' => $t + 3600,
        'iat' => $t
    ]);

    $base64UrlHeader = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($header));
    $base64UrlPayload = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($payload));
    $signatureInput = $base64UrlHeader . "." . $base64UrlPayload;
    
    if (!openssl_sign($signatureInput, $signature, $privateKey, 'SHA256')) {
        throw new Exception('Falha ao assinar o Token JWT.');
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
    
    $tokenData = json_decode($response, true);
    $accessToken = $tokenData['access_token'] ?? null;

    if (!$accessToken) {
        throw new Exception('Erro na geração do Access Token.');
    }

    // ============================================================
    // 3. ENVIAR FOTO PARA O GOOGLE DRIVE
    // ============================================================
    if (!empty($driveFolderId) && isset($_FILES['foto']) && $_FILES['foto']['error'] === UPLOAD_ERR_OK) {
        $fileTmpPath = $_FILES['foto']['tmp_name'];
        $fileName    = $_FILES['foto']['name'];
        $fileType    = $_FILES['foto']['type'];
        
        $metadata = [
            'name' => 'Evidencia_' . $protocolo . '_' . $fileName,
            'parents' => [$driveFolderId]
        ];
        
        $ch = curl_init('https://www.googleapis.com/drive/v3/files');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($metadata));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json'
        ]);
        $responseMeta = curl_exec($ch);
        curl_close($ch);
        
        $metaJson = json_decode($responseMeta, true);
        $fileId = $metaJson['id'] ?? null;

        if ($fileId) {
            $fileBinary = file_get_contents($fileTmpPath);

            $ch = curl_init("https://www.googleapis.com/upload/drive/v3/files/{$fileId}?uploadType=media");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
            curl_setopt($ch, CURLOPT_POSTFIELDS, $fileBinary);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: ' . $fileType
            ]);
            curl_exec($ch);
            curl_close($ch);
            
            $link_foto = 'https://drive.google.com/open?id=' . $fileId;
        }
    }

    // ============================================================
    // 4. SALVAR DADOS NO GOOGLE SHEETS
    // ============================================================
$values = [
        [
            $protocolo,       // Coluna A
            $data_hora,       // Coluna B
            $nomeEmpresa,     // Coluna C
            $identificacao,   // Coluna D
            $nome,            // Coluna E
            $contato,         // Coluna F
            $categoria,       // Coluna G
            $local,           // Coluna H
            $urgencia,        // Coluna I
            $descricao,       // Coluna J
            $link_foto        // Coluna K
        ]
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
    curl_exec($ch);
    curl_close($ch);

    // ============================================================
    // 5. ENVIAR E-MAIL VIA SMTP DO GMAIL (CURL)
    // ============================================================
    if (!empty($smtpEmail) && !empty($smtpPassword) && !empty($emailRH)) {
        
        $subject = "Novo Relato SST - Protocolo {$protocolo}";
        
        // Montagem do corpo em HTML formatado
        $messageHtml = "
        <div style='font-family: Arial, sans-serif; color: #333; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #eee; border-radius: 8px;'>
            <h2 style='color: #2c3e50; border-bottom: 2px solid #3498db; padding-bottom: 10px;'>Novo Relato de Segurança Protocolado (NR-1)</h2>
            <p><strong>Protocolo:</strong> <span style='font-size: 16px; color: #e74c3c; font-weight: bold;'>{$protocolo}</span></p>
            <p><strong>Data/Hora:</strong> {$data_hora}</p>
            <p><strong>Urgência:</strong> " . strtoupper($urgencia) . "</p>
            <hr style='border: 0; border-top: 1px solid #eee; margin: 20px 0;'>
            <p><strong>Identificação:</strong> " . ($identificacao === 'anonimo' ? 'Anônimo' : 'Identificado') . "</p>
            <p><strong>Nome do Relator:</strong> {$nome}</p>
            <p><strong>Contato fornecido:</strong> {$contato}</p>
            <p><strong>Natureza do Risco:</strong> {$categoria}</p>
            <p><strong>Local/Setor do Fato:</strong> {$local}</p>
            <hr style='border: 0; border-top: 1px solid #eee; margin: 20px 0;'>
            <p><strong>Descrição do ocorrido:</strong><br><span style='font-style: italic; color: #555;'>" . nl2br(htmlspecialchars($descricao)) . "</span></p>
            <hr style='border: 0; border-top: 1px solid #eee; margin: 20px 0;'>
            <p><strong>Link da Evidência:</strong> <a href='{$link_foto}' style='color: #3498db; text-decoration: none; font-weight: bold;'>Acessar Arquivo Anexo</a></p>
        </div>
        ";

        // Montagem do envelope e dos headers brutos do protocolo de e-mail (RFC 2822)
        $boundary = "___EMAIL_BOUNDARY___";
        
        $data = "From: Canal NR-1 <" . $smtpEmail . ">\r\n";
        $data .= "To: <" . $emailRH . ">\r\n";
        $data .= "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n";
        $data .= "MIME-Version: 1.0\r\n";
        $data .= "Content-Type: multipart/alternative; boundary=\"" . $boundary . "\"\r\n";
        $data .= "\r\n";
        
        // Parte em Texto Puro (Fallback)
        $data .= "--" . $boundary . "\r\n";
        $data .= "Content-Type: text/plain; charset=\"UTF-8\"\r\n";
        $data .= "\r\n";
        $data .= "Novo relato recebido. Protocolo: {$protocolo}. Acesse a planilha para ver os detalhes.\r\n";
        
        // Parte em HTML
        $data .= "--" . $boundary . "\r\n";
        $data .= "Content-Type: text/html; charset=\"UTF-8\"\r\n";
        $data .= "\r\n";
        $data .= $messageHtml . "\r\n";
        $data .= "--" . $boundary . "--\r\n";

        // Disparo SMTP via comandos nativos de cURL
        $ch = curl_init('smtps://smtp.gmail.com:465');
        curl_setopt($ch, CURLOPT_USERNAME, $smtpEmail);
        curl_setopt($ch, CURLOPT_PASSWORD, $smtpPassword);
        curl_setopt($ch, CURLOPT_USE_SSL, CURLUSESSL_ALL);
        curl_setopt($ch, CURLOPT_MAIL_FROM, '<' . $smtpEmail . '>');
        curl_setopt($ch, CURLOPT_MAIL_RCPT, ['<' . $emailRH . '>']);
        
        // Função que alimenta o payload do e-mail linha por linha para o cURL enviar via stream
        curl_setopt($ch, CURLOPT_INFILESIZE, strlen($data));
        curl_setopt($ch, CURLOPT_UPLOAD, true);
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $data);
        rewind($stream);
        curl_setopt($ch, CURLOPT_READFUNCTION, function($ch, $fd, $length) use ($stream) {
            return fread($stream, $length);
        });

        curl_exec($ch);
        curl_close($ch);
        fclose($stream);
    }

    // Retorna resposta de sucesso absoluto
    echo json_encode([
        'success' => true,
        'protocolo' => $protocolo
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Erro interno: ' . $e->getMessage()
    ]);
}