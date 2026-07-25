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
$domain = strtolower( trim( $_POST['domain'] ?? '' ) );
$domain = preg_replace( '/^https?:\/\//i', '', $domain );
$domain = explode( '/', $domain )[0];
$domain = explode( ':', $domain )[0];

if ( empty( $license_key ) || empty( $domain ) || empty( $provider ) ) {
    http_response_code( 400 );
    echo json_encode( [ 'success' => false, 'message' => 'Parâmetros obrigatórios ausentes.' ] );
    exit;
}

try {
    $db = get_db_connection();
    
    // 1. Validar licença no banco (busca insensível a maiúsculas/minúsculas)
    $stmt = $db->prepare( "SELECT * FROM licenses WHERE UPPER(license_key) = UPPER(?) LIMIT 1" );
    $stmt->execute( [ $license_key ] );
    $license = $stmt->fetch();
    
    if ( ! $license ) {
        http_response_code( 404 );
        $preview = substr( $license_key, 0, 6 ) . '…';
        echo json_encode( [ 'success' => false, 'message' => 'Chave de licença não encontrada no banco. Recebido: ' . $preview . ' Dom: ' . $domain ] );
        exit;
    }
    
    // 2. Verificar status da licença
    if ( $license['status'] !== 'ACTIVE' ) {
        http_response_code( 403 );
        echo json_encode( [ 'success' => false, 'message' => 'Licença inativa ou expirada. Status: ' . $license['status'] ] );
        exit;
    }
    
    // 3. Verificar domínio associado (normalizado lowercase, sem protocolo/path/porta)
    $stmt = $db->prepare( "SELECT * FROM activations WHERE license_id = ? AND LOWER(TRIM(domain)) = LOWER(TRIM(?)) LIMIT 1" );
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
        case 'cloudflare':
            return call_cloudflare_text( $key, $prompt, $system, $opts );
        default:
            return [ 'success' => false, 'message' => 'Provedor de texto desconhecido: ' . $provider ];
    }
}

function call_openai_chat( string $key, string $prompt, string $system, array $opts ): array {
    if ( empty( $opts['model'] ) ) {
        return [ 'success' => false, 'message' => 'Modelo OpenAI não especificado.' ];
    }
    $model      = $opts['model'];
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
    if ( empty( $opts['model'] ) ) {
        return [ 'success' => false, 'message' => 'Modelo Gemini não especificado.' ];
    }
    $model = $opts['model'];
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
    if ( empty( $opts['model'] ) ) {
        return [ 'success' => false, 'message' => 'Modelo Anthropic não especificado.' ];
    }
    $model      = $opts['model'];
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
    if ( empty( $opts['model'] ) ) {
        return [ 'success' => false, 'message' => 'Modelo DeepSeek não especificado.' ];
    }
    $model      = $opts['model'];
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
            return call_pollinations( $key, $prompt, $opts );
        case 'poe':
            return call_poe_image( $key, $prompt, $opts );
        case 'apiframe':
            return call_apiframe_image( $key, $prompt, $opts );
        case 'cloudflare':
            return call_cloudflare_image( $key, $prompt, $opts );
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

    $model = ! empty( $opts['model'] ) ? $opts['model'] : 'gemini-2.5-flash-image';

    // Se o modelo for antigo ('imagen-*'), substitui automaticamente pelo novo modelo oficial
    if ( strpos( $model, 'imagen' ) !== false ) {
        $model = 'gemini-2.5-flash-image';
    }

    // Modelos modernos 'gemini-*-image' usam o endpoint :generateContent
    $url     = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$key}";
    $payload = [
        'contents'         => [ [ 'parts' => [ [ 'text' => $prompt ] ] ] ],
        'generationConfig' => [ 'responseModalities' => [ 'IMAGE' ] ],
    ];

    $res = curl_post( $url, json_encode( $payload ), [ 'Content-Type: application/json' ] );
    if ( ! $res['success'] ) {
        if ( strpos( $res['message'], 'limit: 0' ) !== false || strpos( $res['message'], 'Quota exceeded' ) !== false ) {
            $res['message'] = 'A API de imagem do Gemini exige faturamento (Billing) ativo no Google AI Studio (cota grátis para imagens = 0). Recomendamos usar Pollinations AI (Grátis sem chave) ou Hugging Face nas Configurações.';
        }
        return $res;
    }

    $data  = json_decode( $res['body'], true );
    $parts = $data['candidates'][0]['content']['parts'] ?? [];
    $b64   = '';
    foreach ( $parts as $p ) {
        if ( ! empty( $p['inlineData']['data'] ) ) {
            $b64 = $p['inlineData']['data'];
            break;
        }
    }

    if ( empty( $b64 ) ) {
        return [ 'success' => false, 'message' => 'Imagem não retornada pelo Gemini.' ];
    }

    return [ 'success' => true, 'base64' => $b64, 'message' => '' ];
}

function call_huggingface( string $key, string $prompt, array $opts ): array {
    if ( empty( $key ) ) return [ 'success' => false, 'message' => 'Chave de API Hugging Face ausente.' ];

    $model = $opts['model'] ?? 'black-forest-labs/FLUX.1-schnell';

    // Novo endpoint: router Together via HuggingFace (OpenAI-compatible, retorna JSON com URL)
    $url     = 'https://router.huggingface.co/together/v1/images/generations';
    $payload = [
        'model'  => $model,
        'prompt' => $prompt,
        'n'      => 1,
        'size'   => $opts['size'] ?? '1024x1024',
    ];

    $res = curl_post( $url, json_encode( $payload ), [
        'Authorization: Bearer ' . $key,
        'Content-Type: application/json',
    ] );

    if ( ! $res['success'] ) return $res;

    $data    = json_decode( $res['body'], true );
    $img_url = $data['data'][0]['url'] ?? '';
    $b64_raw = $data['data'][0]['b64_json'] ?? '';

    // Preferir URL: baixar a imagem e converter para base64
    if ( ! empty( $img_url ) ) {
        $ch = curl_init( $img_url );
        curl_setopt_array( $ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => false,
        ] );
        $img_body = curl_exec( $ch );
        curl_close( $ch );

        if ( $img_body !== false && strlen( $img_body ) > 500 ) {
            return [ 'success' => true, 'base64' => base64_encode( $img_body ), 'message' => '' ];
        }
    }

    // Fallback: b64_json direto
    if ( ! empty( $b64_raw ) ) {
        return [ 'success' => true, 'base64' => $b64_raw, 'message' => '' ];
    }

    return [ 'success' => false, 'message' => 'Imagem não retornada pelo Hugging Face.' ];
}

function call_pollinations( string $key, string $prompt, array $opts ): array {
    $width  = $opts['width'] ?? 1024;
    $height = $opts['height'] ?? 1024;
    $model  = $opts['model'] ?? 'flux';
    $url    = 'https://image.pollinations.ai/prompt/' . urlencode( $prompt ) . "?width={$width}&height={$height}&model={$model}&nologo=true&private=true";

    if ( ! empty( $key ) ) {
        $url .= '&key=' . urlencode( $key );

        $ch = curl_init( $url );
        curl_setopt_array( $ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [ 'Authorization: Bearer ' . $key ],
            CURLOPT_TIMEOUT        => 45,
            CURLOPT_SSL_VERIFYPEER => false,
        ] );
        $body = curl_exec( $ch );
        $code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
        curl_close( $ch );

        if ( $code === 200 && $body !== false && strlen( $body ) > 500 ) {
            return [ 'success' => true, 'base64' => base64_encode( $body ), 'message' => '' ];
        } elseif ( $code !== 200 && $body !== false ) {
            $json = json_decode( $body, true );
            $msg  = $json['error']['message'] ?? $json['error'] ?? ( 'HTTP ' . $code );
            return [ 'success' => false, 'message' => 'Pollinations: ' . $msg ];
        }
    }

    return [ 'success' => true, 'url' => $url, 'message' => '' ];
}

function call_apiframe_image( string $key, string $prompt, array $opts ): array {
    if ( empty( $key ) ) return [ 'success' => false, 'message' => 'Chave de API Apiframe.ai ausente.' ];

    $model = $opts['model'] ?? 'midjourney';

    // 1. Criar job de geração de imagem
    $url     = 'https://api.apiframe.ai/v2/images/generate';
    $payload = [
        'prompt' => $prompt,
        'model'  => $model,
    ];

    $ch = curl_init( $url );
    curl_setopt_array( $ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS     => json_encode( $payload ),
        CURLOPT_HTTPHEADER     => [
            'X-API-Key: ' . $key,
            'Content-Type: application/json',
        ],
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => false,
    ] );

    $body = curl_exec( $ch );
    $code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
    curl_close( $ch );

    $data = json_decode( $body, true );

    if ( $code !== 200 && $code !== 201 && $code !== 202 ) {
        $err = $data['error']['message'] ?? $data['error'] ?? ( 'Erro HTTP ' . $code );
        if ( is_array( $err ) ) $err = json_encode( $err );
        if ( $code === 402 ) {
            $avail = $data['creditsAvailable'] ?? 0;
            $req   = $data['creditsRequired'] ?? 0;
            return [ 'success' => false, 'message' => "APIFrame: Créditos insuficientes na sua conta APIFrame.ai (Disponível: {$avail}, Necessário: {$req})." ];
        }
        return [ 'success' => false, 'message' => 'APIFrame: ' . $err ];
    }

    $job_id = $data['jobId'] ?? $data['task_id'] ?? $data['id'] ?? '';

    // Se a imagem veio direta no primeiro response
    $direct_url = $data['images'][0] ?? $data['image_url'] ?? $data['url'] ?? '';
    if ( ! empty( $direct_url ) ) {
        return [ 'success' => true, 'url' => $direct_url, 'message' => '' ];
    }

    if ( empty( $job_id ) ) {
        $msg = $data['error']['message'] ?? $data['message'] ?? ( 'HTTP ' . $code . ': ' . substr( $body, 0, 200 ) );
        return [ 'success' => false, 'message' => 'APIFrame: ' . $msg ];
    }

    // 2. Polling do Job ID até a imagem ficar pronta (máx 60s)
    for ( $i = 0; $i < 30; $i++ ) {
        sleep( 2 );

        $ch_p = curl_init( 'https://api.apiframe.ai/v2/jobs/' . $job_id );
        curl_setopt_array( $ch_p, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'X-API-Key: ' . $key,
            ],
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => false,
        ] );
        $p_body = curl_exec( $ch_p );
        $p_code = curl_getinfo( $ch_p, CURLINFO_HTTP_CODE );
        curl_close( $ch_p );

        if ( $p_code === 200 ) {
            $p_data = json_decode( $p_body, true );
            $status = strtoupper( $p_data['status'] ?? '' );

            if ( $status === 'COMPLETED' || $status === 'SUCCESS' || $status === 'DONE' || ! empty( $p_data['images'][0] ) ) {
                // Selecionar sempre a primeira imagem individual isolada (images[0]), evitando o quadros 2x2 (gridUrl)
                $img_url = '';
                if ( ! empty( $p_data['images'] ) && is_array( $p_data['images'] ) ) {
                    $img_url = $p_data['images'][0];
                } elseif ( ! empty( $p_data['image_url'] ) ) {
                    $img_url = $p_data['image_url'];
                } elseif ( ! empty( $p_data['url'] ) ) {
                    $img_url = $p_data['url'];
                }

                if ( ! empty( $img_url ) ) {
                    return [ 'success' => true, 'url' => $img_url, 'message' => '' ];
                }
            }

            if ( $status === 'FAILED' || $status === 'ERROR' ) {
                $err_msg = $p_data['error']['message'] ?? $p_data['error'] ?? $p_data['message'] ?? 'Falha ao processar imagem no APIFrame.';
                if ( is_array( $err_msg ) ) $err_msg = json_encode( $err_msg );
                return [ 'success' => false, 'message' => 'APIFrame: ' . $err_msg ];
            }
        }
    }

    return [ 'success' => false, 'message' => 'APIFrame: Tempo limite excedido ao aguardar geração.' ];
}

function call_cloudflare_text( string $key, string $prompt, string $system, array $opts ): array {
    $parts = explode( ':', $key, 2 );
    $account_id = trim( $parts[0] ?? '' );
    $api_token  = trim( $parts[1] ?? '' );

    if ( empty( $account_id ) || empty( $api_token ) ) {
        return [ 'success' => false, 'message' => 'Credenciais Cloudflare (Account ID ou API Token) ausentes.' ];
    }

    $model   = $opts['model'] ?? '@cf/meta/llama-3.3-70b-instruct-fp8';
    $max_tok = (int) ( $opts['max_tokens'] ?? 2500 );

    $url = "https://api.cloudflare.com/client/v4/accounts/{$account_id}/ai/run/{$model}";

    $payload = [
        'messages' => [
            [ 'role' => 'system', 'content' => $system ],
            [ 'role' => 'user',   'content' => $prompt ],
        ],
        'max_tokens' => $max_tok,
    ];

    $res = curl_post( $url, json_encode( $payload ), [
        'Authorization: Bearer ' . $api_token,
        'Content-Type: application/json',
    ] );

    if ( ! $res['success'] ) return $res;

    $data = json_decode( $res['body'], true );
    $text = $data['result']['response'] ?? '';

    if ( empty( $text ) && ! empty( $data['result']['description'] ) ) {
        $text = $data['result']['description'];
    }

    if ( empty( $text ) ) {
        $err = $data['errors'][0]['message'] ?? 'Resposta vazia do Cloudflare Workers AI.';
        return [ 'success' => false, 'message' => 'Cloudflare: ' . $err ];
    }

    return [ 'success' => true, 'text' => trim( $text ), 'message' => '' ];
}

function call_cloudflare_image( string $key, string $prompt, array $opts ): array {
    $parts = explode( ':', $key, 2 );
    $account_id = trim( $parts[0] ?? '' );
    $api_token  = trim( $parts[1] ?? '' );

    if ( empty( $account_id ) || empty( $api_token ) ) {
        return [ 'success' => false, 'message' => 'Credenciais Cloudflare (Account ID ou API Token) ausentes.' ];
    }

    $model = $opts['model'] ?? '@cf/black-forest-labs/flux-1-schnell';
    $url   = "https://api.cloudflare.com/client/v4/accounts/{$account_id}/ai/run/{$model}";

    $payload = [
        'prompt' => $prompt,
        'steps'  => 6,
    ];

    $res = curl_post( $url, json_encode( $payload ), [
        'Authorization: Bearer ' . $api_token,
        'Content-Type: application/json',
    ] );

    if ( ! $res['success'] ) return $res;

    $data = json_decode( $res['body'], true );
    $b64  = $data['result']['image'] ?? '';

    if ( empty( $b64 ) && ! empty( $res['body'] ) && strlen( $res['body'] ) > 500 && empty( $data['errors'] ) ) {
        $b64 = base64_encode( $res['body'] );
    }

    if ( empty( $b64 ) ) {
        $err = $data['errors'][0]['message'] ?? 'Falha ao gerar imagem no Cloudflare Workers AI.';
        return [ 'success' => false, 'message' => 'Cloudflare: ' . $err ];
    }

    return [ 'success' => true, 'base64' => $b64, 'message' => '' ];
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
