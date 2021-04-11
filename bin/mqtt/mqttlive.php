#!/usr/bin/php
<?php
require_once "loxberry_system.php";
require_once "loxberry_io.php";
require_once "phpMQTT/phpMQTT.php";
 
// Global variables
$data_transferfolder = "/dev/shm/s4ltmp";
$data_transferfile = $data_transferfolder."/mqttlive_dataspool.json";
$perlprocessor_filename = LBPBINDIR."/lox2telegraf.pl";
 
if(!file_exists($data_transferfolder) ){
	mkdir( $data_transferfolder );
}

// Get the MQTT Gateway connection details from LoxBerry
$creds = mqtt_connectiondetails();
 
// MQTT requires a unique client id
$client_id = uniqid(gethostname()."_s4llive");
 
$basetopic = 's4llive/';
 
$mqtt = new Bluerhinos\phpMQTT($creds['brokerhost'],  $creds['brokerport'], $client_id);
if( ! $mqtt->connect(true, NULL, $creds['brokeruser'], $creds['brokerpass'] ) ) {
	
	echo "MQTT connection failed";
	exit(1);
	// We should do a retry here
} 

echo "Connected to MQTT broker ".$creds['brokerhost'].":".$creds['brokerport']."\n";



// Define and subscribe topic
$topics['s4llive/#'] = array('qos' => 0, 'function' => 's4llive_mqttmsg');
$mqtt->subscribe($topics, 0);

// Create record queue and ui data
$recordqueue = array();
$uidata = array();
$uidata_update = true;

// Schedules of this program
$runtime_1secs = microtime(true);
$runtime_5mins = microtime(true);

// Measure CPU usage for fun ;-)
$cpu_usage = getrusage()["ru_utime.tv_usec"];



// Read stats.json
readStatsjson();
// Initial push file-queued data to influx
callPerlProcessor();
// Initial write UI data
writeInfoForUI();

// Permanent loop
while($mqtt->proc()) {
	if( microtime(true)>=$runtime_1secs+1 ) {
		// Run tasks every second
		tasks_1secs();
		
		$runtime_1secs=microtime(true);
	}
	
	if( time()>=$runtime_5mins+307 ) {
		// Run tasks every 5 minutes
		
		// Calculate CPU usage
		$cpu_usage_now = getrusage()["ru_utime.tv_usec"];
		$newruntime=microtime(true);
		echo "CPU time: " . round( ($cpu_usage_now-$cpu_usage)/($newruntime-$runtime_5mins)/1000000*100, 2) ." %\n";
		$cpu_usage = $cpu_usage_now;
		
		// Run 5 Min. task
		tasks_5mins();
		
		$runtime_5mins=$newruntime;
	}
}

$mqtt->close();

function s4llive_mqttmsg ($topic, $msg){
	global $basetopic;
	global $stats;
	global $recordqueue;
	global $uidata;
	
	$timestamp_epoch = microtime(true);
	$timestamp_nsec = sprintf('%.0f', $timestamp_epoch*1000000000);
	echo "Msg Recieved: timestamp: $timestamp_nsec " . date('r') . "Topic: {$topic}\t$msg\n";
	
	$reltopic = substr( $topic, strlen( $basetopic ) );
	echo "Relative Topic $reltopic\n";
	list( $msno, $uuid, $output ) = explode( "/", $reltopic );
	echo "MS: $msno UUID: $uuid OUTPUT: $output\n";
	
	$id = "${msno}_${uuid}";
	
	$errormsg = "";
	
	// Message validated
	if( property_exists ( $stats, $id ) ) {
		$dest = $stats->$id;
		echo "Name: $dest->name Room: $dest->room\n";
		
		if( empty($dest->measurementname) ) {
			echo "ERROR No measurementname defined in stats.json for UUID $uuid\n";
			return;
		}
		
		$item = new stdClass();
		$item->timestamp = $timestamp_nsec;
		$item->measurementname = $dest->measurementname;
		$item->source = "mqttlive";
		$item->values = array( array( $output => $msg ) );
		if( $dest->name ) $item->name = $dest->name;
		if( $dest->description ) $item->description = $dest->description;
		if( $dest->uuid ) $item->uuid = $dest->uuid;
		if( $dest->type ) $item->type = $dest->type;
		if( $dest->category ) $item->category = $dest->category;
		if( $dest->room ) $item->room = $dest->room;
		if( $dest->msno ) $item->msno = $dest->msno;
		
		$recordqueue[] = $item;
	
		echo "linequeue length before sending: " . count($recordqueue) . "\n";
		
		/* 
		// Socket send - not required anymore
		foreach( $recordqueue as $linekey => $lineobj) {
			$expectedbytes = strlen($lineobj->line);
			$sentbytes = false;
			if( $socket ) {
				$sentbytes = fwrite( $socket, $lineobj->line );
				if( $sentbytes != false && $sentbytes == $expectedbytes ) {
					unset( $recordqueue[$linekey] );
				}
				else { 
					echo "ERROR sending line: Sent: $sentbytes Expected: $expectedbytes\n";
				}
			}
		}
		
		*/
	}
	
	// UNKNOWN Message
	else {
		$errormsg = "$msno/$uuid not found";
		echo "UNKNOWN $msno/$uuid\n";
	}
	
	unset($uidata["$reltopic"]);
	$uirecord = array(
		"timestamp_epoch" => $timestamp_epoch,
		"topic_full" => $topic,
		"topic_rel" => $reltopic,
		"msno" => $msno,
		"uuid" => $uuid,
		"output" => $output,
		"value" => $msg,
		"stat_found" => isset( $dest ) ? true : false,
		"errormsg" => $errormsg
	);
	$uidata["$reltopic"] = $uirecord;
	$uidata_update = true;

}

/*
// Create Influx lineprot - not necessary anymore
function getLineprot( $timestamp, $value, $dest, $output ) {
	
	$measurement = "stats4lox";
	$tags = array();
	$fields = array();
	
	$measurement = LineProtCleanMeasurements($measurement);
	
	
	if( $dest->category ) $tags["category"] = LineProtCleanTags($dest->category);
	if( $dest->description ) $tags["description"] = LineProtCleanTags($dest->description);
	if( $dest->msno ) $tags["msno"] = LineProtCleanTags($dest->msno);
	if( $dest->name ) $tags["name"] = LineProtCleanTags($dest->name);
	if( $dest->uuid ) $tags["uuid"] = LineProtCleanTags($dest->uuid);
	if( $dest->room ) $tags["room"] = LineProtCleanTags($dest->room);
	if( $dest->type ) $tags["type"] = LineProtCleanTags($dest->type);
	
	$valname = $dest->uuid."_".$output;
	$fields["$valname"] = $value;
	
	$line = "$measurement";
	
	foreach( $tags as $tagkey => $tagvalue ) {
		$line .= ",$tagkey=$tagvalue";
	}
	
	$line .= ' ';
	
	foreach( $fields as $fieldkey => $fieldvalue ) {
		$line .= "$fieldkey=$fieldvalue,";
	}
	$line = rtrim($line, ',');
	
	$line .= ' ';
	
	$line .= $timestamp;
	
	$line .= "\n";
	
	return $line;
	
}

*/

// Tag keys, tag values, field keys
function LineProtCleanTags( $line ) {
	return str_replace( [',', '=', ' '], [ '\,', '\=', '\ '], $line );
}
// Measurements
function LineProtCleanMeasurements( $line ) {
	return str_replace( [',', ' '], [ '\,', '\ '], $line );
}
// String field values
function LineProtCleanStringFieldValues( $line ) {
	return str_replace( ['"'], [ '\"'], $line );
}



function tasks_1secs() {
	global $uidata_update;
	
	echo "tasks_1secs\n";
	readStatsjson(); // (but possibly by inotify)
	outputLinequeue();
	
	if( $uidata_update ) {
		writeInfoForUI();
		$uidata_update = false;
	}
}

function tasks_5mins() {
	global $recordqueue;
	
	echo "tasks_5mins\n";
	// get_miniservers (but possibly by inotify)
	
	$queue_size = count($recordqueue);
	echo "Cleanup: Current linequeue length: $queue_size\n";
		
	// Cleanup queue if queue is too big
	if( $queue_size > 1000 ) {
		usort($recordqueue, function($a, $b) {
			return $a->timestamp < $b->timestamp ? -1 : 1; });
		
		// Reduce queue to the 900 latest entries
		$recordqueue = array_slice($recordqueue, $queue_size-900);
	}
}



function readStatsjson() {
	global $stats;
	$statscfg = json_decode( file_get_contents( LBPCONFIGDIR . "/stats.json" ) );
	// Convert Loxone array to list of objects
	$stats = new stdClass();
	if( !$statscfg ) {
		return;
	}
	
	//	"loxone" is an unindexed array in stats.json.
	//	To directly access the element, create an object list with key $msno_$uuid
	
	foreach($statscfg->loxone as $cfg) {
        $msno = $cfg->msno;
		$uuid = $cfg->uuid;
		$stats->{"${msno}_${uuid}"} = $cfg;
		
    }

	// print_r( $stats );

}

/*
// UNIX socket - not required anymore

function openUnixSocket() {
	
	// Read S4L config
	$s4lcfg = json_decode( file_get_contents( LBPCONFIGDIR . "/stats4lox.json" ) );
	
	$unixsocketfile = "unix://" . $s4lcfg->telegraf->unixsocket;
	
	if( empty( $unixsocketfile ) ) {
		echo "ERROR Reading stats4lox.json telegraf unix socket\n";
		return;
	}
	
	$socket = stream_socket_client ( $unixsocketfile , $errno , $errstr, 10, STREAM_CLIENT_CONNECT );

	if( !$socket ) {
		echo "ERROR opening telegraf unix socket $unixsocketfile\n";
		return;
	}
	
	return $socket;
}
*/

function outputLinequeue() {
	global $data_transferfile;
	global $recordqueue;

	$queue_size = count($recordqueue);
	print "Queue size: $queue_size elements\n"; 
	
	if( $queue_size == 0 ) {
		// Nothing to do
		return;
	}

	$outputfh = fopen($data_transferfile, "c+");
	
	//Lock File, error if unable to lock
	if( !flock($outputfh, LOCK_EX | LOCK_NB) ) {
		echo "Data Transferfile could not be locked. Trying again in a second. ($data_transferfile)\n";
		fclose($outputfh);
		return;
	}
	
	// echo "READ FILE\n";
	rewind($outputfh);
	$filedatastr = fread($outputfh, 5242880 );
	// echo "FILEDATASTR:\n".$filedatastr."\n";
	
	if( strlen($filedatastr) > 0) {
		$filedata = json_decode( $filedatastr , false, 512, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_IGNORE);
	} 
	else {
		$filedata = "";
	}
	
	// echo "FILEDATA is " . gettype($filedata) . "\n";

	if( is_array($filedata) ) {
		// echo "ARRAYMERGE\n";
		$filedata = array_merge( $filedata, $recordqueue );
	}
	else {
		// echo "SET FILEDATA TO RECORDQUEUE\n";
		$filedata = $recordqueue;
	}
	
	try {
		ftruncate($outputfh, 0);
		rewind($outputfh);
		fwrite($outputfh, json_encode( $filedata, JSON_INVALID_UTF8_IGNORE | JSON_PRETTY_PRINT | JSON_UNESCAPED_LINE_TERMINATORS | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
		flock($outputfh, LOCK_UN);
		fclose($outputfh);
	}
	catch( Exception $e ) {
		echo "EXCEPTION on writing file - linequeue not truncated. ".$e->getMessage()."\n";
		return;
	}
	
	// Things are sent, we can truncate the linequeue
	$recordqueue = array();
	callPerlProcessor();
	
	
	
} 
	
function callPerlProcessor() {
	global $perlprocessor_filename;
	global $perlprocessor_pid;
	global $data_transferfile;
	
	echo "PID running? " . posix_getpgid($perlprocessor_pid) . "\n";
if( empty($perlprocessor_pid) or empty(posix_getpgid($perlprocessor_pid)) ) {
		echo "RUN PERL PROCESSOR\n";
		exec("$perlprocessor_filename \"$data_transferfile\" >/dev/null 2>&1 & echo $!; ", $perlprocessor_pid);
		$perlprocessor_pid = $perlprocessor_pid[0];
		echo "Output: $perlprocessor_pid\n";
	}
	else { 
		echo "PERL PROCESSOR seems to be running. Skipping this round.\n";
	}
}
	


function writeInfoForUI() {

	// tbi
	
}
