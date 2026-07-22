<?php
/**
 * API: Verificação de Licença.
 * Chamada pelo plugin cliente para validar se a licença e domínio continuam ativos.
 */

header( 'Content-Type: application/json; charset=utf-8' );

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';

if ( $_SERVER['REQUEST_METHOD'] !== 'POST' ) {
    http_response_code( 405 );
    echo json_encode( [ 'success' => false, 'message' => 'Método não permitido.' ] );
    exit;
}

$license_key = trim( $_POST['license_key'] ?? '' );
$domain      = trim( $_POST['domain'] ?? '' );

// Limpar domínio (remover http://, https://, portas e subpastas)
$domain = strtolower( trim( $_POST['domain'] ?? '' ) );
$domain = preg_replace( '/^https?:\/\//i', '', $domain );
$domain = explode( '/', $domain )[0];
$domain = explode( ':', $domain )[0];

if ( empty( $license_key ) || empty( $domain ) ) {
    http_response_code( 400 );
    echo json_encode( [ 'success' => false, 'message' => 'Chave de licença e domínio são obrigatórios.' ] );
    exit;
}

try {
    $db = get_db_connection();
    
    // 1. Buscar licença no banco
    $stmt = $db->prepare( "SELECT * FROM licenses WHERE license_key = ? LIMIT 1" );
    $stmt->execute( [ $license_key ] );
    $license = $stmt->fetch();
    
    if ( ! $license ) {
        http_response_code( 404 );
        echo json_encode( [ 'success' => false, 'message' => 'Chave de licença inválida.' ] );
        exit;
    }
    
    // 2. Verificar status da licença
    if ( $license['status'] !== 'ACTIVE' ) {
        http_response_code( 403 );
        echo json_encode( [ 'success' => false, 'message' => 'Licença inativa. Status: ' . $license['status'] ] );
        exit;
    }
    
    // 3. Verificar se o domínio está ativado para esta licença
    $stmt = $db->prepare( "SELECT * FROM activations WHERE license_id = ? AND domain = ? LIMIT 1" );
    $stmt->execute( [ $license['id'], $domain ] );
    $activation = $stmt->fetch();
    
    if ( ! $activation ) {
        http_response_code( 403 );
        echo json_encode( [ 'success' => false, 'message' => 'Este domínio não está autorizado a usar esta licença.' ] );
        exit;
    }
    
    // 4. Gerar novo token de verificação assinado (válido por 24 horas)
    $expires = time() + ( 24 * 3600 );
    $payload = [
        'license_key'  => $license_key,
        'domain'       => $domain,
        'status'       => 'ACTIVE',
        'expires'      => $expires,
        'client_email' => $license['client_email'],
        'payment_link' => get_setting( 'asaas_payment_link', '#' ),
    ];
    
    $payload_str = json_encode( $payload );
    $signature   = hash_hmac( 'sha256', $payload_str, JWT_SECRET );
    
    echo json_encode( [
        'success'      => true,
        'message'      => 'Licença ativa e autorizada.',
        'token'        => base64_encode( $payload_str ),
        'signature'    => $signature,
        'expires'      => $expires,
        'payment_link' => get_setting( 'asaas_payment_link', '#' ),
    ] );

} catch ( Exception $e ) {
    http_response_code( 500 );
    echo json_encode( [ 'success' => false, 'message' => 'Erro interno do servidor: ' . $e->getMessage() ] );
}
