<?php

set_time_limit(1200);

function Speed($user, $pasw)
{
    include __DIR__."/config.php";

    // Obtener el hash del usuario
    $consulta = "SELECT hash FROM masgps.hash WHERE user='$user' AND pasw='$pasw'";
    $resultado = mysqli_query($mysqli, $consulta);

    if (!$resultado) {
        die("Error en la consulta SQL: " . mysqli_error($mysqli));
    }

    $data = mysqli_fetch_assoc($resultado);
    echo
    $hash = $data['hash'] ?? null;

    if (!$hash) {
        die("Hash no encontrado para el usuario especificado.");
    }

    date_default_timezone_set("America/Santiago");
    $ayer = date('Y-m-d', strtotime("-1 days"));
    include __DIR__."/listadoSpeedTandem.php"; // Cargar $ids desde este archivo

    // Función auxiliar para ejecutar cURL
    function ejecutarCurl($url, $postData, $headers)
    {
        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postData,
            CURLOPT_HTTPHEADER => $headers
        ]);
        $response = curl_exec($curl);
        curl_close($curl);
        return json_decode($response);
    }

    $headers = [
        'Accept: */*',
        'Accept-Language: es-419,es;q=0.9,en;q=0.8',
        'Connection: keep-alive',
        'Content-Type: application/x-www-form-urlencoded; charset=UTF-8',
        'Cookie: _ga=GA1.2.728367267.1665672802; session_key=313bcf73d4cab8b8934bae1556b273e2; _gid=GA1.2.1549217858.1690386194; locale=es; check_audit=313bcf73d4cab8b8934bae1556b273e2; _ga_XXFQ02HEZ2=GS1.2.1690401308.12.1.1690401317.0.0.0',
        'Origin: http://www.trackermasgps.com',
        'Referer: http://www.trackermasgps.com/pro/applications/reports/index.html?newuiwrap=1',
        'User-Agent: Mozilla/5.0'
    ];

    // Generar el informe
    $postData = http_build_query([
        'hash' => $hash,
        'title' => 'Informe de violación de velocidad',
        'trackers' => $ids,
        'from' => "{$ayer} 00:00:00",
        'to' => "{$ayer} 23:59:59",
        'time_filter' => json_encode(['from' => '00:00', 'to' => '23:59', 'weekdays' => [1, 2, 3, 4, 5, 6, 7]]),
        'plugin' => json_encode(['hide_empty_tabs' => true, 'plugin_id' => 27, 'show_seconds' => true, 'min_duration_minutes' => 1, 'max_speed' => 80, 'group_by_driver' => false, 'filter' => true])
    ]);

    $arreglo = ejecutarCurl('http://www.trackermasgps.com/api-v2/report/tracker/generate', $postData, $headers);
    $reporte = $arreglo->id ?? null;

    if (!$reporte) {
        die("No se pudo generar el reporte.");
    }

    // Loop para verificar si el reporte está listo
    do {
        sleep(10);
        $postData = http_build_query(['hash' => $hash, 'report_id' => $reporte]);
        $datos = ejecutarCurl('http://www.trackermasgps.com/api-v2/report/tracker/retrieve', $postData, $headers);

        if (isset($datos->report->sheets)) {
            foreach ($datos->report->sheets as $tracker) {
                $pat = $tracker->header;
                $id_tracker = $tracker->entity_ids[0];
                $eventos = $tracker->sections[1]->data[0]->rows;

                foreach ($eventos as $evento) {
                    $direccion = $evento->max_speed_address->v;
                    $start_time = $evento->start_time->v;

                    if (str_contains($direccion, 'Antofagasta Ruta B-400,RUTA B400')) {
                        $duration = $evento->duration->v;
                        $max_speed = $evento->max_speed->v;
                        $lat = $evento->max_speed_address->location->lat;
                        $lng = $evento->max_speed_address->location->lng;
                        
                        $qry = "INSERT INTO `masgps`.`tandemOver80km` 
                            (`contrato`, `id_tracker`, `patente`, `fecha`, `start_time`, `duration`, `max_speed`, `lat`, `lng`, `direccion`) 
                            VALUES ('$user', '$id_tracker', '$pat', '$ayer', '$start_time', '$duration', '$max_speed', '$lat', '$lng', '$direccion')";
                            echo $direccion;

                         if (!mysqli_query($mysqli, $qry)) {
                             echo "Error al insertar datos: " . mysqli_error($mysqli);
                         }
                    } else {
                         "$ayer : $start_time Sin datos de velocidad mayor a 80km con duración de más de 1 minuto en GeoZonas para la patente : $pat - $direccion <br>";
                    }
                }
            }
            break;
        }
    } while (!isset($datos->report->sheets));

    mysqli_close($mysqli); // Cerrar la conexión
}
?>
