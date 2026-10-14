<?php
/**
 * Integração com a API SyncPay (Cash-In PIX, Cartão de Crédito e Transações).
 *
 * @package WPAIPublisher
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'DB_NAME' ) ) {
    require_once __DIR__ . '/config.php';
    require_once __DIR__ . '/db.php';
}

class SyncPayClient {

    const API_BASE = 'https://api.syncpayments.com.br';

    /**
     * Retorna a URL padrão para o Webhook da SyncPay neste servidor.
     */
    public static function get_default_webhook_url(): string {
        $scheme = ( isset( $_SERVER['HTTPS'] ) && $_SERVER['HTTPS'] === 'on' ) ? 'https' : 'http';
        $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $dir    = rtrim( dirname( $_SERVER['REQUEST_URI'] ?? $_SERVER['SCRIPT_NAME'] ?? '' ), '/\\' );

        // Normalizar caso chamado de dentro de /api/
        if ( str_ends_with( $dir, '/api' ) ) {
            $dir = substr( $dir, 0, -4 );
        }

        return $scheme . '://' . $host . $dir . '/api/webhook-syncpay.php';
    }

    /**
     * Obtém ou renova o Bearer Access Token junto à API SyncPay.
     * O token dura 1 hora.
     */
    public static function get_valid_access_token(): string {
        $token   = get_setting( 'syncpay_access_token', '' );
        $expires = (int) get_setting( 'syncpay_token_expires', '0' );

        // Se o token ainda for válido por pelo menos 60 segundos, reutiliza
        if ( ! empty( $token ) && $expires > ( time() + 60 ) ) {
            return $token;
        }

        $client_id     = trim( get_setting( 'syncpay_client_id', '' ) );
        $client_secret = trim( get_setting( 'syncpay_client_secret', '' ) );

        if ( empty( $client_id ) || empty( $client_secret ) ) {
            return '';
        }

        $payload = [
            'client_id'     => $client_id,
            'client_secret' => $client_secret,
        ];

        $res = self::api_request( '/api/partner/v1/auth-token', 'POST', $payload, true );

        if ( $res['code'] === 200 && ! empty( $res['data']['access_token'] ) ) {
            $new_token  = (string) $res['data']['access_token'];
            $expires_in = (int) ( $res['data']['expires_in'] ?? 3600 );
            $expires_at = time() + $expires_in;

            set_setting( 'syncpay_access_token', $new_token );
            set_setting( 'syncpay_token_expires', (string) $expires_at );

            return $new_token;
        }

        return '';
    }

    /**
     * Faz requisição HTTP à API SyncPay.
     */
    public static function api_request( string $endpoint, string $method = 'GET', array $payload = [], bool $is_auth = false ): array {
        $url = self::API_BASE . $endpoint;
        $ch  = curl_init( $url );

        $headers = [
            'Accept: application/json',
            'Content-Type: application/json',
            'User-Agent: WPAIPublisher/1.0',
        ];

        if ( ! $is_auth ) {
            $token = self::get_valid_access_token();
            if ( empty( $token ) ) {
                return [
                    'code' => 401,
                    'data' => [ 'message' => 'SyncPay não autenticado. Verifique Client ID e Client Secret no painel.' ],
                ];
            }
            $headers[] = 'Authorization: Bearer ' . $token;
        }

        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 25,
            CURLOPT_CUSTOMREQUEST  => strtoupper( $method ),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
        ];

        if ( in_array( strtoupper( $method ), [ 'POST', 'PUT', 'PATCH' ] ) ) {
            $opts[ CURLOPT_POSTFIELDS ] = json_encode( $payload );
        }

        curl_setopt_array( $ch, $opts );
        $response = curl_exec( $ch );
        $code     = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
        $curl_err = curl_error( $ch );
        curl_close( $ch );

        if ( $curl_err ) {
            return [
                'code' => 500,
                'data' => [ 'message' => 'Erro de conexão cURL: ' . $curl_err ],
            ];
        }

        $decoded = json_decode( (string) $response, true );
        return [
            'code' => $code,
            'data' => is_array( $decoded ) ? $decoded : [ 'raw' => $response ],
        ];
    }

    /**
     * Cria solicitação de depósito via Pix (Cash-In).
     *
     * @param array $params Dados da cobrança: amount, name, cpf, email, phone, description, webhook_url.
     * @return array
     */
    public static function create_pix_charge( array $params ): array {
        $amount      = (float) ( $params['amount'] ?? 0 );
        $description = trim( (string) ( $params['description'] ?? 'Licença WP AI Publisher' ) );
        $name        = trim( (string) ( $params['name'] ?? '' ) );
        $cpf         = preg_replace( '/\D/', '', (string) ( $params['cpf'] ?? '' ) );
        $email       = trim( (string) ( $params['email'] ?? '' ) );
        $phone       = preg_replace( '/\D/', '', (string) ( $params['phone'] ?? '' ) );
        $webhook_url = trim( (string) ( $params['webhook_url'] ?? self::get_default_webhook_url() ) );

        if ( $amount <= 0 ) {
            return [ 'success' => false, 'message' => 'Valor da cobrança inválido.' ];
        }

        if ( strlen( $cpf ) !== 11 ) {
            return [ 'success' => false, 'message' => 'CPF inválido para cobrança PIX (deve ter 11 dígitos).' ];
        }

        if ( strlen( $phone ) < 10 || strlen( $phone ) > 11 ) {
            return [ 'success' => false, 'message' => 'Telefone deve possuir DDD + número (10 ou 11 dígitos).' ];
        }

        $payload = [
            'amount'      => round( $amount, 2 ),
            'description' => substr( $description, 0, 100 ),
            'webhook_url' => $webhook_url,
            'client'      => [
                'name'  => $name,
                'cpf'   => $cpf,
                'email' => $email,
                'phone' => $phone,
            ],
        ];

        $res = self::api_request( '/api/partner/v1/cash-in', 'POST', $payload );

        if ( in_array( $res['code'], [ 200, 201 ] ) && ! empty( $res['data']['pix_code'] ) ) {
            return [
                'success'    => true,
                'identifier' => $res['data']['identifier'] ?? '',
                'pix_code'   => $res['data']['pix_code'],
                'message'    => $res['data']['message'] ?? 'Cobrança PIX gerada com sucesso.',
                'raw'        => $res['data'],
            ];
        }

        $err = self::extract_error_message( $res, 'Erro ao gerar PIX na SyncPay.' );
        return [ 'success' => false, 'message' => $err ];
    }

    /**
     * Sela os dados do cartão de crédito em um token seguro temporário de uso único (POST /api/partner/v1/card-tokens).
     *
     * @param array $card Array com: number, holder_name, expiry_month, expiry_year, cvv.
     * @return array
     */
    public static function seal_card( array $card ): array {
        $number       = preg_replace( '/\D/', '', (string) ( $card['number'] ?? '' ) );
        $holder_name  = trim( (string) ( $card['holder_name'] ?? '' ) );
        $expiry_month = sprintf( '%02d', (int) ( $card['expiry_month'] ?? 0 ) );
        $expiry_year  = trim( (string) ( $card['expiry_year'] ?? '' ) );
        $cvv          = trim( (string) ( $card['cvv'] ?? '' ) );

        // Normalizar ano para 4 dígitos
        if ( strlen( $expiry_year ) === 2 ) {
            $expiry_year = '20' . $expiry_year;
        }

        $payload = [
            'card' => [
                'number'       => $number,
                'holder_name'  => strtoupper( $holder_name ),
                'expiry_month' => $expiry_month,
                'expiry_year'  => $expiry_year,
                'cvv'          => $cvv,
            ],
        ];

        $res = self::api_request( '/api/partner/v1/card-tokens', 'POST', $payload );

        $token = $res['data']['data']['token'] ?? ( $res['data']['token'] ?? '' );
        if ( in_array( $res['code'], [ 200, 201 ] ) && ! empty( $token ) ) {
            return [
                'success' => true,
                'token'   => $token,
                'brand'   => $res['data']['data']['brand'] ?? ( $res['data']['brand'] ?? '' ),
                'last4'   => $res['data']['data']['last4'] ?? ( $res['data']['last4'] ?? '' ),
            ];
        }

        $err = self::extract_error_message( $res, 'Erro ao tokenizar cartão de crédito.' );
        return [ 'success' => false, 'message' => $err ];
    }

    /**
     * Realiza a cobrança de cartão de crédito usando token selado (POST /api/partner/v1/credit-card).
     *
     * @param array $params Dados completos da cobrança.
     * @return array
     */
    public static function create_card_charge( array $params ): array {
        $amount       = (float) ( $params['amount'] ?? 0 );
        $description  = trim( (string) ( $params['description'] ?? 'Licença WP AI Publisher' ) );
        $installments = max( 1, (int) ( $params['installments'] ?? 1 ) );
        $card_token   = trim( (string) ( $params['card_token'] ?? '' ) );
        $name         = trim( (string) ( $params['name'] ?? '' ) );
        $email        = trim( (string) ( $params['email'] ?? '' ) );
        $phone        = preg_replace( '/\D/', '', (string) ( $params['phone'] ?? '' ) );
        $cpf          = preg_replace( '/\D/', '', (string) ( $params['cpf'] ?? '' ) );
        $webhook_url  = trim( (string) ( $params['webhook_url'] ?? self::get_default_webhook_url() ) );

        if ( empty( $card_token ) ) {
            return [ 'success' => false, 'message' => 'Token do cartão de crédito não fornecido.' ];
        }

        $ip = $params['ip'] ?? ( $_SERVER['HTTP_CF_CONNECTING_IP'] ?? ( $_SERVER['HTTP_X_FORWARDED_FOR'] ?? ( $_SERVER['REMOTE_ADDR'] ?? '177.136.244.18' ) ) );
        if ( strpos( $ip, ',' ) !== false ) {
            $ip = trim( explode( ',', $ip )[0] );
        }
        if ( empty( $ip ) || $ip === '127.0.0.1' || $ip === '::1' ) {
            $ip = '177.136.244.18';
        }

        // Montar dados do cliente
        $client_data = [
            'name'     => $name,
            'email'    => $email,
            'document' => [
                'type'   => strlen( $cpf ) > 11 ? 'cnpj' : 'cpf',
                'number' => $cpf,
            ],
        ];

        // Normalizar telefone com DDI (55) se fornecido
        if ( ! empty( $phone ) ) {
            if ( ( strlen( $phone ) === 10 || strlen( $phone ) === 11 ) && ! str_starts_with( $phone, '55' ) ) {
                $phone = '55' . $phone;
            }
            $client_data['phone'] = $phone;
        }

        $scheme   = ( isset( $_SERVER['HTTPS'] ) && $_SERVER['HTTPS'] === 'on' ) ? 'https' : 'http';
        $host     = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $uri      = $_SERVER['REQUEST_URI'] ?? '/checkout.php';
        $page_url = $params['page_url'] ?? ( $scheme . '://' . $host . $uri );

        $payload = [
            'amount'       => round( $amount, 2 ),
            'description'  => substr( $description, 0, 100 ),
            'installments' => $installments,
            'webhook_url'  => $webhook_url,
            'card'         => [
                'token' => $card_token,
            ],
            'client'       => $client_data,
            'device'       => [
                'ip'         => $ip,
                'user_agent' => substr( (string) ( $_SERVER['HTTP_USER_AGENT'] ?? 'Mozilla/5.0' ), 0, 255 ),
                'page_url'   => substr( (string) $page_url, 0, 255 ),
                'metadata'   => (object) [],
            ],
        ];

        $res = self::api_request( '/api/partner/v1/credit-card', 'POST', $payload );

        $tx_id = $res['data']['data']['transaction_id'] ?? ( $res['data']['transaction_id'] ?? '' );
        if ( in_array( $res['code'], [ 200, 201 ] ) && ! empty( $tx_id ) ) {
            return [
                'success'        => true,
                'transaction_id' => $tx_id,
                'message'        => $res['data']['data']['message'] ?? ( $res['data']['message'] ?? 'Cobrança no cartão submetida com sucesso.' ),
                'raw'            => $res['data'],
            ];
        }

        $err = self::extract_error_message( $res, 'Transação de cartão não autorizada.' );
        return [ 'success' => false, 'message' => $err ];
    }

    /**
     * Consulta o status de uma transação na SyncPay (GET /api/partner/v1/transaction/{identifier}).
     *
     * @param string $identifier ID da transação / reference_id retornado no CashIn ou Credit Card.
     * @return string Status normalizado: PAID, PENDING, FAILED, REFUNDED ou UNKNOWN.
     */
    public static function check_transaction_status( string $identifier ): string {
        $identifier = trim( $identifier );
        if ( empty( $identifier ) ) {
            return 'UNKNOWN';
        }

        $res = self::api_request( '/api/partner/v1/transaction/' . urlencode( $identifier ) );

        if ( $res['code'] === 200 && ! empty( $res['data']['data']['status'] ) ) {
            $raw_status = strtolower( (string) $res['data']['data']['status'] );

            switch ( $raw_status ) {
                case 'completed':
                case 'paid':
                case 'pago':
                    return 'PAID';
                case 'pending':
                case 'processando':
                    return 'PENDING';
                case 'failed':
                case 'recusado':
                case 'falho':
                    return 'FAILED';
                case 'refunded':
                case 'estornado':
                    return 'REFUNDED';
                case 'med':
                    return 'SUSPENDED';
                default:
                    return strtoupper( $raw_status );
            }
        }

        return 'UNKNOWN';
    }

    /**
     * Extrai mensagem amigável de erro de respostas da SyncPay.
     */
    private static function extract_error_message( array $res, string $fallback ): string {
        $data = $res['data'] ?? [];

        if ( ! empty( $data['message'] ) && is_string( $data['message'] ) ) {
            $msg = $data['message'];
            if ( ! empty( $data['errors'] ) && is_array( $data['errors'] ) ) {
                $err_details = [];
                foreach ( $data['errors'] as $field => $messages ) {
                    if ( is_array( $messages ) ) {
                        $err_details[] = implode( ', ', $messages );
                    } elseif ( is_string( $messages ) ) {
                        $err_details[] = $messages;
                    }
                }
                if ( ! empty( $err_details ) ) {
                    $msg .= ' (' . implode( ' | ', $err_details ) . ')';
                }
            }
            return $msg;
        }

        if ( ! empty( $data['error'] ) && is_string( $data['error'] ) ) {
            return $data['error'];
        }

        return $fallback . ' (HTTP ' . ( $res['code'] ?? '0' ) . ')';
    }
}
