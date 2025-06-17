<?php
/**
 * Sistema de Autenticación para Dashboard Trafficstars
 * Maneja login, logout, sesiones y verificación de usuarios
 */

// Definir acceso seguro
define('SECURE_ACCESS', true);

// Incluir configuración de seguridad
if (file_exists('security.php')) {
    require_once 'security.php';
}

session_start();

// Configuración
define('SESSION_LIFETIME', 86400); // 24 horas
define('MAX_LOGIN_ATTEMPTS', 3);
define('LOCKOUT_TIME', 300); // 5 minutos
define('SECRET_KEY', '2b3e28296fd7f5b62293d9ed23171b29d292dce3e6afd6c7f7737c8ae13ea2a2'); // Clave generada automáticamente

// Headers
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Manejo de peticiones OPTIONS para CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

/**
 * Clase principal de autenticación
 */
class Authentication {
    private $users;
    private $db_file = 'users.json'; // En producción usar base de datos real
    
    public function __construct() {
        $this->loadUsers();
    }
    
    /**
     * Cargar usuarios (en producción usar base de datos)
     */
    private function loadUsers() {
        // Usuarios de demostración
        $this->users = [
            'admin@trafficstars.com' => [
                'id' => 1,
                'name' => 'Administrador',
                'email' => 'admin@trafficstars.com',
                'password' => password_hash('admin123', PASSWORD_DEFAULT),
                'role' => 'admin',
                'api_client_id' => '',
                'api_secret' => '',
                'created_at' => '2024-01-01',
                'last_login' => null
            ],
            'user@trafficstars.com' => [
                'id' => 2,
                'name' => 'Usuario Demo',
                'email' => 'user@trafficstars.com',
                'password' => password_hash('user123', PASSWORD_DEFAULT),
                'role' => 'user',
                'api_client_id' => '',
                'api_secret' => '',
                'created_at' => '2024-01-01',
                'last_login' => null
            ]
        ];
        
        // Intentar cargar usuarios desde archivo
        if (file_exists($this->db_file)) {
            $data = json_decode(file_get_contents($this->db_file), true);
            if ($data) {
                $this->users = array_merge($this->users, $data);
            }
        }
    }
    
    /**
     * Guardar usuarios (en producción usar base de datos)
     */
    private function saveUsers() {
        file_put_contents($this->db_file, json_encode($this->users, JSON_PRETTY_PRINT));
    }
    
    /**
     * Verificar límite de intentos de login
     */
    private function checkLoginAttempts($email) {
        $attempts_key = 'login_attempts_' . md5($email);
        $lockout_key = 'lockout_until_' . md5($email);
        
        // Verificar si está bloqueado
        if (isset($_SESSION[$lockout_key]) && $_SESSION[$lockout_key] > time()) {
            $remaining = $_SESSION[$lockout_key] - time();
            throw new Exception("Cuenta bloqueada. Intenta nuevamente en " . ceil($remaining / 60) . " minutos.");
        }
        
        // Contar intentos
        $attempts = isset($_SESSION[$attempts_key]) ? $_SESSION[$attempts_key] : 0;
        
        if ($attempts >= MAX_LOGIN_ATTEMPTS) {
            $_SESSION[$lockout_key] = time() + LOCKOUT_TIME;
            $_SESSION[$attempts_key] = 0;
            throw new Exception("Demasiados intentos fallidos. Cuenta bloqueada temporalmente.");
        }
        
        return $attempts;
    }
    
    /**
     * Registrar intento de login
     */
    private function recordLoginAttempt($email, $success = false) {
        $attempts_key = 'login_attempts_' . md5($email);
        
        if ($success) {
            unset($_SESSION[$attempts_key]);
            unset($_SESSION['lockout_until_' . md5($email)]);
        } else {
            $_SESSION[$attempts_key] = isset($_SESSION[$attempts_key]) ? $_SESSION[$attempts_key] + 1 : 1;
        }
    }
    
    /**
     * Generar token JWT simple
     */
    private function generateToken($userId, $email) {
        $header = json_encode(['typ' => 'JWT', 'alg' => 'HS256']);
        $payload = json_encode([
            'user_id' => $userId,
            'email' => $email,
            'exp' => time() + SESSION_LIFETIME,
            'iat' => time()
        ]);
        
        $base64Header = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($header));
        $base64Payload = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($payload));
        
        $signature = hash_hmac('sha256', $base64Header . "." . $base64Payload, SECRET_KEY, true);
        $base64Signature = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));
        
        return $base64Header . "." . $base64Payload . "." . $base64Signature;
    }
    
    /**
     * Verificar token JWT
     */
    private function verifyToken($token) {
        $parts = explode('.', $token);
        
        if (count($parts) != 3) {
            return false;
        }
        
        $header = base64_decode(str_replace(['-', '_'], ['+', '/'], $parts[0]));
        $payload = base64_decode(str_replace(['-', '_'], ['+', '/'], $parts[1]));
        $signatureProvided = $parts[2];
        
        $base64Header = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($header));
        $base64Payload = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($payload));
        
        $signature = hash_hmac('sha256', $base64Header . "." . $base64Payload, SECRET_KEY, true);
        $base64Signature = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));
        
        if ($base64Signature !== $signatureProvided) {
            return false;
        }
        
        $payloadData = json_decode($payload, true);
        
        // Verificar expiración
        if ($payloadData['exp'] < time()) {
            return false;
        }
        
        return $payloadData;
    }
    
    /**
     * Login de usuario
     */
    public function login($email, $password, $remember = false) {
        try {
            // Verificar intentos de login
            $this->checkLoginAttempts($email);
            
            // Validar entrada
            if (empty($email) || empty($password)) {
                throw new Exception("Email y contraseña son requeridos");
            }
            
            // Verificar si el usuario existe
            if (!isset($this->users[$email])) {
                $this->recordLoginAttempt($email, false);
                throw new Exception("Credenciales incorrectas");
            }
            
            $user = $this->users[$email];
            
            // Verificar contraseña
            if (!password_verify($password, $user['password'])) {
                $this->recordLoginAttempt($email, false);
                throw new Exception("Credenciales incorrectas");
            }
            
            // Login exitoso
            $this->recordLoginAttempt($email, true);
            
            // Actualizar último login
            $this->users[$email]['last_login'] = date('Y-m-d H:i:s');
            $this->saveUsers();
            
            // Crear sesión
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_email'] = $user['email'];
            $_SESSION['user_name'] = $user['name'];
            $_SESSION['user_role'] = $user['role'];
            $_SESSION['login_time'] = time();
            
            // Generar token
            $token = $this->generateToken($user['id'], $user['email']);
            
            // Si "recordar", extender duración de sesión
            if ($remember) {
                ini_set('session.cookie_lifetime', SESSION_LIFETIME * 7); // 7 días
            }
            
            return [
                'success' => true,
                'token' => $token,
                'user' => [
                    'id' => $user['id'],
                    'name' => $user['name'],
                    'email' => $user['email'],
                    'role' => $user['role']
                ]
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Logout de usuario
     */
    public function logout() {
        // Destruir sesión
        $_SESSION = array();
        
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }
        
        session_destroy();
        
        return [
            'success' => true,
            'message' => 'Sesión cerrada exitosamente'
        ];
    }
    
    /**
     * Verificar si el usuario está autenticado
     */
    public function isAuthenticated() {
        // Verificar sesión
        if (isset($_SESSION['user_id']) && isset($_SESSION['login_time'])) {
            // Verificar expiración de sesión
            if ((time() - $_SESSION['login_time']) > SESSION_LIFETIME) {
                $this->logout();
                return false;
            }
            
            // Actualizar tiempo de actividad
            $_SESSION['last_activity'] = time();
            return true;
        }
        
        return false;
    }
    
    /**
     * Verificar token
     */
    public function verify($token) {
        $payload = $this->verifyToken($token);
        
        if ($payload) {
            return [
                'valid' => true,
                'user' => [
                    'id' => $payload['user_id'],
                    'email' => $payload['email']
                ]
            ];
        }
        
        return ['valid' => false];
    }
    
    /**
     * Obtener información del usuario actual
     */
    public function getCurrentUser() {
        if ($this->isAuthenticated()) {
            return [
                'id' => $_SESSION['user_id'],
                'email' => $_SESSION['user_email'],
                'name' => $_SESSION['user_name'],
                'role' => $_SESSION['user_role']
            ];
        }
        
        return null;
    }
    
    /**
     * Actualizar credenciales API del usuario
     */
    public function updateApiCredentials($email, $clientId, $apiSecret) {
        if (isset($this->users[$email])) {
            $this->users[$email]['api_client_id'] = $clientId;
            $this->users[$email]['api_secret'] = $apiSecret;
            $this->saveUsers();
            return true;
        }
        return false;
    }
    
    /**
     * Obtener credenciales API del usuario
     */
    public function getApiCredentials($email) {
        if (isset($this->users[$email])) {
            return [
                'client_id' => $this->users[$email]['api_client_id'],
                'api_secret' => $this->users[$email]['api_secret']
            ];
        }
        return null;
    }
}

// Procesar peticiones
function processRequest() {
    $auth = new Authentication();
    
    // Obtener datos de la petición
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? $_GET['action'] ?? '';
    
    switch ($action) {
        case 'login':
            $email = $input['email'] ?? '';
            $password = $input['password'] ?? '';
            $remember = $input['remember'] ?? false;
            
            $result = $auth->login($email, $password, $remember);
            echo json_encode($result);
            break;
            
        case 'logout':
            $result = $auth->logout();
            echo json_encode($result);
            break;
            
        case 'verify':
            $token = $input['token'] ?? '';
            $result = $auth->verify($token);
            echo json_encode($result);
            break;
            
        case 'check':
            $isAuth = $auth->isAuthenticated();
            $user = $isAuth ? $auth->getCurrentUser() : null;
            
            echo json_encode([
                'authenticated' => $isAuth,
                'user' => $user
            ]);
            break;
            
        case 'get_api_credentials':
            if ($auth->isAuthenticated()) {
                $email = $_SESSION['user_email'];
                $credentials = $auth->getApiCredentials($email);
                echo json_encode([
                    'success' => true,
                    'credentials' => $credentials
                ]);
            } else {
                http_response_code(401);
                echo json_encode([
                    'success' => false,
                    'message' => 'No autorizado'
                ]);
            }
            break;
            
        case 'update_api_credentials':
            if ($auth->isAuthenticated()) {
                $email = $_SESSION['user_email'];
                $clientId = $input['client_id'] ?? '';
                $apiSecret = $input['api_secret'] ?? '';
                
                $success = $auth->updateApiCredentials($email, $clientId, $apiSecret);
                echo json_encode([
                    'success' => $success
                ]);
            } else {
                http_response_code(401);
                echo json_encode([
                    'success' => false,
                    'message' => 'No autorizado'
                ]);
            }
            break;
            
        default:
            http_response_code(400);
            echo json_encode([
                'error' => 'Acción no válida'
            ]);
    }
}

// Procesar la petición
processRequest();
?>