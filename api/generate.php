<?php
/**
 * API Gateway: Processa chamadas de IA (Texto e Imagem) intermediando e validando a licença no MySQL.
 */

header( 'Content-Type: application/json; charset=utf-8' );

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';

if ( $_SERVER['REQUEST_METHOD'] !== 'POST' ) {
    http_response_code( 405 );
    echo json_encode( [ 'success' => false, 'message' => 'Método não permitido.' ] );
    exit;
}

// ── Parâmetros Recebidos ──────────────────────────────────────────────────────
$license_key = trim( $_POST['license_key'] ?? '' );
$domain      = trim( $_POST['domain'] ?? '' );
$action      = trim( $_POST['action'] ?? 'text' ); // 'text' ou 'image'
$provider    = trim( $_POST['provider'] ?? '' );
$api_key     = trim( $_POST['api_key'] ?? '' );
$prompt      = $_POST['prompt'] ?? '';
$system      = $_POST['system'] ?? '';
$options_raw = $_POST['options'] ?? '';
$options     = ! empty( $options_raw ) ? json_decode( $options_raw, true ) : [];

// Limpar domínio
$domain = preg_replace( '/^https?:\/\//i', '', $domain );
$domain = rtrim( $domain, '/' );

if ( empty( $license_key ) || empty( $domain ) || empty( $provider ) ) {
    http_response_code( 400 );
    echo json_encode( [ 'success' => false, 'message' => 'Parâmetros obrigatórios ausentes.' ] );
    exit;
}

try {
    $db = get_db_connection();
    
    // 1. Validar licença no banco
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
        echo json_encode( [ 'success' => false, 'message' => 'Licença inativa ou expirada. Status: ' . $license['status'] ] );
        exit;
    }
    
    // 3. Verificar domínio associado
    $stmt = $db->prepare( "SELECT * FROM activations WHERE license_id = ? AND domain = ? LIMIT 1" );
    $stmt->execute( [ $license['id'], $domain ] );
    $activation = $stmt->fetch();
    
    if ( ! $activation ) {
        http_response_code( 403 );
        echo json_encode( [ 'success' => false, 'message' => 'Domínio não autorizado a usar esta licença.' ] );
        exit;
    }
    
    // 4. Executar chamada de IA de acordo com a ação e provedor
    if ( $action === 'text' ) {
        $result = handle_text_generation( $provider, $api_key, $prompt, $system, $options );
    } else {
        $result = handle_image_generation( $provider, $api_key, $prompt, $options );
    }
    
    echo json_encode( $result );

} catch ( Exception $e ) {
    http_response_code( 500 );
    echo json_encode( [ 'success' => false, 'message' => 'Erro interno no gateway: ' . $e->getMessage() ] );
}

// ── Funções de Geração de Texto ──────────────────────────────────────────────

function handle_text_generation( string $provider, string $key, string $prompt, string $system, array $opts ): array {
    if ( empty( $key ) ) {
        return [ 'success' => false, 'message' => 'Chave de API do provedor não informada.' ];
    }

    switch ( $provider ) {
        case 'openai':
            return call_openai_chat( $key, $prompt, $system, $opts );
        case 'gemini':
            return call_gemini_chat( $key, $prompt, $system, $opts );
        case 'anthropic':
            return call_anthropic_chat( $key, $prompt, $system, $opts );
        case 'deepseek':
            return call_deepseek_chat( $key, $prompt, $system, $opts );
        default:
            return [ 'success' => false, 'message' => 'Provedor de texto desconhecido: ' . $provider ];
    }
}

function call_openai_chat( string $key, string $prompt, string $system, array $opts ): array {
    $model      = $opts['model'] ?? 'gpt-4o';
    $max_tokens = (int) ( $opts['max_tokens'] ?? 6000 );

    $payload = [
        'model'      => $model,
        'max_tokens' => $max_tokens,
        'messages'   => [
            [ 'role' => 'system', 'content' => $system ],
            [ 'role' => 'user',   'content' => $prompt ],
        ],
    ];

    $res = curl_post( 'https://api.openai.com/v1/chat/completions', json_encode( $payload ), [
        'Authorization: Bearer ' . $key,
        'Content-Type: application/json'
    ] );

    if ( ! $res['success'] ) return $res;

    $data = json_decode( $res['body'], true );
    $text = $data['choices'][0]['message']['content'] ?? '';
    if ( empty( $text ) ) {
        return [ 'success' => false, 'message' => 'Resposta vazia da OpenAI.' ];
    }

    return [ 'success' => true, 'text' => $text, 'message' => '' ];
}

function call_gemini_chat( string $key, string $prompt, string $system, array $opts ): array {
    $model = $opts['model'] ?? 'gemini-2.0-flash';
    $url   = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$key}";

    $combined_prompt = "Instruções do Sistema:\n{$system}\n\nTarefa:\n{$prompt}";

    $payload = [
        'contents'           => [ [ 'parts' => [ [ 'text' => $combined_prompt ] ] ] ],
        'generationConfig'   => [ 'maxOutputTokens' => $opts['max_tokens'] ?? 8000 ],
    ];

    if ( ! empty( $opts['tools'] ) ) {
        $payload['tools'] = $opts['tools'];
    }

    $res = curl_post( $url, json_encode( $payload ), [
        'Content-Type: application/json'
    ] );

    if ( ! $res['success'] ) return $res;

    $data = json_decode( $res['body'], true );
    $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
    if ( empty( $text ) ) {
        return [ 'success' => false, 'message' => 'Resposta vazia do Gemini.' ];
    }

    return [ 'success' => true, 'text' => $text, 'message' => '' ];
}

function call_anthropic_chat( string $key, string $prompt, string $system, array $opts ): array {
    $model      = $opts['model'] ?? 'claude-sonnet-4-5';
    $max_tokens = (int) ( $opts['max_tokens'] ?? 6000 );

    $payload = [
        'model'      => $model,
        'max_tokens' => $max_tokens,
        'system'     => $system,
        'messages'   => [
            [ 'role' => 'user', 'content' => $prompt ],
        ],
    ];

    $res = curl_post( 'https://api.anthropic.com/v1/messages', json_encode( $payload ), [
        'x-api-key: ' . $key,
        'anthropic-version: 2023-06-01',
        'Content-Type: application/json'
    ] );

    if ( ! $res['success'] ) return $res;

    $data = json_decode( $res['body'], true );
    $text = $data['content'][0]['text'] ?? '';
    if ( empty( $text ) ) {
        return [ 'success' => false, 'message' => 'Resposta vazia da Anthropic.' ];
    }

    return [ 'success' => true, 'text' => $text, 'message' => '' ];
}

function call_deepseek_chat( string $key, string $prompt, string $system, array $opts ): array {
    $model      = $opts['model'] ?? 'deepseek-chat';
    $max_tokens = (int) ( $opts['max_tokens'] ?? 6000 );

    $payload = [
        'model'      => $model,
        'max_tokens' => $max_tokens,
        'messages'   => [
            [ 'role' => 'system', 'content' => $system ],
            [ 'role' => 'user',   'content' => $prompt ],
        ],
    ];

    $res = curl_post( 'https://api.deepseek.com/chat/completions', json_encode( $payload ), [
        'Authorization: Bearer ' . $key,
        'Content-Type: application/json'
    ] );

    if ( ! $res['success'] ) return $res;

    $data = json_decode( $res['body'], true );
    $text = $data['choices'][0]['message']['content'] ?? '';
    if ( empty( $text ) ) {
        return [ 'success' => false, 'message' => 'Resposta vazia do DeepSeek.' ];
    }

    return [ 'success' => true, 'text' => $text, 'message' => '' ];
}

// ── Funções de Geração de Imagem ─────────────────────────────────────────────

function handle_image_generation( string $provider, string $key, string $prompt, array $opts ): array {
    switch ( $provider ) {
        case 'dalle3':
            return call_dalle3( $key, $prompt, $opts );
        case 'gemini':
            return call_gemini_imagen( $key, $prompt, $opts );
        case 'huggingface':
            return call_huggingface( $key, $prompt, $opts );
        case 'pollinations':
            return call_pollinations( $prompt, $opts );
        case 'poe':
            return call_poe_image( $key, $prompt, $opts );
        default:
            return [ 'success' => false, 'message' => 'Provedor de imagem desconhecido: ' . $provider ];
    }
}

function call_poe_image( string $key, string $prompt, array $opts ): array {
    if ( empty( $key ) ) return [ 'success' => false, 'message' => 'Chave de API Poe.com ausente.' ];

    $model = $opts['model'] ?? 'FLUX-schnell';
    $payload = [
        'model'    => $model,
        'messages' => [
            [ 'role' => 'user', 'content' => $prompt ]
        ],
        'stream'   => false
    ];

    $res = curl_post( 'https://api.poe.com/v1/chat/completions', json_encode( $payload ), [
        'Authorization: Bearer ' . $key,
        'Content-Type: application/json'
    ] );

    if ( ! $res['success'] ) return $res;

    $data = json_decode( $res['body'], true );
    $content = $data['choices'][0]['message']['content'] ?? '';
    if ( empty( $content ) ) {
        return [ 'success' => false, 'message' => 'Resposta vazia do Poe.' ];
    }

    if ( preg_match( '/!\[.*?\]\((https?:\/\/[^\s\)]+)\)/i', $content, $matches ) ) {
        $image_url = $matches[1];
    } else if ( preg_match( '/(https?:\/\/[^\s\)]+\.(?:png|jpg|jpeg|webp)(?:\?[^\s\)]*)?)/i', $content, $matches ) ) {
        $image_url = $matches[1];
    } else if ( preg_match( '/(https?:\/\/[^\s\)]+)/i', $content, $matches ) ) {
        $image_url = $matches[1];
    } else {
        return [ 'success' => false, 'message' => 'Não foi possível extrair a URL da imagem da resposta do Poe: ' . htmlspecialchars( $content ) ];
    }

    $image_url = trim( $image_url, '()"\' ' );
    return [ 'success' => true, 'url' => $image_url, 'message' => '' ];
}

function call_dalle3( string $key, string $prompt, array $opts ): array {
    if ( empty( $key ) ) return [ 'success' => false, 'message' => 'Chave de API OpenAI ausente.' ];

    $payload = [
        'model'   => 'dall-e-3',
        'prompt'  => $prompt,
        'n'       => 1,
        'size'    => $opts['size'] ?? '1792x1024',
        'quality' => $opts['quality'] ?? 'standard',
    ];

    $res = curl_post( 'https://api.openai.com/v1/images/generations', json_encode( $payload ), [
        'Authorization: Bearer ' . $key,
        'Content-Type: application/json'
    ] );

    if ( ! $res['success'] ) return $res;

    $data = json_decode( $res['body'], true );
    $url  = $data['data'][0]['url'] ?? '';
    if ( empty( $url ) ) {
        return [ 'success' => false, 'message' => 'Imagem não gerada pela OpenAI.' ];
    }

    return [ 'success' => true, 'url' => $url, 'message' => '' ];
}

function call_gemini_imagen( string $key, string $prompt, array $opts ): array {
    if ( empty( $key ) ) return [ 'success' => false, 'message' => 'Chave de API Gemini ausente.' ];

    $model = $opts['model'] ?? 'imagen-4.0-generate-001';
    $url   = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:predict?key={$key}";

    $payload = [
        'instances'  => [ [ 'prompt' => $prompt ] ],
        'parameters' => [
            'sampleCount'  => 1,
            'aspectRatio'  => $opts['aspect_ratio'] ?? '16:9',
        ],
    ];

    $res = curl_post( $url, json_encode( $payload ), [
        'Content-Type: application/json'
    ] );

    if ( ! $res['success'] ) return $res;

    $data = json_decode( $res['body'], true );
    $b64  = $data['predictions'][0]['bytesBase64Encoded'] ?? '';
    if ( empty( $b64 ) ) {
        return [ 'success' => false, 'message' => 'Imagem não retornada pelo Imagen.' ];
    }

    // O plugin sabe lidar com dados base64 brutos
    return [ 'success' => true, 'base64' => $b64, 'message' => '' ];
}

function call_huggingface( string $key, string $prompt, array $opts ): array {
    if ( empty( $key ) ) return [ 'success' => false, 'message' => 'Chave de API Hugging Face ausente.' ];

    $model = $opts['model'] ?? 'black-forest-labs/FLUX.1-schnell';
    $url   = "https://router.huggingface.co/hf-inference/models/{$model}";

    $payload = [ 'inputs' => $prompt ];

    $res = curl_post( $url, json_encode( $payload ), [
        'Authorization: Bearer ' . $key,
        'Content-Type: application/json'
    ] );

    if ( ! $res['success'] ) return $res;

    // Hugging Face pode retornar a imagem binária diretamente. 
    // Vamos converter o corpo binário para base64 para que o plugin possa decodificá-lo de forma segura e limpa.
    $b64 = base64_encode( $res['body'] );
    return [ 'success' => true, 'base64' => $b64, 'message' => '' ];
}

function call_pollinations( string $prompt, array $opts ): array {
    $width  = $opts['width'] ?? 1024;
    $height = $opts['height'] ?? 576;
    $url    = 'https://image.pollinations.ai/prompt/' . urlencode( $prompt ) . "?width={$width}&height={$height}&nologo=true&private=true";

    return [ 'success' => true, 'url' => $url, 'message' => '' ];
}

// ── Helper HTTP cURL ──────────────────────────────────────────────────────────

function curl_post( string $url, string $payload, array $headers ): array {
    $ch = curl_init( $url );
    curl_setopt_array( $ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 90,
        CURLOPT_SSL_VERIFYPEER => false,
    ] );

    $body = curl_exec( $ch );
    $code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
    $err  = curl_error( $ch );
    curl_close( $ch );

    if ( $body === false ) {
        return [ 'success' => false, 'message' => 'Erro de conexão cURL: ' . $err ];
    }

    if ( $code !== 200 ) {
        $json = json_decode( $body, true );
        $msg = $json['error']['message'] ?? $json['error'] ?? ( 'HTTP ' . $code . ': ' . substr( $body, 0, 300 ) );
        return [ 'success' => false, 'message' => $msg ];
    }

    return [ 'success' => true, 'body' => $body, 'code' => $code ];
}
