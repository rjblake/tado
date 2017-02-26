<?php
// Reads Tado information and Updates Domoticz accordingly. Currently there is no real error handling, so will just stop if there is a problem somewhere
// Only reads info currently and does not update/manipulate setpoint, etc. - this will follow

// Domoticz Info
$DOMOIPAddress	  = "192.168.XXX.XXX"; // Your Domoticz Server IP Address
$DOMOPort 			  = "XXXX"; // Your Domoticz Server Port
$Username 			  = "myDOMOusername"; // Your Domoticz Server username
$Password 			  = "myDOMOpassword";  // Your Domoticz Server password
$DOMOUpdate 		  = "1";  // If <> "1" will not update Domoticz
$nvalue 			    = "0"; // for Domoticz devices
$tado_tempIDX 		= "nnn"; // Your Domoticz DeviceID for the Tado Temperature/Humidity device
$tado_setpointIDX	= "nnn"; // Your Domoticz DeviceID for the Tado Setpoint device

// Tado Login info
$username 			  = "my.email@mail.com"; // Your MyTado login name (email)
$password 			  = "my.tado.password"; // Your MyTado password
$token_file			  = "/tmp/tadotoken"; // Where the TadoToken file will be written - check for Windows paths
$token_life			  = "480"; // How long before getting new TadoToken in seconds 480 default allows for default 599 expiry
$sleep_time			  = "60"; // in seconds before getting new data. 60secs is min time Tado updates if 2% change (i think)

while (true) #infinite loop until false
{	
	$new_token = token_age($token_file, $token_life);
	if ($new_token == true)
		{
		get_token($username, $password, $token_file);
		echo "Fetched new token | ";
		}
	else
		{
		echo "Used cached token | ";
		}
		
	$home_id = get_me($token_file);
	$zone_id = get_zone_id($token_file, $home_id);
	$tado_temp_humidity_setpoint = get_zone_temp($token_file, $home_id, $zone_id);
	$tado_temp_humidity = $tado_temp_humidity_setpoint['0'];
	$tado_setpoint = $tado_temp_humidity_setpoint['1'];
	echo "HomeID: $home_id - ZoneID: $zone_id - Temp&Humidity: $tado_temp_humidity - Setpoint: $tado_setpoint\n";
	$DOMO_update = update_device($tado_tempIDX, $nvalue, $tado_temp_humidity, $DOMOIPAddress, $DOMOPort, $Username, $Password, $DOMOUpdate);
	$DOMO_update = update_device($tado_setpointIDX, $nvalue, $tado_setpoint, $DOMOIPAddress, $DOMOPort, $Username, $Password, $DOMOUpdate);
	// $tado_graph_data = get_graph_data($token_file);
	sleep($sleep_time);
}

function get_token($username, $password, $token_file) // Gets a token info from Tado
{
	$ch = curl_init();
	curl_setopt($ch, CURLOPT_URL, "https://my.tado.com/oauth/token");
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
	curl_setopt($ch, CURLOPT_POSTFIELDS, "client_id=tado-webapp&grant_type=password&scope=home.user&username=$username&password=$password");
	curl_setopt($ch, CURLOPT_POST, 1);

	$headers = array();
	$headers[] = "Content-Type: application/x-www-form-urlencoded";
	curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

	// either this approach
	// $result = curl_exec($ch);
	$parsed_json = json_decode(curl_exec($ch), true);
	$token_contents = $parsed_json['access_token'];
	file_put_contents($token_file, $token_contents);

	if (curl_errno($ch))
		{
    		echo 'Error:' . curl_error($ch);
		}
	curl_close ($ch);
	return;
}

function token_age($token_file, $token_life) // Checks age of the token file vs. currnet time
{
	clearstatcache();
	$filemtime = @filemtime($token_file);
	if (!$filemtime or (time() - $filemtime >= $token_life))
		{
		$new_token = true;
		$time_diff = (time() - $filemtime);
		echo "Token Age: $time_diff | ";
		}
	else
		{
		$new_token = false;
		$time_diff = (time() - $filemtime);
		echo "Token Age: $time_diff | ";
    	}
    return $new_token;
}

function get_me($token_file) // Gets the HomeID needed for other stuff
{
	$ch = curl_init();
	curl_setopt($ch, CURLOPT_URL, "https://my.tado.com/api/v2/me");
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
	curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "GET");

	$file = file_get_contents($token_file, true);
	$headers = array();
	$headers[] = "Authorization: Bearer $file";
	curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

	$parsed_json = json_decode(curl_exec($ch), true);
	$home_id = $parsed_json['homes']['0']['id'];
	
	if (curl_errno($ch))
		{
		    echo 'Error:' . curl_error($ch);
		}
	curl_close ($ch);
	return $home_id;
}

function get_zone_id($token_file, $home_id) // Gets the ZoneID for the HEATING
{
	$ch = curl_init();
	curl_setopt($ch, CURLOPT_URL, "https://my.tado.com/api/v2/homes/$home_id/zones");
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
	curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "GET");

	$file = file_get_contents($token_file, true);
	$headers = array();
	$headers[] = "Authorization: Bearer $file";
	curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

	$parsed_json = json_decode(curl_exec($ch), true);
	$zone_id_key = findkey($parsed_json, 'type', 'HEATING');
	$zone_id = $parsed_json[$zone_id_key]['id'];
	
	if (curl_errno($ch))
		{
		    echo 'Error:' . curl_error($ch);
		}
	curl_close ($ch);
	return $zone_id;
}

function get_zone_temp($token_file, $home_id, $zone_id) // Gets Temperature, Humidity and Setpoint for the ZoneID
{
	$ch = curl_init();
	curl_setopt($ch, CURLOPT_URL, "https://my.tado.com/api/v2/homes/$home_id/zones/$zone_id/state");
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
	curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "GET");

	$file = file_get_contents($token_file, true);
	$headers = array();
	$headers[] = "Authorization: Bearer $file";
	curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

	$parsed_json = json_decode(curl_exec($ch), true);
	$setpoint = $parsed_json['setting']['temperature']['celsius'];
	$currenttemperature = $parsed_json['sensorDataPoints']['insideTemperature']['celsius'];
	$timestamp = $parsed_json['sensorDataPoints']['insideTemperature']['timestamp'];
	$humidity = $parsed_json['sensorDataPoints']['humidity']['percentage'];
	if ($humidity <= 30.00)
		{$humidity_status = 2;}
	elseif (($humidity > 30.00) && ($humidity <= 50.00))
		{$humidity_status = 1;}
	elseif (($humidity > 50.00) && ($humidity <= 60.00))
		{$humidity_status = 0;}
	elseif ($humidity > 60.00)
		{$humidity_status = 3;}	
	
	$temp_humidity_value = "$currenttemperature;$humidity;$humidity_status";	
	
	if (curl_errno($ch))
		{
		    echo 'Error:' . curl_error($ch);
		}
	curl_close ($ch);
	return array ($temp_humidity_value, $setpoint);	
}

function get_graph_data($token_file) // Gets data that can be used to plot Temperature over time
{
	$ch = curl_init();
	curl_setopt($ch, CURLOPT_URL, "https://my.tado.com/mobile/1.6/getTemperaturePlotData");
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
	curl_setopt($ch, CURLOPT_POSTFIELDS, "fromDate=2017-02-23T00:00:00.001Z&toDate=2017-02-26T14:30:00.000Z&zoneId=1");
	curl_setopt($ch, CURLOPT_POST, 1);

	$file = file_get_contents($token_file, true);
	$headers = array();
	$headers[] = "Authorization: Bearer $file";
	$headers[] = "Content-Type: application/x-www-form-urlencoded";
	curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

	// If you want raw JSON
	// $json_data = curl_exec($ch);
	// print($json_data);
	
	// If you want to parse JSON->ARRAY
	$parsed_json = json_decode(curl_exec($ch), true);
	// print_r($parsed_json);
	
	if (curl_errno($ch))
		{
		    echo 'Error:' . curl_error($ch);
		}
	curl_close ($ch);
	
	// Return the JSON
	// return $json_data;
	
	// Return the Array
	return array ($parsed_json);
}

function update_device($idx, $nvalue, $svalue, $DOMOIPAddress, $DOMOPort, $Username, $Password, $DOMOUpdate) // Updates Domoticz Devices
{
	if ($DOMOUpdate == 1)
	{	
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_URL, "http://$Username:$Password@$DOMOIPAddress:$DOMOPort/json.htm?type=command&param=udevice&idx=$idx&nvalue=$nvalue&svalue=$svalue");
		curl_exec($ch);
		curl_close($ch);
	}
}

function findkey($arraytosearch, $field, $value) // Find the array key corresponding to a value of a field
{
   	foreach($arraytosearch as $key => $arraytosearch)
   		{
      	if ( $arraytosearch[$field] === $value )
        return $key;
   		}
	return false;
}
?>
