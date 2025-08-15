<?php
/**
 * GPS Point Processor
 * Processes shuffled GPS points, cleans data, creates trips, and outputs GeoJSON
 */

class GPSProcessor {
    private $rejectsLog = [];
    private $trips = [];
    private $colors = [
        '#FF0000', '#00FF00', '#0000FF', '#FFFF00', '#FF00FF', 
        '#00FFFF', '#FFA500', '#800080', '#008000', '#FFC0CB'
    ];

    /**
     * Calculate haversine distance between two GPS points
     */
    private function haversineDistance($lat1, $lon1, $lat2, $lon2) {
        $earthRadius = 6371; // km
        
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        
        $a = sin($dLat/2) * sin($dLat/2) + 
             cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * 
             sin($dLon/2) * sin($dLon/2);
        
        $c = 2 * atan2(sqrt($a), sqrt(1-$a));
        
        return $earthRadius * $c;
    }

    /**
     * Validate GPS coordinates
     */
    private function isValidCoordinate($lat, $lon) {
        return is_numeric($lat) && is_numeric($lon) && 
               $lat >= -90 && $lat <= 90 && 
               $lon >= -180 && $lon <= 180;
    }

    /**
     * Validate timestamp
     */
    private function isValidTimestamp($timestamp) {
        return $this->parseTimestamp($timestamp) !== false;
    }

    /**
     * Parse timestamp to DateTime object
     */
    private function parseTimestamp($timestamp) {
        $formats = [
            'c',                    // ISO 8601 with timezone
            'Y-m-d\TH:i:s\Z',      // UTC format
            'Y-m-d\TH:i:s',        // Your format: 2025-05-13T05:15:30
            'Y-m-d H:i:s',         // Standard format
            'Y-m-d\TH:i:s.u\Z',    // With microseconds
            'Y-m-d\TH:i:s.u'       // With microseconds, no Z
        ];
        
        foreach ($formats as $format) {
            $dt = DateTime::createFromFormat($format, $timestamp);
            if ($dt !== false) {
                return $dt;
            }
        }
        
        return false;
    }

    /**
     * Clean and validate GPS data
     */
    private function cleanData($data) {
        $cleaned = [];
        $lineNum = 1;

        foreach ($data as $row) {
            $lineNum++;
            
            // Skip empty rows
            if (empty($row) || !isset($row['device_id'], $row['lat'], $row['lon'], $row['timestamp'])) {
                $this->rejectsLog[] = "Line $lineNum: Missing required fields";
                continue;
            }

            $deviceId = trim($row['device_id']);
            $lat = floatval($row['lat']);
            $lon = floatval($row['lon']);
            $timestamp = trim($row['timestamp']);

            // Validate coordinates
            if (!$this->isValidCoordinate($lat, $lon)) {
                $this->rejectsLog[] = "Line $lineNum: Invalid coordinates ($lat, $lon)";
                continue;
            }

            // Validate timestamp
            if (!$this->isValidTimestamp($timestamp)) {
                $this->rejectsLog[] = "Line $lineNum: Invalid timestamp ($timestamp)";
                continue;
            }

            $dt = $this->parseTimestamp($timestamp);
            if (!$dt) {
                $this->rejectsLog[] = "Line $lineNum: Could not parse timestamp ($timestamp)";
                continue;
            }

            $cleaned[] = [
                'device_id' => $deviceId,
                'lat' => $lat,
                'lon' => $lon,
                'timestamp' => $timestamp,
                'datetime' => $dt
            ];
        }

        return $cleaned;
    }

    /**
     * Sort points by timestamp
     */
    private function sortByTimestamp($data) {
        usort($data, function($a, $b) {
            return $a['datetime'] <=> $b['datetime'];
        });
        return $data;
    }

    /**
     * Split data into trips based on time gaps and distance jumps
     */
    private function createTrips($data) {
        if (empty($data)) return [];

        $trips = [];
        $currentTrip = [$data[0]];
        $tripNumber = 1;

        for ($i = 1; $i < count($data); $i++) {
            $prev = $data[$i-1];
            $curr = $data[$i];

            // Calculate time gap in minutes
            $timeGap = ($curr['datetime']->getTimestamp() - $prev['datetime']->getTimestamp()) / 60;

            // Calculate distance in km
            $distance = $this->haversineDistance(
                $prev['lat'], $prev['lon'],
                $curr['lat'], $curr['lon']
            );

            // Check if we need to start a new trip
            if ($timeGap > 25 || $distance > 2) {
                // Save current trip
                $trips["trip_$tripNumber"] = $currentTrip;
                $tripNumber++;
                
                // Start new trip
                $currentTrip = [$curr];
            } else {
                // Add to current trip
                $currentTrip[] = $curr;
            }
        }

        // Don't forget the last trip
        if (!empty($currentTrip)) {
            $trips["trip_$tripNumber"] = $currentTrip;
        }

        return $trips;
    }

    /**
     * Calculate trip statistics
     */
    private function calculateTripStats($trip) {
        if (count($trip) < 2) {
            return [
                'total_distance' => 0,
                'duration' => 0,
                'avg_speed' => 0,
                'max_speed' => 0
            ];
        }

        $totalDistance = 0;
        $maxSpeed = 0;
        $speeds = [];

        for ($i = 1; $i < count($trip); $i++) {
            $prev = $trip[$i-1];
            $curr = $trip[$i];

            // Distance between consecutive points
            $segmentDistance = $this->haversineDistance(
                $prev['lat'], $prev['lon'],
                $curr['lat'], $curr['lon']
            );
            $totalDistance += $segmentDistance;

            // Time difference in hours
            $timeDiff = ($curr['datetime']->getTimestamp() - $prev['datetime']->getTimestamp()) / 3600;

            // Calculate speed (avoid division by zero)
            if ($timeDiff > 0) {
                $speed = $segmentDistance / $timeDiff;
                $speeds[] = $speed;
                $maxSpeed = max($maxSpeed, $speed);
            }
        }

        // Duration in minutes
        $duration = ($trip[count($trip)-1]['datetime']->getTimestamp() - $trip[0]['datetime']->getTimestamp()) / 60;

        // Average speed
        $avgSpeed = !empty($speeds) ? array_sum($speeds) / count($speeds) : 0;

        return [
            'total_distance' => round($totalDistance, 2),
            'duration' => round($duration, 2),
            'avg_speed' => round($avgSpeed, 2),
            'max_speed' => round($maxSpeed, 2)
        ];
    }

    /**
     * Generate GeoJSON output
     */
    private function generateGeoJSON($trips) {
        $features = [];
        $tripIndex = 0;

        foreach ($trips as $tripName => $trip) {
            if (count($trip) < 2) continue; // Skip single-point trips

            $coordinates = [];
            foreach ($trip as $point) {
                $coordinates[] = [$point['lon'], $point['lat']]; // GeoJSON uses [lon, lat]
            }

            $stats = $this->calculateTripStats($trip);
            $color = $this->colors[$tripIndex % count($this->colors)];

            $feature = [
                'type' => 'Feature',
                'geometry' => [
                    'type' => 'LineString',
                    'coordinates' => $coordinates
                ],
                'properties' => [
                    'trip_name' => $tripName,
                    'total_distance_km' => $stats['total_distance'],
                    'duration_min' => $stats['duration'],
                    'avg_speed_kmh' => $stats['avg_speed'],
                    'max_speed_kmh' => $stats['max_speed'],
                    'stroke' => $color,
                    'stroke-width' => 3,
                    'point_count' => count($trip)
                ]
            ];

            $features[] = $feature;
            $tripIndex++;
        }

        return [
            'type' => 'FeatureCollection',
            'features' => $features
        ];
    }

    /**
     * Write rejects log
     */
    private function writeRejectsLog() {
        if (!empty($this->rejectsLog)) {
            file_put_contents('rejects.log', implode("\n", $this->rejectsLog) . "\n");
            echo "Rejected " . count($this->rejectsLog) . " invalid rows. See rejects.log for details.\n";
        }
    }

    /**
     * Process CSV file
     */
    public function processFile($filename) {
        if (!file_exists($filename)) {
            die("Error: File '$filename' not found.\n");
        }

        echo "Processing GPS data from $filename...\n";

        // Read CSV - handle potential formatting issues
        $data = [];
        $content = file_get_contents($filename);
        
        // Check if the data appears to be concatenated without proper separators
        if (strpos($content, ',') === false && strpos($content, 'device_id') !== false) {
            // Parse the concatenated format from your example
            $lines = explode("\n", trim($content));
            $header = ['device_id', 'lat', 'lon', 'timestamp'];
            
            foreach ($lines as $lineNum => $line) {
                if (empty(trim($line))) continue;
                
                // Extract data using regex for the concatenated format
                if (preg_match('/^([a-zA-Z0-9]+)([0-9.-]+)([0-9.-]+)([0-9T:-]+)$/', trim($line), $matches)) {
                    $data[] = [
                        'device_id' => $matches[1],
                        'lat' => $matches[2],
                        'lon' => $matches[3],
                        'timestamp' => $matches[4]
                    ];
                }
            }
        } else {
            // Standard CSV parsing
            if (($handle = fopen($filename, 'r')) !== false) {
                $header = fgetcsv($handle);
                
                if (!$header || !in_array('device_id', $header) || !in_array('lat', $header) || 
                    !in_array('lon', $header) || !in_array('timestamp', $header)) {
                    die("Error: CSV must have columns: device_id, lat, lon, timestamp\n");
                }

                while (($row = fgetcsv($handle)) !== false) {
                    if (count($row) === count($header)) {
                        $data[] = array_combine($header, $row);
                    }
                }
                fclose($handle);
            }
        }

        echo "Read " . count($data) . " rows from CSV.\n";

        // Clean data
        $cleaned = $this->cleanData($data);
        echo "Cleaned data: " . count($cleaned) . " valid rows.\n";

        // Sort by timestamp
        $sorted = $this->sortByTimestamp($cleaned);
        echo "Sorted by timestamp.\n";

        // Create trips
        $trips = $this->createTrips($sorted);
        echo "Created " . count($trips) . " trips.\n";

        // Generate statistics
        foreach ($trips as $tripName => $trip) {
            $stats = $this->calculateTripStats($trip);
            echo sprintf(
                "%s: %d points, %.2f km, %.2f min, avg %.2f km/h, max %.2f km/h\n",
                $tripName,
                count($trip),
                $stats['total_distance'],
                $stats['duration'],
                $stats['avg_speed'],
                $stats['max_speed']
            );
        }

        // Generate GeoJSON
        $geoJson = $this->generateGeoJSON($trips);
        
        // Write output files
        file_put_contents('trips.geojson', json_encode($geoJson, JSON_PRETTY_PRINT));
        echo "Generated trips.geojson\n";

        // Write rejects log
        $this->writeRejectsLog();

        echo "Processing complete!\n";
    }
}

// Main execution
if ($argc < 2) {
    echo "Usage: php your_script.php <csv_file>\n";
    echo "Example: php your_script.php gps_data.csv\n";
    exit(1);
}

$filename = $argv[1];
$processor = new GPSProcessor();
$processor->processFile($filename);
?>
