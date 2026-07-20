<?php
/**
 * API: Ativação de Licença.
 * Vincula a licença a um domínio de site e retorna um token assinado.
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

// Limpar domínio (remover http://, https:// e barras finais)
$domain = preg_replace( '/^https?:\/\//i', '', $domain );
$domain = rtrim( $domain, '/' );

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
        echo json_encode( [ 'success' => false, 'message' => 'Esta licença não está ativa. Status atual: ' . $license['status'] ] );
        exit;
    }
    
    // 3. Verificar limite de ativações (1 domínio por padrão)
    $stmt = $db->prepare( "SELECT * FROM activations WHERE license_id = ?" );
    $stmt->execute( [ $license['id'] ] );
    $activations = $stmt->fetchAll();
    
    $is_already_active_on_domain = false;
    foreach ( $activations as $act ) {
        if ( strcasecmp( $act['domain'], $domain ) === 0 ) {
            $is_already_active_on_domain = true;
            break;
        }
    }
    
    if ( ! $is_already_active_on_domain ) {
        if ( count( $activations ) >= 1 ) {
            http_response_code( 400 );
            echo json_encode( [ 
                'success' => false, 
                'message' => 'Esta licença já está ativa em outro domínio (' . $activations[0]['domain'] . '). Libere-a no painel administrativo antes de usá-la aqui.' 
            ] );
            exit;
        }
        
        // Registrar nova ativação
        $stmt = $db->prepare( "INSERT INTO activations (license_id, domain) VALUES (?, ?)" );
        $stmt->execute( [ $license['id'], $domain ] );
    }
    
    // 4. Gerar Token assinado digitalmente (HMAC) para o cliente salvar em cache
    $expires = time() + ( 24 * 3600 ); // Válido por 24 horas
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
        'message'      => 'Licença ativada com sucesso.',
        'token'        => base64_encode( $payload_str ),
        'signature'    => $signature,
        'expires'      => $expires,
        'payment_link' => get_setting( 'asaas_payment_link', '#' ),
    ] );

} catch ( Exception $e ) {
    http_response_code( 500 );
    echo json_encode( [ 'success' => false, 'message' => 'Erro interno do servidor: ' . $e->getMessage() ] );
}
