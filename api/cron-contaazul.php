<?php
/**
 * Cron / Polling de Cobranças da Conta Azul.
 * Como a Conta Azul não possui webhooks nativos, este script consulta periodicamente
 * as licenças com pagamentos pendentes e ativa as que foram quitadas.
 */

header( 'Content-Type: application/json; charset=utf-8' );

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../contaazul.php';

try {
    $db = get_db_connection();

    // Buscar licenças que estão aguardando pagamento geradas pela Conta Azul
    $stmt = $db->prepare( "
        SELECT * FROM licenses 
        WHERE status IN ('PENDING', 'WAITING') 
          AND (gateway = 'contaazul' OR asaas_subscription_id LIKE 'CA-%' OR asaas_customer_id LIKE 'CA-%')
        ORDER BY id DESC
        LIMIT 50
    " );
    $stmt->execute();
    $pending_licenses = $stmt->fetchAll();

    $updated = 0;
    $checked = 0;
    $results = [];

    foreach ( $pending_licenses as $lic ) {
        $checked++;
        // Obter o ID da cobrança armazenado (no campo asaas_subscription_id ou gateway_reference_id)
        $charge_id = $lic['gateway_reference_id'] ?? $lic['asaas_subscription_id'] ?? '';
        $charge_id = preg_replace( '/^CA-/', '', $charge_id );

        if ( empty( $charge_id ) ) {
            continue;
        }

        $status = ContaAzulClient::check_charge_status( $charge_id );
        $results[] = [
            'license_key' => $lic['license_key'],
            'charge_id'   => $charge_id,
            'status'      => $status,
        ];

        if ( $status === 'PAID' ) {
            $up_stmt = $db->prepare( "UPDATE licenses SET status = 'ACTIVE' WHERE id = ?" );
            $up_stmt->execute( [ $lic['id'] ] );
            $updated++;
        } elseif ( $status === 'CANCELLED' ) {
            $up_stmt = $db->prepare( "UPDATE licenses SET status = 'EXPIRED' WHERE id = ?" );
            $up_stmt->execute( [ $lic['id'] ] );
        } elseif ( $status === 'OVERDUE' ) {
            $up_stmt = $db->prepare( "UPDATE licenses SET status = 'SUSPENDED' WHERE id = ?" );
            $up_stmt->execute( [ $lic['id'] ] );
        }
    }

    echo json_encode( [
        'success' => true,
        'checked' => $checked,
        'updated' => $updated,
        'details' => $results,
        'time'    => date( 'c' ),
    ] );

} catch ( Exception $e ) {
    http_response_code( 500 );
    echo json_encode( [ 'success' => false, 'error' => $e->getMessage() ] );
}
