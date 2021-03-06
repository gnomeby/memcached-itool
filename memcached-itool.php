<?php
/**
 * @author: Andrey Niakhaichyk andrey@niakhaichyk.org
 * @version: 1.0
 */

const DEFAULT_TCP_TIMEOUT = 30;
const DEFAULT_UNIXSOCKET_TIMEOUT = 5;

const DUMPMODE_ONLYKEYS = 0;
const DUMPMODE_KEYVALUES = 1;
const REMOVEMODE_EXPIRED = 2;

$params = $_SERVER['argv'];

// Parse command line
$host = 'localhost';
$port = 11211;
$socketpath = NULL;
$mode = 'display';
if(!empty($params[1]))
{
  if(strpos($params[1], '/') === 0)
  {
    $socketpath = $params[1];
    $host = NULL;
    $port = NULL;
  }
  elseif(strpos($params[1], ':') > 0)
    list($host, $port) = explode(':', $params[1]);
  else
    $host = $params[1];
}
if(!empty($params[2]))
{
  $mode = $params[2];
}

// Check params
if(count($params) < 2 || !in_array($mode, array('display', 'dumpkeys', 'dump', 'removeexp', 'settings', 'stats', 'sizes')))
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
  case 'settings':
    show_stats($fp, 'stats settings');
    break;

  case 'stats':
    show_stats($fp, 'stats');
    break;

  case 'sizes':
    sizes($fp);
    break;

  case 'dumpkeys':
    iterkeys($fp, DUMPMODE_ONLYKEYS);
    break;

  case 'removeexp':
    iterkeys($fp, REMOVEMODE_EXPIRED);
    break;

  case 'dump':
    iterkeys($fp, DUMPMODE_KEYVALUES);
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
 Usage: memcached-itool <host[:port] | /path/to/socket> [mode]

	memcached-itool localhost:11211 display    # shows slabs information (display is default mode)
	memcached-itool localhost:11211 dumpkeys   # dumps only keys names
	memcached-itool localhost:11211 dump       # dumps keys and values, values only for non expired keys
	memcached-itool localhost:11211 removeexp  # remove expired keys (you may need run several times)
	memcached-itool localhost:11211 settings   # shows memcached settings
	memcached-itool localhost:11211 sizes      # group keys by sizes and show how many we waste memory
	memcached-itool localhost:11211 stats      # shows general stats

Warning! dumpkeys, dump, removeexp and sizes modes *will* lock up your cache! It iterates over *every item* and examines the size. 
While the operation is fast, if you have many items you could prevent memcached from serving requests for several seconds.

Warning! dump and removeexp modes influence on memcached internal statistic like *expired_unfetched* and *get_misses*. So we recommend only use it for debugging purposes.

HELP;
}


function send_and_receive($fp, $command)
{
  fwrite($fp, $command."\r\n");
  $result = '';
  while (!feof($fp))
  {
    $result .= fgets($fp);

    if(strpos($result, 'END'."\r\n") !== FALSE)
      break;
  }
  
  $lines = explode("\r\n", $result);
  foreach($lines as $key=>$line)
  {
    if(strlen($line) == 0 || trim($line) == 'END')
      unset($lines[$key]);
  }

  return $lines;
}


function slabs_stats($fp)
{
  $slabs = array();

  $lines = send_and_receive($fp, 'stats slabs');
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

  $lines = send_and_receive($fp, 'stats items');
  foreach($lines as $line)
  {
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
    if($num == 'total')
      continue;

    $slab['age'] = !empty($slab['age']) ? $slab['age'] : 0;
    $slab['number'] = !empty($slab['number']) ? $slab['number'] : 0;
    $slab['evicted'] = !empty($slab['evicted']) ? $slab['evicted'] : 0;
    $slab['evicted_time'] = !empty($slab['evicted_time']) ? $slab['evicted_time'] : 0;
    $slab['outofmemory'] = !empty($slab['outofmemory']) ? $slab['outofmemory'] : 0;

    $slabs[$num] = $slab;
  }
  ksort($slabs);

  return $slabs;
}


function get_stats($fp, $command = 'stats')
{
  $stats = array();

  $lines = send_and_receive($fp, $command);
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
  ksort($stats);

  return $stats;
}


function settings_stats($fp)
{
  $stats = array();

  $lines = send_and_receive($fp, 'stats settings');
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

  return $stats;
}


function display($fp)
{
  $slabs = slabs_stats($fp);

  print "  # Chunk_Size  Max_age   Pages   Count   Full?  Evicted Evict_Time OOM     Used   Wasted".PHP_EOL;
  foreach($slabs as $num => $slab) 
  {
    if($num == 'total')
      continue;

    $is_slab_full = $slab['free_chunks_end'] == 0 ? "yes" : "no";
    $wasted = $slab['number'] ? (1.0 - (float)$slab['mem_requested'] / ($slab['chunk_size'] * $slab['number'])) * 100 : 0.0;

    printf("%3d %10s %7ds %7d %7d %7s %8d %10d %3d %8s %7d%%".PHP_EOL, $num, descriptive_size($slab['chunk_size']), $slab['age'], $slab['total_pages'], $slab['number'], $is_slab_full, 
      $slab['evicted'], $slab['evicted_time'], $slab['outofmemory'], descriptive_size($slab['mem_requested']), $wasted);
  }

  print PHP_EOL."Total:".PHP_EOL;
  foreach($slabs['total'] as $property=>$value)
  {
    if($property == 'total_malloced')
      printf("%-15s %12s".PHP_EOL, $property, descriptive_size($value));
    else
      printf("%-15s %12s".PHP_EOL, $property, $value);
  }

  $stats = settings_stats($fp);
  $pages = 1;
  for($chunk_size = 96; $chunk_size * $stats['growth_factor'] < $stats['item_size_max']; $chunk_size *= $stats['growth_factor'])
    $pages++;
  printf("%-15s %12s (real %s - %s)".PHP_EOL, 'maxbytes', descriptive_size($stats['maxbytes']), descriptive_size(max($stats['item_size_max'] * $pages, $stats['maxbytes'])), descriptive_size($stats['item_size_max'] * ($pages + $stats['maxbytes'] / $stats['item_size_max'] - 1)));
  
  printf("%-15s %12s".PHP_EOL, 'item_size_max', descriptive_size($stats['item_size_max']));
  printf("%-15s %12s".PHP_EOL, 'evictions', $stats['evictions']);
  printf("%-15s %12s".PHP_EOL, 'growth_factor', $stats['growth_factor']);

}


function show_stats($fp, $command = 'stats')
{
  $stats = get_stats($fp, $command);
  ksort($stats);

  printf ("%24s %15s".PHP_EOL, "Field", "Value");
  foreach($stats as $property => $value)
    printf ("%24s %15s".PHP_EOL, $property, $value);
}


function iterkeys($fp, $dumpmode = DUMPMODE_ONLYKEYS)
{
  $slabs = slabs_stats($fp);
  $stats = get_stats($fp, 'stats');
  ksort($slabs);

  printf("      %-40s %20s %10s %8s".PHP_EOL, 'Key', 'Expire status', 'Size', 'Waste');
  foreach($slabs as $num => $slab) 
  {
    if($num == 'total')
      continue;

    if($slab['number'])
    {
      $lines = send_and_receive($fp, "stats cachedump {$num} {$slab['number']}");
      foreach($lines as $line)
      {
        $m = array();
        if(preg_match('/^ITEM ([^\s]+) \[(\d+) b; (\d+) s\]/', $line, $m))
        {
          $key = $m[1];
          $size = $m[2];

          $now = time();

          $waste = (1.0 - (float)$size / $slab['chunk_size']) * 100;
          $expiration_time = $m[3];
          if($expiration_time == $stats['time'] - $stats['uptime'])
            $status = '[never expire]';
          elseif($now > $expiration_time)
            $status = '[expired]';
          else
            $status = ($expiration_time - $now).'s left';

          printf("ITEM  %-40s %20s %10s %7.0f%%".PHP_EOL, $key, $status, $size, $waste);

          // Get value
          if($dumpmode == DUMPMODE_KEYVALUES && $status != '[expired]')
          {
            $lines = send_and_receive($fp, "get {$key}");
            if(count($lines))
            {
              $info = $lines[0];
              $data = $lines[1];
              preg_match('/^VALUE ([^\s]+) (\d+) (\d+)/', $info, $m);
              $flags = $m[2];
              printf("VALUE %-40s flags=%X".PHP_EOL, $key, $flags);
              printf("%s".PHP_EOL, $data);
            }
          }

          // Get value
          if($dumpmode == REMOVEMODE_EXPIRED && $status == '[expired]')
          {
            $lines = send_and_receive($fp, "get {$key}");
          }
        }
      }
    }
  }
}


function sizes($fp)
{
  $sizes = array();
  $stats = settings_stats($fp);

  printf("%-10s %10s %10s %10s".PHP_EOL, 'Size', 'Items', 'Chunk_Size', 'Wasted');

  $lines = send_and_receive($fp, 'stats sizes');
  foreach($lines as $line)
  {
    $m = array();
    if(preg_match('/^STAT ([^\s]+) ([^\s]+)/', $line, $m))
    {
      $size = $m[1];
      $values = $m[2];

      for($chunk_size = 96; $chunk_size * $stats['growth_factor'] < $size; $chunk_size *= $stats['growth_factor']);
      $chunk_size *= $stats['growth_factor'];
      if($chunk_size * $stats['growth_factor'] > $stats['item_size_max'])
        $chunk_size = $stats['item_size_max'];

      $wasted = (1.0 - $size / $chunk_size) * 100;

      printf("%-10s %10d %10s %9.0f%%".PHP_EOL, descriptive_size($size), $values, descriptive_size((int)$chunk_size), $wasted);

      $sizes[$size] = $values;
    }
  }
}


function descriptive_size($size)
{
  if($size >= 1024*1024)
    return sprintf('%.1fM', (float)$size/(1024*1024));
  if($size >= 1024)
    return sprintf('%.1fK', (float)$size/(1024));
  return $size.'B';
}