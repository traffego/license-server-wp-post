<?php
/**
 * Conexão com o Banco de Dados MySQL e inicialização das tabelas.
 */

require_once __DIR__ . '/config.php';

try {
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    // Conectar diretamente ao banco para evitar erro de privilégios de "CREATE DATABASE" em hospedagem compartilhada
    try {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
        $pdo = new PDO( $dsn, DB_USER, DB_PASS, $options );
    } catch ( PDOException $e ) {
        // Código de erro 1049 indica banco inexistente. Tenta criar se for ambiente local
        if ( $e->getCode() === 1049 || strpos( $e->getMessage(), 'Unknown database' ) !== false ) {
            $dsn_temp = "mysql:host=" . DB_HOST . ";charset=utf8mb4";
            $pdo_temp = new PDO( $dsn_temp, DB_USER, DB_PASS, $options );
            $db_name_safe = preg_replace( '/[^a-zA-Z0-9_]/', '', DB_NAME );
            $pdo_temp->exec( "CREATE DATABASE IF NOT EXISTS `{$db_name_safe}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" );
            
            // Tenta conectar novamente ao banco recém-criado
            $pdo = new PDO( "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS, $options );
        } else {
            throw $e;
        }
    }
    
    // 2. Criar tabela 'licenses' se não existir
    $pdo->exec( "
        CREATE TABLE IF NOT EXISTS `licenses` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `license_key` VARCHAR(255) UNIQUE NOT NULL,
            `client_email` VARCHAR(255) NOT NULL,
            `status` VARCHAR(50) NOT NULL DEFAULT 'ACTIVE',
            `asaas_customer_id` VARCHAR(100) DEFAULT NULL,
            `asaas_subscription_id` VARCHAR(100) DEFAULT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB;
    " );
    
    // 3. Criar tabela 'activations' se não existir
    $pdo->exec( "
        CREATE TABLE IF NOT EXISTS `activations` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `license_id` INT NOT NULL,
            `domain` VARCHAR(255) NOT NULL,
            `activated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY `unique_license_domain` (`license_id`, `domain`),
            FOREIGN KEY (`license_id`) REFERENCES `licenses`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB;
    " );

    // 4. Criar tabela 'settings' se não existir
    $pdo->exec( "
        CREATE TABLE IF NOT EXISTS `settings` (
            `key_name` VARCHAR(255) PRIMARY KEY,
            `key_value` TEXT
        ) ENGINE=InnoDB;
    " );

    // 5. Criar tabela 'plans' se não existir
    $pdo->exec( "
        CREATE TABLE IF NOT EXISTS `plans` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `name` VARCHAR(255) NOT NULL,
            `price` DECIMAL(10,2) NOT NULL,
            `duration_days` INT NOT NULL DEFAULT 30,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB;
    " );

    // Inicializar os 3 planos oficiais se tabela vazia
    $check_plans = $pdo->query( "SELECT COUNT(*) FROM plans" )->fetchColumn();
    if ( (int) $check_plans === 0 ) {
        $pdo->exec( "
            INSERT INTO plans (name, price, duration_days) VALUES 
            ('Plano Mensal - Starter', 49.90, 30),
            ('Plano Trimestral - Pro', 129.90, 90),
            ('Plano Anual - Agência', 399.00, 365);
        " );
    }

    // Inicializar configurações padrões se vazias
    $defaults = [
        'asaas_api_key'      => '',
        'asaas_environment'  => 'sandbox',
        'asaas_payment_link' => '',
    ];
    foreach ( $defaults as $key => $val ) {
        $stmt = $pdo->prepare( "INSERT IGNORE INTO settings (key_name, key_value) VALUES (?, ?)" );
        $stmt->execute( [ $key, $val ] );
    }

} catch ( PDOException $e ) {
    http_response_code( 500 );
    exit( 'Falha de conexão com o banco de dados MySQL: ' . htmlspecialchars( $e->getMessage() ) );
}

/**
 * Retorna a instância ativa do PDO.
 */
function get_db_connection(): PDO {
    global $pdo;
    return $pdo;
}

/**
 * Retorna uma configuração do banco MySQL.
 */
function get_setting( string $key, string $default = '' ): string {
    try {
        $db = get_db_connection();
        $stmt = $db->prepare( "SELECT key_value FROM settings WHERE key_name = ? LIMIT 1" );
        $stmt->execute( [ $key ] );
        $row = $stmt->fetch();
        return $row ? (string) $row['key_value'] : $default;
    } catch ( Exception $e ) {
        return $default;
    }
}

/**
 * Atualiza ou insere uma configuração no banco.
 */
function set_setting( string $key, string $value ): void {
    try {
        $db = get_db_connection();
        $stmt = $db->prepare( "INSERT INTO settings (key_name, key_value) VALUES (?, ?) 
                               ON DUPLICATE KEY UPDATE key_value = ?" );
        $stmt->execute( [ $key, $value, $value ] );
    } catch ( Exception $e ) {
        // Ignorar ou logar
    }
}

/**
 * Retorna a URL base do Asaas de acordo com o ambiente no banco.
 */
function get_asaas_base_url(): string {
    $env = get_setting( 'asaas_environment', 'sandbox' );
    return $env === 'production'
        ? 'https://api.asaas.com'
        : 'https://sandbox.asaas.com/api';
}
