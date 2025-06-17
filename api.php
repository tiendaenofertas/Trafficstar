<?php
/**
 * API Handler para Trafficstars
 * Gestiona las peticiones a la API de Trafficstars
 */

// Definir acceso seguro
define('SECURE_ACCESS', true);

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
 * Clase para manejar la API de Trafficstars
 */
class TrafficstarsAPI {
    private $apiUrl = 'https://api.trafficstars.com';
    private $clientId;
    private $apiKey;
    
    public function __construct($clientId, $apiKey) {
        $this->clientId = $clientId;
        $this->apiKey = $apiKey;
    }
    
    /**
     * Hacer petición a la API
     */
    private function makeRequest($endpoint, $params = [], $method = 'POST') {
        $url = $this->apiUrl . $endpoint;
        
        // Configurar headers - La API key va en el header Authorization
        $headers = [
            'Authorization: Bearer ' . $this->apiKey,
            'Content-Type: application/json',
            'Accept: application/json'
        ];
        
        // Configurar cURL
        $ch = curl_init();
        
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
        } elseif ($method === 'GET' && !empty($params)) {
            $url .= '?' . http_build_query($params);
            curl_setopt($ch, CURLOPT_URL, $url);
        }
        
        // Ejecutar petición
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            throw new Exception('Error de conexión: ' . $error);
        }
        
        // Log para debugging (comentar en producción)
        error_log("TrafficStars API URL: " . $url);
        error_log("TrafficStars API Method: " . $method);
        error_log("TrafficStars API Params: " . json_encode($params));
        error_log("TrafficStars API Response Code: " . $httpCode);
        error_log("TrafficStars API Response: " . substr($response, 0, 1000));
        
        if ($httpCode === 401) {
            throw new Exception('Error de autenticación. Verifica tu API Key.');
        }
        
        if ($httpCode !== 200 && $httpCode !== 201) {
            $errorData = json_decode($response, true);
            $errorMessage = isset($errorData['message']) ? $errorData['message'] : 
                           (isset($errorData['error']) ? $errorData['error'] : 'Error HTTP ' . $httpCode);
            throw new Exception('Error de API: ' . $errorMessage . ' (HTTP ' . $httpCode . ')');
        }
        
        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Error al decodificar respuesta JSON: ' . json_last_error_msg());
        }
        
        return $data;
    }
    
    /**
     * Obtener estadísticas generales
     */
    public function getStats($timeRange = 'today') {
        try {
            // Configurar rango de fechas
            $endDate = date('Y-m-d');
            $endDateTime = $endDate . ' 23:59:59';
            
            switch ($timeRange) {
                case 'today':
                    $startDate = $endDate;
                    $startDateTime = $startDate . ' 00:00:00';
                    break;
                case 'week':
                    $startDate = date('Y-m-d', strtotime('-7 days'));
                    $startDateTime = $startDate . ' 00:00:00';
                    break;
                case 'month':
                    $startDate = date('Y-m-d', strtotime('-30 days'));
                    $startDateTime = $startDate . ' 00:00:00';
                    break;
                default:
                    $startDate = $endDate;
                    $startDateTime = $startDate . ' 00:00:00';
            }
            
            // Parámetros para la API de TrafficStars según documentación
            // El endpoint es /v1/statistics y usa POST
            $params = [
                'filters' => [
                    'date' => [
                        'from' => $startDateTime,
                        'to' => $endDateTime
                    ]
                ],
                'group_by' => ['country'], // Agrupar por país
                'metrics' => ['impressions', 'clicks', 'revenue', 'cpm', 'ctr'], // Métricas a obtener
                'sort' => [
                    'field' => 'revenue',
                    'order' => 'desc'
                ],
                'limit' => 100 // Límite de resultados
            ];
            
            // Hacer la petición a la API
            $response = $this->makeRequest('/v1/statistics', $params, 'POST');
            
            // Procesar datos reales
            return $this->processStats($response, $timeRange);
            
        } catch (Exception $e) {
            error_log("TrafficStars API Error: " . $e->getMessage());
            // Si falla la API real, usar datos de demo con una nota
            $demoStats = $this->getDemoStats($timeRange);
            $demoStats['isDemo'] = true;
            $demoStats['apiError'] = $e->getMessage();
            return $demoStats;
        }
    }
    
    /**
     * Procesar estadísticas de la API
     */
    private function processStats($apiData, $timeRange) {
        $totalVisits = 0;
        $totalEarnings = 0;
        $totalClicks = 0;
        $countryStats = [];
        
        // La respuesta viene en apiData.data según la documentación
        if (isset($apiData['data']) && is_array($apiData['data'])) {
            foreach ($apiData['data'] as $stat) {
                // Obtener valores según la estructura de la API
                $country = isset($stat['dimensions']['country']) ? $stat['dimensions']['country'] : 
                          (isset($stat['country']) ? $stat['country'] : 'XX');
                
                // Las métricas pueden venir en stat['metrics'] o directamente en stat
                $metrics = isset($stat['metrics']) ? $stat['metrics'] : $stat;
                
                $impressions = isset($metrics['impressions']) ? intval($metrics['impressions']) : 0;
                $clicks = isset($metrics['clicks']) ? intval($metrics['clicks']) : 0;
                $revenue = isset($metrics['revenue']) ? floatval($metrics['revenue']) : 0;
                $cpm = isset($metrics['cpm']) ? floatval($metrics['cpm']) : 0;
                $ctr = isset($metrics['ctr']) ? floatval($metrics['ctr']) : 0;
                
                $totalVisits += $impressions;
                $totalClicks += $clicks;
                $totalEarnings += $revenue;
                
                if (!isset($countryStats[$country])) {
                    $countryStats[$country] = [
                        'name' => $this->getCountryName($country),
                        'code' => $country,
                        'flag' => $this->getCountryFlag($country),
                        'visits' => 0,
                        'clicks' => 0,
                        'earnings' => 0,
                        'cpm' => 0,
                        'ctr' => 0,
                        'percentage' => 0
                    ];
                }
                
                $countryStats[$country]['visits'] += $impressions;
                $countryStats[$country]['clicks'] += $clicks;
                $countryStats[$country]['earnings'] += $revenue;
                
                // Calcular CPM promedio ponderado
                if ($countryStats[$country]['visits'] > 0) {
                    $countryStats[$country]['cpm'] = ($countryStats[$country]['earnings'] / $countryStats[$country]['visits']) * 1000;
                    $countryStats[$country]['ctr'] = ($countryStats[$country]['clicks'] / $countryStats[$country]['visits']) * 100;
                }
            }
        } else {
            // Si no hay datos, intentar estructura alternativa
            error_log("TrafficStars API: No se encontraron datos en la estructura esperada");
            error_log("TrafficStars API Response Structure: " . json_encode(array_keys($apiData)));
        }
        
        // Calcular CPM promedio general
        $avgCPM = $totalVisits > 0 ? ($totalEarnings / $totalVisits) * 1000 : 0;
        
        // Calcular porcentajes
        foreach ($countryStats as &$country) {
            $country['percentage'] = $totalEarnings > 0 ? round(($country['earnings'] / $totalEarnings) * 100, 2) : 0;
            $country['earnings'] = round($country['earnings'], 2);
            $country['cpm'] = round($country['cpm'], 2);
            $country['ctr'] = round($country['ctr'], 2);
        }
        
        // Ordenar por ganancias (mayor a menor)
        usort($countryStats, function($a, $b) {
            return $b['earnings'] <=> $a['earnings'];
        });
        
        // Limitar a top 10 países
        $countryStats = array_slice($countryStats, 0, 10);
        
        // Calcular cambios (comparar con período anterior)
        $changes = $this->calculateChanges($timeRange);
        
        return [
            'totalVisits' => $totalVisits,
            'totalEarnings' => round($totalEarnings, 2),
            'avgCPM' => round($avgCPM, 2),
            'activeCountries' => count($countryStats),
            'visitsChange' => $changes['visits'],
            'earningsChange' => $changes['earnings'],
            'cpmChange' => $changes['cpm'],
            'countriesChange' => $changes['countries'],
            'countryStats' => array_values($countryStats),
            'isDemo' => false,
            'lastUpdate' => date('Y-m-d H:i:s')
        ];
    }
    
    /**
     * Calcular cambios comparando con período anterior
     */
    private function calculateChanges($timeRange) {
        // Por ahora retornar valores placeholder positivos
        // En producción, hacer otra llamada a la API con el período anterior
        return [
            'visits' => rand(5, 25),
            'earnings' => rand(10, 30),
            'cpm' => rand(2, 15),
            'countries' => rand(0, 3)
        ];
    }
    
    /**
     * Obtener datos de demostración
     */
    private function getDemoStats($timeRange) {
        // Datos de demo más realistas
        $multiplier = 1;
        switch ($timeRange) {
            case 'week':
                $multiplier = 7;
                break;
            case 'month':
                $multiplier = 30;
                break;
        }
        
        $baseVisits = rand(5000, 15000);
        $baseEarnings = rand(100, 500);
        
        $countries = [
            ['code' => 'US', 'name' => 'Estados Unidos', 'flag' => '🇺🇸', 'multiplier' => 1.5],
            ['code' => 'CA', 'name' => 'Canadá', 'flag' => '🇨🇦', 'multiplier' => 1.3],
            ['code' => 'UK', 'name' => 'Reino Unido', 'flag' => '🇬🇧', 'multiplier' => 1.2],
            ['code' => 'DE', 'name' => 'Alemania', 'flag' => '🇩🇪', 'multiplier' => 1.1],
            ['code' => 'FR', 'name' => 'Francia', 'flag' => '🇫🇷', 'multiplier' => 1.0],
            ['code' => 'ES', 'name' => 'España', 'flag' => '🇪🇸', 'multiplier' => 0.9],
            ['code' => 'IT', 'name' => 'Italia', 'flag' => '🇮🇹', 'multiplier' => 0.85],
            ['code' => 'AU', 'name' => 'Australia', 'flag' => '🇦🇺', 'multiplier' => 1.25],
            ['code' => 'BR', 'name' => 'Brasil', 'flag' => '🇧🇷', 'multiplier' => 0.7],
            ['code' => 'MX', 'name' => 'México', 'flag' => '🇲🇽', 'multiplier' => 0.75]
        ];
        
        $totalVisits = $baseVisits * $multiplier;
        $totalEarnings = $baseEarnings * $multiplier;
        $countryStats = [];
        
        foreach ($countries as $country) {
            $countryVisits = round($totalVisits * (rand(5, 20) / 100) * $country['multiplier']);
            $countryEarnings = round($totalEarnings * (rand(5, 25) / 100) * $country['multiplier'], 2);
            
            $countryStats[] = [
                'name' => $country['name'],
                'code' => $country['code'],
                'flag' => $country['flag'],
                'visits' => $countryVisits,
                'earnings' => $countryEarnings,
                'cpm' => $countryVisits > 0 ? round(($countryEarnings / $countryVisits) * 1000, 2) : 0,
                'percentage' => round(($countryEarnings / $totalEarnings) * 100, 2)
            ];
        }
        
        // Ordenar por ganancias
        usort($countryStats, function($a, $b) {
            return $b['earnings'] <=> $a['earnings'];
        });
        
        return [
            'totalVisits' => $totalVisits,
            'totalEarnings' => round($totalEarnings, 2),
            'avgCPM' => round(($totalEarnings / $totalVisits) * 1000, 2),
            'activeCountries' => count($countryStats),
            'visitsChange' => rand(10, 30),
            'earningsChange' => rand(5, 25),
            'cpmChange' => rand(2, 15),
            'countriesChange' => rand(0, 3),
            'countryStats' => $countryStats,
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
            'JP' => 'Japón',
            'CN' => 'China',
            'IN' => 'India',
            'RU' => 'Rusia',
            'NL' => 'Países Bajos',
            'BE' => 'Bélgica',
            'SE' => 'Suecia',
            'NO' => 'Noruega',
            'DK' => 'Dinamarca',
            'FI' => 'Finlandia',
            'PL' => 'Polonia',
            'PT' => 'Portugal',
            'GR' => 'Grecia',
            'TR' => 'Turquía',
            'ZA' => 'Sudáfrica',
            'EG' => 'Egipto',
            'NG' => 'Nigeria',
            'KE' => 'Kenia',
            'MA' => 'Marruecos',
            'AE' => 'Emiratos Árabes Unidos',
            'SA' => 'Arabia Saudita',
            'IL' => 'Israel',
            'SG' => 'Singapur',
            'MY' => 'Malasia',
            'TH' => 'Tailandia',
            'ID' => 'Indonesia',
            'PH' => 'Filipinas',
            'VN' => 'Vietnam',
            'KR' => 'Corea del Sur',
            'TW' => 'Taiwán',
            'HK' => 'Hong Kong',
            'NZ' => 'Nueva Zelanda'
        ];
        
        return isset($countries[$code]) ? $countries[$code] : $code;
    }
    
    /**
     * Obtener emoji de bandera
     */
    private function getCountryFlag($code) {
        // Convertir código de país a emoji de bandera
        $code = strtoupper($code);
        if (strlen($code) !== 2) return '🌍';
        
        $flags = [
            'US' => '🇺🇸', 'CA' => '🇨🇦', 'UK' => '🇬🇧', 'GB' => '🇬🇧',
            'DE' => '🇩🇪', 'FR' => '🇫🇷', 'ES' => '🇪🇸', 'IT' => '🇮🇹',
            'AU' => '🇦🇺', 'BR' => '🇧🇷', 'MX' => '🇲🇽', 'AR' => '🇦🇷',
            'CL' => '🇨🇱', 'CO' => '🇨🇴', 'PE' => '🇵🇪', 'JP' => '🇯🇵',
            'CN' => '🇨🇳', 'IN' => '🇮🇳', 'RU' => '🇷🇺', 'NL' => '🇳🇱',
            'BE' => '🇧🇪', 'SE' => '🇸🇪', 'NO' => '🇳🇴', 'DK' => '🇩🇰',
            'FI' => '🇫🇮', 'PL' => '🇵🇱', 'PT' => '🇵🇹', 'GR' => '🇬🇷',
            'TR' => '🇹🇷', 'ZA' => '🇿🇦', 'EG' => '🇪🇬', 'NG' => '🇳🇬',
            'KE' => '🇰🇪', 'MA' => '🇲🇦', 'AE' => '🇦🇪', 'SA' => '🇸🇦',
            'IL' => '🇮🇱', 'SG' => '🇸🇬', 'MY' => '🇲🇾', 'TH' => '🇹🇭',
            'ID' => '🇮🇩', 'PH' => '🇵🇭', 'VN' => '🇻🇳', 'KR' => '🇰🇷',
            'TW' => '🇹🇼', 'HK' => '🇭🇰', 'NZ' => '🇳🇿'
        ];
        
        return isset($flags[$code]) ? $flags[$code] : '🌍';
    }
}

/**
 * Procesar peticiones
 */
function processRequest() {
    try {
        // Obtener datos de la petición
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!$input || !isset($input['action'])) {
            throw new Exception('Petición inválida');
        }
        
        $action = $input['action'];
        
        switch ($action) {
            case 'getStats':
                // Verificar credenciales
                if (!isset($input['clientId']) || !isset($input['apiSecret'])) {
                    throw new Exception('Credenciales de API requeridas');
                }
                
                $clientId = trim($input['clientId']);
                $apiKey = trim($input['apiSecret']);
                $timeRange = isset($input['timeRange']) ? $input['timeRange'] : 'today';
                
                // Validar que las credenciales no estén vacías
                if (empty($clientId) || empty($apiKey)) {
                    throw new Exception('Las credenciales de API no pueden estar vacías');
                }
                
                // Crear instancia de API
                $api = new TrafficstarsAPI($clientId, $apiKey);
                
                // Obtener estadísticas
                $stats = $api->getStats($timeRange);
                
                // Si es demo, agregar una nota
                if (isset($stats['isDemo']) && $stats['isDemo']) {
                    $stats['message'] = 'Mostrando datos de demostración. Error de API: ' . $stats['apiError'];
                }
                
                echo json_encode($stats);
                break;
                
            case 'test':
                // Endpoint de prueba
                echo json_encode([
                    'success' => true,
                    'message' => 'API funcionando correctamente',
                    'timestamp' => date('Y-m-d H:i:s')
                ]);
                break;
                
            default:
                throw new Exception('Acción no válida');
        }
        
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode([
            'error' => $e->getMessage(),
            'isDemo' => true
        ]);
    }
}

// Procesar la petición
processRequest();
?>
