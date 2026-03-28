<?php
// Configurar headers correctos
header('Content-Type: text/html; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('X-XSS-Protection: 1; mode=block');

// Verificar si el usuario está autenticado
session_start();
$isAuthenticated = isset($_SESSION['user_id']) && isset($_SESSION['login_time']);

// Si no está autenticado, redirigir al login
if (!$isAuthenticated) {
    header('Location: login.html');
    exit();
}

// Obtener información del usuario
$userName = $_SESSION['user_name'] ?? $_SESSION['user_email'] ?? 'Usuario';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Trafficstars - Estadísticas en Tiempo Real</title>
    
    <!-- CSS Integrado -->
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background: #0f0f23;
            color: #e4e4e7;
            line-height: 1.6;
            min-height: 100vh;
        }

        /* Header */
        .header {
            background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
            padding: 2rem 0;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 0 2rem;
        }

        .header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }

        h1 {
            font-size: 2rem;
            font-weight: 700;
            background: linear-gradient(135deg, #60a5fa 0%, #a78bfa 100%);
            background-clip: text;
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 1rem;
            background: rgba(255, 255, 255, 0.05);
            padding: 0.75rem 1.5rem;
            border-radius: 12px;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }

        .status-indicator {
            width: 10px;
            height: 10px;
            background: #10b981;
            border-radius: 50%;
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0% { box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7); }
            70% { box-shadow: 0 0 0 10px rgba(16, 185, 129, 0); }
            100% { box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); }
        }

        /* Selector de cuenta en header */
        #headerAccountSelector {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            color: #e4e4e7;
            padding: 0.5rem 1rem;
            border-radius: 8px;
            font-size: 0.875rem;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        #headerAccountSelector:hover {
            background: rgba(255, 255, 255, 0.1);
            border-color: rgba(255, 255, 255, 0.2);
        }

        /* Tarjetas de Métricas */
        .metrics-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1.5rem;
            margin: 2rem 0;
        }

        .metric-card {
            background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 16px;
            padding: 1.5rem;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .metric-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #60a5fa, #a78bfa);
            transform: scaleX(0);
            transition: transform 0.3s ease;
        }

        .metric-card:hover::before {
            transform: scaleX(1);
        }

        .metric-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 30px -10px rgba(96, 165, 250, 0.3);
        }

        .metric-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }

        .metric-title {
            font-size: 0.875rem;
            color: #94a3b8;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .metric-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
        }

        .metric-value {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }

        .metric-change {
            font-size: 0.875rem;
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }

        .positive { color: #10b981; }
        .negative { color: #ef4444; }

        /* Tabla de Estadísticas */
        .stats-section {
            background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 16px;
            padding: 1.5rem;
            margin: 2rem 0;
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .section-title {
            font-size: 1.25rem;
            font-weight: 600;
        }

        .filters {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
        }

        .filter-btn {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            color: #e4e4e7;
            padding: 0.5rem 1rem;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 0.875rem;
        }

        .filter-btn:hover {
            background: rgba(255, 255, 255, 0.1);
            border-color: rgba(255, 255, 255, 0.2);
        }

        .filter-btn.active {
            background: linear-gradient(135deg, #60a5fa 0%, #a78bfa 100%);
            border-color: transparent;
        }

        /* Tabla Responsiva */
        .table-container {
            overflow-x: auto;
            margin-top: 1rem;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th, td {
            text-align: left;
            padding: 1rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
        }

        th {
            background: rgba(255, 255, 255, 0.03);
            font-weight: 600;
            color: #94a3b8;
            text-transform: uppercase;
            font-size: 0.75rem;
            letter-spacing: 0.05em;
        }

        tbody tr {
            transition: background 0.2s ease;
        }

        tbody tr:hover {
            background: rgba(255, 255, 255, 0.02);
        }

        .country-cell {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .flag {
            width: 24px;
            height: 24px;
            border-radius: 4px;
            background: #64748b;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.75rem;
        }

        .earnings {
            font-weight: 600;
            color: #10b981;
        }

        .cpm {
            color: #60a5fa;
        }

        /* Loader */
        .loader-container {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(15, 15, 35, 0.9);
            z-index: 1000;
            justify-content: center;
            align-items: center;
        }

        .loader {
            width: 50px;
            height: 50px;
            border: 3px solid rgba(255, 255, 255, 0.1);
            border-top-color: #60a5fa;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        /* Modal de Configuración */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.8);
            z-index: 2000;
            justify-content: center;
            align-items: center;
            padding: 1rem;
        }

        .modal-content {
            background: #1e293b;
            border-radius: 16px;
            padding: 2rem;
            max-width: 700px;
            width: 100%;
            max-height: 90vh;
            overflow-y: auto;
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }

        .modal-title {
            font-size: 1.5rem;
            font-weight: 600;
        }

        .close-btn {
            background: none;
            border: none;
            color: #94a3b8;
            font-size: 1.5rem;
            cursor: pointer;
            transition: color 0.2s ease;
        }

        .close-btn:hover {
            color: #e4e4e7;
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        label {
            display: block;
            margin-bottom: 0.5rem;
            color: #94a3b8;
            font-size: 0.875rem;
        }

        input, select {
            width: 100%;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            color: #e4e4e7;
            padding: 0.75rem 1rem;
            border-radius: 8px;
            font-size: 1rem;
            transition: all 0.3s ease;
        }

        input:focus, select:focus {
            outline: none;
            border-color: #60a5fa;
            background: rgba(255, 255, 255, 0.08);
        }

        .btn-primary {
            background: linear-gradient(135deg, #60a5fa 0%, #a78bfa 100%);
            color: white;
            border: none;
            padding: 0.75rem 2rem;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            width: 100%;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 30px -10px rgba(96, 165, 250, 0.5);
        }

        /* Alertas */
        .alert {
            padding: 1rem 1.5rem;
            border-radius: 8px;
            margin-bottom: 1rem;
            display: none;
        }

        .alert-error {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.2);
            color: #fca5a5;
        }

        .alert-success {
            background: rgba(16, 185, 129, 0.1);
            border: 1px solid rgba(16, 185, 129, 0.2);
            color: #86efac;
        }

        /* Estilos para cuentas múltiples */
        .account-item {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 12px;
            padding: 1rem;
            margin-bottom: 1rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: all 0.3s ease;
        }

        .account-item:hover {
            background: rgba(255, 255, 255, 0.08);
            border-color: rgba(255, 255, 255, 0.2);
        }

        .account-item.active {
            border-color: #60a5fa;
            background: rgba(96, 165, 250, 0.1);
        }

        .account-info {
            flex: 1;
        }

        .account-name {
            font-weight: 600;
            margin-bottom: 0.25rem;
        }

        .account-id {
            font-size: 0.875rem;
            color: #64748b;
        }

        .account-actions {
            display: flex;
            gap: 0.5rem;
        }

        .account-btn {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            color: #e4e4e7;
            padding: 0.5rem 0.75rem;
            border-radius: 8px;
            cursor: pointer;
            font-size: 0.875rem;
            transition: all 0.3s ease;
        }

        .account-btn:hover {
            background: rgba(255, 255, 255, 0.1);
        }

        .account-btn.danger {
            color: #ef4444;
            border-color: rgba(239, 68, 68, 0.2);
        }

        .account-btn.danger:hover {
            background: rgba(239, 68, 68, 0.1);
            border-color: rgba(239, 68, 68, 0.3);
        }

        .account-badge {
            background: #10b981;
            color: white;
            padding: 0.25rem 0.75rem;
            border-radius: 6px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .header-content {
                flex-direction: column;
                text-align: center;
            }

            h1 {
                font-size: 1.5rem;
            }

            .metric-value {
                font-size: 1.5rem;
            }

            .table-container {
                margin: 0 -1rem;
            }

            th, td {
                padding: 0.75rem 0.5rem;
                font-size: 0.875rem;
            }

            .account-item {
                flex-direction: column;
                gap: 1rem;
            }

            .account-actions {
                width: 100%;
                justify-content: space-between;
            }
        }
    </style>
</head>
<body>
    <!-- Loader -->
    <div class="loader-container" id="loader">
        <div class="loader"></div>
    </div>

    <!-- Header -->
    <header class="header">
        <div class="container">
            <div class="header-content">
                <div style="display: flex; align-items: center; gap: 1.5rem;">
                    <h1>Dashboard Trafficstars</h1>
                    <select id="headerAccountSelector" onchange="quickSwitchAccount()">
                        <option value="">Seleccionar cuenta...</option>
                    </select>
                </div>
                <div class="user-info">
                    <div class="status-indicator"></div>
                    <span id="userName"><?php echo htmlspecialchars($userName); ?></span>
                    <button class="filter-btn" onclick="openSettings()">⚙️ Configuración</button>
                    <button class="filter-btn" onclick="logout()" style="background: rgba(239, 68, 68, 0.1); border-color: rgba(239, 68, 68, 0.2);">🚪 Cerrar Sesión</button>
                </div>
            </div>
        </div>
    </header>

    <!-- Main Content -->
    <main class="container">
        <!-- Alertas -->
        <div class="alert alert-error" id="errorAlert"></div>
        <div class="alert alert-success" id="successAlert"></div>

        <!-- Métricas Principales -->
        <div class="metrics-grid">
            <div class="metric-card">
                <div class="metric-header">
                    <span class="metric-title">Visitas Totales</span>
                    <div class="metric-icon" style="background: rgba(96, 165, 250, 0.1); color: #60a5fa;">
                        👁️
                    </div>
                </div>
                <div class="metric-value" id="totalVisits">0</div>
                <div class="metric-change positive">
                    <span>↑</span>
                    <span id="visitsChange">+0%</span>
                </div>
            </div>

            <div class="metric-card">
                <div class="metric-header">
                    <span class="metric-title">Ganancias Totales</span>
                    <div class="metric-icon" style="background: rgba(16, 185, 129, 0.1); color: #10b981;">
                        💰
                    </div>
                </div>
                <div class="metric-value" id="totalEarnings">$0.00</div>
                <div class="metric-change positive">
                    <span>↑</span>
                    <span id="earningsChange">+0%</span>
                </div>
            </div>

            <div class="metric-card">
                <div class="metric-header">
                    <span class="metric-title">CPM Promedio</span>
                    <div class="metric-icon" style="background: rgba(167, 139, 250, 0.1); color: #a78bfa;">
                        📊
                    </div>
                </div>
                <div class="metric-value" id="avgCPM">$0.00</div>
                <div class="metric-change positive">
                    <span>↑</span>
                    <span id="cpmChange">+0%</span>
                </div>
            </div>

            <div class="metric-card">
                <div class="metric-header">
                    <span class="metric-title">Países Activos</span>
                    <div class="metric-icon" style="background: rgba(251, 191, 36, 0.1); color: #fbbf24;">
                        🌍
                    </div>
                </div>
                <div class="metric-value" id="activeCountries">0</div>
                <div class="metric-change positive">
                    <span>↑</span>
                    <span id="countriesChange">+0</span>
                </div>
            </div>
        </div>

        <!-- Tabla de Estadísticas por País -->
        <div class="stats-section">
            <div class="section-header">
                <h2 class="section-title">Estadísticas por País</h2>
                <div class="filters">
                    <button class="filter-btn active" onclick="setTimeFilter('today')">Hoy</button>
                    <button class="filter-btn" onclick="setTimeFilter('week')">7 Días</button>
                    <button class="filter-btn" onclick="setTimeFilter('month')">30 Días</button>
                    <button class="filter-btn" onclick="refreshData()">🔄 Actualizar</button>
                </div>
            </div>

            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>País</th>
                            <th>Visitas</th>
                            <th>Ganancias</th>
                            <th>CPM</th>
                            <th>% del Total</th>
                        </tr>
                    </thead>
                    <tbody id="countryStatsBody">
                        <tr>
                            <td colspan="5" style="text-align: center; color: #64748b;">No hay datos disponibles</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </main>

    <!-- Modal de Configuración de Cuentas Múltiples -->
    <div class="modal" id="settingsModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Configuración de Cuentas TrafficStars</h3>
                <button class="close-btn" onclick="closeSettings()">×</button>
            </div>
            
            <!-- Selector de cuenta -->
            <div class="form-group">
                <label>Cuenta Activa</label>
                <div style="display: flex; gap: 1rem; align-items: center;">
                    <select id="accountSelector" onchange="switchAccount()" style="flex: 1;">
                        <option value="">Seleccionar cuenta...</option>
                    </select>
                    <button class="filter-btn" onclick="showAddAccountForm()">+ Nueva Cuenta</button>
                </div>
            </div>

            <!-- Formulario para agregar nueva cuenta -->
            <div id="addAccountForm" style="display: none;">
                <div style="background: rgba(96, 165, 250, 0.1); border: 1px solid rgba(96, 165, 250, 0.2); border-radius: 12px; padding: 1.5rem; margin: 1.5rem 0;">
                    <h4 style="margin-bottom: 1rem;">Agregar Nueva Cuenta</h4>
                    <form onsubmit="addNewAccount(event)">
                        <div class="form-group">
                            <label for="newAccountName">Nombre de la Cuenta</label>
                            <input type="text" id="newAccountName" placeholder="Ej: Cuenta Principal" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="newClientId">ID de Cliente</label>
                            <input type="text" id="newClientId" placeholder="Ingresa tu Client ID" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="newApiSecret">Clave API Secreta (Token JWT)</label>
                            <input type="password" id="newApiSecret" placeholder="Ingresa tu API Secret" required>
                        </div>
                        
                        <div style="display: flex; gap: 1rem;">
                            <button type="submit" class="btn-primary" style="flex: 1;">Agregar Cuenta</button>
                            <button type="button" class="filter-btn" onclick="hideAddAccountForm()" style="background: rgba(239, 68, 68, 0.1); border-color: rgba(239, 68, 68, 0.2);">Cancelar</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Lista de cuentas existentes -->
            <div id="accountsList" style="margin-top: 2rem;">
                <h4 style="margin-bottom: 1rem;">Cuentas Configuradas</h4>
                <div id="accountsContainer">
                    <p style="text-align: center; color: #64748b;">No hay cuentas configuradas</p>
                </div>
            </div>

            <!-- Configuración general -->
            <div style="margin-top: 2rem; padding-top: 2rem; border-top: 1px solid rgba(255, 255, 255, 0.1);">
                <h4 style="margin-bottom: 1rem;">Configuración General</h4>
                <div class="form-group">
                    <label for="refreshInterval">Intervalo de Actualización (segundos)</label>
                    <input type="number" id="refreshInterval" value="300" min="60" max="3600" onchange="updateRefreshInterval()">
                </div>
            </div>
        </div>
    </div>

    <script>
        // Variables globales
        let accounts = [];
        let activeAccountId = null;
        let currentFilter = 'today';
        let refreshTimer = null;
        let currentUser = <?php echo json_encode([
            'id' => $_SESSION['user_id'],
            'email' => $_SESSION['user_email'],
            'name' => $_SESSION['user_name'],
            'role' => $_SESSION['user_role']
        ]); ?>;
        let apiConfig = {
            clientId: '',
            apiSecret: '',
            refreshInterval: 300
        };

        // Inicializar el sistema de cuentas múltiples
        function initializeAccounts() {
            // Cargar cuentas desde localStorage
            const savedAccounts = localStorage.getItem('ts_accounts');
            if (savedAccounts) {
                accounts = JSON.parse(savedAccounts);
            }
            
            // Cargar cuenta activa
            activeAccountId = localStorage.getItem('ts_active_account');
            
            // Si no hay cuentas, crear una por defecto con las credenciales guardadas
            if (accounts.length === 0) {
                const clientId = localStorage.getItem('ts_client_id');
                const apiSecret = localStorage.getItem('ts_api_secret');
                
                if (clientId && apiSecret) {
                    accounts.push({
                        id: generateAccountId(),
                        name: 'Cuenta Principal',
                        clientId: clientId,
                        apiSecret: apiSecret,
                        createdAt: new Date().toISOString()
                    });
                    saveAccounts();
                }
            }
            
            updateAccountSelector();
            updateHeaderAccountSelector();
            updateAccountsList();
        }

        // Generar ID único para cuenta
        function generateAccountId() {
            return 'acc_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
        }

        // Guardar cuentas en localStorage
        function saveAccounts() {
            localStorage.setItem('ts_accounts', JSON.stringify(accounts));
            if (activeAccountId) {
                localStorage.setItem('ts_active_account', activeAccountId);
            }
        }

        // Actualizar selector de cuentas
        function updateAccountSelector() {
            const selector = document.getElementById('accountSelector');
            if (!selector) return;
            
            selector.innerHTML = '<option value="">Seleccionar cuenta...</option>';
            
            accounts.forEach(account => {
                const option = document.createElement('option');
                option.value = account.id;
                option.textContent = account.name;
                if (account.id === activeAccountId) {
                    option.selected = true;
                }
                selector.appendChild(option);
            });
        }

        // Actualizar selector de cuentas en el header
        function updateHeaderAccountSelector() {
            const selector = document.getElementById('headerAccountSelector');
            if (!selector) return;
            
            selector.innerHTML = '<option value="">Seleccionar cuenta...</option>';
            
            accounts.forEach(account => {
                const option = document.createElement('option');
                option.value = account.id;
                option.textContent = account.name;
                if (account.id === activeAccountId) {
                    option.selected = true;
                }
                selector.appendChild(option);
            });
        }

        // Cambio rápido de cuenta desde el header
        function quickSwitchAccount() {
            const selector = document.getElementById('headerAccountSelector');
            const newAccountId = selector.value;
            
            if (newAccountId && newAccountId !== activeAccountId) {
                activeAccountId = newAccountId;
                const account = accounts.find(acc => acc.id === activeAccountId);
                if (account) {
                    apiConfig.clientId = account.clientId;
                    apiConfig.apiSecret = account.apiSecret;
                    saveAccounts();
                    loadDashboard();
                    showAlert('success', `Cambiado a: ${account.name}`);
                }
            }
        }

        // Actualizar lista de cuentas
        function updateAccountsList() {
            const container = document.getElementById('accountsContainer');
            if (!container) return;
            
            container.innerHTML = '';
            
            if (accounts.length === 0) {
                container.innerHTML = '<p style="text-align: center; color: #64748b;">No hay cuentas configuradas</p>';
                return;
            }
            
            accounts.forEach(account => {
                const accountDiv = document.createElement('div');
                accountDiv.className = 'account-item' + (account.id === activeAccountId ? ' active' : '');
                
                accountDiv.innerHTML = `
                    <div class="account-info">
                        <div class="account-name">${account.name}</div>
                        <div class="account-id">ID: ${account.clientId.substr(0, 20)}...</div>
                    </div>
                    <div class="account-actions">
                        ${account.id === activeAccountId ? '<span class="account-badge">Activa</span>' : ''}
                        <button class="account-btn" onclick="editAccount('${account.id}')">Editar</button>
                        <button class="account-btn danger" onclick="deleteAccount('${account.id}')">Eliminar</button>
                    </div>
                `;
                
                container.appendChild(accountDiv);
            });
        }

        // Cambiar cuenta activa
        function switchAccount() {
            const selector = document.getElementById('accountSelector');
            activeAccountId = selector.value;
            
            if (activeAccountId) {
                const account = accounts.find(acc => acc.id === activeAccountId);
                if (account) {
                    // Actualizar configuración global con los datos de la cuenta
                    apiConfig.clientId = account.clientId;
                    apiConfig.apiSecret = account.apiSecret;
                    
                    saveAccounts();
                    updateAccountsList();
                    updateHeaderAccountSelector();
                    loadDashboard();
                    showAlert('success', `Cuenta cambiada a: ${account.name}`);
                }
            }
        }

        // Mostrar formulario para agregar cuenta
        function showAddAccountForm() {
            document.getElementById('addAccountForm').style.display = 'block';
            document.getElementById('newAccountName').focus();
        }

        // Ocultar formulario para agregar cuenta
        function hideAddAccountForm() {
            document.getElementById('addAccountForm').style.display = 'none';
            document.getElementById('newAccountName').value = '';
            document.getElementById('newClientId').value = '';
            document.getElementById('newApiSecret').value = '';
        }

        // Agregar nueva cuenta
        function addNewAccount(event) {
            event.preventDefault();
            
            const name = document.getElementById('newAccountName').value.trim();
            const clientId = document.getElementById('newClientId').value.trim();
            const apiSecret = document.getElementById('newApiSecret').value.trim();
            
            if (!name || !clientId || !apiSecret) {
                showAlert('error', 'Todos los campos son requeridos');
                return;
            }
            
            // Verificar si ya existe una cuenta con el mismo clientId
            if (accounts.some(acc => acc.clientId === clientId)) {
                showAlert('error', 'Ya existe una cuenta con este ID de Cliente');
                return;
            }
            
            const newAccount = {
                id: generateAccountId(),
                name: name,
                clientId: clientId,
                apiSecret: apiSecret,
                createdAt: new Date().toISOString()
            };
            
            accounts.push(newAccount);
            activeAccountId = newAccount.id;
            
            // Actualizar configuración global
            apiConfig.clientId = clientId;
            apiConfig.apiSecret = apiSecret;
            
            saveAccounts();
            updateAccountSelector();
            updateHeaderAccountSelector();
            updateAccountsList();
            hideAddAccountForm();
            
            showAlert('success', `Cuenta "${name}" agregada correctamente`);
            loadDashboard();
        }

        // Editar cuenta
        function editAccount(accountId) {
            const account = accounts.find(acc => acc.id === accountId);
            if (!account) return;
            
            const newName = prompt('Nuevo nombre para la cuenta:', account.name);
            if (newName && newName.trim()) {
                account.name = newName.trim();
                saveAccounts();
                updateAccountSelector();
                updateHeaderAccountSelector();
                updateAccountsList();
                showAlert('success', 'Cuenta actualizada correctamente');
            }
        }

        // Eliminar cuenta
        function deleteAccount(accountId) {
            const account = accounts.find(acc => acc.id === accountId);
            if (!account) return;
            
            if (!confirm(`¿Estás seguro de eliminar la cuenta "${account.name}"?`)) {
                return;
            }
            
            accounts = accounts.filter(acc => acc.id !== accountId);
            
            // Si se eliminó la cuenta activa, seleccionar la primera disponible
            if (accountId === activeAccountId) {
                activeAccountId = accounts.length > 0 ? accounts[0].id : null;
                if (activeAccountId) {
                    const newActiveAccount = accounts[0];
                    apiConfig.clientId = newActiveAccount.clientId;
                    apiConfig.apiSecret = newActiveAccount.apiSecret;
                }
            }
            
            saveAccounts();
            updateAccountSelector();
            updateHeaderAccountSelector();
            updateAccountsList();
            
            showAlert('success', 'Cuenta eliminada correctamente');
            
            if (accounts.length > 0) {
                loadDashboard();
            }
        }

        // Actualizar intervalo de actualización
        function updateRefreshInterval() {
            apiConfig.refreshInterval = parseInt(document.getElementById('refreshInterval').value);
            localStorage.setItem('ts_refresh_interval', apiConfig.refreshInterval);
            startAutoRefresh();
            showAlert('success', 'Intervalo de actualización guardado');
        }

        // Funciones de UI
        function openSettings() {
            document.getElementById('settingsModal').style.display = 'flex';
            document.getElementById('refreshInterval').value = apiConfig.refreshInterval || 300;
            updateAccountSelector();
            updateAccountsList();
        }

        function closeSettings() {
            document.getElementById('settingsModal').style.display = 'none';
        }

        function showLoader() {
            document.getElementById('loader').style.display = 'flex';
        }

        function hideLoader() {
            document.getElementById('loader').style.display = 'none';
        }

        function showAlert(type, message) {
            const alertId = type === 'error' ? 'errorAlert' : 'successAlert';
            const alert = document.getElementById(alertId);
            if (alert) {
                alert.textContent = message;
                alert.style.display = 'block';
                
                setTimeout(() => {
                    alert.style.display = 'none';
                }, 5000);
            }
        }

        // Cambiar filtro de tiempo
        function setTimeFilter(filter) {
            currentFilter = filter;
            
            // Actualizar botones activos
            document.querySelectorAll('.filter-btn').forEach(btn => {
                btn.classList.remove('active');
            });
            event.target.classList.add('active');
            
            loadDashboard();
        }

        // Cargar datos del dashboard
        async function loadDashboard() {
            if (!apiConfig.clientId || !apiConfig.apiSecret) {
                console.log('No hay credenciales configuradas');
                return;
            }
            
            showLoader();
            
            try {
                const response = await fetch('api.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        action: 'getStats',
                        clientId: apiConfig.clientId,
                        apiSecret: apiConfig.apiSecret,
                        timeRange: currentFilter
                    })
                });
                
                const data = await response.json();
                
                if (data.error) {
                    throw new Error(data.error);
                }
                
                updateDashboard(data);
                hideLoader();
                
                // Solo mostrar mensaje de éxito si no es demo
                if (!data.isDemo) {
                    showAlert('success', 'Datos actualizados correctamente');
                } else if (data.message) {
                    showAlert('error', data.message);
                }
                
            } catch (error) {
                hideLoader();
                showAlert('error', 'Error al cargar los datos: ' + error.message);
                console.error('Error:', error);
            }
        }

        // Actualizar dashboard con los datos
        function updateDashboard(data) {
            // Actualizar métricas principales
            document.getElementById('totalVisits').textContent = formatNumber(data.totalVisits || 0);
            document.getElementById('totalEarnings').textContent = formatCurrency(data.totalEarnings || 0);
            document.getElementById('avgCPM').textContent = formatCurrency(data.avgCPM || 0);
            document.getElementById('activeCountries').textContent = data.activeCountries || 0;
            
            // Actualizar cambios porcentuales
            updatePercentageChange('visitsChange', data.visitsChange || 0);
            updatePercentageChange('earningsChange', data.earningsChange || 0);
            updatePercentageChange('cpmChange', data.cpmChange || 0);
            updatePercentageChange('countriesChange', data.countriesChange || 0, true);
            
            // Actualizar tabla de países
            updateCountryTable(data.countryStats || []);
        }

        // Actualizar cambio porcentual
        function updatePercentageChange(elementId, value, isCount = false) {
            const element = document.getElementById(elementId);
            if (!element) return;
            
            const parentElement = element.parentElement;
            if (isCount) {
                element.textContent = '+' + value;
            } else {
                element.textContent = formatPercentage(value);
            }
            
            // Actualizar color según si es positivo o negativo
            if (parentElement) {
                parentElement.classList.remove('positive', 'negative');
                parentElement.classList.add(value >= 0 ? 'positive' : 'negative');
                
                // Actualizar icono
                const icon = parentElement.querySelector('span:first-child');
                if (icon) {
                    icon.textContent = value >= 0 ? '↑' : '↓';
                }
            }
        }

        // Actualizar tabla de países
        function updateCountryTable(countryStats) {
            const tbody = document.getElementById('countryStatsBody');
            if (!tbody) return;
            
            tbody.innerHTML = '';
            
            if (countryStats.length === 0) {
                tbody.innerHTML = '<tr><td colspan="5" style="text-align: center; color: #64748b;">No hay datos disponibles</td></tr>';
                return;
            }
            
            countryStats.forEach(country => {
                const row = document.createElement('tr');
                row.innerHTML = `
                    <td>
                        <div class="country-cell">
                            <div class="flag">${country.flag || country.code}</div>
                            <span>${country.name}</span>
                        </div>
                    </td>
                    <td>${formatNumber(country.visits)}</td>
                    <td class="earnings">${formatCurrency(country.earnings)}</td>
                    <td class="cpm">${formatCurrency(country.cpm)}</td>
                    <td>${country.percentage}%</td>
                `;
                tbody.appendChild(row);
            });
        }

        // Funciones de formato
        function formatNumber(num) {
            return new Intl.NumberFormat('es-ES').format(num);
        }

        function formatCurrency(num) {
            return new Intl.NumberFormat('es-ES', {
                style: 'currency',
                currency: 'USD',
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            }).format(num);
        }

        function formatPercentage(num) {
            const prefix = num >= 0 ? '+' : '';
            return prefix + num.toFixed(1) + '%';
        }

        // Actualización automática
        function startAutoRefresh() {
            if (refreshTimer) {
                clearInterval(refreshTimer);
            }
            
            refreshTimer = setInterval(() => {
                loadDashboard();
            }, apiConfig.refreshInterval * 1000);
        }

        // Actualización manual
        function refreshData() {
            loadDashboard();
        }

        // Función de logout
        async function logout() {
            if (confirm('¿Estás seguro de que deseas cerrar sesión?')) {
                try {
                    await fetch('auth.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                        },
                        body: JSON.stringify({
                            action: 'logout'
                        })
                    });

                    // Limpiar almacenamiento local
                    sessionStorage.clear();
                    localStorage.removeItem('auth_token');
                    
                    // Redirigir al login
                    window.location.href = 'login.html';
                } catch (error) {
                    console.error('Error al cerrar sesión:', error);
                    // Redirigir de todos modos
                    window.location.href = 'login.html';
                }
            }
        }

        // Cerrar modal al hacer clic fuera
        window.onclick = function(event) {
            const modal = document.getElementById('settingsModal');
            if (event.target === modal) {
                closeSettings();
            }
        }

        // Timeout de sesión
        let sessionTimeout;
        const SESSION_TIMEOUT = 30 * 60 * 1000; // 30 minutos

        function resetSessionTimeout() {
            clearTimeout(sessionTimeout);
            sessionTimeout = setTimeout(() => {
                showAlert('error', 'Tu sesión ha expirado. Serás redirigido al login.');
                setTimeout(() => {
                    logout();
                }, 3000);
            }, SESSION_TIMEOUT);
        }

        // Resetear timeout en cada interacción
        document.addEventListener('click', resetSessionTimeout);
        document.addEventListener('keypress', resetSessionTimeout);

        // Inicialización
        document.addEventListener('DOMContentLoaded', function() {
            console.log('Dashboard iniciando...');
            
            // Inicializar el sistema
            initializeAccounts();
            
            // Cargar intervalo de actualización
            apiConfig.refreshInterval = parseInt(localStorage.getItem('ts_refresh_interval') || '300');
            
            // Si no hay cuentas configuradas, abrir configuración
            if (!activeAccountId || accounts.length === 0) {
                console.log('No hay cuentas configuradas');
                openSettings();
                showAlert('error', 'Por favor configura al menos una cuenta de TrafficStars');
            } else {
                console.log('Cargando cuenta activa:', activeAccountId);
                // Cargar configuración de la cuenta activa
                const activeAccount = accounts.find(acc => acc.id === activeAccountId);
                if (activeAccount) {
                    apiConfig.clientId = activeAccount.clientId;
                    apiConfig.apiSecret = activeAccount.apiSecret;
                    console.log('Configuración cargada, iniciando dashboard...');
                    loadDashboard();
                    startAutoRefresh();
                }
            }
            
            // Iniciar timeout de sesión
            resetSessionTimeout();
        });
    </script>
</body>
</html>
