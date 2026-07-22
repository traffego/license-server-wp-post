<?php
/**
 * Painel Administrativo de Licenças.
 * Design premium e moderno (Dark Mode, navegação por abas/páginas e ícones vetoriais).
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
        <script src="https://unpkg.com/lucide@latest"></script>
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
            input[type="text"], input[type="password"] {
                width: 100%;
                padding: 14px 18px;
                background: rgba(0, 0, 0, 0.3);
                border: 1px solid rgba(255, 255, 255, 0.1);
                border-radius: 12px;
                color: #fff;
                font-size: 15px;
                outline: none;
                transition: all 0.2s;
            }
            input[type="text"]:focus, input[type="password"]:focus {
                border-color: #7c3aed;
                box-shadow: 0 0 0 4px rgba(124, 58, 237, 0.15);
            }
            button {
                width: 100%;
                padding: 14px;
                background: linear-gradient(135deg, #7c3aed, #6366f1);
                border: none;
                border-radius: 12px;
                color: #fff;
                font-size: 16px;
                font-weight: 600;
                cursor: pointer;
                transition: transform 0.2s, box-shadow 0.2s;
                margin-top: 12px;
            }
            button:hover {
                transform: translateY(-2px);
                box-shadow: 0 10px 25px rgba(124, 58, 237, 0.4);
            }
            .error-message {
                background: rgba(239, 68, 68, 0.15);
                border: 1px solid rgba(239, 68, 68, 0.3);
                color: #fca5a5;
                padding: 12px;
                border-radius: 10px;
                font-size: 13px;
                margin-bottom: 20px;
            }
        </style>
    </head>
    <body>
        <div class="login-container">
            <div style="display: flex; justify-content: center; margin-bottom: 16px;">
                <i data-lucide="shield-check" style="width: 48px; height: 48px; color: #a78bfa;"></i>
            </div>
            <h1>Painel de Licenças</h1>
            <p class="subtitle">Faça login para acessar o gerenciamento</p>
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
                <button type="submit" name="login">Entrar</button>
            </form>
        </div>
        <script>lucide.createIcons();</script>
    </body>
    </html>
    <?php
    exit;
}

// ── Se logado, processar requisições e roteamento ─────────────────────────────
$db   = get_db_connection();
$view = $_GET['view'] ?? 'licenses';

$message = '';
$error   = '';

// ── Handlers de Licença ───────────────────────────────────────────────────────
if ( isset( $_POST['create_license'] ) ) {
    $email      = filter_var( $_POST['email'] ?? '', FILTER_VALIDATE_EMAIL );
    $custom_key = trim( $_POST['custom_key'] ?? '' );
    $status     = $_POST['status'] ?? 'ACTIVE';
    $asaas_sub  = trim( $_POST['asaas_subscription_id'] ?? '' );
    
    if ( ! $email ) {
        $error = 'E-mail do cliente inválido.';
    } else {
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

if ( isset( $_POST['update_status'] ) ) {
    $id         = (int) $_POST['license_id'];
    $new_status = $_POST['status'] ?? 'ACTIVE';
    try {
        $stmt = $db->prepare( "UPDATE licenses SET status = ? WHERE id = ?" );
        $stmt->execute( [ $new_status, $id ] );
        $message = "Status da licença atualizado.";
    } catch ( PDOException $e ) {
        $error = 'Erro ao atualizar status: ' . $e->getMessage();
    }
}

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

// ── Handlers de Planos ────────────────────────────────────────────────────────
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

if ( isset( $_POST['update_plan'] ) ) {
    $plan_id    = (int) $_POST['plan_id'];
    $plan_name  = trim( $_POST['plan_name'] ?? '' );
    $plan_price = (float) ( $_POST['plan_price'] ?? 0 );
    $plan_days  = (int) ( $_POST['plan_days'] ?? 30 );

    if ( $plan_id <= 0 || empty( $plan_name ) || $plan_price <= 0 ) {
        $error = 'Dados inválidos para atualizar o plano.';
    } else {
        try {
            $stmt = $db->prepare( "UPDATE plans SET name = ?, price = ?, duration_days = ? WHERE id = ?" );
            $stmt->execute( [ $plan_name, $plan_price, $plan_days, $plan_id ] );
            $message = "Plano '{$plan_name}' atualizado com sucesso!";
        } catch ( PDOException $e ) {
            $error = 'Erro ao atualizar plano: ' . $e->getMessage();
        }
    }
}

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

$edit_plan_data = null;
if ( isset( $_GET['edit_plan'] ) ) {
    $edit_id = (int) $_GET['edit_plan'];
    $stmt = $db->prepare( "SELECT * FROM plans WHERE id = ? LIMIT 1" );
    $stmt->execute( [ $edit_id ] );
    $edit_plan_data = $stmt->fetch();
}

// ── Handlers de Configurações ─────────────────────────────────────────────────
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

// ── Dados para Renderização das Telas ─────────────────────────────────────────
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
$plans    = $db->query( "SELECT * FROM plans ORDER BY price ASC" )->fetchAll();

// Testar Conexão Asaas
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

function selected( $val1, $val2, $echo = true ) {
    $result = $val1 === $val2 ? 'selected="selected"' : '';
    if ( $echo ) echo $result;
    return $result;
}
function esc_html( $str ) {
    return htmlspecialchars( (string) $str, ENT_QUOTES, 'UTF-8' );
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Painel Admin — License Server</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        :root {
            --bg-color: #0b0813;
            --card-bg: rgba(255, 255, 255, 0.03);
            --card-border: rgba(255, 255, 255, 0.08);
            --accent: #7c3aed;
            --accent-hover: #6d28d9;
            --text-main: #f8fafc;
            --text-sub: rgba(248, 250, 252, 0.6);
            --border-input: rgba(255, 255, 255, 0.12);
        }

        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Outfit', sans-serif; }
        body { background: var(--bg-color); color: var(--text-main); min-height: 100vh; display: flex; flex-direction: column; }

        /* Navigation Header */
        .navbar { background: rgba(11, 8, 19, 0.8); border-bottom: 1px solid var(--card-border); backdrop-filter: blur(15px); padding: 0 40px; display: flex; justify-content: space-between; align-items: center; height: 70px; sticky: top; top: 0; z-index: 100; }
        .nav-brand { display: flex; align-items: center; gap: 12px; font-weight: 800; font-size: 20px; color: #fff; text-decoration: none; }
        .nav-brand i { color: var(--accent); }

        .nav-links { display: flex; gap: 8px; list-style: none; height: 100%; align-items: center; }
        .nav-item a { display: flex; align-items: center; gap: 8px; padding: 10px 18px; border-radius: 10px; color: var(--text-sub); text-decoration: none; font-size: 14px; font-weight: 600; transition: all 0.2s; }
        .nav-item a:hover { color: #fff; background: rgba(255, 255, 255, 0.05); }
        .nav-item.active a { color: #fff; background: rgba(124, 58, 237, 0.2); border: 1px solid rgba(124, 58, 237, 0.3); }

        .nav-logout { display: flex; align-items: center; gap: 8px; color: #f87171; text-decoration: none; font-size: 14px; font-weight: 600; padding: 8px 14px; border-radius: 8px; background: rgba(239, 68, 68, 0.1); border: 1px solid rgba(239, 68, 68, 0.2); transition: 0.2s; }
        .nav-logout:hover { background: rgba(239, 68, 68, 0.25); color: #fff; }

        /* Container Layout */
        .main-content { max-width: 1200px; width: 100%; margin: 40px auto; padding: 0 20px; flex: 1; }
        .page-title { font-size: 26px; font-weight: 800; margin-bottom: 24px; display: flex; align-items: center; gap: 12px; }

        /* Card System */
        .card { background: var(--card-bg); border: 1px solid var(--card-border); backdrop-filter: blur(20px); border-radius: 20px; padding: 28px; box-shadow: 0 15px 35px rgba(0,0,0,0.3); margin-bottom: 24px; }
        .card-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .card h2 { font-size: 18px; font-weight: 700; display: flex; align-items: center; gap: 10px; }

        /* Forms */
        .form-group { margin-bottom: 18px; }
        label { display: block; font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-sub); margin-bottom: 8px; }
        input, select { width: 100%; padding: 12px 16px; background: rgba(0, 0, 0, 0.3); border: 1px solid var(--border-input); border-radius: 10px; color: #fff; font-size: 14px; outline: none; transition: 0.2s; }
        input:focus, select:focus { border-color: var(--accent); box-shadow: 0 0 0 3px rgba(124, 58, 237, 0.15); }

        /* Buttons */
        .btn { display: inline-flex; align-items: center; gap: 8px; justify-content: center; padding: 12px 20px; border-radius: 10px; font-size: 14px; font-weight: 600; border: none; cursor: pointer; text-decoration: none; transition: 0.2s; }
        .btn-primary { background: linear-gradient(135deg, var(--accent), #6366f1); color: #fff; }
        .btn-primary:hover { opacity: 0.9; transform: translateY(-1px); }
        .btn-secondary { background: rgba(255, 255, 255, 0.06); color: #fff; border: 1px solid var(--card-border); }
        .btn-secondary:hover { background: rgba(255, 255, 255, 0.12); }
        .btn-danger { background: rgba(239, 68, 68, 0.15); color: #f87171; border: 1px solid rgba(239, 68, 68, 0.3); }
        .btn-danger:hover { background: rgba(239, 68, 68, 0.3); color: #fff; }

        /* Table */
        .table-responsive { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; text-align: left; }
        th { padding: 14px 16px; font-size: 12px; font-weight: 700; text-transform: uppercase; color: var(--text-sub); border-bottom: 1px solid var(--card-border); }
        td { padding: 16px; border-bottom: 1px solid rgba(255, 255, 255, 0.04); font-size: 14px; vertical-align: middle; }
        tr:hover td { background: rgba(255, 255, 255, 0.02); }

        /* Badges */
        .badge { display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; border-radius: 20px; font-size: 12px; font-weight: 700; }
        .badge-active { background: rgba(16, 185, 129, 0.15); color: #34d399; border: 1px solid rgba(16, 185, 129, 0.3); }
        .badge-inactive { background: rgba(245, 158, 11, 0.15); color: #fbbf24; border: 1px solid rgba(245, 158, 11, 0.3); }
        .badge-error { background: rgba(239, 68, 68, 0.15); color: #f87171; border: 1px solid rgba(239, 68, 68, 0.3); }

        .domain-tag { display: inline-block; background: rgba(124, 58, 237, 0.15); color: #c4b5fd; padding: 3px 8px; border-radius: 6px; font-size: 12px; font-family: monospace; margin: 2px; }
        .alert { padding: 14px 18px; border-radius: 12px; font-size: 14px; margin-bottom: 24px; display: flex; align-items: center; gap: 10px; }
        .alert-success { background: rgba(16, 185, 129, 0.15); border: 1px solid rgba(16, 185, 129, 0.3); color: #34d399; }
        .alert-danger { background: rgba(239, 68, 68, 0.15); border: 1px solid rgba(239, 68, 68, 0.3); color: #f87171; }
    </style>
</head>
<body>

    <!-- Header Navigation -->
    <nav class="navbar">
        <a href="index.php" class="nav-brand">
            <i data-lucide="shield-check"></i>
            WP AI Publisher
        </a>

        <ul class="nav-links">
            <li class="nav-item <?php echo $view === 'licenses' ? 'active' : ''; ?>">
                <a href="index.php?view=licenses">
                    <i data-lucide="key"></i>
                    Licenças
                </a>
            </li>
            <li class="nav-item <?php echo $view === 'plans' ? 'active' : ''; ?>">
                <a href="index.php?view=plans">
                    <i data-lucide="package"></i>
                    Planos de Assinatura
                </a>
            </li>
            <li class="nav-item <?php echo $view === 'settings' ? 'active' : ''; ?>">
                <a href="index.php?view=settings">
                    <i data-lucide="sliders"></i>
                    Configurações Asaas
                </a>
            </li>
        </ul>

        <a href="?logout=1" class="nav-logout">
            <i data-lucide="log-out"></i>
            Sair
        </a>
    </nav>

    <!-- Main Content Body -->
    <div class="main-content">

        <?php if ( $message ): ?>
            <div class="alert alert-success">
                <i data-lucide="check-circle-2"></i>
                <div><?php echo $message; ?></div>
            </div>
        <?php endif; ?>

        <?php if ( $error ): ?>
            <div class="alert alert-danger">
                <i data-lucide="alert-circle"></i>
                <div><?php echo $error; ?></div>
            </div>
        <?php endif; ?>

        <?php if ( $view === 'plans' ): ?>
            <!-- ── PÁGINA 2: PLANOS DE ASSINATURA ────────────────────────────── -->
            <div class="page-title">
                <i data-lucide="package" style="color: var(--accent);"></i>
                Planos de Assinatura
            </div>

            <div style="display: grid; grid-template-columns: 360px 1fr; gap: 24px;">
                <!-- Formulário: Criar / Editar Plano -->
                <div class="card">
                    <h2>
                        <i data-lucide="<?php echo $edit_plan_data ? 'edit' : 'plus-circle'; ?>" style="color: var(--accent);"></i>
                        <?php echo $edit_plan_data ? 'Editar Plano' : 'Novo Plano'; ?>
                    </h2>
                    <form action="index.php?view=plans" method="POST">
                        <?php if ( $edit_plan_data ): ?>
                            <input type="hidden" name="plan_id" value="<?php echo $edit_plan_data['id']; ?>">
                        <?php endif; ?>

                        <div class="form-group">
                            <label for="plan_name">Nome do Plano</label>
                            <input type="text" name="plan_name" id="plan_name" required value="<?php echo esc_html( $edit_plan_data['name'] ?? '' ); ?>" placeholder="Ex: Plano Mensal, Anual">
                        </div>
                        <div class="form-group">
                            <label for="plan_price">Preço em R$</label>
                            <input type="number" step="0.01" name="plan_price" id="plan_price" required value="<?php echo esc_html( $edit_plan_data['price'] ?? '' ); ?>" placeholder="49.90">
                        </div>
                        <div class="form-group">
                            <label for="plan_days">Validade em Dias</label>
                            <input type="number" name="plan_days" id="plan_days" required value="<?php echo esc_html( $edit_plan_data['duration_days'] ?? 30 ); ?>" placeholder="30">
                        </div>

                        <?php if ( $edit_plan_data ): ?>
                            <button type="submit" name="update_plan" class="btn btn-primary" style="width: 100%;">
                                <i data-lucide="check-circle"></i>
                                Salvar Alterações
                            </button>
                            <a href="index.php?view=plans" class="btn btn-secondary" style="width: 100%; margin-top: 10px; text-align: center; justify-content: center;">
                                Cancelar Edição
                            </a>
                        <?php else: ?>
                            <button type="submit" name="create_plan" class="btn btn-primary" style="width: 100%;">
                                <i data-lucide="plus"></i>
                                Adicionar Plano
                            </button>
                        <?php endif; ?>
                    </form>
                </div>

                <!-- Tabela: Planos Cadastrados -->
                <div class="card">
                    <h2>
                        <i data-lucide="list" style="color: var(--accent);"></i>
                        Planos Ativos
                    </h2>
                    <?php if ( empty( $plans ) ): ?>
                        <p style="color: var(--text-sub); font-size: 14px;">Nenhum plano cadastrado.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Nome do Plano</th>
                                        <th>Valor R$</th>
                                        <th>Validade (Dias)</th>
                                        <th>Ações</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ( $plans as $p ): ?>
                                        <tr>
                                            <td style="font-weight: 700; color: #fff;"><?php echo esc_html( $p['name'] ); ?></td>
                                            <td style="color: #34d399; font-weight: 800; font-size: 16px;">R$ <?php echo number_format( $p['price'], 2, ',', '.' ); ?></td>
                                            <td><span class="badge badge-active"><?php echo $p['duration_days']; ?> dias</span></td>
                                            <td>
                                                <div style="display: flex; gap: 6px;">
                                                    <a href="index.php?view=plans&edit_plan=<?php echo $p['id']; ?>" class="btn btn-secondary" style="padding: 6px 12px; font-size: 12px;">
                                                        <i data-lucide="edit-3" style="width: 14px; height: 14px;"></i>
                                                        Editar
                                                    </a>
                                                    <a href="index.php?view=plans&delete_plan=<?php echo $p['id']; ?>" class="btn btn-danger" style="padding: 6px 12px; font-size: 12px;" onclick="return confirm('Excluir este plano?')">
                                                        <i data-lucide="trash-2" style="width: 14px; height: 14px;"></i>
                                                        Excluir
                                                    </a>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        <?php elseif ( $view === 'settings' ): ?>
            <!-- ── PÁGINA 3: CONFIGURAÇÕES ASAAS ──────────────────────────────── -->
            <div class="page-title">
                <i data-lucide="sliders" style="color: var(--accent);"></i>
                Configurações do Asaas & Gateway
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 24px;">
                <!-- Card: Formulário de Conexão -->
                <div class="card">
                    <div class="card-header">
                        <h2>
                            <i data-lucide="link-2" style="color: var(--accent);"></i>
                            Integração Asaas
                        </h2>
                        <span class="badge <?php echo $asaas_class; ?>">
                            <i data-lucide="activity" style="width: 14px; height: 14px;"></i>
                            <?php echo $asaas_status; ?>
                        </span>
                    </div>

                    <form action="index.php?view=settings" method="POST">
                        <div class="form-group">
                            <label for="asaas_api_key">Chave de API Asaas (access_token)</label>
                            <input type="password" name="asaas_api_key" id="asaas_api_key" value="<?php echo esc_html( get_setting( 'asaas_api_key' ) ); ?>" placeholder="$aact_...">
                        </div>
                        <div class="form-group">
                            <label for="asaas_environment">Ambiente de Operação</label>
                            <select name="asaas_environment" id="asaas_environment">
                                <option value="sandbox" <?php selected( get_setting( 'asaas_environment', 'sandbox' ), 'sandbox' ); ?>>Sandbox (Ambiente de Testes)</option>
                                <option value="production" <?php selected( get_setting( 'asaas_environment', 'sandbox' ), 'production' ); ?>>Produção (Vendas Reais)</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="asaas_payment_link">Link de Pagamento Padrão (Opcional)</label>
                            <input type="url" name="asaas_payment_link" id="asaas_payment_link" value="<?php echo esc_html( get_setting( 'asaas_payment_link' ) ); ?>" placeholder="https://.../checkout.php">
                        </div>
                        <button type="submit" name="save_asaas_settings" class="btn btn-primary" style="width: 100%;">
                            <i data-lucide="save"></i>
                            Salvar Configurações
                        </button>
                    </form>
                </div>

                <!-- Card: Checkout Público -->
                <div class="card">
                    <h2>
                        <i data-lucide="shopping-cart" style="color: var(--accent);"></i>
                        Checkout Público Integrado
                    </h2>
                    <p style="font-size: 14px; color: var(--text-sub); margin: 12px 0 20px 0;">
                        Sua aplicação possui uma página de vendas/checkout pública pronta com integração direta ao Asaas (PIX e Cartão).
                    </p>

                    <?php $checkout_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://" . ($_SERVER['HTTP_HOST'] ?? 'localhost') . rtrim(dirname($_SERVER['REQUEST_URI'] ?? ''), '/\\') . "/checkout.php"; ?>
                    
                    <div class="form-group">
                        <label>URL do Checkout Público</label>
                        <input type="text" readonly value="<?php echo esc_html( $checkout_url ); ?>" onclick="this.select()" style="font-family: monospace; font-size: 13px; color: #a78bfa;">
                    </div>

                    <div style="display: flex; gap: 10px; margin-top: 20px;">
                        <button class="btn btn-secondary" onclick="navigator.clipboard.writeText('<?php echo $checkout_url; ?>'); alert('Link copiado!');" style="flex:1;">
                            <i data-lucide="copy"></i>
                            Copiar Link
                        </button>
                        <a href="<?php echo $checkout_url; ?>" target="_blank" class="btn btn-primary" style="flex:1;">
                            <i data-lucide="external-link"></i>
                            Abrir Checkout
                        </a>
                    </div>
                </div>
            </div>

        <?php else: ?>
            <!-- ── PÁGINA 1: GERENCIAMENTO DE LICENÇAS (PADRÃO) ────────────────── -->
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
                <div class="page-title" style="margin-bottom: 0;">
                    <i data-lucide="key" style="color: var(--accent);"></i>
                    Gerenciamento de Licenças
                </div>
                <button class="btn btn-primary" onclick="document.getElementById('modal-create').style.display='block';">
                    <i data-lucide="plus-circle"></i>
                    Gerar Nova Licença
                </button>
            </div>

            <!-- Card Modal/Form para Criar Licença -->
            <div id="modal-create" class="card" style="display: none; border-color: var(--accent);">
                <div class="card-header">
                    <h2>
                        <i data-lucide="key-round" style="color: var(--accent);"></i>
                        Criar Nova Licença Manual
                    </h2>
                    <button class="btn btn-secondary" onclick="document.getElementById('modal-create').style.display='none';" style="padding: 6px 12px;">Fechar</button>
                </div>
                <form action="index.php?view=licenses" method="POST">
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                        <div class="form-group">
                            <label for="email">E-mail do Cliente</label>
                            <input type="email" name="email" id="email" required placeholder="cliente@email.com">
                        </div>
                        <div class="form-group">
                            <label for="custom_key">Chave da Licença (Opcional)</label>
                            <input type="text" name="custom_key" id="custom_key" placeholder="Deixe em branco para autogerar">
                        </div>
                        <div class="form-group">
                            <label for="asaas_subscription_id">ID Assinatura Asaas (Opcional)</label>
                            <input type="text" name="asaas_subscription_id" id="asaas_subscription_id" placeholder="sub_xxxxxx ou pay_xxxxxx">
                        </div>
                        <div class="form-group">
                            <label for="status">Status Inicial</label>
                            <select name="status" id="status">
                                <option value="ACTIVE">Ativo (ACTIVE)</option>
                                <option value="SUSPENDED">Suspenso (SUSPENDED)</option>
                                <option value="EXPIRED">Expirado (EXPIRED)</option>
                            </select>
                        </div>
                    </div>
                    <button type="submit" name="create_license" class="btn btn-primary">
                        <i data-lucide="check"></i>
                        Confirmar e Salvar Licença
                    </button>
                </form>
            </div>

            <!-- Tabela de Licenças -->
            <div class="card">
                <div class="card-header">
                    <h2>
                        <i data-lucide="shield" style="color: var(--accent);"></i>
                        Licenças Cadastradas (<?php echo count($licenses); ?>)
                    </h2>
                    <form action="index.php" method="GET" style="display: flex; gap: 8px;">
                        <input type="hidden" name="view" value="licenses">
                        <input type="text" name="search" placeholder="Buscar chave ou e-mail..." value="<?php echo esc_html( $search ); ?>" style="width: 260px;">
                        <button type="submit" class="btn btn-secondary">
                            <i data-lucide="search"></i>
                        </button>
                    </form>
                </div>

                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Chave de Licença</th>
                                <th>Cliente / E-mail</th>
                                <th>Domínios Ativos</th>
                                <th>Status</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ( empty( $licenses ) ): ?>
                                <tr>
                                    <td colspan="5" style="text-align: center; color: var(--text-sub); padding: 40px;">
                                        Nenhuma licença encontrada.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ( $licenses as $lic ): ?>
                                    <tr>
                                        <td>
                                            <span style="font-family: monospace; font-weight: 700; color: #c4b5fd;"><?php echo esc_html( $lic['license_key'] ); ?></span>
                                            <?php if ( $lic['asaas_subscription_id'] ): ?>
                                                <div style="font-size: 11px; color: var(--text-sub); margin-top: 4px; font-family: monospace;">
                                                    Asaas: <?php echo esc_html( $lic['asaas_subscription_id'] ); ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div style="font-weight: 600; color: #fff;"><?php echo esc_html( $lic['client_email'] ); ?></div>
                                            <div style="font-size: 11px; color: var(--text-sub); margin-top: 2px;">
                                                Gerada em: <?php echo date( 'd/m/Y H:i', strtotime( $lic['created_at'] ) ); ?>
                                            </div>
                                        </td>
                                        <td>
                                            <?php if ( $lic['domains'] ): ?>
                                                <?php foreach ( explode( ', ', $lic['domains'] ) as $dom ): ?>
                                                    <span class="domain-tag"><?php echo esc_html( $dom ); ?></span>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <span style="font-style: italic; color: var(--text-sub); font-size: 12px;">Nenhum</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <form action="index.php?view=licenses" method="POST" style="display: inline;">
                                                <input type="hidden" name="license_id" value="<?php echo $lic['id']; ?>">
                                                <select name="status" onchange="this.form.submit()" style="padding: 6px 10px; font-size: 12px; font-weight: 700; width: auto;">
                                                    <option value="ACTIVE" <?php selected( $lic['status'], 'ACTIVE' ); ?>>Ativo</option>
                                                    <option value="SUSPENDED" <?php selected( $lic['status'], 'SUSPENDED' ); ?>>Suspenso</option>
                                                    <option value="EXPIRED" <?php selected( $lic['status'], 'EXPIRED' ); ?>>Expirado</option>
                                                </select>
                                                <input type="hidden" name="update_status" value="1">
                                            </form>
                                        </td>
                                        <td>
                                            <div style="display: flex; gap: 6px;">
                                                <a href="index.php?view=licenses&clear_domains=<?php echo $lic['id']; ?>" class="btn btn-secondary" style="padding: 6px 10px; font-size: 12px;" title="Resetar Domínio" onclick="return confirm('Liberar domínios da licença?')">
                                                    <i data-lucide="refresh-cw" style="width: 14px; height: 14px;"></i>
                                                    Reset
                                                </a>
                                                <a href="index.php?view=licenses&delete_license=<?php echo $lic['id']; ?>" class="btn btn-danger" style="padding: 6px 10px; font-size: 12px;" title="Excluir" onclick="return confirm('Excluir licença?')">
                                                    <i data-lucide="trash-2" style="width: 14px; height: 14px;"></i>
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

    </div>

    <script>lucide.createIcons();</script>
</body>
</html>
