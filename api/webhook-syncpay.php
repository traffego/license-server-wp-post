<?php
/**
 * API: Webhook da SyncPay.
 * Recebe notificações de PIX e Cartão da SyncPay e atualiza o status da licença no MySQL.
 *
 * @package WPAIPublisher
 */

header( 'Content-Type: application/json; charset=utf-8' );

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../syncpay.php';

// Pegar payload enviado pela SyncPay
$payload_raw = file_get_contents( 'php://input' );
$data        = json_decode( $payload_raw, true );

if ( ! is_array( $data ) ) {
    http_response_code( 400 );
    echo json_encode( [ 'success' => false, 'message' => 'Payload JSON inválido.' ] );
    exit;
}

// Validação opcional de token Bearer do webhook se configurado no painel
$expected_token = trim( get_setting( 'syncpay_webhook_token', '' ) );
if ( ! empty( $expected_token ) ) {
    $auth_header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if ( preg_match( '/Bearer\s+(.*)$/i', $auth_header, $matches ) ) {
        $received_token = trim( $matches[1] );
        if ( ! hash_equals( $expected_token, $received_token ) ) {
            http_response_code( 401 );
            echo json_encode( [ 'success' => false, 'message' => 'Token de autorização do webhook inválido.' ] );
            exit;
        }
    }
}

// Identificar o ID da transação
// SyncPay pode enviar em 'id', 'identifier', 'transaction_id' ou 'reference_id'
$transaction_id = $data['id'] ?? ( $data['identifier'] ?? ( $data['transaction_id'] ?? ( $data['reference_id'] ?? null ) ) );

if ( empty( $transaction_id ) ) {
    http_response_code( 400 );
    echo json_encode( [ 'success' => false, 'message' => 'ID de transação não identificado no payload.' ] );
    exit;
}

try {
    $db = get_db_connection();

    // Tentar localizar a licença associada à transação
    $sp_ref = 'SP-' . $transaction_id;
    $stmt   = $db->prepare( "
        SELECT * FROM licenses 
        WHERE gateway_reference_id = ? 
           OR gateway_reference_id = ? 
           OR asaas_subscription_id = ? 
           OR asaas_subscription_id = ? 
        LIMIT 1
    " );
    $stmt->execute( [ $transaction_id, $sp_ref, $transaction_id, $sp_ref ] );
    $license = $stmt->fetch();

    if ( ! $license ) {
        error_log( "Webhook SyncPay recebido, mas nenhuma licença foi localizada para Transaction ID: {$transaction_id}" );
        echo json_encode( [ 'success' => true, 'message' => 'Evento recebido, mas nenhuma licença correspondente no banco.' ] );
        exit;
    }

    // Determinar status
    $raw_status = isset( $data['status'] ) ? strtolower( (string) $data['status'] ) : '';

    // Se o webhook não trouxer o status (ex: CashinOld legado), consulta na API
    if ( empty( $raw_status ) ) {
        $normalized = SyncPayClient::check_transaction_status( $transaction_id );
        if ( $normalized !== 'UNKNOWN' ) {
            $raw_status = strtolower( $normalized );
        }
    }

    $new_status = null;
    switch ( $raw_status ) {
        case 'completed':
        case 'paid':
        case 'pago':
            $new_status = 'ACTIVE';
            break;

        case 'failed':
        case 'recusado':
        case 'falho':
            $new_status = 'SUSPENDED';
            break;

        case 'refunded':
        case 'estornado':
            $new_status = 'EXPIRED';
            break;

        case 'med':
            $new_status = 'SUSPENDED';
            break;

        case 'pending':
        case 'processando':
            $new_status = 'PENDING';
            break;
    }

    if ( $new_status !== null ) {
        $up = $db->prepare( "UPDATE licenses SET status = ? WHERE id = ?" );
        $up->execute( [ $new_status, $license['id'] ] );

        error_log( "Licença ID {$license['id']} atualizada para {$new_status} via webhook SyncPay (Transação: {$transaction_id}, Status original: {$raw_status})" );

        echo json_encode( [
            'success'        => true,
            'license_key'    => $license['license_key'],
            'new_status'     => $new_status,
            'transaction_id' => $transaction_id,
        ] );
    } else {
        echo json_encode( [
            'success' => true,
            'message' => 'Evento recebido sem alteração de status necessária.',
        ] );
    }

} catch ( Exception $e ) {
    http_response_code( 500 );
    echo json_encode( [ 'success' => false, 'error' => $e->getMessage() ] );
}
