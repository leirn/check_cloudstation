#!/usr/bin/php
<?php

/************************
 * 
 * check_cloudstation.php
 * 
 * Script to check the status of backups on Synology NAS
 * 
 * Existing statuses for tasks :
 * - uptodate : normal status
 * - syncing : normal status
 * - err_ssl_change : critical status requiring human intervention
 * 
 ************************/

$debug = false;

set_error_handler(
    function ($severity, $message, $file, $line) {
        throw new ErrorException($message, $severity, $severity, $file, $line);
    }
);

function print_help() {
	global $argv;
	echo $argv[0]." [-h -v] -H hostname [-P port] [-s] -u username -p password\n".
			"\n".
			"List of options\n".
			"    -H : hostname to be checked\n".
			"    -P : port to connect to. Defaut is 5000 or 5001 if -s if set\n".
			"    -u : username to connect to host\n".
			"    -p : password to connect to host\n".
			"    -s : activate http (http by default)\n".
			"    -v : verbose. Activate debug info\n".
			"    -h : print this help.\n";
}

function syno_request($url) {
	
	$arrContextOptions=array(
	    "ssl"=>array(
	        "verify_peer"=>false,
	        "verify_peer_name"=>false,
	    ),
	);
	
	global $debug;
	try  
	{ 
		if(false === ($json = file_get_contents($url, false, stream_context_create($arrContextOptions)))) {
			echo "Error on url $url";
			exit(3);
		}
	}
	catch (Exception $e)
	{
		echo "Error on url $url : ".$e->getCode()." - ".$e->getMessage();
		exit(3);
	}
    $obj = json_decode($json);
    if($debug) {echo "$url\n"; print_r($obj);}
    if($obj->success != "true"){
    	echo "Error while getting $url\n. ".print_r($obj, true);
    	exit(3);
    }
    else
    return $obj;
}

$ssl = "";

// Example from https://www.nas-forum.com/forum/topic/46256-script-web-api-synology/
// Parsing Args
if(isset($options['h'])) { print_help(); exit;}
if(isset($options['v'])) $debug = true;

$options = getopt("shPvH:u:p:");
if($debug) print_r($options);

// Check servername
if(!isset($options['H'])) {echo "Hostname not defined.\n";print_help();exit;} else {
	$port = 5000;
	if(isset($options['s'])) {
		$port = 5001;
		$ssl = 's';
	}
	if(isset($options['P'])) $port = $options['P'];
	$server = "http$ssl://".$options['H'].":$port";
}

// Check username
if(!isset($options['u'])) {echo "Username not defined.\n";print_help();exit;} else $login = $options['u'];

//Check password
if(!isset($options['p'])) {echo "Password not defined.\n";print_help();exit;} else $pass = $options['p'];


    /* API VERSIONS */
    //SYNO.API.Auth
    $vAuth = 2;
    
	$api = "SYNO.CloudStation.ShareSync.Connection";
    //$api = "SYNO.DownloadStation.Task";
    
    $sessiontype="CloudStation";
    //$sessiontype="downloadStation";
    

    //Get SYNO.API.Auth Path (recommended by Synology for further update)
    $obj = syno_request($server.'/webapi/query.cgi?api=SYNO.API.Info&method=Query&version=1&query=SYNO.API.Auth');
    $path = $obj->data->{'SYNO.API.Auth'}->path;

    //Login and creating SID
    $obj = syno_request($server.'/webapi/'.$path.'?api=SYNO.API.Auth&method=Login&version='.$vAuth.'&account='.$login.'&passwd='.$pass.'&session='.$sessiontype.'=&format=sid');
    
	//authentification successful
	$sid = $obj->data->sid;
	if($debug) echo "SId : $sid\n";
    
    
    //Get SYNOSYNO.Backup.Statistics (recommended by Synology for further update)
    $obj = syno_request($server.'/webapi/query.cgi?api=SYNO.API.Info&method=Query&version=1&query='.$api);
    $path = $obj->data->{$api}->path;
	
	//get tray status
	$obj = syno_request($server.'/webapi/'.$path.'?additional=["tray_status"]&api=SYNO.CloudStation.ShareSync.Connection&method=list&version=1&_sid='.$sid);
	
	foreach ($obj->data->conn as $val) {
		echo $val->server_name." : ";
		switch($val->status) {
			case "err_ssl_change":
				echo "SSL Certificate has been changed";
			case "uptodate":
			echo "Up to date.";
				break;
			case "syncing":
				echo "Syncing";
				break;
			default:
				echo "Unknown statys ".$val->status;
				break;
		}
		echo "\n";
	}
	
	echo "Overall status : ";
	switch($obj->data->tray_status) {
		case "uptodate":
			$status_n = 0;
			echo "Up to date.";
			break;
		case "syncing":
			$status_n = 0;
			echo "Syncing.";
			break;
		case "err_ssl_change":
			$status_n = 2;
			echo "SSL Certificate has been changed.";
			break;
		default:
			$status_n = 3;
			echo "Unknown statys ".$obj->data->tray_status.".";
			break;
	}
	
	$nagios_status = array (
		0 => "OK",
		1 => "WARNING",
		2 => "CRITICAL",
		3 => "UNKNOWN",
		);
	echo " Status is ".$nagios_status[$status_n]."\n";
	

	//Get SYNO.API.Auth Path (recommended by Synology for further update)
    $obj = syno_request($server.'/webapi/query.cgi?api=SYNO.API.Info&method=Query&version=1&query=SYNO.API.Auth');
    $path = $obj->data->{'SYNO.API.Auth'}->path;
    
    //Logout and destroying SID
    $obj = syno_request($server.'/webapi/'.$path.'?api=SYNO.API.Auth&method=Logout&version='.$vAuth.'&session=HyperBackup&_sid='.$sid);
    
    /*
		Nagios understands the following exit codes:
		
		0 - Service is OK.
		1 - Service has a WARNING.
		2 - Service is in a CRITICAL status.
		3 - Service status is UNKNOWN.
	*/
    exit ($status_n);
    
?>
  