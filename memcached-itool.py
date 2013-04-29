# @author:  Andrey Niakhaichyk andrey@niakhaichyk.org
# @version: 1.0

import sys
import socket

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
  sp.close()


exit()