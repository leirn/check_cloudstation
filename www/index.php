<!DOCTYPE html>
<?php

$debug = false;

function ping($host,$port=80,$timeout=6)
{
        $fsock = fsockopen($host, $port, $errno, $errstr, $timeout);
        if ( ! $fsock )
        {
                return FALSE;
        }
        else
        {
        		fclose($fsock);
                return TRUE;
        }
}

set_error_handler(
    function ($severity, $message, $file, $line) {
        throw new ErrorException($message, $severity, $severity, $file, $line);
    }
);

function syno_request($url) {
	
	$arrContextOptions=array(
	    "ssl"=>array(
	        "verify_peer"=>false,
	        "verify_peer_name"=>false,
	    ),
	);
	
	$debug = $GLOBALS['debug'];
	try  
	{ 
		if(false === ($json = file_get_contents($url, false, stream_context_create($arrContextOptions)))) {
			throw new Exception( "Error on url $url", 2);
		}
	}
	catch (Exception $e)
	{
		throw new Exception( "Error on url $url : ".$e->getCode()." - ".$e->getMessage(), 1, $e);
	}
    $obj = json_decode($json);
    if($debug) {echo "$url\n"; print_r($obj);}
    if($obj->success != "true"){
    	throw new Exception( "Error while getting $url", 2);
    }
    else
    return $obj;
}

function getShareSyncStatus ($host, $port, $login, $pass, $ssl) {
	$debug = $GLOBALS['debug'];
	
    if($ssl) $protocol = "https://"; else $protocol = "http://";
    
    $server = $protocol.$host.":".$port;

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
	
	echo "Overall status : ";
	switch($obj->data->tray_status) {
		case "uptodate":
			$status_n = 0;
			break;
		case "syncing":
			$status_n = 0;
			break;
		case "err_ssl_change":
		default:
			$status_n = 2;
			break;
	}
	
	//Get SYNO.API.Auth Path (recommended by Synology for further update)
    $obj = syno_request($server.'/webapi/query.cgi?api=SYNO.API.Info&method=Query&version=1&query=SYNO.API.Auth');
    $path = $obj->data->{'SYNO.API.Auth'}->path;
    
    //Logout and destroying SID
    $obj = syno_request($server.'/webapi/'.$path.'?api=SYNO.API.Auth&method=Logout&version='.$vAuth.'&session=HyperBackup&_sid='.$sid);

    return $status_n;
}

include("config.php");

foreach($hosts as $id => $host) {
	try {
		if(!ping($host["hostname"], $host["port"])) throw new Exception($host["hostname"]." is not reachable");
		$hosts[$id]["status"] = getShareSyncStatus ($host["hostname"], $host["port"], $host["username"], $host["password"], $host["ssl"]);
	}
	catch (Exception $e) {
		$hosts[$id]["status"] = 2;
		if($debug) print_r($e);
	}	
}

?>
<html>
<head>
<title>Etat synchro NAS</title>
</head>
<body>
<ul>
<?php 
foreach($hosts as $host) {
	?>
<li><?php echo $host["description"] ?>&nbsp;: <?php if($host["status"] == 0) echo "NAS &agrave; jour";elseif($host["status"] == 1) echo "Synchro en cours"; else echo "Probl&egrave;me, voir avec Laurent"; ?></li>
<?php } ?>
</ul>
</body>
</html>
  