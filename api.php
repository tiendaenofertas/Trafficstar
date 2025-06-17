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
    private $apiUrl = 'https://api.trafficstars.com/v2';
    private $clientId;
    private $apiSecret;
    
    public function __construct($clientId, $apiSecret) {
        $this->clientId = $clientId;
        $this->apiSecret = $apiSecret;
    }
    
    /**
     * Hacer petición a la API
     */
    private function makeRequest($endpoint, $params = []) {
        $url = $this->apiUrl . $endpoint;
        
        // Configurar headers con autenticación
        $headers = [
            'Authorization: Bearer ' . $this->apiSecret,
            'Content-Type: application/json',
            'Accept: application/json'
        ];
        
        // Configurar cURL
        $ch = curl_init();
        
        if (!empty($params)) {
            if (strpos($endpoint, '?') === false) {
                $url .= '?' . http_build_query($params);
            } else {
                $url .= '&' . http_build_query($params);
            }
        }
        
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        // Ejecutar petición
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            throw new Exception('Error de conexión: ' . $error);
        }
        
        if ($httpCode !== 200) {
            $errorData = json_decode($response, true);
            $errorMessage = isset($errorData['error']) ? $errorData['error'] : 'Error HTTP ' . $httpCode;
            throw new Exception('Error de API: ' . $errorMessage);
        }
        
        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Error al decodificar respuesta JSON');
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
            switch ($timeRange) {
                case 'today':
                    $startDate = $endDate;
                    break;
                case 'week':
                    $startDate = date('Y-m-d', strtotime('-7 days'));
                    break;
                case 'month':
                    $startDate = date('Y-m-d', strtotime('-30 days'));
                    break;
                default:
                    $startDate = $endDate;
            }
            
            // Obtener estadísticas
            $params = [
                'start_date' => $startDate,
                'end_date' => $endDate,
                'group_by' => 'country'
            ];
            
            // Endpoint para estadísticas (ajustar según documentación de Trafficstars)
            $stats = $this->makeRequest('/statistics', $params);
            
            // Procesar datos
            return $this->processStats($stats, $timeRange);
            
        } catch (Exception $e) {
            // Si hay error, devolver datos de demo
            return $this->getDemoStats($timeRange);
        }
    }
    
    /**
     * Procesar estadísticas de la API
     */
    private function processStats($apiData, $timeRange) {
        $totalVisits = 0;
        $totalEarnings = 0;
        $countryStats = [];
        
        // Procesar datos de la API (ajustar según estructura real)
        if (isset($apiData['data']) && is_array($apiData['data'])) {
            foreach ($apiData['data'] as $stat) {
                $visits = isset($stat['impressions']) ? intval($stat['impressions']) : 0;
                $earnings = isset($stat['revenue']) ? floatval($stat['revenue']) : 0;
                $country = isset($stat['country']) ? $stat['country'] : 'Unknown';
                
                $totalVisits += $visits;
                $totalEarnings += $earnings;
                
                if (!isset($countryStats[$country])) {
                    $countryStats[$country] = [
                        'name' => $this->getCountryName($country),
                        'code' => $country,
                        'flag' => $this->getCountryFlag($country),
                        'visits' => 0,
                        'earnings' => 0,
                        'cpm' => 0,
                        'percentage' => 0
                    ];
                }
                
                $countryStats[$country]['visits'] += $visits;
                $countryStats[$country]['earnings'] += $earnings;
            }
        }
        
        // Calcular CPM y porcentajes
        $avgCPM = $totalVisits > 0 ? ($totalEarnings / $totalVisits) * 1000 : 0;
        
        foreach ($countryStats as &$country) {
            $country['cpm'] = $country['visits'] > 0 ? ($country['earnings'] / $country['visits']) * 1000 : 0;
            $country['percentage'] = $totalEarnings > 0 ? round(($country['earnings'] / $totalEarnings) * 100, 2) : 0;
        }
        
        // Ordenar por ganancias
        usort($countryStats, function($a, $b) {
            return $b['earnings'] - $a['earnings'];
        });
        
        // Limitar a top 10 países
        $countryStats = array_slice($countryStats, 0, 10);
        
        return [
            'totalVisits' => $totalVisits,
            'totalEarnings' => round($totalEarnings, 2),
            'avgCPM' => round($avgCPM, 2),
            'activeCountries' => count($countryStats),
            'visitsChange' => $this->calculateChange($timeRange, 'visits'),
            'earningsChange' => $this->calculateChange($timeRange, 'earnings'),
            'cpmChange' => $this->calculateChange($timeRange, 'cpm'),
            'countriesChange' => $this->calculateChange($timeRange, 'countries'),
            'countryStats' => array_values($countryStats)
        ];
    }
    
    /**
     * Obtener datos de demostración
     */
    private function getDemoStats($timeRange) {
        // Datos de demo para pruebas
        $multiplier = 1;
        switch ($timeRange) {
            case 'week':
                $multiplier = 7;
                break;
            case 'month':
                $multiplier = 30;
                break;
        }
        
        $baseVisits = rand(1000, 5000);
        $baseEarnings = rand(50, 200);
        
        $countries = [
            ['code' => 'US', 'name' => 'Estados Unidos', 'flag' => '🇺🇸', 'multiplier' => 1.5],
            ['code' => 'UK', 'name' => 'Reino Unido', 'flag' => '🇬🇧', 'multiplier' => 1.2],
            ['code' => 'DE', 'name' => 'Alemania', 'flag' => '🇩🇪', 'multiplier' => 1.1],
            ['code' => 'FR', 'name' => 'Francia', 'flag' => '🇫🇷', 'multiplier' => 1.0],
            ['code' => 'ES', 'name' => 'España', 'flag' => '🇪🇸', 'multiplier' => 0.9],
            ['code' => 'IT', 'name' => 'Italia', 'flag' => '🇮🇹', 'multiplier' => 0.85],
            ['code' => 'CA', 'name' => 'Canadá', 'flag' => '🇨🇦', 'multiplier' => 1.3],
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
            return $b['earnings'] - $a['earnings'];
        });
        
        return [
            'totalVisits' => $totalVisits,
            'totalEarnings' => round($totalEarnings, 2),
            'avgCPM' => round(($totalEarnings / $totalVisits) * 1000, 2),
            'activeCountries' => count($countryStats),
            'visitsChange' => rand(-10, 30),
            'earningsChange' => rand(-5, 25),
            'cpmChange' => rand(-3, 15),
            'countriesChange' => rand(0, 3),
            'countryStats' => $countryStats
        ];
    }
    
    /**
     * Calcular cambio porcentual (placeholder)
     */
    private function calculateChange($timeRange, $metric) {
        // En producción, comparar con período anterior
        return rand(-10, 30);
    }
    
    /**
     * Obtener nombre del país
     */
    private function getCountryName($code) {
        $countries = [
            'US' => 'Estados Unidos',
            'UK' => 'Reino Unido',
            'GB' => 'Reino Unido',
            'DE' => 'Alemania',
            'FR' => 'Francia',
            'ES' => 'España',
            'IT' => 'Italia',
            'CA' => 'Canadá',
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
            'US' => '🇺🇸', 'UK' => '🇬🇧', 'GB' => '🇬🇧', 'DE' => '🇩🇪',
            'FR' => '🇫🇷', 'ES' => '🇪🇸', 'IT' => '🇮🇹', 'CA' => '🇨🇦',
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
                
                $clientId = $input['clientId'];
                $apiSecret = $input['apiSecret'];
                $timeRange = isset($input['timeRange']) ? $input['timeRange'] : 'today';
                
                // Crear instancia de API
                $api = new TrafficstarsAPI($clientId, $apiSecret);
                
                // Obtener estadísticas
                $stats = $api->getStats($timeRange);
                
                echo json_encode($stats);
                break;
                
            default:
                throw new Exception('Acción no válida');
        }
        
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode([
            'error' => $e->getMessage()
        ]);
    }
}

// Procesar la petición
processRequest();
?>