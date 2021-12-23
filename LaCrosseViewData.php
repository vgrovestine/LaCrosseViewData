<?php
class LaCrosseViewData {
  public $ssl_verify_host = 1;
  public $ssl_verify_peer = 1;
  private $ts_now = 0;
  private $timezone = 'UTC';
  private $format_datetime = 'Y-m-d H:i T';
  private $format_date = 'Y-m-d';
  private $format_time = 'H:i';
  private $auth_token = '';
  private $sensors = array();
  private $fields = array();
  private $hr_obs_duration = 1;
  private $raw_data = array();
  private $agg_data = array();


  function __construct() {
    $this->ts_now = time();
  }


  public function authenticate($email, $password, $auth_token_cache_file = false, $auth_token_cache_timeout = 24 * 60 * 60) {
    if(!empty($auth_token_cache_file) && file_exists($auth_token_cache_file) && 
      filemtime($auth_token_cache_file) < ($this->ts_now - $auth_token_cache_timeout)) {
      $this->auth_token = file_get_contents($auth_token_cache_file);
      if(!empty($this->auth_token)) {
        return true;
      }
    }
    $url = 'https://www.googleapis.com/identitytoolkit/v3/relyingparty/verifyPassword'  
      . '?key=AIzaSyD-Uo0hkRIeDYJhyyIg-TvAv8HhExARIO4';
    if(empty($email)) {
      die('Bailing out of ' . __METHOD__ . ':  Email is required.');
    }
    if(empty($password)) {
      die('Bailing out of ' . __METHOD__ . ':  Password is required.');
    }
    $curl_response = json_decode($this->curlPost($url, 'email=' . $email . '&password=' . $password . '&returnSecureToken=true'));
    $this->auth_token = $curl_response->idToken;
    if(!empty($this->auth_token)) {
      file_put_contents($auth_token_cache_file, $this->auth_token);
    }
    return true;
  }


  public function setTimezone($timezone) {
    $this->timezone = $timezone;
  }


  public function setDateTimeFormat($format_datetime = false, $format_date = false, $format_time = false) {
    if(!empty($format_datetime)) {
      $this->format_datetime = $format_datetime;
    }
    if(!empty($format_date)) {
      $this->format_date = $format_date;
    }
    if(!empty($format_time)) {
      $this->format_time = $format_time;
    }
  }


  public function setDuration($hours_duration) {
    if(!is_numeric($hours_duration) || $hours_duration < 0.1 || $hours_duration > 24) {
      die('Bailing out of ' . __METHOD__ . ':  Obs duration must be a number between 0.1 (6 minutes) and 24 hours.');
      return false;
    }
    $this->hr_obs_duration = $hours_duration;
    return true;
  }


  public function setSensors($sensors) {
    if(!is_array(($sensors))) {
      $sensors = explode(',', $sensors);
    }
    $this->sensors = $sensors;
    for($k = 0; $k < count($this->sensors); $k++) {
      $this->sensors[$k] = strtoupper(trim($this->sensors[$k]));
    }
    if(empty($this->sensors)) {
      die('Bailing out of ' . __METHOD__ . ':  One or more sensor serial numbers are required.');
      return false;
    }
    return true;
  }


  public function setFields($fields) {
    if(!is_array(($fields))) {
      $fields = explode(',', $fields);
    }
    $this->fields = $fields;
    for($k = 0; $k < count($this->fields); $k++) {
      $this->fields[$k] = trim($this->fields[$k]);
    }
    if(empty($this->fields)) {
      return false;
    }
    return true;
  }


  public function getTimestamp() {
    return $this->ts_now;
  }


  public function getRawData() {
    return $this->raw_data;
  }


  public function getAggregateData() {
    return $this->agg_data;
  }


  public function isAuthenticated() {
    if(empty($this->auth_token)) {
      return false;
    }
    return true;
  }


  private function retrieveLocations() {
    $url = 'https://lax-gateway.appspot.com/_ah/api/lacrosseClient/v1.1/active-user/locations';
    return json_decode($this->curlGet($url, $this->auth_token), true);
  }


  private function retrieveSensors($location_id) {
    $url = 'https://lax-gateway.appspot.com/_ah/api/lacrosseClient/v1.1/active-user/location/'
      . $location_id . '/sensorAssociations?prettyPrint=false';
    return json_decode($this->curlGet($url, $this->auth_token), true);
  }


  private function retrieveSensorFeed($sensor_id) {
    $url = 'https://ingv2.lacrossetechnology.com/api/v1.1/active-user/device-association/ref.user-device.'
      . $sensor_id . '/feed?aggregates=ai.ticks.1&types=spot'
      . (empty($this->fields) ? : '&fields=' . implode(',', $this->fields))
      . '&tz=' . $this->timezone
      . '&from=' . ($this->ts_now - ($this->hr_obs_duration * 60 * 60))
      . '&to=' . $this->ts_now;
    return json_decode($this->curlGet($url, $this->auth_token), true);
  }


  public function retrieveSensorData($hr_obs_duration = 1) {
    $this->setDuration($hr_obs_duration);
    date_default_timezone_set($this->timezone);
    $x = array();
    $locations = $this->retrieveLocations();
    foreach($locations['items'] as $l) {
      $sensors = $this->retrieveSensors($l['id']);
      $locations['items']['sensors'] = $sensors;
      foreach($sensors['items'] as $s) {
        foreach($this->sensors as $s_id) {
          if(strcasecmp($s['sensor']['serial'], $s_id) == 0) {
            $data = $this->retrieveSensorFeed($s['id']);
            $x[] = array(
              'location_name' => $l['name'],
              'location_id' => $l['id'],
              'sensor_name' => $s['sensor']['type']['name'],
              'sensor_description' => $s['sensor']['type']['description'],
              'sensor_serial' => $s['sensor']['serial'],
              'sensor_id' => $s['sensor']['id'], 
              'fields' => $data['ref.user-device.' . $s['id']]['ai.ticks.1']['fields']
            );
          }
        }
      }
    }
    $this->raw_data = $locations;
    $this->agg_data = $x;
    return $x;
  }


  public function aggregateSensorData() {
    $tmp_data = $this->agg_data;
    $x = array();
    for($k = 0; $k < count($tmp_data); $k++) {
      foreach($tmp_data[$k] as $dataKey => $dataValue) {
        if(strcasecmp($dataKey, 'fields') === 0) {
          $tmp_series = array();
          foreach($dataValue as $fieldKey => $fieldValue) {
            $tmp_series[$fieldKey] = array(
              'unit' => $fieldValue['unit'], 
              'unit_abbrev' => $this->abbreviateUnit(($fieldValue['unit'])),
              'source' => array()
            );
            for($j = 0; $j < count($fieldValue['values']); $j++) {
              $tmp_measurement = array(
                'ts' => $fieldValue['values'][$j]['u'], 
                'measurement' => $fieldValue['values'][$j]['s']
              );
              $tmp_series[$fieldKey]['source'][] = $tmp_measurement;
            }
          }
          $x[$tmp_data[$k]['sensor_serial']]['fields'] = $tmp_series;
        }
        else {
          $x[$tmp_data[$k]['sensor_serial']][$dataKey] = $dataValue;
        }
      }
    }
    foreach($x as $xKey => $xValue) {
      foreach($xValue['fields'] as $fieldKey => $fieldValue) {
        $min = $max = array('ts' => false, 'measurement' => false, 'datetime' => false);
        $avg = false;
        $median = array();
        foreach($fieldValue['source'] as $fv) {
          if(empty($median)) {
            $min['ts'] = $max['ts'] = $fv['ts'];
            $min['datetime'] = $max['datetime'] = date($this->format_datetime, $fv['ts']);
            $min['date'] = $max['date'] = date($this->format_date, $fv['ts']);
            $min['time'] = $max['time'] = date($this->format_time, $fv['ts']);
            $min['measurement'] = $max['measurement'] = $fv['measurement'];
          }
          else {
            if($min['measurement'] > $fv['measurement']) {
              $min['ts'] = $fv['ts'];
              $min['measurement'] = $fv['measurement'];
              $min['datetime'] = date($this->format_datetime, $fv['ts']);
              $min['date'] = date($this->format_date, $fv['ts']);
              $min['time'] = date($this->format_time, $fv['ts']);
            }
            if($max['measurement'] < $fv['measurement']) {
              $max['ts'] = $fv['ts'];
              $max['measurement'] = $fv['measurement'];
              $max['datetime'] = date($this->format_datetime, $fv['ts']);
              $max['date'] = date($this->format_date, $fv['ts']);
              $max['time'] = date($this->format_time, $fv['ts']);
            }
          }
          $median[] = $fv['measurement'];
        }
        $avg = round(array_sum($median) / count($median), 1);
        sort($median);
        $median = $median[ceil(count($median) / 2) - 1];
        $x[$xKey]['fields'][$fieldKey]['min'] = $min;
        $x[$xKey]['fields'][$fieldKey]['max'] = $max;
        $x[$xKey]['fields'][$fieldKey]['avg'] = array('measurement' => $avg);
        $x[$xKey]['fields'][$fieldKey]['median'] = array('measurement' => $median);
        $x[$xKey]['fields'][$fieldKey]['recent'] = $fieldValue['source'][count($fieldValue['source'])-1];
        $x[$xKey]['fields'][$fieldKey]['recent']['datetime'] = date($this->format_datetime, $x[$xKey]['fields'][$fieldKey]['recent']['ts']);
        $x[$xKey]['fields'][$fieldKey]['recent']['trend'] = $this->abbreviateUnit('trend_steady');
        if($x[$xKey]['fields'][$fieldKey]['min']['ts'] > $x[$xKey]['fields'][$fieldKey]['max']['ts']) {
          if($x[$xKey]['fields'][$fieldKey]['recent']['measurement'] > $x[$xKey]['fields'][$fieldKey]['min']['measurement']) {
            $x[$xKey]['fields'][$fieldKey]['recent']['trend'] = $this->abbreviateUnit('trend_positive');
          }
        }
        else if($x[$xKey]['fields'][$fieldKey]['min']['ts'] < $x[$xKey]['fields'][$fieldKey]['max']['ts']) {
          if($x[$xKey]['fields'][$fieldKey]['recent']['measurement'] < $x[$xKey]['fields'][$fieldKey]['max']['measurement']) {
            $x[$xKey]['fields'][$fieldKey]['recent']['trend'] = $this->abbreviateUnit('trend_negative');
          }
        }
      }
    }
    $this->agg_data = $x;
    return $x;
  }


  private function abbreviateUnit($unit) {
    switch($unit) {
      case 'degrees_celsius':
        return '°C';
        break;
      case 'degrees_farenheit':
        return '°F';
        break;
      case 'kilometers_per_hour':
        return 'kph';
        break;
      case 'meters_per_second':
        return 'mps';
        break;
      case 'miles_per_hour':
        return 'mph';
        break;
      case 'millibars':
        return 'mb';
        break;
      case 'relative_humidity':
        return '%';
        break;
      case 'trend_negative':
        return '↓';
        break;
      case 'trend_positive':
        return '↑';
        break;
      case 'trend_steady':
        return ''; //'↔';
        break;
    }
    return $unit;
  }


  public function prepareObservation($text) {
    return preg_replace_callback('/\{([0-9a-z\.]+)\}/i', 'self::replaceObservationDataCallback', $text);
  }


  public function replaceObservationDataCallback($matches) {
    $obs_text = null;
    $x = explode('.', $matches[1]);
    if(count($x) == 1) {
      switch($x[0]) {
        case 'datetime':
          $obs_text = date($this->format_datetime, $this->ts_now);
          break;
        case 'date':
          $obs_text = date($this->format_date, $this->ts_now);
          break;
        case 'time':
          $obs_text = date($this->format_time, $this->ts_now);
          break;
      }
    }
    else if(count($x) == 3) {
      $x[] = 'measurement';
    }
    if(count($x) == 4) {
      $x = array_combine(array('sensor', 'field', 'aggregate', 'value'), $x);
      $obs_text = $this->agg_data[strtoupper($x['sensor'])]['fields'][$x['field']][$x['aggregate']][$x['value']];
      if($x['value'] == 'measurement') {
        $obs_text .= $this->agg_data[strtoupper($x['sensor'])]['fields'][$x['field']]['unit_abbrev'];
      }
    }
    return (is_null($obs_text) ? $matches[0] : $obs_text);
  }


  public function sendObservationToEmail($sender, $recipient, $subject, $message) {
    $headers = array(
      'From: ' .  $sender,
      'Reply-To: ' . $sender,
      'X-Mailer: PHP/' . phpversion()
      );
    return mail(
      $recipient, 
      $this->prepareObservation($subject), 
      wordwrap($this->prepareObservation($message), 70, "\r\n", false), 
      implode("\r\n", $headers)
      );
  }


  // TO DO: Weather Underground posting
  // public function sendObservationToWeatherUnderground($station_id, $station_key, $api_key, $json_obs) {}


  // TO DO: Twitter posting
  // public function sendObservationToTwitter($oauth, $tweet) {}


  private function curlGet($url, $bearer_token = false) {
    $handler = curl_init($url);
    curl_setopt($handler, CURLOPT_VERBOSE, false);
    curl_setopt($handler, CURLOPT_SSL_VERIFYHOST, $this->ssl_verify_host);
    curl_setopt($handler, CURLOPT_SSL_VERIFYPEER, $this->ssl_verify_peer);
    curl_setopt($handler, CURLOPT_RETURNTRANSFER, true);
    if(!empty($bearer_token)) {
      //curl_setopt($handler, CURLOPT_HTTPHEADER, array('Authorization: Bearer ' . $this->auth_token));
      curl_setopt($handler, CURLOPT_HTTPAUTH, CURLAUTH_BEARER);
      curl_setopt($handler, CURLOPT_XOAUTH2_BEARER, $bearer_token);	
    }
    $response = curl_exec($handler);
    if(curl_error($handler)) {
      $response = false;
    }
    if($response === false || !empty(curl_error($handler))) {
      die('Bailing out of ' . __METHOD__ . ':  ' . curl_error($handler));
      return false;
    }
    curl_close($handler);
    return $response;
  }


  private function curlPost($url, $post_fields, $bearer_token = false) {
    $handler = curl_init($url);
    curl_setopt($handler, CURLOPT_VERBOSE, false);
    curl_setopt($handler, CURLOPT_SSL_VERIFYHOST, $this->ssl_verify_host);
    curl_setopt($handler, CURLOPT_SSL_VERIFYPEER, $this->ssl_verify_peer);
    curl_setopt($handler, CURLOPT_RETURNTRANSFER, true);
    if(!empty($bearer_token)) {
      //curl_setopt($handler, CURLOPT_HTTPHEADER, array('Authorization: Bearer ' . $this->auth_token));
      curl_setopt($handler, CURLOPT_HTTPAUTH, CURLAUTH_BEARER);
      curl_setopt($handler, CURLOPT_XOAUTH2_BEARER, $bearer_token);	
    }
    curl_setopt($handler, CURLOPT_POST, true);
    curl_setopt($handler, CURLOPT_POSTFIELDS, $post_fields);
    $response = curl_exec($handler);
    if(curl_error($handler)) {
      $response = false;
    }
    if($response === false || !empty(curl_error($handler))) {
      die('Bailing out of ' . __METHOD__ . ':  ' . curl_error($handler));
      return false;
    }
    curl_close($handler);
    return $response;  
  }


  public function dump($x, $exit = false, $pre = false) {
    echo ($pre ? '<pre>' : '') . print_r($x, true) . ($pre ? '</pre>' : '') . "\n";
    if($exit) {
      exit();
    }
  }

}
?>