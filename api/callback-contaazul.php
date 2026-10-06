<?php
/**
 * Callback OAuth 2.0 da Conta Azul.
 * Recebe o authorization_code e salva o access_token e refresh_token no MySQL.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../contaazul.php';

$code  = trim( $_GET['code'] ?? '' );
$state = trim( $_GET['state'] ?? '' );
$error = trim( $_GET['error'] ?? '' );

$admin_redirect = '../index.php?view=settings';

if ( ! empty( $error ) ) {
    $err_desc = urlencode( $_GET['error_description'] ?? $error );
    header( "Location: {$admin_redirect}&ca_error={$err_desc}" );
    exit;
}

if ( empty( $code ) ) {
    header( "Location: {$admin_redirect}&ca_error=" . urlencode( 'Código de autorização não recebido.' ) );
    exit;
}

$result = ContaAzulClient::handle_authorization_code( $code );

if ( $result['success'] ) {
    // Definir Conta Azul como gateway ativo por conveniência
    set_setting( 'active_gateway', 'contaazul' );
    header( "Location: {$admin_redirect}&ca_success=1" );
    exit;
} else {
    $msg = urlencode( $result['message'] ?? 'Falha na autenticação OAuth.' );
    header( "Location: {$admin_redirect}&ca_error={$msg}" );
    exit;
}
