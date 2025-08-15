# GPS Trip Processor

A PHP script that processes shuffled GPS points, cleans invalid data, creates trips based on time gaps and distance jumps, and outputs GeoJSON visualization data.

## Files

- `script.php` - Main processing script
- `README.md` - This documentation

## Requirements

- PHP 8.0 or higher
- Input CSV file with columns: `device_id`, `lat`, `lon`, `timestamp`

## Usage

```bash
php script.php points.csv
```

## Input Format

CSV file with headers:
- `device_id` - String identifier for the GPS device
- `lat` - Latitude in decimal degrees (-90 to 90)
- `lon` - Longitude in decimal degrees (-180 to 180)  
- `timestamp` - ISO 8601 timestamp (e.g., 2025-05-13T05:15:30)

## Processing Logic

1. **Data Cleaning**: Removes rows with invalid coordinates or malformed timestamps
2. **Sorting**: Orders all valid points by timestamp
3. **Trip Creation**: Splits points into separate trips when:
   - Time gap > 25 minutes between consecutive points, OR
   - Straight-line distance > 2 km between consecutive points (using haversine formula)
4. **Statistics**: Calculates for each trip:
   - Total distance (km)
   - Duration (minutes)
   - Average speed (km/h)
   - Maximum speed (km/h)

## Output Files

- `trips.geojson` - GeoJSON FeatureCollection with each trip as a colored LineString
- `rejects.log` - Log of rejected rows with reasons (created only if there are rejections)

## GeoJSON Features

Each trip is represented as a LineString feature with properties:
- `trip_name` - Sequential trip identifier (trip_1, trip_2, etc.)
- `total_distance_km` - Total distance of the trip
- `duration_min` - Trip duration in minutes
- `avg_speed_kmh` - Average speed
- `max_speed_kmh` - Maximum speed between any two consecutive points
- `stroke` - Color for visualization (#FF0000, #00FF00, #0000FF, etc.)
- `stroke-width` - Line width (3px)
- `point_count` - Number of GPS points in the trip

## Example Output

For your `points.csv` file, the script generated:
- **Trip 1**: 205.71 km, 24.71 km/h avg speed (Red)
- **Trip 2**: 93.31 km, 11.21 km/h avg speed (Green) 
- **Trip 3**: 79.45 km, 9.54 km/h avg speed (Blue)

## Performance

Designed to process typical GPS datasets in under 1 minute on a standard laptop using only PHP standard library functions.

## Error Handling

Invalid data is logged to `rejects.log` with specific reasons:
- Missing required fields
- Invalid coordinates (999, 999 or outside valid lat/lon ranges)
- Malformed timestamps ("not-a-timestamp" or unparseable formats)

## Visualization

The generated GeoJSON can be viewed in:
- QGIS (free desktop GIS software)
- Mapbox Studio
- Google My Maps
- Any web mapping library (Leaflet, OpenLayers)

## Implementation Notes

- Uses haversine formula for accurate distance calculations
- Handles various timestamp formats automatically  
- Memory-efficient processing for large datasets
- Comprehensive input validation and error reporting
