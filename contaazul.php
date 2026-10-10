<?php
/**
 * Integração com a API Conta Azul (OAuth 2.0 e Cobranças).
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

class ContaAzulClient {

    const AUTH_URL  = 'https://login.contaazul.com/#/oauth/authorize';
    const TOKEN_URL = 'https://api-v2.contaazul.com/oauth/token';
    const API_BASE  = 'https://api-v2.contaazul.com';

    /**
     * Retorna a URL para redirecionar o usuário para o login OAuth da Conta Azul.
     */
    public static function get_authorize_url( string $state = '' ): string {
        $client_id    = trim( get_setting( 'contaazul_client_id', '' ) );
        $redirect_uri = trim( get_setting( 'contaazul_redirect_uri', '' ) );

        if ( empty( $redirect_uri ) ) {
            $redirect_uri = self::get_default_redirect_uri();
        }

        if ( empty( $state ) ) {
            $state = bin2hex( random_bytes( 16 ) );
        }

        $params = [
            'response_type' => 'code',
            'client_id'     => $client_id,
            'redirect_uri'  => $redirect_uri,
            'scope'         => 'openid profile aws.cognito.signin.user.admin',
            'state'         => $state,
        ];

        return self::AUTH_URL . '?' . http_build_query( $params );
    }

    /**
     * Retorna o Redirect URI padrão baseado no host atual.
     */
    public static function get_default_redirect_uri(): string {
        $scheme = ( ! empty( $_SERVER['HTTPS'] ) && $_SERVER['HTTPS'] !== 'off' ) ? 'https' : 'http';
        $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $script = $_SERVER['SCRIPT_NAME'] ?? '';
        $dir    = rtrim( str_replace( '\\', '/', dirname( $script ) ), '/' );

        // Normalizar caso esteja em subdiretório
        $base = $scheme . '://' . $host . $dir;
        return rtrim( $base, '/' ) . '/api/callback-contaazul.php';
    }

    /**
     * Troca o authorization_code por tokens (access_token + refresh_token).
     */
    public static function handle_authorization_code( string $code ): array {
        $client_id     = trim( get_setting( 'contaazul_client_id', '' ) );
        $client_secret = trim( get_setting( 'contaazul_client_secret', '' ) );
        $redirect_uri  = trim( get_setting( 'contaazul_redirect_uri', '' ) );

        if ( empty( $redirect_uri ) ) {
            $redirect_uri = self::get_default_redirect_uri();
        }

        $basic_auth = base64_encode( $client_id . ':' . $client_secret );

        $post_fields = http_build_query( [
            'grant_type'   => 'authorization_code',
            'code'         => $code,
            'redirect_uri' => $redirect_uri,
        ] );

        $ch = curl_init( self::TOKEN_URL );
        curl_setopt_array( $ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $post_fields,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Basic ' . $basic_auth,
                'Content-Type: application/x-www-form-urlencoded',
                'User-Agent: WPAIPublisher/1.0',
            ],
        ] );

        $response = curl_exec( $ch );
        $code_res = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
        curl_close( $ch );

        $data = json_decode( $response, true ) ?: [];

        if ( $code_res === 200 && ! empty( $data['access_token'] ) ) {
            $expires_in = (int) ( $data['expires_in'] ?? 3600 );
            $expires_at = time() + $expires_in;

            set_setting( 'contaazul_access_token', $data['access_token'] );
            if ( ! empty( $data['refresh_token'] ) ) {
                set_setting( 'contaazul_refresh_token', $data['refresh_token'] );
            }
            set_setting( 'contaazul_token_expires', (string) $expires_at );

            return [ 'success' => true, 'data' => $data ];
        }

        $err = $data['error_description'] ?? $data['message'] ?? ( 'Erro HTTP ' . $code_res );
        return [ 'success' => false, 'message' => $err ];
    }

    /**
     * Renova o token de acesso utilizando o refresh_token.
     */
    public static function refresh_access_token(): array {
        $client_id     = trim( get_setting( 'contaazul_client_id', '' ) );
        $client_secret = trim( get_setting( 'contaazul_client_secret', '' ) );
        $refresh_token = trim( get_setting( 'contaazul_refresh_token', '' ) );

        if ( empty( $client_id ) || empty( $client_secret ) || empty( $refresh_token ) ) {
            return [ 'success' => false, 'message' => 'Credenciais ou refresh_token ausentes.' ];
        }

        $basic_auth  = base64_encode( $client_id . ':' . $client_secret );
        $post_fields = http_build_query( [
            'grant_type'    => 'refresh_token',
            'refresh_token' => $refresh_token,
        ] );

        $ch = curl_init( self::TOKEN_URL );
        curl_setopt_array( $ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $post_fields,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Basic ' . $basic_auth,
                'Content-Type: application/x-www-form-urlencoded',
                'User-Agent: WPAIPublisher/1.0',
            ],
        ] );

        $response = curl_exec( $ch );
        $code_res = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
        curl_close( $ch );

        $data = json_decode( $response, true ) ?: [];

        if ( $code_res === 200 && ! empty( $data['access_token'] ) ) {
            $expires_in = (int) ( $data['expires_in'] ?? 3600 );
            $expires_at = time() + $expires_in;

            set_setting( 'contaazul_access_token', $data['access_token'] );
            if ( ! empty( $data['refresh_token'] ) ) {
                set_setting( 'contaazul_refresh_token', $data['refresh_token'] );
            }
            set_setting( 'contaazul_token_expires', (string) $expires_at );

            return [ 'success' => true, 'access_token' => $data['access_token'] ];
        }

        $err = $data['error_description'] ?? $data['message'] ?? ( 'Falha ao renovar token (HTTP ' . $code_res . ')' );
        return [ 'success' => false, 'message' => $err ];
    }

    /**
     * Retorna um access_token válido (renovando se expirar em menos de 5 min).
     */
    public static function get_valid_access_token(): string {
        $token   = trim( get_setting( 'contaazul_access_token', '' ) );
        $expires = (int) get_setting( 'contaazul_token_expires', '0' );

        // Se expira nos próximos 300 segundos (5 min) ou já expirou, renova
        if ( empty( $token ) || ( time() + 300 ) >= $expires ) {
            $refresh = self::refresh_access_token();
            if ( $refresh['success'] ) {
                return (string) $refresh['access_token'];
            }
            return '';
        }

        return $token;
    }

    /**
     * Requisição autenticada à API v2 da Conta Azul.
     */
    public static function api_request( string $endpoint, string $method = 'GET', array $payload = [] ): array {
        $token = self::get_valid_access_token();
        if ( empty( $token ) ) {
            return [ 'code' => 401, 'data' => [ 'message' => 'Conta Azul não autenticado ou token expirado.' ] ];
        }

        $url = rtrim( self::API_BASE, '/' ) . '/' . ltrim( $endpoint, '/' );
        $ch  = curl_init( $url );

        $headers = [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
            'Accept: application/json',
            'User-Agent: WPAIPublisher/1.0',
        ];

        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => $headers,
        ];

        if ( $method === 'POST' ) {
            $opts[CURLOPT_POST]       = true;
            $opts[CURLOPT_POSTFIELDS] = json_encode( $payload );
        } elseif ( $method === 'PUT' ) {
            $opts[CURLOPT_CUSTOMREQUEST] = 'PUT';
            $opts[CURLOPT_POSTFIELDS]    = json_encode( $payload );
        }

        curl_setopt_array( $ch, $opts );
        $res  = curl_exec( $ch );
        $code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
        curl_close( $ch );

        $data = json_decode( $res, true ) ?: [];
        return [ 'code' => $code, 'data' => $data ];
    }

    /**
     * Buscar ou cadastrar cliente/contato no Conta Azul.
     */
    public static function get_or_create_customer( array $customer_data ): array {
        $email    = trim( $customer_data['email'] ?? '' );
        $cpfCnpj  = preg_replace( '/\D/', '', $customer_data['cpfCnpj'] ?? '' );
        $name     = trim( $customer_data['name'] ?? '' );
        $phone    = preg_replace( '/\D/', '', $customer_data['phone'] ?? '' );

        // 1. Buscar pessoa por documento ou e-mail na API v2
        $filter = ! empty( $cpfCnpj ) ? ( 'documentos=' . urlencode( $cpfCnpj ) ) : ( 'emails=' . urlencode( $email ) );
        $search = self::api_request( '/v1/pessoas?' . $filter );

        if ( ! empty( $search['data'] ) ) {
            $items = $search['data']['items'] ?? ( is_array( $search['data'] ) && isset( $search['data'][0] ) ? $search['data'] : [] );
            if ( ! empty( $items[0]['id'] ) ) {
                return [ 'success' => true, 'customer_id' => $items[0]['id'] ];
            }
        }

        // 2. Cadastrar pessoa na API v2 (/v1/pessoas)
        $is_cnpj     = ( strlen( $cpfCnpj ) > 11 );
        $tipo_pessoa = $is_cnpj ? 'Jurídica' : 'Física';

        $payload = [
            'nome'        => $name,
            'tipo_pessoa' => $tipo_pessoa,
            'perfis'      => [ 'Cliente' ],
            'documento'   => $cpfCnpj,
            'email'       => $email,
            'telefone'    => $phone,
        ];

        $create = self::api_request( '/v1/pessoas', 'POST', $payload );
        $customer_id = $create['data']['id'] ?? ( $create['data']['uuid'] ?? '' );

        if ( in_array( $create['code'], [ 200, 201 ] ) && ! empty( $customer_id ) ) {
            return [ 'success' => true, 'customer_id' => $customer_id ];
        }

        $err = self::extract_error_message( $create, 'Erro ao cadastrar pessoa no Conta Azul.' );
        return [ 'success' => false, 'message' => $err ];
    }

    /**
     * Gerar cobrança (Pix / Boleto / Link) na Conta Azul.
     */
    public static function create_charge( array $charge_data ): array {
        $customer_id = $charge_data['customer_id'];
        $amount      = (float) $charge_data['amount'];
        $description = $charge_data['description'] ?? 'Licença WP AI Publisher';
        $due_date    = $charge_data['due_date'] ?? date( 'Y-m-d', strtotime( '+3 days' ) );

        $payload = [
            'customer_id'     => $customer_id,
            'value'           => $amount,
            'due_date'        => $due_date,
            'description'     => $description,
            'maximo_parcelas' => 1,
            'status'          => 'PENDING',
        ];

        // Tentar endpoint direto de cobrança
        $res = self::api_request( '/v1/financeiro/eventos-financeiros/contas-a-receber/gerar-cobranca', 'POST', $payload );

        if ( in_array( $res['code'], [ 200, 201 ] ) && ! empty( $res['data'] ) ) {
            $data = $res['data'];
            return [
                'success'      => true,
                'charge_id'    => $data['id'] ?? ( $data['cobranca_id'] ?? '' ),
                'link'         => $data['link_pagamento'] ?? ( $data['url'] ?? '' ),
                'pix_code'     => $data['pix_copia_cola'] ?? ( $data['pix'] ?? '' ),
                'pix_qr'       => $data['pix_qr_code_url'] ?? '',
                'bank_slip'    => $data['linha_digitavel'] ?? ( $data['boleto_url'] ?? '' ),
                'raw'          => $data,
            ];
        }

        // Se o endpoint específico não aceitar, criar venda/recebível direto com link
        $fallback = self::api_request( '/v1/financeiro/eventos-financeiros/contas-a-receber', 'POST', [
            'cliente_id'      => $customer_id,
            'valor'           => $amount,
            'data_vencimento' => $due_date,
            'descricao'       => $description,
        ] );

        if ( in_array( $fallback['code'], [ 200, 201 ] ) && ! empty( $fallback['data']['id'] ) ) {
            return [
                'success'   => true,
                'charge_id' => $fallback['data']['id'],
                'link'      => $fallback['data']['link_cobranca'] ?? '',
                'pix_code'  => '',
                'pix_qr'    => '',
                'raw'       => $fallback['data'],
            ];
        }

        $msg = self::extract_error_message( $res, 'Erro ao gerar cobrança no Conta Azul.' );
        if ( ! empty( $fallback['data'] ) && $fallback['code'] !== 404 ) {
            $msg_alt = self::extract_error_message( $fallback, '' );
            if ( $msg_alt ) {
                $msg .= ' | ' . $msg_alt;
            }
        }
        return [ 'success' => false, 'message' => trim( $msg, ' | ' ) ];
    }

    /**
     * Extrai mensagem de erro legível de qualquer resposta da API Conta Azul.
     */
    public static function extract_error_message( array $res, string $default = 'Erro desconhecido' ): string {
        $data = $res['data'] ?? [];
        if ( is_string( $data ) && ! empty( $data ) ) {
            return $data;
        }
        if ( ! empty( $data['message'] ) && is_string( $data['message'] ) ) {
            return $data['message'];
        }
        if ( ! empty( $data['error_description'] ) && is_string( $data['error_description'] ) ) {
            return $data['error_description'];
        }
        if ( ! empty( $data['error'] ) ) {
            if ( is_string( $data['error'] ) ) {
                return $data['error'];
            }
            if ( is_array( $data['error'] ) ) {
                if ( ! empty( $data['error']['message'] ) ) {
                    return (string) $data['error']['message'];
                }
                return json_encode( $data['error'], JSON_UNESCAPED_UNICODE );
            }
        }
        if ( ! empty( $data['errors'] ) && is_array( $data['errors'] ) ) {
            $parts = [];
            foreach ( $data['errors'] as $item ) {
                if ( is_string( $item ) ) {
                    $parts[] = $item;
                } elseif ( is_array( $item ) ) {
                    $field = $item['field'] ?? $item['campo'] ?? '';
                    $m     = $item['message'] ?? $item['mensagem'] ?? $item['description'] ?? json_encode( $item, JSON_UNESCAPED_UNICODE );
                    $parts[] = $field ? "{$field}: {$m}" : $m;
                }
            }
            if ( ! empty( $parts ) ) {
                return implode( '; ', $parts );
            }
        }
        if ( ! empty( $data ) && is_array( $data ) ) {
            return json_encode( $data, JSON_UNESCAPED_UNICODE );
        }
        $code = $res['code'] ?? 0;
        return $default . ( $code ? " (HTTP {$code})" : '' );
    }

    /**
     * Consultar status de uma cobrança para verificar se foi paga.
     */
    public static function check_charge_status( string $charge_id ): string {
        if ( empty( $charge_id ) ) {
            return 'UNKNOWN';
        }

        $res = self::api_request( '/v1/financeiro/eventos-financeiros/contas-a-receber/' . urlencode( $charge_id ) );

        if ( $res['code'] === 200 && ! empty( $res['data'] ) ) {
            $status = strtoupper( (string) ( $res['data']['status'] ?? $res['data']['situacao'] ?? '' ) );

            // Status da Conta Azul mapeados para status da licença
            if ( in_array( $status, [ 'PAGO', 'LIQUIDADO', 'QUITADO', 'RECEBIDO', 'RECEIVED', 'CONFIRMED' ] ) ) {
                return 'PAID';
            }
            if ( in_array( $status, [ 'CANCELADO', 'EXCLUIDO', 'DELETED', 'REFUNDED' ] ) ) {
                return 'CANCELLED';
            }
            if ( in_array( $status, [ 'ATRASADO', 'VENCIDO', 'OVERDUE' ] ) ) {
                return 'OVERDUE';
            }
            return 'PENDING';
        }

        return 'UNKNOWN';
    }
}
