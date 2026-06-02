<?php
// api/theme.php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

ini_set('display_errors', 0);
error_reporting(E_ALL);

$configPath = __DIR__ . '/config_clientes.php';

if (!file_exists($configPath)) {
    echo json_encode(['success' => false, 'message' => 'Configuração não encontrada.']);
    exit;
}

$clientes = require $configPath;
$clienteId = $_GET['cliente'] ?? 'padrao';

// Fallback: Se o cliente não existir, carrega a empresa_a_123 como padrão
if (!isset($clientes[$clienteId])) {
    $clienteId = 'empresa_a_123'; 
}

$dadosCliente = $clientes[$clienteId];

// Envia o JSON perfeito para o script.js ler
echo json_encode([
    'success' => true,
    'nome'    => $dadosCliente['nome'],
    'cnpj'    => $dadosCliente['cnpj'],
    'tema'    => [
        'primary'              => $dadosCliente['tema']['primary'],
        'primary_dark'         => $dadosCliente['tema']['primary_hover'],
        'primary_light'        => $dadosCliente['tema']['accent'] . '1A', // Accent + 10% opacidade
        'primary_focus_shadow' => $dadosCliente['tema']['primary'] . '33'  // Primary + 20% opacidade
    ]
]);