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
    // 1. Configurações e Multi-tenant
    $empresa_id = $_POST['empresa_id'] ?? 'padrao';
    $dadosCliente = null;
    if (file_exists($configPath)) {
        $clientes = require $configPath;
        if (isset($clientes[$empresa_id])) {
            $dadosCliente = $clientes[$empresa_id];
        } elseif (isset($clientes['empresa_a_123'])) {
            $dadosCliente = $clientes['empresa_a_123']; // Fallback padrão
        }
    }

    $nomeEmpresa         = $dadosCliente ? ($dadosCliente['nome'] ?? 'Padrão') : 'Padrão';
    $spreadsheetId       = ($dadosCliente && !empty($dadosCliente['spreadsheet_id'])) ? $dadosCliente['spreadsheet_id'] : getenv('GOOGLE_SHEET_ID');
    $driveFolderId       = ($dadosCliente && !empty($dadosCliente['drive_folder_id'])) ? $dadosCliente['drive_folder_id'] : getenv('GOOGLE_DRIVE_FOLDER_ID');
    $emailRH             = ($dadosCliente && !empty($dadosCliente['email_rh'])) ? $dadosCliente['email_rh'] : getenv('EMAIL_RH_DESTINO');

    $serviceAccountEmail = getenv('GOOGLE_SERVICE_ACCOUNT_EMAIL');
    $privateKey          = getenv('GOOGLE_PRIVATE_KEY');
    
    // Variáveis para o SMTP Puro do Gmail
    $smtpEmail           = getenv('SMTP_EMAIL'); 
    $smtpPassword        = getenv('SMTP_PASSWORD');

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

    $link_foto = '';
    $upload_debug = [];

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
    // 3. ENVIAR FOTO PARA O GOOGLE DRIVE (2 PASSOS COM Content-Length)
    // ============================================================
    if (!empty($driveFolderId) && isset($_FILES['foto']) && $_FILES['foto']['error'] === UPLOAD_ERR_OK) {
        $fileTmpPath = $_FILES['foto']['tmp_name'];
        $fileName    = $_FILES['foto']['name'];
        $fileType    = $_FILES['foto']['type'];
        $fileSize    = $_FILES['foto']['size'];
        
        $upload_debug['file_received'] = true;
        $upload_debug['file_name'] = $fileName;
        $upload_debug['file_size'] = $fileSize;
        $upload_debug['file_type'] = $fileType;
        $upload_debug['tmp_path'] = $fileTmpPath;
        
        if (!file_exists($fileTmpPath) || !is_readable($fileTmpPath)) {
            $upload_debug['error'] = 'Arquivo temporário não encontrado ou sem permissão';
            error_log('Drive: Arquivo temporário não encontrado: ' . $fileTmpPath);
        } else {
            $fileBinary = file_get_contents($fileTmpPath);
            
            if ($fileBinary === false) {
                $upload_debug['error'] = 'file_get_contents retornou false';
                error_log('Drive: file_get_contents falhou para: ' . $fileTmpPath);
            } else {
                $upload_debug['binary_size'] = strlen($fileBinary);
                
                // --- PASSO 1: Criar metadados do arquivo ---
                $metadata = [
                    'name' => 'Evidencia_' . $protocolo . '_' . $fileName,
                    'parents' => [$driveFolderId]
                ];
                
                $ch = curl_init('https://www.googleapis.com/drive/v3/files?supportsAllDrives=true');
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($metadata));
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'Authorization: Bearer ' . $accessToken,
                    'Content-Type: application/json'
                ]);
                $responseMeta = curl_exec($ch);
                $httpMeta = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curlErrorMeta = curl_error($ch);
                curl_close($ch);
                
                $upload_debug['step1_http'] = $httpMeta;
                $upload_debug['step1_curl_error'] = $curlErrorMeta;
                $upload_debug['step1_response'] = substr($responseMeta ?? '', 0, 200);
                
                $metaJson = json_decode($responseMeta, true);
                $fileId = $metaJson['id'] ?? null;
                
                if (!$fileId) {
                    $upload_debug['error'] = 'Falha ao criar metadados no Drive';
                    error_log('Drive: Falha criar metadados. HTTP: ' . $httpMeta . ' | cURL: ' . $curlErrorMeta . ' | Resposta: ' . ($responseMeta ?? 'vazio'));
                } else {
                    $upload_debug['file_id'] = $fileId;
                    
                    // --- PASSO 2: Upload do binário com Content-Length ---
                    $ch = curl_init("https://www.googleapis.com/upload/drive/v3/files/{$fileId}?uploadType=media&supportsAllDrives=true");
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $fileBinary);
                    curl_setopt($ch, CURLOPT_HTTPHEADER, [
                        'Authorization: Bearer ' . $accessToken,
                        'Content-Type: ' . $fileType,
                        'Content-Length: ' . strlen($fileBinary)
                    ]);
                    $responseUpload = curl_exec($ch);
                    $httpUpload = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    $curlErrorUpload = curl_error($ch);
                    curl_close($ch);
                    
                    $upload_debug['step2_http'] = $httpUpload;
                    $upload_debug['step2_curl_error'] = $curlErrorUpload;
                    $upload_debug['step2_response'] = substr($responseUpload ?? '', 0, 200);
                    
                    $uploadJson = json_decode($responseUpload, true);
                    
                    if ($httpUpload >= 200 && $httpUpload < 300) {
                        $link_foto = 'https://drive.google.com/open?id=' . $fileId;
                        $upload_debug['success'] = true;
                    } else {
                        $upload_debug['error'] = 'Falha no upload do binário (HTTP ' . $httpUpload . ')';
                        error_log('Drive: Falha upload binário. HTTP: ' . $httpUpload . ' | cURL: ' . $curlErrorUpload . ' | Resposta: ' . ($responseUpload ?? 'vazio'));
                    }
                }
            }
        }
    } else {
        $upload_debug['skipped'] = true;
        $upload_debug['driveFolderId_empty'] = empty($driveFolderId);
        $upload_debug['foto_isset'] = isset($_FILES['foto']);
        $upload_debug['foto_error'] = isset($_FILES['foto']) ? $_FILES['foto']['error'] : 'N/A';
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
            <p><strong>Nome do Relator:</strong> " . htmlspecialchars($nome, ENT_QUOTES, 'UTF-8') . "</p>
            <p><strong>Contato fornecido:</strong> " . htmlspecialchars($contato, ENT_QUOTES, 'UTF-8') . "</p>
            <p><strong>Natureza do Risco:</strong> " . htmlspecialchars($categoria, ENT_QUOTES, 'UTF-8') . "</p>
            <p><strong>Local/Setor do Fato:</strong> " . htmlspecialchars($local, ENT_QUOTES, 'UTF-8') . "</p>
            <hr style='border: 0; border-top: 1px solid #eee; margin: 20px 0;'>
            <p><strong>Descrição do ocorrido:</strong><br><span style='font-style: italic; color: #555;'>" . nl2br(htmlspecialchars($descricao)) . "</span></p>
            " . ($link_foto ? "<hr style='border: 0; border-top: 1px solid #eee; margin: 20px 0;'>
            <p><strong>Link da Evidência:</strong> <a href='{$link_foto}' style='color: #3498db; text-decoration: none; font-weight: bold;'>Acessar Arquivo Anexo</a></p>" : "") . "
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
        'protocolo' => $protocolo,
        'upload_debug' => $upload_debug ?? []
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Erro interno: ' . $e->getMessage()
    ]);
}