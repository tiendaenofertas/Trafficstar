<?php
/**
 * API Handler para TrafficStars - Versión con OAuth2
 * @version 4.0
 */

// Configuración
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Headers CORS
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit(0);
}

class TrafficstarsAPI {
    // URLs según la documentación
    private $authUrl = 'https://id.trafficstars.com';
    private $apiUrl = 'https://api.trafficstars.com';
    private $clientId;
    private $apiKey;
    private $accessToken = null;
    private $timeout = 30;
    private $debug = true;
    
    public function __construct($clientId, $apiKey) {
        $this->clientId = $clientId;
        $this->apiKey = $apiKey;
    }
    
    /**
     * Obtener token de acceso OAuth2
     */
    private function getAccessToken() {
        // Si ya tenemos un token, intentar usarlo
        if ($this->accessToken) {
            return $this->accessToken;
        }
        
        // El API Key que nos dieron YA ES un JWT token, no necesitamos intercambiarlo
        // Intentar decodificar para verificar
        $parts = explode('.', $this->apiKey);
        if (count($parts) === 3) {
            // Es un JWT válido, usarlo directamente
            $this->accessToken = $this->apiKey;
            return $this->accessToken;
        }
        
        // Si no es JWT, intentar intercambiar por token
        $url = $this->authUrl . '/realms/trafficstars/protocol/openid-connect/token';
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'grant_type' => 'client_credentials',
                'client_id' => $this->clientId,
                'client_secret' => $this->apiKey
            ]),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded'
            ],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT => $this->timeout
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode === 200) {
            $data = json_decode($response, true);
            if (isset($data['access_token'])) {
                $this->accessToken = $data['access_token'];
                return $this->accessToken;
            }
        }
        
        // Si falla, usar el API key directamente
        $this->accessToken = $this->apiKey;
        return $this->accessToken;
    }
    
    /**
     * Hacer petición a la API con autenticación correcta
     */
    private function makeRequest($endpoint, $params = [], $method = 'POST') {
        // Obtener token de acceso
        $token = $this->getAccessToken();
        
        // Construir URL completa
        $url = $this->apiUrl . $endpoint;
        
        // Headers con el token
        $headers = [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
            'Accept: application/json',
            'User-Agent: TrafficStars-Dashboard/4.0'
        ];
        
        $ch = curl_init();
        
        // Configuración base
        $curlOptions = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1
        ];
        
        if ($method === 'POST') {
            $curlOptions[CURLOPT_URL] = $url;
            $curlOptions[CURLOPT_POST] = true;
            $curlOptions[CURLOPT_POSTFIELDS] = json_encode($params);
        } elseif ($method === 'GET') {
            $queryString = http_build_query($params);
            $curlOptions[CURLOPT_URL] = $url . ($queryString ? '?' . $queryString : '');
            $curlOptions[CURLOPT_HTTPGET] = true;
        }
        
        curl_setopt_array($ch, $curlOptions);
        
        // Capturar información detallada para debug
        if ($this->debug) {
            curl_setopt($ch, CURLOPT_VERBOSE, true);
            $verbose = fopen('php://temp', 'w+');
            curl_setopt($ch, CURLOPT_STDERR, $verbose);
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        $curlErrno = curl_errno($ch);
        $effectiveUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        
        if ($this->debug && isset($verbose)) {
            rewind($verbose);
            $verboseLog = stream_get_contents($verbose);
            fclose($verbose);
        }
        
        curl_close($ch);
        
        // Log detallado
        if ($this->debug) {
            error_log("=== TrafficStars API Request ===");
            error_log("URL: $url");
            error_log("Effective URL: $effectiveUrl");
            error_log("Method: $method");
            error_log("Token (first 50 chars): " . substr($token, 0, 50) . "...");
            error_log("HTTP Code: $httpCode");
            error_log("Response: " . substr($response, 0, 500));
            if (isset($verboseLog)) {
                error_log("Verbose: " . substr($verboseLog, 0, 500));
            }
        }
        
        // Información de debug
        $debugInfo = [
            'url' => $url,
            'method' => $method,
            'httpCode' => $httpCode,
            'tokenUsed' => substr($token, 0, 20) . '...',
            'response_preview' => substr($response, 0, 200)
        ];
        
        // Manejo de errores
        if ($curlErrno) {
            throw new Exception("Error de conexión: $curlError (código: $curlErrno)");
        }
        
        // Si obtenemos HTML en lugar de JSON, es un problema de URL
        if ($httpCode === 200 && strpos($response, '<html') !== false) {
            throw new Exception("La API devolvió HTML en lugar de JSON. Esto indica un problema con la URL del endpoint.");
        }
        
        if ($httpCode === 404) {
            throw new Exception("Endpoint no encontrado (404). URL intentada: $url. Debug: " . json_encode($debugInfo));
        } elseif ($httpCode === 401) {
            throw new Exception("Error de autenticación (401). El token podría estar expirado o ser inválido.");
        } elseif ($httpCode === 403) {
            throw new Exception("Acceso denegado (403). Verifica los permisos de tu API key.");
        } elseif ($httpCode >= 400) {
            $errorData = json_decode($response, true);
            $errorMessage = isset($errorData['message']) ? $errorData['message'] : 
                           (isset($errorData['error']) ? $errorData['error'] : 
                           (isset($errorData['error_description']) ? $errorData['error_description'] : "Error HTTP $httpCode"));
            throw new Exception($errorMessage . ". Debug: " . json_encode($debugInfo));
        }
        
        if (empty($response)) {
            throw new Exception("Respuesta vacía del servidor.");
        }
        
        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Error al decodificar JSON: ' . json_last_error_msg());
        }
        
        return $data;
    }
    
    /**
     * Obtener estadísticas - método principal
     */
    public function getStats($timeRange = 'today') {
        try {
            // Calcular fechas
            $dates = $this->calculateDateRange($timeRange);
            
            // Primero intentar con el formato exacto de la documentación
            $params = [
                'from' => $dates['timestamp_start'],
                'to' => $dates['timestamp_end'],
                'group' => ['country'],
                'with_subaccounts' => false
            ];
            
            // Intentar primero con POST
            try {
                $response = $this->makeRequest('/v1/statistics', $params, 'POST');
                return $this->processStats($response, $timeRange);
            } catch (Exception $e) {
                if (strpos($e->getMessage(), '404') !== false) {
                    // Si falla con POST, intentar con GET
                    $getParams = [
                        'from' => $dates['timestamp_start'],
                        'to' => $dates['timestamp_end'],
                        'group' => 'country'
                    ];
                    
                    $response = $this->makeRequest('/v1/statistics', $getParams, 'GET');
                    return $this->processStats($response, $timeRange);
                }
                throw $e;
            }
            
        } catch (Exception $e) {
            // Si todo falla, intentar obtener información de la cuenta
            try {
                // Intentar endpoint de información de cuenta
                $accountInfo = $this->makeRequest('/v1/account', [], 'GET');
                error_log("Account info: " . json_encode($accountInfo));
            } catch (Exception $accountEx) {
                error_log("No se pudo obtener información de la cuenta: " . $accountEx->getMessage());
            }
            
            throw $e;
        }
    }
    
    /**
     * Calcular rango de fechas
     */
    private function calculateDateRange($timeRange) {
        date_default_timezone_set('UTC');
        
        $endDate = time();
        $endOfDay = strtotime('today 23:59:59');
        
        switch ($timeRange) {
            case 'today':
                $startDate = strtotime('today 00:00:00');
                $endDate = $endOfDay;
                break;
            case 'week':
                $startDate = strtotime('-7 days 00:00:00');
                $endDate = $endOfDay;
                break;
            case 'month':
                $startDate = strtotime('-30 days 00:00:00');
                $endDate = $endOfDay;
                break;
            default:
                $startDate = strtotime('today 00:00:00');
                $endDate = $endOfDay;
        }
        
        return [
            'timestamp_start' => $startDate,
            'timestamp_end' => $endDate,
            'date_start' => date('Y-m-d', $startDate),
            'date_end' => date('Y-m-d', $endDate)
        ];
    }
    
    /**
     * Procesar estadísticas
     */
    private function processStats($apiData, $timeRange) {
        $totalImpressions = 0;
        $totalClicks = 0;
        $totalRevenue = 0;
        $countryStats = [];
        
        // Buscar los datos en diferentes posibles estructuras
        $items = null;
        
        if (isset($apiData['items'])) {
            $items = $apiData['items'];
        } elseif (isset($apiData['data'])) {
            $items = $apiData['data'];
        } elseif (isset($apiData['statistics'])) {
            $items = $apiData['statistics'];
        } elseif (isset($apiData['result'])) {
            $items = $apiData['result'];
        } elseif (is_array($apiData) && !empty($apiData) && isset($apiData[0])) {
            $items = $apiData;
        }
        
        if (!$items || !is_array($items)) {
            if (isset($apiData['impressions']) || isset($apiData['revenue'])) {
                $items = [$apiData];
            } else {
                // Log la estructura recibida para debug
                error_log("Estructura de respuesta no reconocida: " . json_encode(array_keys($apiData)));
                return $this->getEmptyStats();
            }
        }
        
        // Procesar items
        foreach ($items as $item) {
            $country = strtoupper($item['country'] ?? $item['geo'] ?? $item['country_code'] ?? 'XX');
            $impressions = intval($item['impressions'] ?? $item['views'] ?? 0);
            $clicks = intval($item['clicks'] ?? 0);
            $revenue = floatval($item['revenue'] ?? $item['earnings'] ?? 0);
            $ecpm = floatval($item['ecpm'] ?? $item['cpm'] ?? 0);
            
            $totalImpressions += $impressions;
            $totalClicks += $clicks;
            $totalRevenue += $revenue;
            
            if (!isset($countryStats[$country])) {
                $countryStats[$country] = [
                    'name' => $this->getCountryName($country),
                    'code' => $country,
                    'flag' => $this->getCountryFlag($country),
                    'visits' => 0,
                    'clicks' => 0,
                    'earnings' => 0,
                    'cpm' => 0,
                    'percentage' => 0
                ];
            }
            
            $countryStats[$country]['visits'] += $impressions;
            $countryStats[$country]['clicks'] += $clicks;
            $countryStats[$country]['earnings'] += $revenue;
            
            if ($ecpm > 0) {
                $countryStats[$country]['cpm'] = $ecpm;
            }
        }
        
        // Calcular métricas
        $avgCPM = $totalImpressions > 0 ? ($totalRevenue / $totalImpressions) * 1000 : 0;
        
        // Procesar países
        foreach ($countryStats as &$country) {
            if ($country['cpm'] == 0 && $country['visits'] > 0) {
                $country['cpm'] = ($country['earnings'] / $country['visits']) * 1000;
            }
            
            if ($totalRevenue > 0) {
                $country['percentage'] = ($country['earnings'] / $totalRevenue) * 100;
            }
            
            $country['earnings'] = round($country['earnings'], 2);
            $country['cpm'] = round($country['cpm'], 2);
            $country['percentage'] = round($country['percentage'], 2);
        }
        
        // Ordenar por ganancias
        usort($countryStats, function($a, $b) {
            return $b['earnings'] <=> $a['earnings'];
        });
        
        $countryStats = array_slice($countryStats, 0, 10);
        
        return [
            'totalVisits' => $totalImpressions,
            'totalEarnings' => round($totalRevenue, 2),
            'avgCPM' => round($avgCPM, 2),
            'activeCountries' => count($countryStats),
            'visitsChange' => 0,
            'earningsChange' => 0,
            'cpmChange' => 0,
            'countriesChange' => 0,
            'countryStats' => array_values($countryStats),
            'isDemo' => false,
            'lastUpdate' => date('Y-m-d H:i:s')
        ];
    }
    
    /**
     * Devolver estadísticas vacías
     */
    private function getEmptyStats() {
        return [
            'totalVisits' => 0,
            'totalEarnings' => 0,
            'avgCPM' => 0,
            'activeCountries' => 0,
            'visitsChange' => 0,
            'earningsChange' => 0,
            'cpmChange' => 0,
            'countriesChange' => 0,
            'countryStats' => [],
            'isDemo' => false,
            'lastUpdate' => date('Y-m-d H:i:s')
        ];
    }
    
    /**
     * Obtener nombre del país
     */
    private function getCountryName($code) {
        $countries = [
            'US' => 'Estados Unidos',
            'CA' => 'Canadá',
            'UK' => 'Reino Unido',
            'GB' => 'Reino Unido',
            'DE' => 'Alemania',
            'FR' => 'Francia',
            'ES' => 'España',
            'IT' => 'Italia',
            'AU' => 'Australia',
            'BR' => 'Brasil',
            'MX' => 'México',
            'AR' => 'Argentina',
            'CL' => 'Chile',
            'CO' => 'Colombia',
            'PE' => 'Perú',
            'TW' => 'Taiwán',
            'CN' => 'China',
            'HK' => 'Hong Kong',
            'JP' => 'Japón',
            'KR' => 'Corea del Sur',
            'SG' => 'Singapur',
            'MY' => 'Malasia',
            'TH' => 'Tailandia',
            'VN' => 'Vietnam',
            'IN' => 'India',
            'RU' => 'Rusia',
            'UA' => 'Ucrania',
            'PL' => 'Polonia',
            'NL' => 'Países Bajos',
            'BE' => 'Bélgica',
            'CH' => 'Suiza',
            'AT' => 'Austria',
            'SE' => 'Suecia',
            'NO' => 'Noruega',
            'DK' => 'Dinamarca',
            'FI' => 'Finlandia',
            'NZ' => 'Nueva Zelanda',
            'ZA' => 'Sudáfrica',
            'EG' => 'Egipto',
            'IL' => 'Israel',
            'TR' => 'Turquía',
            'GR' => 'Grecia',
            'PT' => 'Portugal',
            'CZ' => 'República Checa',
            'HU' => 'Hungría',
            'RO' => 'Rumania',
            'BG' => 'Bulgaria',
            'XX' => 'Desconocido'
        ];
        
        return isset($countries[$code]) ? $countries[$code] : $code;
    }
    
    /**
     * Obtener emoji de bandera
     */
    private function getCountryFlag($code) {
        // Convertir código de país a emoji de bandera
        if ($code === 'XX' || strlen($code) !== 2) {
            return '🌍';
        }
        
        $code = strtoupper($code);
        $flag = '';
        
        // Convertir cada letra a su equivalente emoji
        for ($i = 0; $i < 2; $i++) {
            $flag .= mb_chr(ord($code[$i]) - ord('A') + 0x1F1E6, 'UTF-8');
        }
        
        return $flag;
    }
    
    /**
     * Verificar estado de la API y obtener información
     */
    public function checkApiStatus() {
        $status = [
            'auth_status' => 'unknown',
            'api_status' => 'unknown',
            'endpoints_available' => [],
            'account_info' => null
        ];
        
        // Verificar autenticación
        try {
            $token = $this->getAccessToken();
            $status['auth_status'] = 'ok';
            $status['token_preview'] = substr($token, 0, 50) . '...';
        } catch (Exception $e) {
            $status['auth_status'] = 'error';
            $status['auth_error'] = $e->getMessage();
        }
        
        // Probar endpoints comunes
        $endpoints = [
            '/v1/account' => 'GET',
            '/v1/statistics' => 'POST',
            '/v1/ads' => 'GET',
            '/v1/campaigns' => 'GET'
        ];
        
        foreach ($endpoints as $endpoint => $method) {
            try {
                if ($method === 'GET') {
                    $this->makeRequest($endpoint, [], 'GET');
                } else {
                    $this->makeRequest($endpoint, [
                        'from' => strtotime('today'),
                        'to' => strtotime('today 23:59:59')
                    ], 'POST');
                }
                $status['endpoints_available'][] = $endpoint;
            } catch (Exception $e) {
                // Endpoint no disponible
            }
        }
        
        return $status;
    }
}

/**
 * Procesar peticiones
 */
function processRequest() {
    try {
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!$input || !isset($input['action'])) {
            throw new Exception('Petición inválida');
        }
        
        switch ($input['action']) {
            case 'getStats':
                if (!isset($input['clientId']) || !isset($input['apiSecret'])) {
                    throw new Exception('Credenciales de API requeridas');
                }
                
                $api = new TrafficstarsAPI($input['clientId'], $input['apiSecret']);
                $stats = $api->getStats($input['timeRange'] ?? 'today');
                
                echo json_encode($stats);
                break;
                
            case 'checkStatus':
                if (!isset($input['clientId']) || !isset($input['apiSecret'])) {
                    throw new Exception('Credenciales de API requeridas');
                }
                
                $api = new TrafficstarsAPI($input['clientId'], $input['apiSecret']);
                $status = $api->checkApiStatus();
                
                echo json_encode($status);
                break;
                
            case 'test':
                echo json_encode([
                    'success' => true,
                    'message' => 'API funcionando',
                    'version' => '4.0',
                    'timestamp' => date('Y-m-d H:i:s')
                ]);
                break;
                
            default:
                throw new Exception('Acción no válida');
        }
        
    } catch (Exception $e) {
        http_response_code(200);
        echo json_encode([
            'error' => $e->getMessage(),
            'isDemo' => false,
            'timestamp' => date('Y-m-d H:i:s')
        ]);
    }
}

processRequest();
?>
