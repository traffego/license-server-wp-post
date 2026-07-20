<?php
/**
 * API: Webhook do Asaas.
 * Recebe notificações de pagamento e assinatura do Asaas e atualiza o MySQL.
 */

header( 'Content-Type: application/json; charset=utf-8' );

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';

// Pegar payload enviado pelo Asaas
$payload_raw = file_get_contents( 'php://input' );
$data        = json_decode( $payload_raw, true );

if ( ! is_array( $data ) || empty( $data['event'] ) ) {
    http_response_code( 400 );
    echo json_encode( [ 'success' => false, 'message' => 'Payload inválido.' ] );
    exit;
}

$event = $data['event'];

// Opcional: Validar token de autenticação enviado pelo Asaas se estiver configurado
// Configurar um token de webhook nas configurações do Asaas e comparar aqui
// $headers = getallheaders();
// $token = $headers['asaas-access-token'] ?? '';

try {
    $db = get_db_connection();
    
    // Identificar ID da assinatura ou cliente no Asaas
    $subscription_id = $data['subscription'] ?? ( $data['payment']['subscription'] ?? null );
    $customer_id     = $data['payment']['customer'] ?? ( $data['customer'] ?? null );
    
    if ( ! $subscription_id && ! $customer_id ) {
        // Nada para associar
        echo json_encode( [ 'success' => true, 'message' => 'Nenhuma assinatura ou cliente identificado no evento.' ] );
        exit;
    }
    
    // Tentar localizar a licença correspondente no MySQL
    $license = null;
    
    if ( $subscription_id ) {
        $stmt = $db->prepare( "SELECT * FROM licenses WHERE asaas_subscription_id = ? LIMIT 1" );
        $stmt->execute( [ $subscription_id ] );
        $license = $stmt->fetch();
    }
    
    if ( ! $license && $customer_id ) {
        $stmt = $db->prepare( "SELECT * FROM licenses WHERE asaas_customer_id = ? LIMIT 1" );
        $stmt->execute( [ $customer_id ] );
        $license = $stmt->fetch();
    }
    
    if ( ! $license ) {
        // Se a licença não foi encontrada, podemos tentar criar uma se o evento for criação de assinatura?
        // Mas o mais comum é o admin criar a licença no painel antes, ou criarmos via API de vendas.
        // Registra no log do servidor
        error_log( "Webhook Asaas recebido, mas nenhuma licença foi localizada para Sub ID: {$subscription_id} ou Cust ID: {$customer_id}" );
        echo json_encode( [ 'success' => true, 'message' => 'Evento recebido, mas nenhuma licença associada no MySQL.' ] );
        exit;
    }
    
    $new_status = null;
    
    switch ( $event ) {
        // Eventos que Ativam o Acesso
        case 'PAYMENT_RECEIVED':
        case 'PAYMENT_CONFIRMED':
        case 'SUBSCRIPTION_CREATED':
        case 'SUBSCRIPTION_ACTIVE':
            $new_status = 'ACTIVE';
            break;
            
        // Eventos que Suspendem o Acesso
        case 'PAYMENT_OVERDUE':
        case 'SUBSCRIPTION_OVERDUE':
            $new_status = 'SUSPENDED';
            break;
            
        // Eventos que Cancelam/Expiram o Acesso
        case 'SUBSCRIPTION_DELETED':
        case 'PAYMENT_DELETED':
        case 'PAYMENT_REFUNDED':
        case 'PAYMENT_CHARGEBACK':
            $new_status = 'EXPIRED';
            break;
    }
    
    if ( $new_status !== null ) {
        // Atualiza o banco de dados
        $stmt = $db->prepare( "UPDATE licenses SET status = ? WHERE id = ?" );
        $stmt->execute( [ $new_status, $license['id'] ] );
        
        // Limpar o cache de ativação dos domínios vinculados se a licença foi inativada
        if ( $new_status !== 'ACTIVE' ) {
            // Opcional: pode-se remover os domínios ativados ou apenas deixar inativo.
            // Manter os domínios associados mas com status inativo é bom para histórico.
        }
        
        error_log( "Licença ID {$license['id']} atualizada para o status {$new_status} via webhook Asaas (Evento: {$event})" );
        echo json_encode( [ 'success' => true, 'message' => "Licença atualizada para {$new_status}" ] );
    } else {
        echo json_encode( [ 'success' => true, 'message' => 'Evento ignorado (sem mudança de status necessária)' ] );
    }

} catch ( Exception $e ) {
    http_response_code( 500 );
    echo json_encode( [ 'success' => false, 'message' => 'Erro ao processar webhook: ' . $e->getMessage() ] );
}
