<?php
/**
 * Painel Administrativo de Licenças.
 * Design premium e moderno (Dark Mode, layout fluído e responsivo).
 */

session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

// ── Autenticação Simples ──────────────────────────────────────────────────────
$authenticated = isset( $_SESSION['logged_in'] ) && $_SESSION['logged_in'] === true;

if ( isset( $_POST['login'] ) ) {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    if ( $username === ADMIN_USER && $password === ADMIN_PASS ) {
        $_SESSION['logged_in'] = true;
        header( 'Location: index.php' );
        exit;
    } else {
        $login_error = 'Usuário ou senha inválidos.';
    }
}

if ( isset( $_GET['logout'] ) ) {
    $_SESSION['logged_in'] = false;
    session_destroy();
    header( 'Location: index.php' );
    exit;
}

if ( ! $authenticated ) {
    // Renderiza a página de login estilizada
    ?>
    <!DOCTYPE html>
    <html lang="pt-BR">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Login — License Server</title>
        <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;800&display=swap" rel="stylesheet">
        <style>
            * { box-sizing: border-box; margin: 0; padding: 0; }
            body {
                background: #0b0813;
                font-family: 'Outfit', sans-serif;
                color: #f8fafc;
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
                position: relative;
                overflow: hidden;
            }
            body::before {
                content: '';
                position: absolute;
                inset: -50%;
                background: radial-gradient(circle at 30% 30%, rgba(124, 58, 237, 0.2) 0%, transparent 60%),
                            radial-gradient(circle at 70% 70%, rgba(236, 72, 153, 0.15) 0%, transparent 60%);
                z-index: -1;
            }
            .login-container {
                background: rgba(255, 255, 255, 0.03);
                border: 1px solid rgba(255, 255, 255, 0.08);
                backdrop-filter: blur(20px);
                border-radius: 24px;
                padding: 48px;
                width: 100%;
                max-width: 440px;
                box-shadow: 0 20px 50px rgba(0, 0, 0, 0.4), inset 0 1px 0 rgba(255, 255, 255, 0.1);
                text-align: center;
            }
            h1 {
                font-size: 28px;
                font-weight: 800;
                margin-bottom: 8px;
                background: linear-gradient(135deg, #fff, #c4b5fd);
                -webkit-background-clip: text;
                -webkit-text-fill-color: transparent;
            }
            p.subtitle {
                font-size: 14px;
                color: rgba(248, 250, 252, 0.5);
                margin-bottom: 32px;
            }
            .form-group {
                margin-bottom: 20px;
                text-align: left;
            }
            label {
                display: block;
                font-size: 13px;
                font-weight: 600;
                color: rgba(248, 250, 252, 0.7);
                margin-bottom: 8px;
                text-transform: uppercase;
                letter-spacing: 0.05em;
            }
            input {
                width: 100%;
                padding: 14px 16px;
                background: rgba(255, 255, 255, 0.05);
                border: 1px solid rgba(255, 255, 255, 0.1);
                border-radius: 12px;
                color: #fff;
                font-family: inherit;
                font-size: 15px;
                transition: border-color 0.2s, background-color 0.2s;
            }
            input:focus {
                outline: none;
                border-color: #7c3aed;
                background: rgba(255, 255, 255, 0.08);
            }
            button {
                width: 100%;
                padding: 14px;
                background: linear-gradient(135deg, #7c3aed, #ec4899);
                border: none;
                border-radius: 12px;
                color: #fff;
                font-weight: 600;
                font-size: 16px;
                cursor: pointer;
                transition: transform 0.2s, box-shadow 0.2s;
                margin-top: 10px;
                box-shadow: 0 10px 25px rgba(124, 58, 237, 0.3);
            }
            button:hover {
                transform: translateY(-2px);
                box-shadow: 0 15px 30px rgba(124, 58, 237, 0.45);
            }
            .error-message {
                background: rgba(239, 68, 68, 0.15);
                border: 1px solid rgba(239, 68, 68, 0.3);
                color: #f87171;
                font-size: 13px;
                padding: 12px;
                border-radius: 8px;
                margin-bottom: 20px;
                text-align: left;
            }
        </style>
    </head>
    <body>
        <div class="login-container">
            <h1>License Server</h1>
            <p class="subtitle">Faça login para gerenciar as licenças de uso</p>
            <?php if ( isset( $login_error ) ): ?>
                <div class="error-message"><?php echo $login_error; ?></div>
            <?php endif; ?>
            <form action="" method="POST">
                <div class="form-group">
                    <label for="username">Usuário</label>
                    <input type="text" name="username" id="username" required autocomplete="off">
                </div>
                <div class="form-group">
                    <label for="password">Senha</label>
                    <input type="password" name="password" id="password" required>
                </div>
                <button type="submit" name="login">Entrar no Painel</button>
            </form>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// ── Se logado, processar ações do Painel ────────────────────────────────────────
$db = get_db_connection();

$message = '';
$error = '';

// Salvar configurações do Asaas
if ( isset( $_POST['save_asaas_settings'] ) ) {
    $api_key  = trim( $_POST['asaas_api_key'] ?? '' );
    $env      = trim( $_POST['asaas_environment'] ?? 'sandbox' );
    $pay_link = trim( $_POST['asaas_payment_link'] ?? '' );
    
    try {
        set_setting( 'asaas_api_key', $api_key );
        set_setting( 'asaas_environment', $env );
        set_setting( 'asaas_payment_link', $pay_link );
        $message = "Configurações do Asaas salvas com sucesso.";
    } catch ( Exception $e ) {
        $error = "Erro ao salvar configurações: " . $e->getMessage();
    }
}

// Criar novo plano
if ( isset( $_POST['create_plan'] ) ) {
    $plan_name  = trim( $_POST['plan_name'] ?? '' );
    $plan_price = (float) ( $_POST['plan_price'] ?? 0 );
    $plan_days  = (int) ( $_POST['plan_days'] ?? 30 );

    if ( empty( $plan_name ) || $plan_price <= 0 ) {
        $error = 'Nome do plano e preço válidos são obrigatórios.';
    } else {
        try {
            $stmt = $db->prepare( "INSERT INTO plans (name, price, duration_days) VALUES (?, ?, ?)" );
            $stmt->execute( [ $plan_name, $plan_price, $plan_days ] );
            $message = "Plano '{$plan_name}' criado com sucesso!";
        } catch ( PDOException $e ) {
            $error = 'Erro ao criar plano: ' . $e->getMessage();
        }
    }
}

// Excluir plano
if ( isset( $_GET['delete_plan'] ) ) {
    $plan_id = (int) $_GET['delete_plan'];
    try {
        $stmt = $db->prepare( "DELETE FROM plans WHERE id = ?" );
        $stmt->execute( [ $plan_id ] );
        $message = "Plano excluído com sucesso.";
    } catch ( PDOException $e ) {
        $error = 'Erro ao excluir plano: ' . $e->getMessage();
    }
}

// Criar nova licença
if ( isset( $_POST['create_license'] ) ) {
    $email = filter_var( $_POST['email'] ?? '', FILTER_VALIDATE_EMAIL );
    $custom_key = trim( $_POST['custom_key'] ?? '' );
    $status = $_POST['status'] ?? 'ACTIVE';
    $asaas_sub = trim( $_POST['asaas_subscription_id'] ?? '' );
    
    if ( ! $email ) {
        $error = 'E-mail do cliente inválido.';
    } else {
        // Gerar chave aleatória se não for customizada
        if ( empty( $custom_key ) ) {
            $custom_key = 'WPAIP-' . strtoupper( bin2hex( random_bytes( 4 ) ) ) . '-' . strtoupper( bin2hex( random_bytes( 4 ) ) ) . '-' . strtoupper( bin2hex( random_bytes( 4 ) ) );
        }
        
        try {
            $stmt = $db->prepare( "INSERT INTO licenses (license_key, client_email, status, asaas_subscription_id) VALUES (?, ?, ?, ?)" );
            $stmt->execute( [ $custom_key, $email, $status, empty($asaas_sub) ? null : $asaas_sub ] );
            $message = "Licença gerada com sucesso: <strong>{$custom_key}</strong>";
        } catch ( PDOException $e ) {
            $error = 'Erro ao criar licença: ' . $e->getMessage();
        }
    }
}

// Excluir licença
if ( isset( $_GET['delete_license'] ) ) {
    $id = (int) $_GET['delete_license'];
    try {
        $stmt = $db->prepare( "DELETE FROM licenses WHERE id = ?" );
        $stmt->execute( [ $id ] );
        $message = "Licença removida com sucesso.";
    } catch ( PDOException $e ) {
        $error = 'Erro ao remover licença: ' . $e->getMessage();
    }
}

// Alterar Status
if ( isset( $_POST['update_status'] ) ) {
    $id = (int) $_POST['license_id'];
    $new_status = $_POST['status'] ?? 'ACTIVE';
    
    try {
        $stmt = $db->prepare( "UPDATE licenses SET status = ? WHERE id = ?" );
        $stmt->execute( [ $new_status, $id ] );
        $message = "Status da licença atualizado.";
    } catch ( PDOException $e ) {
        $error = 'Erro ao atualizar status: ' . $e->getMessage();
    }
}

// Limpar domínios associados
if ( isset( $_GET['clear_domains'] ) ) {
    $id = (int) $_GET['clear_domains'];
    try {
        $stmt = $db->prepare( "DELETE FROM activations WHERE license_id = ?" );
        $stmt->execute( [ $id ] );
        $message = "Domínios liberados com sucesso.";
    } catch ( PDOException $e ) {
        $error = 'Erro ao liberar domínios: ' . $e->getMessage();
    }
}

// Buscar licenças
$search = $_GET['search'] ?? '';
if ( ! empty( $search ) ) {
    $stmt = $db->prepare( "SELECT l.*, GROUP_CONCAT(a.domain SEPARATOR ', ') as domains 
                           FROM licenses l 
                           LEFT JOIN activations a ON l.id = a.license_id 
                           WHERE l.license_key LIKE ? OR l.client_email LIKE ?
                           GROUP BY l.id
                           ORDER BY l.id DESC" );
    $stmt->execute( [ "%$search%", "%$search%" ] );
} else {
    $stmt = $db->query( "SELECT l.*, GROUP_CONCAT(a.domain SEPARATOR ', ') as domains 
                         FROM licenses l 
                         LEFT JOIN activations a ON l.id = a.license_id 
                         GROUP BY l.id
                         ORDER BY l.id DESC" );
}
$licenses = $stmt->fetchAll();

// Buscar planos cadastrados
$plans = $db->query( "SELECT * FROM plans ORDER BY price ASC" )->fetchAll();

// Testar Asaas API
$asaas_status = 'Não configurado';
$asaas_class = 'status-inactive';
$db_api_key  = get_setting( 'asaas_api_key', '' );
$db_env      = get_setting( 'asaas_environment', 'sandbox' );

if ( ! empty( $db_api_key ) ) {
    $ch = curl_init( get_asaas_base_url() . '/v3/customers?limit=1' );
    curl_setopt_array( $ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_HTTPHEADER     => [ 'access_token: ' . $db_api_key ],
    ] );
    $res = curl_exec( $ch );
    $code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
    curl_close( $ch );
    
    if ( $code === 200 ) {
        $asaas_status = 'Conectado (' . strtoupper( $db_env ) . ')';
        $asaas_class = 'status-active';
    } else {
        $asaas_status = 'Erro de Conexão (HTTP ' . $code . ')';
        $asaas_class = 'status-error';
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard — License Server</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            background: #08060f;
            font-family: 'Outfit', sans-serif;
            color: #f8fafc;
            min-height: 100vh;
            padding: 40px 20px;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
        }
        header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 40px;
            padding-bottom: 20px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
        }
        h1 {
            font-size: 32px;
            font-weight: 800;
            background: linear-gradient(135deg, #fff 0%, #c4b5fd 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        .header-actions {
            display: flex;
            align-items: center;
            gap: 20px;
        }
        .status-badge {
            font-size: 13px;
            padding: 6px 12px;
            border-radius: 20px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .status-active { background: rgba(34, 197, 94, 0.15); color: #4ade80; border: 1px solid rgba(34, 197, 94, 0.3); }
        .status-inactive { background: rgba(148, 163, 184, 0.15); color: #cbd5e1; border: 1px solid rgba(148, 163, 184, 0.3); }
        .status-error { background: rgba(239, 68, 68, 0.15); color: #f87171; border: 1px solid rgba(239, 68, 68, 0.3); }
        
        .btn {
            padding: 10px 20px;
            border-radius: 8px;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            font-size: 14px;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: none;
        }
        .btn-primary {
            background: linear-gradient(135deg, #7c3aed, #ec4899);
            color: #fff;
        }
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(124, 58, 237, 0.3);
        }
        .btn-secondary {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            color: #f8fafc;
        }
        .btn-secondary:hover {
            background: rgba(255, 255, 255, 0.1);
        }
        .btn-danger {
            background: rgba(239, 68, 68, 0.15);
            border: 1px solid rgba(239, 68, 68, 0.3);
            color: #f87171;
        }
        .btn-danger:hover {
            background: rgba(239, 68, 68, 0.25);
        }
        
        /* Grid Layout */
        .grid {
            display: grid;
            grid-template-columns: 1fr 2fr;
            gap: 40px;
            margin-bottom: 40px;
        }
        @media (max-width: 900px) {
            .grid { grid-template-columns: 1fr; }
        }
        
        .card {
            background: rgba(255, 255, 255, 0.02);
            border: 1px solid rgba(255, 255, 255, 0.07);
            border-radius: 16px;
            padding: 30px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
        }
        .card h2 {
            font-size: 20px;
            font-weight: 700;
            margin-bottom: 20px;
            color: #fff;
        }
        
        /* Form Styles */
        .form-group {
            margin-bottom: 20px;
        }
        label {
            display: block;
            font-size: 12px;
            font-weight: 600;
            color: rgba(248, 250, 252, 0.6);
            margin-bottom: 6px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        input, select {
            width: 100%;
            padding: 12px;
            background: rgba(255, 255, 255, 0.04);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 8px;
            color: #fff;
            font-family: inherit;
            font-size: 14px;
        }
        input:focus, select:focus {
            outline: none;
            border-color: #7c3aed;
        }
        
        /* Notifications */
        .alert {
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 30px;
            font-size: 14px;
        }
        .alert-success { background: rgba(34, 197, 94, 0.15); border: 1px solid rgba(34, 197, 94, 0.3); color: #86efac; }
        .alert-danger { background: rgba(239, 68, 68, 0.15); border: 1px solid rgba(239, 68, 68, 0.3); color: #fca5a5; }
        
        /* Table Styles */
        .table-responsive {
            overflow-x: auto;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
            font-size: 14px;
        }
        th, td {
            padding: 16px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
        }
        th {
            font-weight: 600;
            color: rgba(248, 250, 252, 0.5);
            text-transform: uppercase;
            font-size: 11px;
            letter-spacing: 0.08em;
        }
        tr:hover td {
            background: rgba(255, 255, 255, 0.01);
        }
        
        .license-key-col {
            font-family: monospace;
            font-size: 14px;
            font-weight: bold;
            color: #c4b5fd;
            background: rgba(124, 58, 237, 0.08);
            padding: 4px 8px;
            border-radius: 6px;
            border: 1px solid rgba(124, 58, 237, 0.15);
        }
        .domain-tag {
            background: rgba(255,255,255,0.05);
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 12px;
            color: #94a3b8;
            font-family: monospace;
        }
        
        .search-bar {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
        }
        .search-bar input {
            max-width: 300px;
        }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <div>
                <h1>Painel de Licenças</h1>
                <p style="color: rgba(248, 250, 252, 0.4); font-size: 14px; margin-top: 4px;">WP AI Publisher Central License Manager</p>
            </div>
            <div class="header-actions">
                <span class="status-badge <?php echo $asaas_class; ?>">
                    Asaas: <?php echo $asaas_status; ?>
                </span>
                <a href="?logout=1" class="btn btn-secondary">Sair do Painel</a>
            </div>
        </header>

        <?php if ( ! empty( $message ) ): ?>
            <div class="alert alert-success"><?php echo $message; ?></div>
        <?php endif; ?>
        <?php if ( ! empty( $error ) ): ?>
            <div class="alert alert-danger"><?php echo $error; ?></div>
        <?php endif; ?>

        <div class="grid">
            <!-- Sidebar: Criar Licença -->
            <div>
                <div class="card">
                    <h2>Nova Licença</h2>
                    <form action="" method="POST">
                        <div class="form-group">
                            <label for="email">E-mail do Cliente</label>
                            <input type="email" name="email" id="email" required placeholder="cliente@email.com">
                        </div>
                        <div class="form-group">
                            <label for="custom_key">Chave de Licença (Opcional)</label>
                            <input type="text" name="custom_key" id="custom_key" placeholder="Deixe em branco para auto-gerar">
                        </div>
                        <div class="form-group">
                            <label for="asaas_subscription_id">ID Assinatura Asaas (Opcional)</label>
                            <input type="text" name="asaas_subscription_id" id="asaas_subscription_id" placeholder="sub_xxxxxxxxxxxx">
                        </div>
                        <div class="form-group">
                            <label for="status">Status Inicial</label>
                            <select name="status" id="status">
                                <option value="ACTIVE">Ativo (ACTIVE)</option>
                                <option value="SUSPENDED">Suspenso (SUSPENDED)</option>
                                <option value="EXPIRED">Expirado (EXPIRED)</option>
                            </select>
                        </div>
                        <button type="submit" name="create_license" class="btn btn-primary" style="width:100%;">Gerar Licença</button>
                    </form>
                </div>

                <div class="card" style="margin-top: 30px;">
                    <h2>Configurações do Asaas</h2>
                    <form action="" method="POST">
                        <div class="form-group">
                            <label for="asaas_api_key">Chave de API Asaas</label>
                            <input type="password" name="asaas_api_key" id="asaas_api_key" value="<?php echo esc_html( get_setting( 'asaas_api_key' ) ); ?>" placeholder="$aact_...">
                        </div>
                        <div class="form-group">
                            <label for="asaas_environment">Ambiente</label>
                            <select name="asaas_environment" id="asaas_environment">
                                <option value="sandbox" <?php selected( get_setting( 'asaas_environment', 'sandbox' ), 'sandbox' ); ?>>Sandbox (Testes)</option>
                                <option value="production" <?php selected( get_setting( 'asaas_environment', 'sandbox' ), 'production' ); ?>>Produção</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="asaas_payment_link">Link de Pagamento Padrão (Se em branco, usará o Checkout abaixo)</label>
                            <input type="url" name="asaas_payment_link" id="asaas_payment_link" value="<?php echo esc_html( get_setting( 'asaas_payment_link' ) ); ?>" placeholder="https://.../checkout.php">
                        </div>
                        <div class="form-group" style="margin-top: 15px; background: rgba(124,58,237,0.1); padding: 12px; border-radius: 8px; border: 1px solid rgba(124,58,237,0.2);">
                            <label style="color:#a78bfa;">Link do Checkout Público Integrado</label>
                            <?php $checkout_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://" . ($_SERVER['HTTP_HOST'] ?? 'localhost') . rtrim(dirname($_SERVER['REQUEST_URI'] ?? ''), '/\\') . "/checkout.php"; ?>
                            <input type="text" readonly value="<?php echo esc_html($checkout_url); ?>" onclick="this.select()" style="font-size:12px; font-family:monospace; cursor:pointer;">
                        </div>
                        <button type="submit" name="save_asaas_settings" class="btn btn-primary" style="width:100%;">Salvar Configurações</button>
                    </form>
                </div>

                <div class="card" style="margin-top: 30px;">
                    <h2>Planos de Assinatura</h2>
                    <form action="" method="POST" style="margin-bottom: 20px;">
                        <div class="form-group">
                            <label for="plan_name">Nome do Plano</label>
                            <input type="text" name="plan_name" id="plan_name" required placeholder="Ex: Plano Mensal, Anual">
                        </div>
                        <div style="display:flex; gap:10px;">
                            <div class="form-group" style="flex:1;">
                                <label for="plan_price">Preço (R$)</label>
                                <input type="number" step="0.01" name="plan_price" id="plan_price" required placeholder="49.90">
                            </div>
                            <div class="form-group" style="flex:1;">
                                <label for="plan_days">Validade (Dias)</label>
                                <input type="number" name="plan_days" id="plan_days" required value="30" placeholder="30">
                            </div>
                        </div>
                        <button type="submit" name="create_plan" class="btn btn-primary" style="width:100%;">Adicionar Plano</button>
                    </form>

                    <h3 style="font-size:14px; color:rgba(248,250,252,0.6); margin-bottom:10px;">Planos Ativos</h3>
                    <?php if ( empty( $plans ) ): ?>
                        <p style="font-size:12px; color:rgba(248,250,252,0.4);">Nenhum plano cadastrado.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table style="font-size:12px;">
                                <thead>
                                    <tr>
                                        <th>Nome</th>
                                        <th>Valor</th>
                                        <th>Dias</th>
                                        <th>Ação</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ( $plans as $p ): ?>
                                        <tr>
                                            <td style="font-weight:600;"><?php echo esc_html( $p['name'] ); ?></td>
                                            <td style="color:#10b981; font-weight:bold;">R$ <?php echo number_format( $p['price'], 2, ',', '.' ); ?></td>
                                            <td><?php echo $p['duration_days']; ?> d</td>
                                            <td>
                                                <a href="?delete_plan=<?php echo $p['id']; ?>" class="btn btn-danger" style="padding:4px 8px; font-size:10px;" onclick="return confirm('Excluir este plano?')">Excluir</a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Listagem de Licenças -->
            <div>
                <div class="card">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
                        <h2>Licenças Cadastradas</h2>
                        <form action="" method="GET" class="search-bar">
                            <input type="text" name="search" placeholder="Buscar por chave ou e-mail..." value="<?php echo esc_html( $search ); ?>">
                            <button type="submit" class="btn btn-secondary">Buscar</button>
                        </form>
                    </div>

                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>Chave</th>
                                    <th>Cliente / E-mail</th>
                                    <th>Domínio(s)</th>
                                    <th>Status</th>
                                    <th>Ações</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ( empty( $licenses ) ): ?>
                                    <tr>
                                        <td colspan="5" style="text-align:center; color:rgba(248, 250, 252, 0.4); padding:40px;">
                                            Nenhuma licença encontrada.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ( $licenses as $lic ): ?>
                                        <tr>
                                            <td>
                                                <span class="license-key-col"><?php echo esc_html( $lic['license_key'] ); ?></span>
                                                <?php if ( $lic['asaas_subscription_id'] ): ?>
                                                    <div style="font-size:10px; color:#a78bfa; margin-top:6px; font-family:monospace;">
                                                        <?php echo esc_html( $lic['asaas_subscription_id'] ); ?>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div style="font-weight:600;"><?php echo esc_html( $lic['client_email'] ); ?></div>
                                                <div style="font-size:11px; color:rgba(248, 250, 252, 0.4); margin-top:4px;">
                                                    Gerada em: <?php echo date( 'd/m/Y H:i', strtotime( $lic['created_at'] ) ); ?>
                                                </div>
                                            </td>
                                            <td>
                                                <?php if ( $lic['domains'] ): ?>
                                                    <?php foreach ( explode( ', ', $lic['domains'] ) as $dom ): ?>
                                                        <span class="domain-tag"><?php echo esc_html( $dom ); ?></span>
                                                    <?php endforeach; ?>
                                                <?php else: ?>
                                                    <span style="font-style:italic; color:rgba(248, 250, 252, 0.3); font-size:12px;">Nenhum</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <form action="" method="POST" style="display:inline;">
                                                    <input type="hidden" name="license_id" value="<?php echo $lic['id']; ?>">
                                                    <select name="status" onchange="this.form.submit()" style="padding:6px; width:auto; font-size:12px; font-weight:600; border-radius:6px; background:#181524;">
                                                        <option value="ACTIVE" <?php selected( $lic['status'], 'ACTIVE' ); ?>>Ativo</option>
                                                        <option value="SUSPENDED" <?php selected( $lic['status'], 'SUSPENDED' ); ?>>Suspenso</option>
                                                        <option value="EXPIRED" <?php selected( $lic['status'], 'EXPIRED' ); ?>>Expirado</option>
                                                    </select>
                                                    <input type="hidden" name="update_status" value="1">
                                                </form>
                                            </td>
                                            <td>
                                                <div style="display:flex; gap:6px;">
                                                    <a href="?clear_domains=<?php echo $lic['id']; ?>" class="btn btn-secondary" style="padding:6px 10px; font-size:11px;" title="Resetar ativações de domínio" onclick="return confirm('Deseja liberar todos os domínios para esta licença?')">Resetar</a>
                                                    <a href="?delete_license=<?php echo $lic['id']; ?>" class="btn btn-danger" style="padding:6px 10px; font-size:11px;" title="Excluir" onclick="return confirm('Tem certeza que deseja excluir esta licença?')">Excluir</a>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
<?php
// Auxiliar helper para select do WordPress (simulado para standalone PHP)
function selected( $val1, $val2, $echo = true ) {
    $result = $val1 === $val2 ? 'selected="selected"' : '';
    if ( $echo ) {
        echo $result;
    }
    return $result;
}
function esc_html( $str ) {
    return htmlspecialchars( $str, ENT_QUOTES, 'UTF-8' );
}
