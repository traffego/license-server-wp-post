<?php
/**
 * Configurações globais do Servidor de Licenças (Modelo).
 * Copie este arquivo como config.php e configure suas credenciais.
 */

if ( count( get_included_files() ) === 1 ) {
    http_response_code( 403 );
    exit( 'Acesso negado.' );
}

// ── Banco de Dados MySQL ──────────────────────────────────────────────────────
define( 'DB_HOST', 'localhost' );
define( 'DB_NAME', 'wpaip_licensing' );
define( 'DB_USER', 'root' );
define( 'DB_PASS', '' );

// ── Integração Asaas ──────────────────────────────────────────────────────────
define( 'ASAAS_API_KEY', 'sua_chave_asaas_aqui' );
define( 'ASAAS_ENV', 'sandbox' ); // 'sandbox' ou 'production'

// ── Segurança ────────────────────────────────────────────────────────────────
define( 'JWT_SECRET', 'substitua_por_uma_chave_secreta_e_segura_123456!' );

// Configuração do Painel Admin
define( 'ADMIN_USER', 'admin' );
define( 'ADMIN_PASS', 'admin123' );

// ── Helper: URLs do Asaas ─────────────────────────────────────────────────────
function get_asaas_base_url(): string {
    return ASAAS_ENV === 'production'
        ? 'https://api.asaas.com'
        : 'https://sandbox.asaas.com/api';
}
