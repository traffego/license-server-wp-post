<?php
/**
 * Endpoint de diagnóstico de chave de licença.
 * Acesso: /license-server-wp-post/api/test-key.php?key=WPAIP-...&token=SEU_TOKEN
 * APAGAR APÓS USO!
 */

header( 'Content-Type: application/json; charset=utf-8' );

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';

// Proteção básica por token
$token = trim( $_GET['token'] ?? '' );
if ( empty( $token ) || $token !== ( defined('ADMIN_PASS') ? ADMIN_PASS : '' ) ) {
    http_response_code( 403 );
    echo json_encode( [ 'error' => 'Token inválido. Use ?token=SUA_SENHA_ADMIN' ] );
    exit;
}

$license_key = trim( $_GET['key'] ?? '' );
if ( empty( $license_key ) ) {
    echo json_encode( [ 'error' => 'Forneça ?key=WPAIP-...' ] );
    exit;
}

$db = get_db_connection();

// Gera o clean_key (mesmo algoritmo do generate.php)
$clean_key = preg_replace( '/[^A-Za-z0-9]/', '', $license_key );

$result = [
    'received_key'  => $license_key,
    'clean_key'     => $clean_key,
    'key_length'    => strlen( $license_key ),
    'key_hex'       => bin2hex( $license_key ), // mostra bytes reais (detecta chars ocultos)
    'queries'       => [],
    'found'         => false,
    'license'       => null,
];

// Query 1: clean_key vs REPLACE/UPPER no banco
$stmt = $db->prepare( "SELECT id, license_key, status, client_email FROM licenses WHERE UPPER(REPLACE(REPLACE(TRIM(license_key), '-', ''), ' ', '')) = UPPER(?) LIMIT 1" );
$stmt->execute( [ $clean_key ] );
$row = $stmt->fetch();
$result['queries'][] = [
    'method'   => 'REPLACE/UPPER',
    'param'    => $clean_key,
    'found'    => (bool) $row,
    'row'      => $row ?: null,
];

if ( $row ) {
    $result['found']   = true;
    $result['license'] = $row;
}

// Query 2: UPPER TRIM exato
if ( ! $result['found'] ) {
    $stmt = $db->prepare( "SELECT id, license_key, status, client_email FROM licenses WHERE UPPER(TRIM(license_key)) = UPPER(TRIM(?)) LIMIT 1" );
    $stmt->execute( [ $license_key ] );
    $row = $stmt->fetch();
    $result['queries'][] = [
        'method'   => 'UPPER TRIM exato',
        'param'    => $license_key,
        'found'    => (bool) $row,
        'row'      => $row ?: null,
    ];
    if ( $row ) {
        $result['found']   = true;
        $result['license'] = $row;
    }
}

// Query 3: LIKE (busca parcial — debug apenas)
if ( ! $result['found'] ) {
    $like_key = '%' . $clean_key . '%';
    $stmt = $db->prepare( "SELECT id, license_key, status, client_email FROM licenses WHERE REPLACE(REPLACE(UPPER(license_key),'-',''),' ','') LIKE ? LIMIT 5" );
    $stmt->execute( [ '%' . $clean_key . '%' ] );
    $rows = $stmt->fetchAll();
    $result['queries'][] = [
        'method'   => 'LIKE parcial (debug)',
        'param'    => $like_key,
        'found'    => count( $rows ) > 0,
        'rows'     => $rows,
    ];
}

// Mostra domínios ativados para esta chave
if ( $result['found'] && isset( $result['license']['id'] ) ) {
    $stmt = $db->prepare( "SELECT * FROM activations WHERE license_id = ?" );
    $stmt->execute( [ $result['license']['id'] ] );
    $result['activations'] = $stmt->fetchAll();
}

// Lista as primeiras 5 licenças do banco para comparar manualmente
$stmt = $db->query( "SELECT id, license_key, status FROM licenses ORDER BY id DESC LIMIT 5" );
$result['latest_licenses_in_db'] = $stmt->fetchAll();

echo json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
