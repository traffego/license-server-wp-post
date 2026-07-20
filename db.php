<?php
/**
 * Conexão com o Banco de Dados MySQL e inicialização das tabelas.
 */

require_once __DIR__ . '/config.php';

try {
    // 1. Tentar conectar ao servidor MySQL (sem especificar banco inicialmente, para criá-lo se necessário)
    $dsn = "mysql:host=" . DB_HOST . ";charset=utf8mb4";
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];
    
    $pdo = new PDO( $dsn, DB_USER, DB_PASS, $options );
    
    // Criar o banco de dados se não existir
    $db_name_safe = preg_replace( '/[^a-zA-Z0-9_]/', '', DB_NAME );
    $pdo->exec( "CREATE DATABASE IF NOT EXISTS `{$db_name_safe}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" );
    
    // Conectar ao banco de dados específico
    $pdo->exec( "USE `{$db_name_safe}`;" );
    
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

} catch ( PDOException $e ) {
    // Em caso de erro, exibir ou logar dependendo do contexto
    if ( count( get_included_files() ) === 1 ) {
        http_response_code( 500 );
        exit( 'Falha de conexão com o banco de dados: ' . $e->getMessage() );
    } else {
        throw $e;
    }
}

/**
 * Retorna a instância ativa do PDO.
 */
function get_db_connection(): PDO {
    global $pdo;
    return $pdo;
}
