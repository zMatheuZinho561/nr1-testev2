<?php
// api/theme.php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// Como é um teste direto no Vercel, definimos as cores padrão do seu layout aqui
echo json_encode([
    'success' => true,
    'nome' => 'Empresa de Teste NR-1',
    'cnpj' => '00.000.000/0001-00',
    'tema' => [
        'primary' => '#E53E3E', // Vermelho AssinaPDF
        'primary_dark' => '#C53030',
        'primary_light' => 'rgba(229, 62, 62, 0.1)',
        'primary_focus_shadow' => 'rgba(229, 62, 62, 0.15)'
    ]
]);