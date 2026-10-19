<?php
/**
 * Diagnóstico temporário do Perfil e Cartão da SyncPay.
 */

header( 'Content-Type: application/json; charset=utf-8' );

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/syncpay.php';

// 1. Forçar limpeza do token em cache para garantir emissão com permissões atualizadas
set_setting( 'syncpay_access_token', '' );
set_setting( 'syncpay_token_expires', '0' );

$client_id = get_setting( 'syncpay_client_id', '' );
$token = SyncPayClient::get_valid_access_token();

if ( empty( $token ) ) {
    echo json_encode( [
        'success'   => false,
        'message'   => 'Não foi possível gerar novo access_token com as credenciais atuais.',
        'client_id' => $client_id,
    ], JSON_PRETTY_PRINT );
    exit;
}

// 2. Consultar Perfil do Parceiro (GET /api/partner/v1/profile)
$res_profile = SyncPayClient::api_request( '/api/partner/v1/profile' );

// 3. Consultar Saldo da Conta (GET /api/partner/v1/balance)
$res_balance = SyncPayClient::api_request( '/api/partner/v1/balance' );

// 4. Testar Selagem de Cartão com o token recém-emitido
$res_card_token = SyncPayClient::seal_card( [
    'number'       => '5149452665242701',
    'holder_name'  => 'MARIA DA SILVA',
    'expiry_month' => '06',
    'expiry_year'  => '2031',
    'cvv'          => '123',
] );

echo json_encode( [
    'success'         => true,
    'configured_client_id' => $client_id,
    'fresh_token_issued'  => ! empty( $token ),
    'profile_response'     => $res_profile,
    'balance_response'     => $res_balance,
    'card_token_response'  => $res_card_token,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
