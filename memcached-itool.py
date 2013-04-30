# @author:  Andrey Niakhaichyk andrey@niakhaichyk.org
# @version: 1.0

import sys
import socket
import re

DEFAULT_TCP_TIMEOUT = 30
DEFAULT_UNIXSOCKET_TIMEOUT = 5

def myhelp():
  print """
Usage: memcached-tool <host[:port] | /path/to/socket> [mode]

  memcached-tool localhost:11211 display    # shows slabs information (display is default mode)
  memcached-tool localhost:11211 dumpkeys   # dumps only keys names
  memcached-tool localhost:11211 dump       # dumps keys and values, values only for non expired keys
  memcached-tool localhost:11211 removeexp  # remove expired keys (you may need run several times)
  memcached-tool localhost:11211 settings   # shows memcached settings
  memcached-tool localhost:11211 sizes      # group keys by sizes and show how many we waste memory
  memcached-tool localhost:11211 stats      # shows general stats

Warning! dumpkeys, dump, removeexp and sizes modes *will* lock up your cache! It iterates over *every item* and examines the size. 
While the operation is fast, if you have many items you could prevent memcached from serving requests for several seconds.

Warning! dump and removeexp modes influence on memcached internal statistic like *expired_unfetched* and *get_misses*. So we recommend only use it for debugging purposes.
"""
  return


def send_and_receive(sp, command):
  sp.send(command + '\r\n')

  result = ""
  while True:
    result += sp.recv(1024)
    if result.find("END" + '\r\n') >= 0: break

  lines = result.strip().split('\r\n')
  return lines[:-1]


def slabs_stats(sp):
  slabs = {'total': {}}

  lines = send_and_receive(sp, 'stats slabs')
  for line in lines:
    properties = re.match('^STAT (\d+):(\w+) (\d+)', line)
    if properties:
      slab_num, slab_property, slab_value = properties.groups()
      slab_num = int(slab_num)
      slab_value = int(slab_value)
      if slab_num not in slabs:
        slabs[slab_num] = {}
      slabs[slab_num][slab_property] = slab_value
      continue

    statistic = re.match('^STAT (\w+) (\d+)', line)
    if statistic:
      stat_property, stat_value = statistic.groups()
      slabs['total'][stat_property] = int(stat_value)

    pass


  lines = send_and_receive(sp, 'stats items')
  for line in lines:
    items_stat = re.match('^STAT items:(\d+):(\w+) (\d+)', line)
    if items_stat:
      slab_num, slab_property, slab_value = items_stat.groups()
      slabs[int(slab_num)][slab_property] = int(slab_value)
      continue

    pass


  for num, slab in slabs.iteritems():
    if num == 'total': continue

    slab['age'] = slab['age'] if 'age' in slab else 0
    slab['number'] = slab['number'] if 'number' in slab else 0
    slab['evicted'] = slab['evicted'] if 'evicted' in slab else 0
    slab['evicted_time'] = slab['evicted_time'] if 'evicted_time' in slab else 0
    slab['outofmemory'] = slab['outofmemory'] if 'outofmemory' in slab else 0
  
  return slabs


def descriptive_size(size):
  if size >= 1024*1024: return '{0:.1f}M'.format(float(size) / (1024*1024))
  if size >= 1024: return '{0:.1f}K'.format(float(size) / 1024)

  return str(size)+'B'


def get_stats(sp, command = 'stats'):
  stats = {}

  lines = send_and_receive(sp, command)
  for line in lines:
    items_stat = re.match('^STAT ([^\s]+) ([^\s]+)', line)
    if items_stat:
      sett_property, sett_value = items_stat.groups()
      stats[sett_property] = sett_value

  return stats


def show_stats(sp, command = 'stats'):
  stats = get_stats(sp, command)

  print "{0:>24s} {1:>15s}".format("Field", "Value")
  for prop in sorted(stats.keys()):
    print "{0:>24s} {1:>15s}".format(prop, stats[prop])

  return


def display(sp):
  slabs = slabs_stats(sp)

  print "  # Chunk_Size  Max_age   Pages   Count   Full?  Evicted Evict_Time OOM     Used   Wasted"

  for num, slab in slabs.iteritems():
    if num == 'total': continue

    is_slab_full = "yes" if slab['free_chunks_end'] == 0 else "no"
    wasted = (1.0 - float(slab['mem_requested']) / (float(slab['chunk_size']) * float(slab['number']))) * 100 if slab['number'] else 0.0

    print "{0:3d} {1:>10s} {2:7d}s {3:7d} {4:7d} {5:>7s} {6:8d} {7:10d} {8:3d} {9:>8s} {10:7.0f}%".format(
      num, descriptive_size(slab['chunk_size']), slab['age'],
      slab['total_pages'], slab['number'], is_slab_full, 
      slab['evicted'], slab['evicted_time'], slab['outofmemory'],
      descriptive_size(slab['mem_requested']), wasted)
  
  print "\n"+"Total:"
  for prop, val in slabs['total'].iteritems():
    if prop == 'total_malloced':
      print "{0:15s} {1:>12s}".format(prop, descriptive_size(val))
    else:
      print "{0:15s} {1:>12d}".format(prop, val)

  # Calculate possible allocated memory
  stats = get_stats(sp, 'stats settings')
  item_size_max = int(stats['item_size_max'])
  maxbytes = int(stats['maxbytes'])
  pages = 1
  chunk_size = 96.0
  while chunk_size * float(stats['growth_factor']) < item_size_max:
    pages += 1
    chunk_size *= float(stats['growth_factor'])

  real_min = descriptive_size(max(item_size_max * pages, maxbytes))
  real_max = descriptive_size(item_size_max * (pages + maxbytes / item_size_max - 1))
  print "{0:15s} {1:>12s} (real {2} - {3})".format('maxbytes', descriptive_size(maxbytes), real_min, real_max)

  # Other settings & statistic
  print "{0:15s} {1:>12s}".format('item_size_max', descriptive_size(item_size_max))
  print "{0:15s} {1:>12s}".format('evictions', stats['evictions'])
  print "{0:15s} {1:>12s}".format('growth_factor', str(stats['growth_factor']))
  
  return



# Default values
host = 'localhost'
port = 11211
socketpath = None
mode = 'display'

# Parse command line
if len(sys.argv) > 1:
  if sys.argv[1].find('/') == 0:
    socketpath = sys.argv[1]
    host = port = None
  elif sys.argv[1].find(':') > 0:
    host, port = sys.argv[1].split(':')
  else:
    host = sys.argv[1]
if len(sys.argv) > 2:
  mode = sys.argv[2]

# All modes
modes = ('display', 'dumpkeys', 'dump', 'removeexp', 'settings', 'stats', 'sizes')

# Check params
if len(sys.argv) < 2 or mode not in modes:
  myhelp()
  exit()


# Connect to memcached
try:
  if host and port:
    sp = socket.create_connection((host, port), DEFAULT_TCP_TIMEOUT)
  else:
    sp = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
    sp.connect(socketpath)
except socket.error as msg:
  print msg
  exit()
else:
  if mode == 'stats':
    show_stats(sp)
  elif mode == 'settings':
    show_stats(sp, 'stats settings')
  else:
    display(sp)

  sp.close()


exit()