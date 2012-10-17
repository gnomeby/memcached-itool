<?php

const DEFAULT_TCP_TIMEOUT = 30;
const DEFAULT_UNIXSOCKET_TIMEOUT = 5;

$params = $_SERVER['argv'];

// Parse command line
$host = 'localhost';
$port = 11211;
$socketpath = NULL;
$mode = 'display';
if(!empty($params[1]))
{
  list($host, $port) = explode(':', $params[1]);
  $port = $port ?: 11211;
  if(strpos($host, '/') === 0)
  {
    $socketpath = $host;
    $host = NULL;
    $port = NULL;
  }
}
if(!empty($params[2]))
{
  $mode = $params[2];
}

// Check params
if(count($params) < 2 || !in_array($mode, array('display', 'dumpkeys', 'stats')))
{
  help();
  exit;
}

// Connect to memcached
$errno = NULL;
$errstr = NULL;
if($host && $port)
  $fp = stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, DEFAULT_TCP_TIMEOUT);
else
  $fp = stream_socket_client("unix://{$socketpath}", $errno, $errstr, DEFAULT_UNIXSOCKET_TIMEOUT);

if (!$fp)
{
  echo "$errstr ($errno)".PHP_EOL;
  exit -1;
}


// Run logic
switch($mode)
{
  case 'stats':
    stats($fp);
    break;

  case 'dumpkeys':
    dumpkeys($fp);
    break;

  case 'display':
  default:
    display($fp);
    break;
}

exit;


// # -------------------- functions
function help()
{
  echo <<<HELP
  Usage: memcached-tool <host[:port] | /path/to/socket> [mode]
	memcached-tool localhost:11211 display    # shows slabs information (display is default mode)
	memcached-tool localhost:11211 stats      # shows general stats
	memcached-tool localhost:11211 dumpkeys   # dumps only keys
HELP;
}


function send_and_receice($fp, $command)
{
  fwrite($fp, $command."\r\n");
  $result = '';
  while (!feof($fp))
  {
    $result .= fread($fp, 1);

    if(strpos($result, 'END'."\r\n") !== FALSE)
      break;
  }
  
  $lines = explode("\r\n", $result);
  foreach($lines as $key=>$line)
  {
    if(!trim($line) || trim($line) == 'END')
      unset($lines[$key]);
  }

  return $lines;
}


function slabs_stats($fp)
{
  $slabs = array();

  $lines = send_and_receice($fp, 'stats slabs');
  foreach($lines as $line)
  {
    $m = array();
    if(preg_match('/^STAT (\d+):(\w+) (\d+)/', $line, $m))
    {
      $slab_num = $m[1];
      $slab_property = $m[2];
      $slab_value = $m[3];

      $slabs[$slab_num][$slab_property] = $slab_value;
    }

    if(preg_match('/^STAT (\w+) (\d+)/', $line, $m))
    {
      $slab_property = $m[1];
      $slab_value = $m[2];

      $slabs['total'][$slab_property] = $slab_value;
    }
  }

  $lines = send_and_receice($fp, 'stats items');
  foreach($lines as $line)
  {
    if(!trim($line))
      continue;
    if(trim($line) == 'END')
      break;

    $m = array();
    if(preg_match('/^STAT items:(\d+):(\w+) (\d+)/', $line, $m))
    {
      $slab_num = $m[1];
      $slab_property = $m[2];
      $slab_value = $m[3];

      $slabs[$slab_num][$slab_property] = $slab_value;
    }
  }

  foreach($slabs as $num => $slab) 
  {
    $slab['age'] = !empty($slab['age']) ? $slab['age'] : 0;
    $slab['number'] = !empty($slab['number']) ? $slab['number'] : 0;
    $slab['evicted'] = !empty($slab['evicted']) ? $slab['evicted'] : 0;
    $slab['evicted_time'] = !empty($slab['evicted_time']) ? $slab['evicted_time'] : 0;
    $slab['outofmemory'] = !empty($slab['outofmemory']) ? $slab['outofmemory'] : 0;

    $slabs[$num] = $slab;
  }

  return $slabs;
}


function display($fp)
{
  $slabs = slabs_stats($fp);

  print "  #  Item_Size  Max_age   Pages   Count   Full?  Evicted Evict_Time OOM\n";
  foreach($slabs as $num => $slab) 
  {
    if($num == 'total')
      continue;

    $chunk_size = $slab['chunk_size'];
    $chunk_size = $chunk_size < 1024 ? $chunk_size.'B' : sprintf("%.1f", $chunk_size/1024).'k';
    $is_slab_full = $slab['free_chunks_end'] == 0 ? "yes" : "no";

    printf("%3d %10s %7ds %7d %7d %7s %8d %10d %3d\n", $num, $chunk_size, $slab['age'], $slab['total_pages'], $slab['number'], $is_slab_full, 
      $slab['evicted'], $slab['evicted_time'], $slab['outofmemory']);
  }

  print PHP_EOL."Total:".PHP_EOL;
  foreach($slabs['total'] as $property=>$value)
  {
    if($property == 'total_malloced')
    {
      $value = sprintf('%.f', $value / 1024 / 1024);
      printf("%12s %10.3fM\n", $property, $value);
    }
    else
      printf("%12s %12s\n", $property, $value);
  }
}


function stats($fp)
{
  $stats = array();

  $lines = send_and_receice($fp, 'stats');
  foreach($lines as $line)
  {
    $m = array();
    if(preg_match('/^STAT ([^\s]+) ([^\s]+)/', $line, $m))
    {
      $property = $m[1];
      $value = $m[2];

      $stats[$property] = $value;
    }
  }

  printf ("%24s %15s\n", "Field", "Value");
  foreach($stats as $property => $value)
    printf ("%24s %15s\n", $property, $value);
}


function dumpkeys($fp)
{
  $slabs = slabs_stats($fp);
  ksort($slabs);

  $now = time();

  printf("%-70s %s\n", 'Key', 'Status');
  foreach($slabs as $num => $slab) 
  {
    if($num == 'total')
      continue;

    if($slab['number'])
    {
      $lines = send_and_receice($fp, "stats cachedump {$num} {$slab['number']}");
      foreach($lines as $line)
      {
	$m = array();
	if(preg_match('/^ITEM ([^\s]+) \[.* (\d+) s\]/', $line, $m))
	{
	  $key = $m[1];
	  $expiration_time = $m[2];
	  $status = $now > $expiration_time ? '[expired]' : '-';

	  printf("%-70s %s\n", $key, $status);
	}
      }
    }
  }
}
