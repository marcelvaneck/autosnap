#!/usr/bin/php
<?PHP

/*    AUTOSNAP.PHP - create snapshots of all your Digital Ocean droplets
 *    Copyright (C) 2014  Resultix BV  Marcel van Eck
 *
 *    This program is free software: you can redistribute it and/or modify
 *    it under the terms of the GNU General Public License as published by
 *    the Free Software Foundation, either version 3 of the License, or
 *    (at your option) any later version.
 *
 *    This program is distributed in the hope that it will be useful,
 *    but WITHOUT ANY WARRANTY; without even the implied warranty of
 *    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *    GNU General Public License for more details.
 *
 *    You should have received a copy of the GNU General Public License
 *    along with this program.  If not, see <http://www.gnu.org/licenses>
*/

// Setup a cronjob at a "non-Digital-Ocean" machine to create automatic snapshots


// Default timezone for the Digital Ocean Control Panel.
// Leave this at UTC for a correct snapshot-age / retention calculation.
date_default_timezone_set("UTC");

// Create your own APIv2 Accesstoken in Dig Oc. control panel and enter it here.
$TOKEN = "XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX";

// Maximum retention time for the snapshots (in seconds)
$MaxAge = 60*60*24*7;
// 60*60*24*7 equals 1 week

function GetList($url){
	$ch = curl_init($url);
	curl_setopt($ch,CURLOPT_HTTPHEADER,array('Authorization: Bearer '.$GLOBALS['TOKEN']));
	curl_setopt($ch,CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch,CURLOPT_HEADER, false);
	$result = curl_exec($ch);
    if(curl_errno($ch)){
		throw new Exception("Unable to get droplet information. Error: ".curl_error($ch));
		}
	curl_close ($ch);
	return $result;
}


function DestroySnapshot($Snapshot){
	$ch = curl_init('https://api.digitalocean.com/v2/images/'.$Snapshot);
	curl_setopt($ch,CURLOPT_HTTPHEADER,array('Authorization: Bearer '.$GLOBALS['TOKEN'], 
	'Content-Type: application/x-www-form-urlencoded'));
	curl_setopt($ch,CURLOPT_CUSTOMREQUEST, 'DELETE');
	curl_setopt($ch,CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch,CURLOPT_HEADER, false);
	$result = curl_exec($ch);
    if(curl_errno($ch)){
		throw new Exception("Unable to destroy snapshot. Error: ".curl_error($ch));
		}
	curl_close ($ch);
	return $result;
}


function SwitchState($Droplet,$action){
	$ch = curl_init('https://api.digitalocean.com/v2/droplets/'.$Droplet.'/actions');
	$data_array = array('type' => $action);
	$data = http_build_query($data_array);
	curl_setopt($ch,CURLOPT_HTTPHEADER,array('Authorization: Bearer '.$GLOBALS['TOKEN'], 
	'Content-Type: application/x-www-form-urlencoded'));
	curl_setopt($ch,CURLOPT_CUSTOMREQUEST, 'POST');
	curl_setopt($ch,CURLOPT_POSTFIELDS, $data);
	curl_setopt($ch,CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch,CURLOPT_HEADER, false);
	$Response = json_decode(curl_exec($ch), true);
    if(curl_errno($ch)){
		throw new Exception("Unable to get droplet state. Error: ".curl_error($ch));
		}
	curl_close ($ch);
	$Action = $Response['action']['id'];
	$Status = "unknown";
	while($Status != "completed"){
		$ch = curl_init('https://api.digitalocean.com/v2/droplets/'.$Droplet.'/actions/'.$Action);
		curl_setopt($ch,CURLOPT_HTTPHEADER,array('Authorization: Bearer '.$GLOBALS['TOKEN'])); 
		curl_setopt($ch,CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch,CURLOPT_HEADER, false);
		$Response = json_decode(curl_exec($ch), true);
	    if(curl_errno($ch)){
			throw new Exception("Unable to get action state. Error: ".curl_error($ch));
			}
	   	curl_close ($ch);
		if(($i=12)){
			throw new Exception('Unable to shutdown gracefully.');
		}
		$Status = $Response['action']['status'];
		if ($Status == "in-progress"){
			sleep(5);
		}
	}
}


function Snapshot($Droplet,$Snapname){
	$ch = curl_init('https://api.digitalocean.com/v2/droplets/'.$Droplet.'/actions');
	$data_array = array('type' => 'snapshot', 'name' => $Snapname);
	$data = http_build_query($data_array);
	curl_setopt($ch,CURLOPT_HTTPHEADER,array('Authorization: Bearer '.$GLOBALS['TOKEN'], 
	'Content-Type: application/x-www-form-urlencoded'));
	curl_setopt($ch,CURLOPT_CUSTOMREQUEST, 'POST');
	curl_setopt($ch,CURLOPT_POSTFIELDS, $data);
	curl_setopt($ch,CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch,CURLOPT_HEADER, false);
	$Response = json_decode(curl_exec($ch), true);
    if(curl_errno($ch)){
		throw new Exception("Unable to create snapshot ".$Snapname.". Error: ".curl_error($ch));
		}
	curl_close ($ch);
	$Action = $Response['action']['id'];
	$Status = "unknown";
	while($Status != "completed"){
		$ch = curl_init('https://api.digitalocean.com/v2/droplets/'.$Droplet.'/actions/'.$Action);
		curl_setopt($ch,CURLOPT_HTTPHEADER,array('Authorization: Bearer '.$GLOBALS['TOKEN'])); 
		curl_setopt($ch,CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch,CURLOPT_HEADER, false);
		$Response = json_decode(curl_exec($ch), true);
	    if(curl_errno($ch)){
			throw new Exception("Unable to get snapshot progress for ".$Snapname.". Error: ".curl_error($ch));
			}
		curl_close ($ch);
		$Status = $Response['action']['status'];
		if ($Status == "in-progress"){
			sleep(5);
		}
	}
}


echo "Checking droplets and existing snapshots. ";
try {
	$AllDroplets = json_decode(GetList('https://api.digitalocean.com/v2/droplets'));
} catch (Exception $e) {
	echo 'Exception: ', $e->getMessage(), "\n";
	exit(1);
}
$DropletArray = $AllDroplets->droplets;
$NumberOfDroplets = count($DropletArray);
if($NumberOfDroplets<1){
	echo "No droplets found.\n";
	exit(2);
}
echo $NumberOfDroplets." droplets identified.\n";
for($i=0; $i<$NumberOfDroplets; $i++){ 
	$DropletID[$i] = $DropletArray[($i)]->id;
	$DropletName[$i] = $DropletArray[($i)]->name;
	$DropletState[$i] = $DropletArray[($i)]->status;
	if($DropletState[$i]!="off"){
		echo "Graceful shutdown for droplet ".$DropletID[$i]." - ".$DropletName[$i]."\n";
		try {
			SwitchState($DropletID[$i],'shutdown');
		} catch (Exception $e) {
			echo 'Exception: ', $e->getMessage(), "\n";
			echo "Attempting blunt power-down for ".$DropletID[$i]." - ".$DropletName[$i]."\n";
			try {
				SwitchState($DropletID[$i],'power_off');
			} catch (Exception $e) {
				echo 'Exception: ', $e->getMessage(), "\n";
				echo "Power-down failed for ".$DropletID[$i]." - ".$DropletName[$i]."\n";
				exit(3);
			}
		}
	} else {
		echo "Droplet ".$DropletID[$i]." - ".$DropletName[$i]." is allready switched off.\n";
	}
	try {
		$SnapshotName = 'AUTOSNAP_'.$DropletID[$i].'_'.date('Ymd_Hi');
		echo "Creating snapshot ".$SnapshotName."\n";
		echo "Please be patient. The proces may take several minutes (depending on dropletsize).\n";
		Snapshot($DropletID[$i],$SnapshotName);
	} catch (Exception $e) {
		echo 'Exception: ', $e->getMessage(), "\n";
		exit(4);
	}
	echo "New snapshot created. Restarting droplet ".$DropletID[$i]." - ".$DropletName[$i]."\n";
}
echo "Cleaning up any obsolete snapshots.\n";
$AllImages = json_decode(GetList('https://api.digitalocean.com/v2/images/'));
$Images = $AllImages->images;
$NumberOfImages = count($Images);
for($i=0; $i<$NumberOfImages; $i++){
	$ImagePublic[$i] = $Images[$i]->public;
	if (!$ImagePublic[$i]){
		$ImageID[$i] = $Images[$i]->id;
		$ImageName[$i] = $Images[$i]->name;
		$ImageCreation[$i] = $Images[$i]->created_at;
		$Age = time()-strtotime($ImageCreation[$i]);
		if (($Age > $MaxAge)&&(substr($ImageName[$i],0,8)=="AUTOSNAP")){
			DestroySnapshot($ImageID[$i]);
			echo $ImageID[$i]." - ".$ImageName[$i]." destroyed\n";
		}
	}
}
echo "Done.\n";

?>
