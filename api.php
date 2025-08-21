<?php
/**
 * API Handler para TrafficStars - Versión con OAuth2 Correcto
 * @version 5.0
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
    private $authUrl = 'https://id.trafficstars.com/realms/trafficstars/protocol/openid-connect/token';
    private $apiUrl = 'https://api.trafficstars.com';
    private $clientId;
    private $clientSecret;
    private $accessToken = null;
    private $tokenExpiry = null;
    private $timeout = 30;
    private $debug = true;
    
    public function __construct($clientId, $clientSecret) {
        $this->clientId = $clientId;
        $this->clientSecret = $clientSecret;
    }
    
    /**
     * Obtener token de acceso OAuth2 según la documentación
     */
    private function getAccessToken($forceRefresh = false) {
        // Si tenemos un token válido y no forzamos refresh, usarlo
        if (!$forceRefresh && $this->accessToken && $this->tokenExpiry && time() < $this->tokenExpiry) {
            return $this->accessToken;
        }
        
        // Obtener nuevo token
        $ch = curl_init();
        
        // Preparar datos del formulario
        $postData = http_build_query([
            'grant_type' => 'client_credentials',
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret
        ]);
        
        curl_setopt_array($ch, [
            CURLOPT_URL => $this->authUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postData,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/json'
            ],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT => $this->timeout
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        if ($this->debug) {
            error_log("=== OAuth2 Token Request ===");
            error_log("URL: " . $this->authUrl);
            error_log("Client ID: " . $this->clientId);
            error_log("HTTP Code: " . $httpCode);
            error_log("Response: " . substr($response, 0, 500));
        }
        
        if ($httpCode !== 200) {
            $errorData = json_decode($response, true);
            $errorMsg = isset($errorData['error_description']) ? $errorData['error_description'] : 
                       (isset($errorData['error']) ? $errorData['error'] : "Error HTTP $httpCode");
            throw new Exception("Error obteniendo token de acceso: " . $errorMsg);
        }
        
        $data = json_decode($response, true);
        
        if (!isset($data['access_token'])) {
            throw new Exception("No se recibió token de acceso en la respuesta");
        }
        
        $this->accessToken = $data['access_token'];
        
        // Calcular expiración (normalmente viene en segundos)
        if (isset($data['expires_in'])) {
            $this->tokenExpiry = time() + $data['expires_in'] - 60; // Restar 60 segundos por seguridad
        } else {
            $this->tokenExpiry = time() + 3600; // Por defecto 1 hora
        }
        
        if ($this->debug) {
            error_log("Token obtenido exitosamente. Expira en: " . $data['expires_in'] . " segundos");
        }
        
        return $this->accessToken;
    }
    
    /**
     * Hacer petición a la API con token OAuth2
     */
    private function makeRequest($endpoint, $params = [], $method = 'GET', $retry = true) {
        // Obtener token de acceso
        $token = $this->getAccessToken();
        
        // Construir URL
        $url = $this->apiUrl . $endpoint;
        
        // Headers con el token
        $headers = [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
            'Accept: application/json'
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
            CURLOPT_MAXREDIRS => 3
        ];
        
        if ($method === 'POST') {
            $curlOptions[CURLOPT_URL] = $url;
            $curlOptions[CURLOPT_POST] = true;
            $curlOptions[CURLOPT_POSTFIELDS] = json_encode($params);
        } elseif ($method === 'GET') {
            if (!empty($params)) {
                $url .= '?' . http_build_query($params);
            }
            $curlOptions[CURLOPT_URL] = $url;
            $curlOptions[CURLOPT_HTTPGET] = true;
        }
        
        curl_setopt_array($ch, $curlOptions);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        if ($this->debug) {
            error_log("=== API Request ===");
            error_log("URL: $url");
            error_log("Method: $method");
            error_log("HTTP Code: $httpCode");
            error_log("Response: " . substr($response, 0, 500));
        }
        
        // Si obtenemos 401, intentar renovar token y reintentar una vez
        if ($httpCode === 401 && $retry) {
            if ($this->debug) {
                error_log("Token expirado, obteniendo nuevo token...");
            }
            $this->getAccessToken(true); // Forzar renovación
            return $this->makeRequest($endpoint, $params, $method, false); // Reintentar sin retry
        }
        
        // Manejo de errores
        if ($curlError) {
            throw new Exception("Error de conexión: $curlError");
        }
        
        if ($httpCode === 404) {
            throw new Exception("Endpoint no encontrado (404): $url");
        } elseif ($httpCode === 403) {
            throw new Exception("Acceso denegado (403). Verifica los permisos de tu aplicación.");
        } elseif ($httpCode >= 400) {
            $errorData = json_decode($response, true);
            $errorMessage = isset($errorData['message']) ? $errorData['message'] : 
                           (isset($errorData['error']) ? $errorData['error'] : "Error HTTP $httpCode");
            throw new Exception($errorMessage);
        }
        
        if (empty($response)) {
            throw new Exception("Respuesta vacía del servidor");
        }
        
        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Error al decodificar JSON: ' . json_last_error_msg());
        }
        
        return $data;
    }
    
    /**
     * Obtener estadísticas
     */
    public function getStats($timeRange = 'today') {
        try {
            // Primero, intentar obtener información de la cuenta para verificar la conexión
            try {
                $accountInfo = $this->makeRequest('/v1/account', [], 'GET');
                if ($this->debug) {
                    error_log("Cuenta verificada: " . json_encode($accountInfo));
                }
            } catch (Exception $e) {
                // Si falla, continuar de todos modos
                if ($this->debug) {
                    error_log("No se pudo verificar cuenta: " . $e->getMessage());
                }
            }
            
            // Calcular fechas
            $dates = $this->calculateDateRange($timeRange);

            // La API oficial espera fechas en formato YYYY-MM-DD y un parámetro
            // de agrupación llamado "group_by". En versiones previas se estaban
            // enviando timestamps Unix y el parámetro "group", lo que provocaba
            // respuestas vacías o inconsistentes.  Aquí normalizamos la llamada
            // según la documentación pública.
            $params = [
                'date_from' => $dates['date_from'],
                'date_to'   => $dates['date_to'],
                'timezone'  => 'UTC',
                'group_by'  => 'country'
            ];

            // Intentar obtener estadísticas usando el endpoint actual
            $response = $this->makeRequest('/v1/statistics', $params, 'GET');
            
            return $this->processStats($response, $timeRange);
            
        } catch (Exception $e) {
            error_log("Error obteniendo estadísticas: " . $e->getMessage());
            
            // Si falla, intentar con diferentes parámetros
            try {
                $params = [
                    'date_from' => $dates['date_from'],
                    'date_to' => $dates['date_to'],
                    'group_by' => 'country'
                ];
                
                $response = $this->makeRequest('/v1/stats', $params, 'GET');
                return $this->processStats($response, $timeRange);
                
            } catch (Exception $e2) {
                throw new Exception("Error al obtener estadísticas: " . $e->getMessage());
            }
        }
    }
    
    /**
     * Calcular rango de fechas
     */
    private function calculateDateRange($timeRange) {
        date_default_timezone_set('UTC');
        
        $endDate = new DateTime();
        $startDate = clone $endDate;
        
        switch ($timeRange) {
            case 'today':
                $startDate->setTime(0, 0, 0);
                $endDate->setTime(23, 59, 59);
                break;
            case 'week':
                $startDate->modify('-7 days')->setTime(0, 0, 0);
                $endDate->setTime(23, 59, 59);
                break;
            case 'month':
                $startDate->modify('-30 days')->setTime(0, 0, 0);
                $endDate->setTime(23, 59, 59);
                break;
        }
        
        return [
            'from' => $startDate->getTimestamp(),
            'to' => $endDate->getTimestamp(),
            'date_from' => $startDate->format('Y-m-d'),
            'date_to' => $endDate->format('Y-m-d')
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
        
        // Buscar los datos en la respuesta
        $items = [];
        
        if (isset($apiData['data'])) {
            // Algunas respuestas incluyen los registros en data.rows
            if (isset($apiData['data']['rows'])) {
                $items = $apiData['data']['rows'];
            } else {
                $items = $apiData['data'];
            }
        } elseif (isset($apiData['items'])) {
            $items = $apiData['items'];
        } elseif (isset($apiData['statistics'])) {
            // La API de TrafficStars devuelve los datos dentro de statistics.rows
            if (isset($apiData['statistics']['rows'])) {
                $items = $apiData['statistics']['rows'];
            } elseif (isset($apiData['statistics']['items'])) {
                $items = $apiData['statistics']['items'];
            } else {
                $items = $apiData['statistics'];
            }
            // Usar resumen si está disponible para totales
            if (isset($apiData['statistics']['summary'])) {
                $summary = $apiData['statistics']['summary'];
                $totalImpressions = intval($summary['impressions'] ?? $summary['imp'] ?? $summary['views'] ?? $totalImpressions);
                $totalClicks      = intval($summary['clicks'] ?? $summary['click'] ?? $totalClicks);
                $totalRevenue     = floatval($summary['revenue'] ?? $summary['earnings'] ?? $summary['earn'] ?? $totalRevenue);
            }
        } elseif (is_array($apiData) && !empty($apiData)) {
            // Si la respuesta es directamente un array
            if (isset($apiData[0])) {
                $items = $apiData;
            } else {
                // Podría ser un objeto único
                $items = [$apiData];
            }
        }
        
        if ($this->debug) {
            error_log("Procesando " . count($items) . " items de estadísticas");
        }
        
        // Procesar cada item
        foreach ($items as $item) {
            // Extraer país/geo
            $country = '';
            if (isset($item['geo'])) {
                $country = strtoupper($item['geo']);
            } elseif (isset($item['country'])) {
                $country = strtoupper($item['country']);
            } elseif (isset($item['country_code'])) {
                $country = strtoupper($item['country_code']);
            }
            
            // Extraer métricas
            $impressions = intval($item['impressions'] ?? $item['imp'] ?? $item['views'] ?? 0);
            $clicks = intval($item['clicks'] ?? $item['click'] ?? 0);
            $revenue = floatval($item['revenue'] ?? $item['earnings'] ?? $item['earn'] ?? 0);
            $cpm = floatval($item['cpm'] ?? $item['ecpm'] ?? 0);
            
            $totalImpressions += $impressions;
            $totalClicks += $clicks;
            $totalRevenue += $revenue;
            
            if (!empty($country)) {
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
                
                if ($cpm > 0 && $countryStats[$country]['cpm'] == 0) {
                    $countryStats[$country]['cpm'] = $cpm;
                }
            }
        }
        
        // Si no hay datos por país pero tenemos totales, crear entrada global
        if (empty($countryStats) && ($totalImpressions > 0 || $totalRevenue > 0)) {
            $countryStats['GLOBAL'] = [
                'name' => 'Total Global',
                'code' => 'GLOBAL',
                'flag' => '🌍',
                'visits' => $totalImpressions,
                'clicks' => $totalClicks,
                'earnings' => $totalRevenue,
                'cpm' => $totalImpressions > 0 ? ($totalRevenue / $totalImpressions) * 1000 : 0,
                'percentage' => 100
            ];
        }
        
        // Calcular CPM promedio
        $avgCPM = $totalImpressions > 0 ? ($totalRevenue / $totalImpressions) * 1000 : 0;
        
        // Procesar estadísticas por país
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
        
        // Limitar a top 10
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
            'VE' => 'Venezuela',
            'EC' => 'Ecuador',
            'BO' => 'Bolivia',
            'PY' => 'Paraguay',
            'UY' => 'Uruguay',
            'TW' => 'Taiwán',
            'CN' => 'China',
            'HK' => 'Hong Kong',
            'JP' => 'Japón',
            'KR' => 'Corea del Sur',
            'SG' => 'Singapur',
            'MY' => 'Malasia',
            'TH' => 'Tailandia',
            'VN' => 'Vietnam',
            'ID' => 'Indonesia',
            'PH' => 'Filipinas',
            'IN' => 'India',
            'PK' => 'Pakistán',
            'BD' => 'Bangladesh',
            'RU' => 'Rusia',
            'UA' => 'Ucrania',
            'PL' => 'Polonia',
            'CZ' => 'República Checa',
            'SK' => 'Eslovaquia',
            'HU' => 'Hungría',
            'RO' => 'Rumania',
            'BG' => 'Bulgaria',
            'HR' => 'Croacia',
            'RS' => 'Serbia',
            'SI' => 'Eslovenia',
            'GR' => 'Grecia',
            'TR' => 'Turquía',
            'IL' => 'Israel',
            'EG' => 'Egipto',
            'ZA' => 'Sudáfrica',
            'NG' => 'Nigeria',
            'KE' => 'Kenia',
            'MA' => 'Marruecos',
            'TN' => 'Túnez',
            'DZ' => 'Argelia',
            'NL' => 'Países Bajos',
            'BE' => 'Bélgica',
            'CH' => 'Suiza',
            'AT' => 'Austria',
            'SE' => 'Suecia',
            'NO' => 'Noruega',
            'DK' => 'Dinamarca',
            'FI' => 'Finlandia',
            'PT' => 'Portugal',
            'IE' => 'Irlanda',
            'NZ' => 'Nueva Zelanda',
            'GLOBAL' => 'Global',
            'XX' => 'Desconocido'
        ];
        
        return isset($countries[$code]) ? $countries[$code] : $code;
    }
    
    /**
     * Obtener emoji de bandera
     */
    private function getCountryFlag($code) {
        if ($code === 'XX' || $code === 'GLOBAL' || strlen($code) !== 2) {
            return '🌍';
        }
        
        $code = strtoupper($code);
        $flag = '';
        
        for ($i = 0; $i < 2; $i++) {
            $flag .= mb_chr(ord($code[$i]) - ord('A') + 0x1F1E6, 'UTF-8');
        }
        
        return $flag;
    }
    
    /**
     * Verificar conexión y obtener endpoints disponibles
     */
    public function testConnection() {
        $results = [
            'auth' => false,
            'endpoints' => [],
            'account' => null,
            'error' => null
        ];
        
        try {
            // Probar autenticación
            $this->getAccessToken(true);
            $results['auth'] = true;
            
            // Probar diferentes endpoints
            $endpoints = [
                '/v1/account' => 'Información de cuenta',
                '/v1/statistics' => 'Estadísticas',
                '/v1/spots' => 'Spots publicitarios',
                '/v1/campaigns' => 'Campañas'
            ];
            
            foreach ($endpoints as $endpoint => $description) {
                try {
                    $response = $this->makeRequest($endpoint, [], 'GET');
                    $results['endpoints'][] = [
                        'endpoint' => $endpoint,
                        'description' => $description,
                        'status' => 'ok'
                    ];
                    
                    if ($endpoint === '/v1/account') {
                        $results['account'] = $response;
                    }
                } catch (Exception $e) {
                    $results['endpoints'][] = [
                        'endpoint' => $endpoint,
                        'description' => $description,
                        'status' => 'error',
                        'error' => $e->getMessage()
                    ];
                }
            }
            
        } catch (Exception $e) {
            $results['error'] = $e->getMessage();
        }
        
        return $results;
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
                
            case 'testConnection':
                if (!isset($input['clientId']) || !isset($input['apiSecret'])) {
                    throw new Exception('Credenciales de API requeridas');
                }
                
                $api = new TrafficstarsAPI($input['clientId'], $input['apiSecret']);
                $results = $api->testConnection();
                
                echo json_encode($results);
                break;
                
            case 'test':
                echo json_encode([
                    'success' => true,
                    'message' => 'API funcionando',
                    'version' => '5.0',
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
