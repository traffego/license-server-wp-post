<?php
/**
 * Checkout Público Integrado ao Asaas com Suporte a Renovação de Licença.
 * Permite compra de nova licença ou renovação de licença existente via PIX ou Cartão.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

// Definir esc_html caso não exista (função do WordPress não disponível em contexto standalone)
if ( ! function_exists( 'esc_html' ) ) {
    function esc_html( $str ) {
        return htmlspecialchars( (string) $str, ENT_QUOTES, 'UTF-8' );
    }
}

$api_key = get_setting( 'asaas_api_key', '' );
$env     = get_setting( 'asaas_environment', 'sandbox' );

// Processar Requisição AJAX / POST
if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['action'] ) && $_POST['action'] === 'process_checkout' ) {
    header( 'Content-Type: application/json; charset=utf-8' );

    if ( empty( $api_key ) ) {
        echo json_encode( [ 'success' => false, 'message' => 'Chave de API do Asaas não configurada no servidor.' ] );
        exit;
    }

    $name           = trim( $_POST['name'] ?? '' );
    $email          = filter_var( trim( $_POST['email'] ?? '' ), FILTER_VALIDATE_EMAIL );
    $cpfCnpj        = preg_replace( '/\D/', '', $_POST['cpfCnpj'] ?? '' );
    $phone          = preg_replace( '/\D/', '', $_POST['phone'] ?? '' );
    $payment_method = strtoupper( trim( $_POST['payment_method'] ?? 'PIX' ) );
    $plan_id        = (int) ( $_POST['plan_id'] ?? 0 );
    $renewal_key    = trim( $_POST['renewal_key'] ?? '' );

    if ( empty( $name ) || ! $email || empty( $cpfCnpj ) ) {
        echo json_encode( [ 'success' => false, 'message' => 'Por favor, preencha Nome, E-mail e CPF/CNPJ válidos.' ] );
        exit;
    }

    $db        = get_db_connection();
    $amount    = 49.90;
    $plan_desc = 'Licença WP AI Publisher';

    if ( $plan_id > 0 ) {
        $stmt = $db->prepare( "SELECT * FROM plans WHERE id = ? LIMIT 1" );
        $stmt->execute( [ $plan_id ] );
        $selected_plan = $stmt->fetch();
        if ( $selected_plan ) {
            $amount    = (float) $selected_plan['price'];
            $plan_desc = 'Licença WP AI Publisher - ' . $selected_plan['name'];
        }
    }

    // Helper cURL para API Asaas
    function call_asaas_api( string $endpoint, string $method = 'GET', array $payload = [] ): array {
        $api_key  = get_setting( 'asaas_api_key', '' );
        $base_url = get_asaas_base_url();

        $ch = curl_init( $base_url . $endpoint );
        $headers = [
            'access_token: ' . $api_key,
            'Content-Type: application/json',
        ];

        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => $headers,
        ];

        if ( $method === 'POST' ) {
            $opts[CURLOPT_POST]       = true;
            $opts[CURLOPT_POSTFIELDS] = json_encode( $payload );
        }

        curl_setopt_array( $ch, $opts );
        $res  = curl_exec( $ch );
        $code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
        curl_close( $ch );

        $data = json_decode( $res, true ) ?: [];
        return [ 'code' => $code, 'data' => $data ];
    }

    // 1. Buscar ou Criar Cliente no Asaas
    $cust_res = call_asaas_api( '/v3/customers?cpfCnpj=' . $cpfCnpj );
    $customer_id = '';

    if ( ! empty( $cust_res['data']['data'][0]['id'] ) ) {
        $customer_id = $cust_res['data']['data'][0]['id'];
    } else {
        $create_cust = call_asaas_api( '/v3/customers', 'POST', [
            'name'        => $name,
            'email'       => $email,
            'cpfCnpj'     => $cpfCnpj,
            'mobilePhone' => $phone,
        ] );

        if ( empty( $create_cust['data']['id'] ) ) {
            $msg = $create_cust['data']['errors'][0]['description'] ?? 'Erro ao cadastrar cliente no Asaas.';
            echo json_encode( [ 'success' => false, 'message' => $msg ] );
            exit;
        }
        $customer_id = $create_cust['data']['id'];
    }

    // 2. Criar Cobrança no Asaas
    $payment_payload = [
        'customer'    => $customer_id,
        'billingType' => $payment_method,
        'value'       => $amount,
        'dueDate'     => date( 'Y-m-d' ),
        'description' => $plan_desc . ' - ' . $email,
    ];

    if ( $payment_method === 'CREDIT_CARD' ) {
        $payment_payload['creditCard'] = [
            'holderName'  => trim( $_POST['card_holder'] ?? '' ),
            'number'      => preg_replace( '/\D/', '', $_POST['card_number'] ?? '' ),
            'expiryMonth' => trim( $_POST['card_month'] ?? '' ),
            'expiryYear'  => trim( $_POST['card_year'] ?? '' ),
            'ccv'         => trim( $_POST['card_ccv'] ?? '' ),
        ];
        $payment_payload['creditCardHolderInfo'] = [
            'name'          => $name,
            'email'         => $email,
            'cpfCnpj'       => $cpfCnpj,
            'postalCode'    => preg_replace( '/\D/', '', $_POST['postal_code'] ?? '00000000' ),
            'addressNumber' => '1',
            'mobilePhone'   => $phone,
        ];
    }

    $pay_res = call_asaas_api( '/v3/payments', 'POST', $payment_payload );

    if ( empty( $pay_res['data']['id'] ) ) {
        $msg = $pay_res['data']['errors'][0]['description'] ?? 'Erro ao gerar cobrança no Asaas.';
        echo json_encode( [ 'success' => false, 'message' => $msg ] );
        exit;
    }

    $payment_id     = $pay_res['data']['id'];
    $initial_status = ( $payment_method === 'CREDIT_CARD' && ( $pay_res['data']['status'] ?? '' ) === 'RECEIVED' ) ? 'ACTIVE' : 'SUSPENDED';

    // 3. Processar Chave de Licença (Renovação vs Nova Licença)
    $final_license_key = '';

    if ( ! empty( $renewal_key ) ) {
        // Verificar se a licença informada existe no banco
        $stmt_check = $db->prepare( "SELECT * FROM licenses WHERE license_key = ? LIMIT 1" );
        $stmt_check->execute( [ $renewal_key ] );
        $existing_lic = $stmt_check->fetch();

        if ( $existing_lic ) {
            $final_license_key = $existing_lic['license_key'];
            try {
                $stmt = $db->prepare( "UPDATE licenses SET status = ?, asaas_customer_id = ?, asaas_subscription_id = ? WHERE license_key = ?" );
                $stmt->execute( [ $initial_status, $customer_id, $payment_id, $final_license_key ] );
            } catch ( Exception $e ) {
                echo json_encode( [ 'success' => false, 'message' => 'Erro ao renovar licença: ' . $e->getMessage() ] );
                exit;
            }
        }
    }

    if ( empty( $final_license_key ) ) {
        // Criar Nova Licença
        $final_license_key = 'WPAIP-' . strtoupper( bin2hex( random_bytes( 4 ) ) ) . '-' . strtoupper( bin2hex( random_bytes( 4 ) ) ) . '-' . strtoupper( bin2hex( random_bytes( 4 ) ) );
        try {
            $stmt = $db->prepare( "INSERT INTO licenses (license_key, client_email, status, asaas_customer_id, asaas_subscription_id) VALUES (?, ?, ?, ?, ?)" );
            $stmt->execute( [ $final_license_key, $email, $initial_status, $customer_id, $payment_id ] );
        } catch ( Exception $e ) {
            echo json_encode( [ 'success' => false, 'message' => 'Erro ao criar nova licença: ' . $e->getMessage() ] );
            exit;
        }
    }

    // 4. Se PIX, buscar dados do QR Code
    $pix_data = null;
    if ( $payment_method === 'PIX' ) {
        $pix_res = call_asaas_api( "/v3/payments/{$payment_id}/pixQrCode" );
        if ( ! empty( $pix_res['data']['payload'] ) ) {
            $pix_data = [
                'payload'        => $pix_res['data']['payload'],
                'encodedImage'   => $pix_res['data']['encodedImage'] ?? '',
                'expirationDate' => $pix_res['data']['expirationDate'] ?? '',
            ];
        }
    }

    echo json_encode( [
        'success'        => true,
        'license_key'    => $final_license_key,
        'status'         => $initial_status,
        'payment_method' => $payment_method,
        'is_renewal'     => ! empty( $renewal_key ),
        'pix'            => $pix_data,
        'message'        => 'Checkout processado com sucesso!',
    ] );
    exit;
}

// ── GET: Carregar Dados e Planos ──────────────────────────────────────────────
$db    = get_db_connection();
$plans = $db->query( "SELECT * FROM plans ORDER BY price ASC" )->fetchAll();

// Verificar se veio com chave de licença para renovação
$param_key       = trim( $_GET['key'] ?? $_GET['license_key'] ?? '' );
$renewal_license = null;

if ( ! empty( $param_key ) ) {
    $stmt = $db->prepare( "SELECT * FROM licenses WHERE license_key = ? LIMIT 1" );
    $stmt->execute( [ $param_key ] );
    $renewal_license = $stmt->fetch();
}

$first_price = ! empty( $plans[0]['price'] ) ? number_format( $plans[0]['price'], 2, ',', '.' ) : '49,90';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $renewal_license ? 'Renovação de Licença' : 'Checkout'; ?> — WP AI Publisher</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-color: #0d0b14;
            --card-bg: #161224;
            --input-bg: #1f1a33;
            --accent: #7c3aed;
            --accent-hover: #6d28d9;
            --text-main: #f8fafc;
            --text-sub: #94a3b8;
            --border-color: #2e264d;
            --success: #10b981;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Inter', sans-serif; }
        body { background-color: var(--bg-color); color: var(--text-main); min-height: 100vh; display: flex; justify-content: center; align-items: center; padding: 20px; }

        .container { width: 100%; max-width: 920px; display: grid; grid-template-columns: 1fr 1fr; gap: 30px; }
        @media (max-width: 768px) { .container { grid-template-columns: 1fr; } }

        .card { background: var(--card-bg); border: 1px solid var(--border-color); border-radius: 16px; padding: 30px; box-shadow: 0 20px 40px rgba(0,0,0,0.4); }

        .product-summary { display: flex; flex-direction: column; justify-content: space-between; }
        .product-title { font-size: 24px; font-weight: 800; background: linear-gradient(135deg, #a78bfa, #f472b6); -webkit-background-clip: text; -webkit-text-fill-color: transparent; margin-bottom: 10px; }
        .price-box { margin: 20px 0; padding: 20px; background: rgba(124, 58, 237, 0.1); border: 1px solid rgba(124, 58, 237, 0.3); border-radius: 12px; }
        .price { font-size: 36px; font-weight: 800; color: #fff; }
        .price span { font-size: 14px; color: var(--text-sub); font-weight: 400; }

        .features-list { list-style: none; margin-top: 15px; }
        .features-list li { margin-bottom: 12px; font-size: 14px; color: var(--text-sub); display: flex; align-items: center; gap: 10px; }
        .features-list li::before { content: "✓"; color: var(--success); font-weight: bold; }

        /* Banner de Renovação */
        .renewal-banner {
            background: rgba(124, 58, 237, 0.15);
            border: 1px solid rgba(124, 58, 237, 0.4);
            border-radius: 14px;
            padding: 16px;
            margin-bottom: 24px;
            text-align: center;
        }
        .renewal-title {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #c4b5fd;
            margin-bottom: 4px;
        }
        .renewal-key {
            font-size: 15px;
            font-family: monospace;
            font-weight: 800;
            color: #fff;
        }

        /* Seletor Visual de Planos (Cards Grid) */
        .plans-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
            margin-bottom: 20px;
        }
        @media (max-width: 500px) {
            .plans-grid { grid-template-columns: 1fr; }
        }
        .plan-card {
            background: #191429;
            border: 2px solid var(--border-color);
            border-radius: 12px;
            padding: 14px 8px;
            text-align: center;
            cursor: pointer;
            transition: all 0.2s ease;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            min-height: 105px;
        }
        .plan-card:hover {
            border-color: rgba(124, 58, 237, 0.6);
            transform: translateY(-2px);
        }
        .plan-card.selected {
            border-color: #7c3aed;
            background: rgba(124, 58, 237, 0.25);
            box-shadow: 0 0 15px rgba(124, 58, 237, 0.4);
        }
        .plan-badge {
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            color: #c4b5fd;
            background: rgba(124, 58, 237, 0.35);
            padding: 3px 8px;
            border-radius: 8px;
            display: inline-block;
            margin-bottom: 6px;
        }
        .plan-name {
            font-size: 12px;
            font-weight: 700;
            color: #ffffff;
            margin-bottom: 6px;
            line-height: 1.2;
        }
        .plan-price {
            display: flex;
            align-items: baseline;
            gap: 2px;
            justify-content: center;
        }
        .plan-price .currency {
            font-size: 11px;
            color: #a78bfa;
            font-weight: 600;
        }
        .plan-price .amount {
            font-size: 16px;
            font-weight: 800;
            color: #34d399;
        }

        .form-group { margin-bottom: 18px; }
        label { display: block; font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; color: var(--text-sub); margin-bottom: 8px; }
        input, select { width: 100%; padding: 14px; background: var(--input-bg); border: 1px solid var(--border-color); border-radius: 8px; color: #fff; font-size: 14px; outline: none; transition: 0.2s; }
        input:focus, select:focus { border-color: var(--accent); box-shadow: 0 0 0 3px rgba(124, 58, 237, 0.2); }

        .payment-tabs { display: flex; gap: 10px; margin-bottom: 20px; }
        .tab-btn { flex: 1; padding: 12px; background: var(--input-bg); border: 1px solid var(--border-color); border-radius: 8px; color: var(--text-sub); font-weight: 600; cursor: pointer; text-align: center; transition: 0.2s; }
        .tab-btn.active { background: var(--accent); color: #fff; border-color: var(--accent); }

        .btn-submit { width: 100%; padding: 16px; background: linear-gradient(135deg, var(--accent), #9333ea); border: none; border-radius: 8px; color: #fff; font-size: 16px; font-weight: 700; cursor: pointer; transition: 0.3s; margin-top: 10px; }
        .btn-submit:hover { opacity: 0.9; transform: translateY(-1px); }

        .alert-error { background: rgba(239, 68, 68, 0.1); border: 1px solid #ef4444; color: #f87171; padding: 14px; border-radius: 8px; font-size: 13px; margin-bottom: 20px; display: none; }

        .result-box { display: none; text-align: center; }
        .key-display { background: #110d21; border: 2px dashed var(--accent); padding: 18px; border-radius: 10px; font-family: monospace; font-size: 18px; font-weight: bold; color: #a78bfa; margin: 20px 0; word-break: break-all; }
        .btn-copy { background: #2e264d; border: 1px solid var(--border-color); color: #fff; padding: 10px 20px; border-radius: 6px; font-size: 13px; font-weight: 600; cursor: pointer; }
        .pix-qr { width: 200px; height: 200px; margin: 15px auto; border-radius: 10px; background: #fff; padding: 10px; }
    </style>
</head>
<body>

<div class="container">
    <!-- Produto / Resumo -->
    <div class="card product-summary">
        <div>
            <div class="product-title">WP AI Publisher</div>
            <p style="color: var(--text-sub); font-size: 14px;">Plugin de Automação e Geração de Conteúdo com Inteligência Artificial para WordPress.</p>
            
            <div class="price-box">
                <div class="price" id="display-price">R$ <?php echo $first_price; ?> <span>/ plano</span></div>
                <div style="font-size: 12px; color: var(--text-sub); margin-top: 4px;">Acesso aos modelos OpenAI, Gemini, Claude, DeepSeek e imagens.</div>
            </div>

            <ul class="features-list">
                <li>Geração Ilimitada via Gateway Seguro</li>
                <li>Suporte a DALL-E 3, Gemini Imagen e Poe</li>
                <li>Ativação Instantânea no WordPress</li>
                <li>Atualizações e Suporte Incluídos</li>
            </ul>
        </div>
        <div style="font-size: 11px; color: var(--text-sub); margin-top: 30px;">
            Pagamento 100% seguro via Asaas.
        </div>
    </div>

    <!-- Formulário de Checkout -->
    <div class="card">
        <div id="checkout-form-container">

            <?php if ( $renewal_license ): ?>
                <div class="renewal-banner">
                    <div class="renewal-title">Renovação de Licença Existente</div>
                    <div class="renewal-key"><?php echo esc_html( $renewal_license['license_key'] ); ?></div>
                </div>
            <?php endif; ?>

            <h2 style="font-size: 20px; margin-bottom: 20px;">
                <?php echo $renewal_license ? 'Selecione o Plano de Renovação' : 'Dados de Pagamento'; ?>
            </h2>

            <div id="alert-error" class="alert-error"></div>

            <form id="checkout-form">
                <input type="hidden" name="action" value="process_checkout">
                <?php if ( $renewal_license ): ?>
                    <input type="hidden" name="renewal_key" value="<?php echo esc_html( $renewal_license['license_key'] ); ?>">
                <?php endif; ?>
                
                <?php if ( ! empty( $plans ) ): ?>
                    <label>Selecione o Plano</label>
                    <div class="plans-grid">
                        <?php foreach ( $plans as $index => $p ): ?>
                            <div class="plan-card <?php echo $index === 0 ? 'selected' : ''; ?>" 
                                 onclick="selectPlanCard(this, <?php echo (int) $p['id']; ?>, '<?php echo number_format( (float) $p['price'], 2, '.', '' ); ?>')">
                                <div class="plan-badge"><?php echo (int) $p['duration_days']; ?> dias</div>
                                <div class="plan-name"><?php echo esc_html( $p['name'] ); ?></div>
                                <div class="plan-price">
                                    <span class="currency">R$</span>
                                    <span class="amount"><?php echo number_format( (float) $p['price'], 2, ',', '.' ); ?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <input type="hidden" id="plan_id" name="plan_id" value="<?php echo $plans[0]['id'] ?? 0; ?>">
                <?php endif; ?>

                <div class="form-group">
                    <label for="name">Nome Completo</label>
                    <input type="text" id="name" name="name" required placeholder="Seu nome completo">
                </div>

                <div class="form-group">
                    <label for="email">E-mail de Recebimento da Licença</label>
                    <input type="email" id="email" name="email" required value="<?php echo esc_html( $renewal_license['client_email'] ?? '' ); ?>" placeholder="seu@email.com" <?php echo $renewal_license ? 'readonly style="background: rgba(124, 58, 237, 0.1); color: #c4b5fd;"' : ''; ?>>
                </div>

                <div class="form-group">
                    <label for="cpfCnpj">CPF ou CNPJ</label>
                    <input type="text" id="cpfCnpj" name="cpfCnpj" required placeholder="000.000.000-00">
                </div>

                <div class="form-group">
                    <label for="phone">Telefone / WhatsApp</label>
                    <input type="text" id="phone" name="phone" placeholder="(00) 90000-0000">
                </div>

                <label>Forma de Pagamento</label>
                <div class="payment-tabs">
                    <div class="tab-btn active" onclick="setPaymentMethod('PIX')">⚡ PIX</div>
                    <div class="tab-btn" onclick="setPaymentMethod('CREDIT_CARD')">💳 Cartão</div>
                </div>
                <input type="hidden" id="payment_method" name="payment_method" value="PIX">

                <div id="card-fields" style="display: none;">
                    <div class="form-group">
                        <label for="card_number">Número do Cartão</label>
                        <input type="text" id="card_number" name="card_number" placeholder="0000 0000 0000 0000">
                    </div>
                    <div class="form-group">
                        <label for="card_holder">Nome no Cartão</label>
                        <input type="text" id="card_holder" name="card_holder" placeholder="Como impresso no cartão">
                    </div>
                    <div style="display: flex; gap: 10px;">
                        <div class="form-group" style="flex:1;">
                            <label for="card_month">Mês (MM)</label>
                            <input type="text" id="card_month" name="card_month" placeholder="12">
                        </div>
                        <div class="form-group" style="flex:1;">
                            <label for="card_year">Ano (AAAA)</label>
                            <input type="text" id="card_year" name="card_year" placeholder="2028">
                        </div>
                        <div class="form-group" style="flex:1;">
                            <label for="card_ccv">CVV</label>
                            <input type="text" id="card_ccv" name="card_ccv" placeholder="123">
                        </div>
                    </div>
                </div>

                <button type="submit" id="btn-pay" class="btn-submit">
                    <?php echo $renewal_license ? 'Renovar Licença por R$ ' . $first_price : 'Pagar R$ ' . $first_price; ?>
                </button>
            </form>
        </div>

        <!-- Sucesso / QR Code -->
        <div id="result-box" class="result-box">
            <h2 style="font-size: 22px; color: var(--success); margin-bottom: 10px;">
                🎉 <?php echo $renewal_license ? 'Licença Renovada!' : 'Licença Gerada!'; ?>
            </h2>
            <p style="font-size: 13px; color: var(--text-sub);">
                <?php echo $renewal_license ? 'Sua licença foi renovada com sucesso.' : 'Copie sua chave de licença abaixo e cole no seu plugin WordPress:'; ?>
            </p>

            <div class="key-display" id="generated-key">WPAIP-XXXX-XXXX</div>
            <button class="btn-copy" onclick="copyKey()">📋 Copiar Chave de Licença</button>

            <div id="pix-area" style="margin-top: 25px; display: none;">
                <h3 style="font-size: 15px; margin-bottom: 8px;">Pagamento PIX Pendente</h3>
                <p style="font-size: 12px; color: var(--text-sub);">Escaneie o QR Code abaixo ou use o Copia e Cola para ativar sua licença instantaneamente:</p>
                
                <img id="pix-image" class="pix-qr" src="" alt="QR Code PIX">
                
                <div style="margin-top: 10px;">
                    <input type="text" id="pix-payload" readonly style="font-size: 11px; text-align: center;">
                    <button class="btn-copy" style="margin-top: 8px;" onclick="copyPixPayload()">Copiar Código PIX</button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    const isRenewal = <?php echo $renewal_license ? 'true' : 'false'; ?>;

    function selectPlanCard(card, planId, rawPrice) {
        document.querySelectorAll('.plan-card').forEach(c => c.classList.remove('selected'));
        card.classList.add('selected');
        document.getElementById('plan_id').value = planId;

        const formatted = parseFloat(rawPrice).toLocaleString('pt-BR', { minimumFractionDigits: 2 });
        const prefix = isRenewal ? 'Renovar Licença por R$ ' : 'Pagar R$ ';
        document.getElementById('btn-pay').innerText = prefix + formatted;
        document.getElementById('display-price').innerText = 'R$ ' + formatted;
    }

    function setPaymentMethod(method) {
        document.getElementById('payment_method').value = method;
        document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
        if (method === 'PIX') {
            document.querySelectorAll('.tab-btn')[0].classList.add('active');
            document.getElementById('card-fields').style.display = 'none';
        } else {
            document.querySelectorAll('.tab-btn')[1].classList.add('active');
            document.getElementById('card-fields').style.display = 'block';
        }
    }

    document.getElementById('checkout-form').addEventListener('submit', function(e) {
        e.preventDefault();
        const btn = document.getElementById('btn-pay');
        const alertError = document.getElementById('alert-error');
        alertError.style.display = 'none';
        btn.disabled = true;
        btn.innerText = 'Processando...';

        const formData = new FormData(this);

        fetch('', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            btn.disabled = false;
            btn.innerText = isRenewal ? 'Renovar Licença' : 'Pagar';

            if (!data.success) {
                alertError.innerText = data.message;
                alertError.style.display = 'block';
                return;
            }

            document.getElementById('checkout-form-container').style.display = 'none';
            document.getElementById('result-box').style.display = 'block';
            document.getElementById('generated-key').innerText = data.license_key;

            if (data.pix) {
                document.getElementById('pix-area').style.display = 'block';
                if (data.pix.encodedImage) {
                    document.getElementById('pix-image').src = 'data:image/png;base64,' + data.pix.encodedImage;
                }
                document.getElementById('pix-payload').value = data.pix.payload;
            }
        })
        .catch(err => {
            btn.disabled = false;
            btn.innerText = isRenewal ? 'Renovar Licença' : 'Pagar';
            alertError.innerText = 'Erro de comunicação com o servidor.';
            alertError.style.display = 'block';
        });
    });

    function copyKey() {
        const key = document.getElementById('generated-key').innerText;
        navigator.clipboard.writeText(key);
        alert('Chave de licença copiada para a área de transferência!');
    }

    function copyPixPayload() {
        const payload = document.getElementById('pix-payload').value;
        navigator.clipboard.writeText(payload);
        alert('Código PIX Copia e Cola copiado!');
    }

</script>
</body>
</html>
