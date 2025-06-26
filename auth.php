<?php
/**
 * Sistema de Autenticación para Dashboard TrafficStars
 * Versión optimizada con seguridad mejorada
 * 
 * @version 2.0
 * @author Dashboard TrafficStars
 */

// Configuración de seguridad
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Iniciar sesión de forma segura
if (session_status() === PHP_SESSION_NONE) {
    session_start([
        'cookie_lifetime' => 86400,
        'cookie_httponly' => true,
        'cookie_secure' => isset($_SERVER['HTTPS']),
        'cookie_samesite' => 'Lax',
        'use_strict_mode' => true,
        'use_cookies' => true,
        'use_only_cookies' => true
    ]);
}

// Configuración
define('SESSION_LIFETIME', 86400); // 24 horas
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOCKOUT_TIME', 900); // 15 minutos
define('SECRET_KEY', hash('sha256', 'TrafficStars_' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '_2024'));

// Headers de seguridad
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');

// Manejo de peticiones OPTIONS para CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit(0);
}

/**
 * Clase principal de autenticación
 */
class Authentication {
    private $users = [];
    private $dbFile = 'users.json';
    private $attemptsFile = 'login_attempts.json';
    private $attempts = [];
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->loadUsers();
        $this->loadAttempts();
        $this->cleanupOldAttempts();
    }
    
    /**
     * Cargar usuarios
     */
    private function loadUsers() {
        // Usuarios por defecto
        $defaultUsers = [
            'admin@trafficstars.com' => [
                'id' => 1,
                'name' => 'Administrador',
                'email' => 'admin@trafficstars.com',
                'password' => password_hash('admin123', PASSWORD_BCRYPT),
                'role' => 'admin',
                'api_credentials' => [],
                'created_at' => '2024-01-01',
                'last_login' => null,
                'active' => true
            ]
        ];
        
        // Cargar usuarios desde archivo si existe
        if (file_exists($this->dbFile) && is_readable($this->dbFile)) {
            $content = file_get_contents($this->dbFile);
            if ($content !== false) {
                $data = json_decode($content, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($data)) {
                    $this->users = array_merge($defaultUsers, $data);
                    return;
                }
            }
        }
        
        $this->users = $defaultUsers;
        $this->saveUsers();
    }
    
    /**
     * Guardar usuarios
     */
    private function saveUsers() {
        $json = json_encode($this->users, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if ($json !== false) {
            file_put_contents($this->dbFile, $json, LOCK_EX);
        }
    }
    
    /**
     * Cargar intentos de login
     */
    private function loadAttempts() {
        if (file_exists($this->attemptsFile) && is_readable($this->attemptsFile)) {
            $content = file_get_contents($this->attemptsFile);
            if ($content !== false) {
                $data = json_decode($content, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($data)) {
                    $this->attempts = $data;
                }
            }
        }
    }
    
    /**
     * Guardar intentos de login
     */
    private function saveAttempts() {
        $json = json_encode($this->attempts, JSON_PRETTY_PRINT);
        if ($json !== false) {
            file_put_contents($this->attemptsFile, $json, LOCK_EX);
        }
    }
    
    /**
     * Limpiar intentos antiguos
     */
    private function cleanupOldAttempts() {
        $changed = false;
        $now = time();
        
        foreach ($this->attempts as $key => $attempt) {
            if (isset($attempt['lockout_until']) && $attempt['lockout_until'] < $now) {
                unset($this->attempts[$key]);
                $changed = true;
            }
        }
        
        if ($changed) {
            $this->saveAttempts();
        }
    }
    
    /**
     * Verificar límite de intentos de login
     */
    private function checkLoginAttempts($email) {
        $key = md5(strtolower($email));
        $now = time();
        
        // Verificar si está bloqueado
        if (isset($this->attempts[$key])) {
            $attempt = $this->attempts[$key];
            
            if (isset($attempt['lockout_until']) && $attempt['lockout_until'] > $now) {
                $remaining = $attempt['lockout_until'] - $now;
                throw new Exception("Cuenta bloqueada. Intenta en " . ceil($remaining / 60) . " minutos.");
            }
            
            // Verificar número de intentos
            if (isset($attempt['count']) && $attempt['count'] >= MAX_LOGIN_ATTEMPTS) {
                $this->attempts[$key]['lockout_until'] = $now + LOCKOUT_TIME;
                $this->saveAttempts();
                throw new Exception("Demasiados intentos fallidos. Cuenta bloqueada temporalmente.");
            }
        }
    }
    
    /**
     * Registrar intento de login
     */
    private function recordLoginAttempt($email, $success = false) {
        $key = md5(strtolower($email));
        
        if ($success) {
            // Login exitoso, resetear intentos
            if (isset($this->attempts[$key])) {
                unset($this->attempts[$key]);
                $this->saveAttempts();
            }
        } else {
            // Login fallido, incrementar intentos
            if (!isset($this->attempts[$key])) {
                $this->attempts[$key] = [
                    'count' => 0,
                    'first_attempt' => time(),
                    'last_attempt' => time()
                ];
            }
            
            $this->attempts[$key]['count']++;
            $this->attempts[$key]['last_attempt'] = time();
            $this->saveAttempts();
        }
    }
    
    /**
     * Generar token JWT
     */
    private function generateToken($userId, $email) {
        $header = [
            'typ' => 'JWT',
            'alg' => 'HS256'
        ];
        
        $payload = [
            'user_id' => $userId,
            'email' => $email,
            'exp' => time() + SESSION_LIFETIME,
            'iat' => time(),
            'iss' => 'TrafficStars-Dashboard',
            'jti' => bin2hex(random_bytes(16))
        ];
        
        $headerEncoded = $this->base64UrlEncode(json_encode($header));
        $payloadEncoded = $this->base64UrlEncode(json_encode($payload));
        
        $signature = hash_hmac('sha256', $headerEncoded . '.' . $payloadEncoded, SECRET_KEY, true);
        $signatureEncoded = $this->base64UrlEncode($signature);
        
        return $headerEncoded . '.' . $payloadEncoded . '.' . $signatureEncoded;
    }
    
    /**
     * Verificar token JWT
     */
    private function verifyToken($token) {
        $parts = explode('.', $token);
        
        if (count($parts) !== 3) {
            return false;
        }
        
        list($headerEncoded, $payloadEncoded, $signatureEncoded) = $parts;
        
        // Verificar firma
        $signature = $this->base64UrlDecode($signatureEncoded);
        $expectedSignature = hash_hmac('sha256', $headerEncoded . '.' . $payloadEncoded, SECRET_KEY, true);
        
        if (!hash_equals($signature, $expectedSignature)) {
            return false;
        }
        
        // Decodificar payload
        $payload = json_decode($this->base64UrlDecode($payloadEncoded), true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            return false;
        }
        
        // Verificar expiración
        if (!isset($payload['exp']) || $payload['exp'] < time()) {
            return false;
        }
        
        return $payload;
    }
    
    /**
     * Base64 URL encode
     */
    private function base64UrlEncode($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
    
    /**
     * Base64 URL decode
     */
    private function base64UrlDecode($data) {
        return base64_decode(strtr($data, '-_', '+/'));
    }
    
    /**
     * Login de usuario
     */
    public function login($email, $password, $remember = false) {
        try {
            // Validar entrada
            $email = filter_var($email, FILTER_VALIDATE_EMAIL);
            if (!$email) {
                throw new Exception("Email inválido");
            }
            
            if (empty($password)) {
                throw new Exception("La contraseña es requerida");
            }
            
            // Verificar intentos de login
            $this->checkLoginAttempts($email);
            
            // Verificar si el usuario existe y está activo
            if (!isset($this->users[$email])) {
                $this->recordLoginAttempt($email, false);
                throw new Exception("Credenciales incorrectas");
            }
            
            $user = $this->users[$email];
            
            // Verificar si la cuenta está activa
            if (isset($user['active']) && !$user['active']) {
                throw new Exception("Cuenta desactivada");
            }
            
            // Verificar contraseña
            if (!password_verify($password, $user['password'])) {
                $this->recordLoginAttempt($email, false);
                throw new Exception("Credenciales incorrectas");
            }
            
            // Verificar si el hash necesita actualización
            if (password_needs_rehash($user['password'], PASSWORD_BCRYPT)) {
                $this->users[$email]['password'] = password_hash($password, PASSWORD_BCRYPT);
            }
            
            // Login exitoso
            $this->recordLoginAttempt($email, true);
            
            // Actualizar último login
            $this->users[$email]['last_login'] = date('Y-m-d H:i:s');
            $this->saveUsers();
            
            // Crear sesión
            session_regenerate_id(true);
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_email'] = $user['email'];
            $_SESSION['user_name'] = $user['name'];
            $_SESSION['user_role'] = $user['role'];
            $_SESSION['login_time'] = time();
            $_SESSION['last_activity'] = time();
            $_SESSION['user_ip'] = $_SERVER['REMOTE_ADDR'] ?? '';
            $_SESSION['user_agent'] = $_SERVER['HTTP_USER_AGENT'] ?? '';
            
            // Generar token
            $token = $this->generateToken($user['id'], $user['email']);
            
            // Si "recordar", extender duración de cookie
            if ($remember) {
                $params = session_get_cookie_params();
                setcookie(
                    session_name(),
                    session_id(),
                    time() + (SESSION_LIFETIME * 7),
                    $params['path'],
                    $params['domain'],
                    $params['secure'],
                    $params['httponly']
                );
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
        // Destruir sesión completamente
        $_SESSION = [];
        
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params["path"],
                $params["domain"],
                $params["secure"],
                $params["httponly"]
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
        // Verificar sesión básica
        if (!isset($_SESSION['user_id']) || !isset($_SESSION['login_time'])) {
            return false;
        }
        
        // Verificar expiración de sesión
        if ((time() - $_SESSION['login_time']) > SESSION_LIFETIME) {
            $this->logout();
            return false;
        }
        
        // Verificar inactividad (30 minutos)
        if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > 1800) {
            $this->logout();
            return false;
        }
        
        // Verificar cambio de IP o User Agent (opcional, puede ser estricto)
        $currentIp = $_SERVER['REMOTE_ADDR'] ?? '';
        $currentAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        
        if (isset($_SESSION['user_ip']) && $_SESSION['user_ip'] !== $currentIp) {
            // Advertencia: esto puede causar problemas con IPs dinámicas
            // $this->logout();
            // return false;
        }
        
        // Actualizar tiempo de actividad
        $_SESSION['last_activity'] = time();
        
        return true;
    }
    
    /**
     * Verificar token
     */
    public function verify($token) {
        try {
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
            
        } catch (Exception $e) {
            return ['valid' => false, 'error' => $e->getMessage()];
        }
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
     * Obtener todas las credenciales API del usuario
     */
    public function getApiCredentials($email) {
        if (isset($this->users[$email])) {
            return [
                'credentials' => $this->users[$email]['api_credentials'] ?? []
            ];
        }
        return ['credentials' => []];
    }
    
    /**
     * Actualizar credenciales API del usuario (múltiples cuentas)
     */
    public function updateApiCredentials($email, $credentials) {
        if (isset($this->users[$email])) {
            if (!is_array($credentials)) {
                $credentials = [];
            }
            
            $this->users[$email]['api_credentials'] = $credentials;
            $this->saveUsers();
            return true;
        }
        return false;
    }
    
    /**
     * Cambiar contraseña
     */
    public function changePassword($email, $currentPassword, $newPassword) {
        try {
            if (!isset($this->users[$email])) {
                throw new Exception("Usuario no encontrado");
            }
            
            $user = $this->users[$email];
            
            // Verificar contraseña actual
            if (!password_verify($currentPassword, $user['password'])) {
                throw new Exception("Contraseña actual incorrecta");
            }
            
            // Validar nueva contraseña
            if (strlen($newPassword) < 6) {
                throw new Exception("La nueva contraseña debe tener al menos 6 caracteres");
            }
            
            // Actualizar contraseña
            $this->users[$email]['password'] = password_hash($newPassword, PASSWORD_BCRYPT);
            $this->saveUsers();
            
            return ['success' => true, 'message' => 'Contraseña actualizada correctamente'];
            
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
}

/**
 * Función principal para procesar peticiones
 */
function processRequest() {
    try {
        $auth = new Authentication();
        
        // Obtener datos de la petición
        $input = json_decode(file_get_contents('php://input'), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $input = $_POST;
        }
        
        $action = $input['action'] ?? $_GET['action'] ?? '';
        
        // Procesar según la acción
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
                        'credentials' => $credentials['credentials']
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
                    $credentials = $input['credentials'] ?? [];
                    
                    $success = $auth->updateApiCredentials($email, $credentials);
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
                
            case 'change_password':
                if ($auth->isAuthenticated()) {
                    $email = $_SESSION['user_email'];
                    $currentPassword = $input['current_password'] ?? '';
                    $newPassword = $input['new_password'] ?? '';
                    
                    $result = $auth->changePassword($email, $currentPassword, $newPassword);
                    echo json_encode($result);
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
                    'error' => 'Acción no válida',
                    'available_actions' => [
                        'login', 'logout', 'verify', 'check',
                        'get_api_credentials', 'update_api_credentials',
                        'change_password'
                    ]
                ]);
        }
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'error' => 'Error del servidor',
            'message' => $e->getMessage()
        ]);
    }
}

// Ejecutar procesamiento
processRequest();
?>
