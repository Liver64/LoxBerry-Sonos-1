<?php

require_once "loxberry_system.php";
require_once($lbphtmldir."/system/sonosAccess.php");
require_once($lbphtmldir."/Helper.php");

#global $sonoszonen, $sonoszone, $zonesonline, $zonesoffline, $folfilePlOn;

# declare general variables
$configfile		= "s4lox_config.json";
$folfilePlOn 	= "$lbpdatadir/PlayerStatus/s4lox_on_";
$Stunden 		= date("H");

echo "<PRE>";

# load config.json
if (file_exists($lbpconfigdir . "/" . $configfile))    {
	$config = json_decode(file_get_contents($lbpconfigdir . "/" . $configfile), TRUE);
} else {
	startlog();
	LOGERR("bin/SW_Update.php: The configuration file could not be loaded, the file may be disrupted. We have to abort...");
	exit;
}

# declare function variables
#$hw_update 		= $config['SYSTEM']['hw_update'];
#$hw_update_time 	= $config['SYSTEM']['hw_update_time'];
#$hw_update_power 	= $config['SYSTEM']['hw_update_power'];

# for Testing only
$hw_update 			= "true";
$hw_update_time 	= "15";		// hour
$hw_update_power 	= "true";	// if Power On is requested
$Stunden 			= "15";

# check 1st if Software Update is turned On and scheduled
if (is_enabled($hw_update) and $hw_update_time == $Stunden)    {
	
	startlog();
	LOGOK("bin/SW_Update.php: Run Updatecheck for Players");
	
	# extract Sonoszonen only
	$sonoszonen = $config['sonoszonen'];
	
	# check Zones Online Status
	checkZonesOn();
	# ++++++++++++++++++++++++++++++++
	# if Power On is turned on
	# ++++++++++++++++++++++++++++++++
	
	if (is_enabled($hw_update_power))    {
		// send power on trigger
		send("1");
		# wait 5 minutes until Zones are up
		LOGDEB("bin/SW_Update.php: Delay of 5 Minutes until All Players are Online");
		sleep(1);
		#sleep(300);
		# Prepare Zones are Online
		require_once($lbphtmldir."/bin/check_on_state.php");
		# check again Zones Online Status
		checkZonesOn();
	}
	#print_r($sonoszone);
	$count = 0;
	# get Software Update from major player(s)
	foreach($sonoszone as $zone => $ip) {
		if (is_enabled($sonoszone[$zone][6]))    {
			$sonos = new SonosAccess($sonoszone[$zone][0]);
			$update = $sonos->CheckForUpdate();
			LOGDEB("bin/SW_Update.php: Updatecheck for Player '".$zone."' executed. Actual Version is: 'v".$update['version']."' Build: '".$update['build']."'");
		}
	}
	# execute check if Update needed
	foreach($sonoszone as $zone => $ip) {
		$info = json_decode(file_get_contents('http://' . $sonoszone[$zone][0] . ':1400/info'), true);
		$vers = $info['device']['softwareVersion'];
		if (!is_null($update['build']))   {
			# for Testing only
			#$vers = '78.1-51069';
			if ($vers != $update['build'])  {
				LOGINF("bin/SW_Update.php: Update for Player '".$zone."' required. Current Version is: '".$vers."' and will be updated to: '".$update['build']."'");
				$count++;
			}
		}
	}
	# Log Player info Offline
	if (!empty($zonesoffline))   {
		$off = implode(", ", $zonesoffline);
		LOGWARN("bin/SW_Update.php: Updatecheck for Player '".$off."' could not be executed, may be they are Offline");
	}
	# if updated req. then execute
	if ($count > 0)  {
		#$update = $sonos->BeginSoftwareUpdate($update['updateurl']);
		LOGDEB("bin/SW_Update.php: Delay of 10 Minutes until all players were updated");
		# wait 10 minutes until Update finished
		#sleep(600);
		LOGOK("bin/SW_Update.php: Update for Playes were Online finished successful.");
	} else {
		LOGDEB("bin/SW_Update.php: No update for Players were Online are required.");
	}
	
	# if Power on was requested send power off
	if (is_enabled($hw_update_power))    {
		send("0");
	}
	@LOGEND("Sonos Software Update");
}


/**
/* Funktion : checkZonesOn() --> prüft Onlinestatus der Player
/*
/* @param:                              
/* @return: 
**/

function checkZonesOn()    {
	
	global $sonoszonen, $sonoszone, $zonesonline, $zonesoffline, $folfilePlOn;

	$zonesonline = array();
	$zonesoffline = array();
	foreach($sonoszonen as $zonen => $ip) {
		$handle = is_file($folfilePlOn."".$zonen.".txt");
		if($handle === true) {
			$sonoszone[$zonen] = $ip;
			array_push($zonesonline, $zonen);
		} else {
			array_push($zonesoffline, $zonen);
		}
	}
}

/**
/* Funktion : startlog --> startet logging
/*
/* @param:                          
/* @return: 
**/

function startlog()   {
	
require_once "loxberry_log.php";

global $lbplogdir;
	
$params = [	"name" => "Sonos Software Update",
				"filename" => "$lbplogdir/update.log",
				"append" => 1,
				"addtime" => 1,
				];
$level = LBSystem::pluginloglevel();
$log = LBLog::newLog($params);
LOGSTART("Sonos Software Update");
}


/**
/* Funktion : send --> sendet Statusdaten
/*
/* @param: string $value                             
/* @return: 
**/

function send($value)    {
	
	global $config;
	
	// check if Data transmission is switched off
	if(!is_enabled($config['LOXONE']['LoxDaten'])) {
		LOGERR("bin/SW_Update.php: You have turned on Auto Update and marked Power-On before Update, but Communication to Loxone is switched off. Please turn on!!");
		notify( LBPPLUGINDIR, "Sonos4lox", "You have turned on Auto Update and marked Power-On before Update, but Communication to Loxone is switched off. Please turn on!!", 1);
		exit;
	}
	if(is_enabled($config['LOXONE']['LoxDatenMQTT'])) {
		sendMQTT($value);
	} else {
		sendUDP($value);
	}
}

/**
/* Funktion : sendUDP --> sendet Statusdaten per UDP
/*
/* @param: string $value                             
/* @return: 
**/

function sendUDP($value)    {
	
	global $lbphtmldir, $config;
	
	require_once("$lbphtmldir/system/io-modul.php");
	require_once("loxberry_io.php");
	
	$mem_sendall = 0;
	$mem_sendall_sec = 3600;
	
	$tmp_array = array();
	$server_port = $config['LOXONE']['LoxPort'];
	$no_ms = $config['LOXONE']['Loxone'];
	$tmp_array["power_on"] = $value;	
	
	$response = udp_send_mem($no_ms, $server_port, "Sonos4lox", $tmp_array);
	$value == "1" ? $val = "On" : $val = "Off";
	LOGINF("bin/SW_Update.php:: Power ".$val." has been send to MS via UDP");
}


/**
/* Funktion : sendMQTT --> sendet Statusdaten per MQTT
/*
/* @param: string $value                             
/* @return: 
**/

function sendMQTT($value)    {
	
	global $lbphtmldir;
	
	require_once "loxberry_io.php";
	require_once "$lbphtmldir/bin/phpmqtt/phpMQTT.php";
	require_once("$lbphtmldir/system/io-modul.php");
	
	# Get the MQTT Gateway connection details from LoxBerry
	$creds = mqtt_connectiondetails();
	# MQTT requires a unique client id
	$client_id = uniqid(gethostname()."_client");
	$mqtt = new Bluerhinos\phpMQTT($creds['brokerhost'],  $creds['brokerport'], $client_id);
	$mqtt->connect(true, NULL, $creds['brokeruser'], $creds['brokerpass']);
	
	$value == "1" ? $val = "On" : $val = "Off";
	$mqtt->publish('Sonos4lox/update', $val, 0, 1);
	LOGINF("bin/SW_Update.php: Power ".$val." has been send to MS via MQTT");
	return;
}
?>