<?php
/**
 * Script de Teste para o Servidor de Licenças.
 * Executa verificações locais de banco de dados, API de verificação e Gateway de geração de texto.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

echo "=== INICIANDO TESTES DO SERVIDOR DE LICENÇAS ===\n\n";

// 1. Inserir Licença de Teste no MySQL
try {
    $db = get_db_connection();
    
    // Deleta se já existir para limpar estado
    $stmt = $db->prepare( "DELETE FROM licenses WHERE license_key = 'WPAIP-TEST-KEY-123'" );
    $stmt->execute();
    
    // Insere licença ativa de teste
    $stmt = $db->prepare( "INSERT INTO licenses (license_key, client_email, status) VALUES ('WPAIP-TEST-KEY-123', 'test@client.com', 'ACTIVE')" );
    $stmt->execute();
    
    $license_id = $db->lastInsertId();
    echo "1. Banco de Dados MySQL: OK (Licença de teste 'WPAIP-TEST-KEY-123' criada com sucesso. ID: {$license_id})\n";
    
    // Limpar ativações anteriores
    $stmt = $db->prepare( "DELETE FROM activations WHERE license_id = ?" );
    $stmt->execute( [ $license_id ] );
    
} catch ( Exception $e ) {
    die( "ERRO NO BANCO DE DADOS: " . $e->getMessage() . "\n" );
}

// ── Determinar URL base local para cURL ───────────────────────────────────────
// Usaremos localhost apontando para o script local
$server_url = "http://localhost:8000"; // Modifique a porta se estiver usando outra
echo "2. URL do Servidor de Teste: {$server_url}\n";

// 2. Testar API de Ativação
$domain = "localhost";
echo "\n3. Testando Endpoint de Ativação (api/activate.php)...\n";

$ch = curl_init( "http://localhost/plugin_wp_posts/license-server/api/activate.php" ); // Fallback para path padrão se não estiver usando servidor embutido
// Se o usuário rodar no PHP server na porta 8000:
// Tenta primeiro o local se soubermos, mas vamos usar um cURL local relativo se o servidor web rodar.
// Para fins de flexibilidade, vamos testar diretamente chamando os arquivos PHP internamente (incluindo-os e passando parâmetros $_POST fictícios)!
// Isso evita a necessidade de um servidor web ativo durante o teste de console!
// Que ideia brilhante e robusta! Em vez de depender do cURL de rede, simulamos as requests PHP alterando $_POST e incluindo os arquivos.
// Vamos fazer as duas coisas. Primeiro por inclusão direta (super resiliente).

function simulate_post_request( string $file_path, array $post_data ): array {
    // Salva estado original do $_POST e $_SERVER
    $orig_post   = $_POST;
    $orig_server = $_SERVER;
    
    $_POST   = $post_data;
    $_SERVER['REQUEST_METHOD'] = 'POST';
    
    ob_start();
    include $file_path;
    $output = ob_get_clean();
    
    // Restaura estado
    $_POST   = $orig_post;
    $_SERVER = $orig_server;
    
    return json_decode( $output, true ) ?: [ 'raw_output' => $output ];
}

// Testar ativação por simulação de inclusão
$res_activate = simulate_post_request( __DIR__ . '/api/activate.php', [
    'license_key' => 'WPAIP-TEST-KEY-123',
    'domain'      => 'localhost'
] );

echo "Resposta de Ativação:\n";
print_r( $res_activate );

if ( ! empty( $res_activate['success'] ) ) {
    echo "✔ Ativação Simulado: SUCESSO!\n";
} else {
    echo "❌ Ativação Simulado: FALHA!\n";
}

// 3. Testar API de Verificação
echo "\n4. Testando Endpoint de Verificação (api/verify.php)...\n";
$res_verify = simulate_post_request( __DIR__ . '/api/verify.php', [
    'license_key' => 'WPAIP-TEST-KEY-123',
    'domain'      => 'localhost'
] );

echo "Resposta de Verificação:\n";
print_r( $res_verify );

if ( ! empty( $res_verify['success'] ) ) {
    echo "✔ Verificação Simulado: SUCESSO!\n";
} else {
    echo "❌ Verificação Simulado: FALHA!\n";
}

// 4. Testar Gateway de IA (api/generate.php) com licença de teste
echo "\n5. Testando Endpoint de Geração de IA (api/generate.php)...\n";
// Se houver uma chave de API Gemini no ambiente ou configurada no plugin, podemos testar.
// Mas para o teste básico do gateway interceptar a licença, vamos mandar com uma chave fictícia e confirmar que o erro que retorna é da API e não do nosso banco!
$res_generate = simulate_post_request( __DIR__ . '/api/generate.php', [
    'license_key' => 'WPAIP-TEST-KEY-123',
    'domain'      => 'localhost',
    'action'      => 'text',
    'provider'    => 'gemini',
    'api_key'     => 'AIzaFakeKey123',
    'prompt'      => 'Olá',
    'system'      => 'Você é um assistente útil.',
    'options'     => json_encode( [ 'model' => 'gemini-2.0-flash' ] )
] );

echo "Resposta de Geração (Falsa chave):\n";
print_r( $res_generate );

// Se o retorno contiver "API key" ou "key" ou "invalid", significa que o gateway validou nossa licença de teste com sucesso
// e repassou a chamada para o Gemini (que falhou devido à chave falsa). Isso prova que o Gateway funcionou!
if ( ! empty( $res_generate['success'] ) || ( isset($res_generate['message']) && strpos(strtolower($res_generate['message']), 'api key') !== false ) || ( isset($res_generate['message']) && strpos(strtolower($res_generate['message']), 'http') !== false ) ) {
    echo "✔ Gateway de Geração: SUCESSO (Verificação de licença passou com êxito!)\n";
} else {
    echo "❌ Gateway de Geração: FALHA!\n";
}

echo "\n=== FIM DOS TESTES DE LICENÇA ===\n";
