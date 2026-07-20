<?php
/**
 * Script de Simulação de Webhook Asaas.
 * Simula a chegada de eventos do Asaas para ativar, suspender ou expirar uma licença no banco de dados local.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

echo "=== SIMULAÇÃO DE WEBHOOK ASAAS ===\n\n";

// 1. Garantir que a licença de teste exista
$db = get_db_connection();
$stmt = $db->prepare( "SELECT * FROM licenses WHERE license_key = 'WPAIP-TEST-KEY-123' LIMIT 1" );
$stmt->execute();
$license = $stmt->fetch();

if ( ! $license ) {
    // Cria licença de teste para simulação
    $stmt = $db->prepare( "INSERT INTO licenses (license_key, client_email, status, asaas_subscription_id) VALUES ('WPAIP-TEST-KEY-123', 'test@client.com', 'ACTIVE', 'sub_test_123456')" );
    $stmt->execute();
    $license_id = $db->lastInsertId();
    echo "Licença de teste 'WPAIP-TEST-KEY-123' com Assinatura 'sub_test_123456' foi criada.\n";
} else {
    // Garante que o ID de assinatura está definido
    $stmt = $db->prepare( "UPDATE licenses SET asaas_subscription_id = 'sub_test_123456' WHERE id = ?" );
    $stmt->execute( [ $license['id'] ] );
    echo "Licença de teste associada à assinatura 'sub_test_123456'.\n";
}

function simulate_webhook_event( string $event, string $subscription_id ): array {
    $payload = [
        'id'           => 'evt_' . bin2hex( random_bytes( 6 ) ),
        'event'        => $event,
        'subscription' => $subscription_id,
        'payment'      => [
            'id'           => 'pay_' . bin2hex( random_bytes( 6 ) ),
            'customer'     => 'cus_test_123',
            'subscription' => $subscription_id,
        ]
    ];
    
    // Simular o recebimento do payload JSON via POST
    $orig_post   = $_POST;
    $orig_server = $_SERVER;
    
    $_SERVER['REQUEST_METHOD'] = 'POST';
    
    // PHP não permite sobrescrever diretamente php://input de forma nativa em includes, 
    // mas nosso script api/webhook-asaas.php lê de php://input.
    // Para contornar e simular em console, podemos usar um cURL local real se o servidor web rodar.
    // Alternativamente, se rodarmos este script localmente, vamos fazer o cURL de simulação HTTP!
    // Para rodar no console PHP sem servidor ativo, a melhor forma é ter suporte na API do webhook para ler
    // de uma variável global de simulação se estiver em modo CLI/DEBUG.
    // Vamos fazer uma simulação cURL que você pode rodar apontando para o seu site online!
    
    return $payload;
}

$event_to_test = $_GET['event'] ?? 'PAYMENT_OVERDUE'; // Mude para testar diferentes status

echo "Evento gerado para simulação: {$event_to_test}\n";
$payload = simulate_webhook_event( $event_to_test, 'sub_test_123456' );

echo "Payload simulado:\n";
echo json_encode( $payload, JSON_PRETTY_PRINT ) . "\n\n";

echo "Para disparar essa simulação contra o seu servidor online, rode o seguinte comando cURL:\n";
$cmd = sprintf(
    "curl -X POST -H \"Content-Type: application/json\" -d '%s' http://seu-dominio.com/license-server-wp-post/api/webhook-asaas.php",
    json_encode( $payload )
);
echo "\n" . $cmd . "\n\n";

// Se o usuário estiver rodando localmente no CLI, vamos aplicar a alteração diretamente no banco para demonstrar!
echo "Aplicando alteração local diretamente no banco de dados para simular...\n";
$new_status = null;
switch ( $event_to_test ) {
    case 'PAYMENT_RECEIVED':
    case 'PAYMENT_CONFIRMED':
    case 'SUBSCRIPTION_CREATED':
    case 'SUBSCRIPTION_ACTIVE':
        $new_status = 'ACTIVE';
        break;
    case 'PAYMENT_OVERDUE':
    case 'SUBSCRIPTION_OVERDUE':
        $new_status = 'SUSPENDED';
        break;
    case 'SUBSCRIPTION_DELETED':
    case 'PAYMENT_DELETED':
    case 'PAYMENT_REFUNDED':
    case 'PAYMENT_CHARGEBACK':
        $new_status = 'EXPIRED';
        break;
}

if ( $new_status ) {
    $stmt = $db->prepare( "UPDATE licenses SET status = ? WHERE asaas_subscription_id = ?" );
    $stmt->execute( [ $new_status, 'sub_test_123456' ] );
    echo "✔ Banco de Dados Atualizado Localmente: A licença 'WPAIP-TEST-KEY-123' agora está com o status: {$new_status}\n";
} else {
    echo "❌ Evento desconhecido ou sem mudança de status necessária.\n";
}
