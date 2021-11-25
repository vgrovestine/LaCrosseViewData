<?php
require_once('LaCrosseViewData.php');

$my_email = 'xxx@yyy.zzz';
$my_password = 'xyz123';
$my_sensors = '012345, 6789AB, CDEF012';

$lcv = new LaCrosseViewData();
$lcv->ssl_verify_host = 0;
$lcv->ssl_verify_peer = 0;
if($lcv->authenticate($my_email, $my_password, __DIR__ . '/auth_token.txt')) { 
  $lcv->setTimezone('America/Halifax');
  $lcv->setDateTimeFormat('M j/y g:ia T', 'M j', 'g:ia');
  $lcv->setFields('BarometricPressure, Temperature, Humidity, WindSpeed');
  $lcv->setSensors($my_sensors);
  $lcv->retrieveSensorData(3);
  $lcv->aggregateSensorData();
  echo '<p>';
  echo $lcv->prepareObservation(
    'OBS {datetime} — ' . 
    'Temperature: {6789AB.Temperature.recent}{6789AB.Temperature.recent.trend}. ' . 
    'Humidity: {6789AB.Humidity.recent}. ' . 
    'Pressure: {012345.BarometricPressure.recent}{012345.BarometricPressure.recent.trend}. ' . 
    'Wind: {CDEF012.WindSpeed.recent}, g.{CDEF012.WindSpeed.max}.'
  );
  echo '</p>';
  echo '<p>';
  echo $lcv->prepareObservation(
    'OBS 3-hr preceding {datetime} — ' . 
    'High: {6789AB.Temperature.max} ({6789AB.Temperature.max.time}). ' . 
    'Low: {6789AB.Temperature.min} ({6789AB.Temperature.min.time}). ' . 
    'Avg/Median: {6789AB.Temperature.avg}/{6789AB.Temperature.median}. ' . 
    'Max Pressure: {012345.BarometricPressure.max} ({012345.BarometricPressure.max.time}). ' . 
    'Min Pressure: {012345.BarometricPressure.min} ({012345.BarometricPressure.min.time}). ' . 
    'Avg/Median: {012345.BarometricPressure.avg}/{012345.BarometricPressure.median}.'
  );
  echo '</p>';
}
?>