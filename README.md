memcached-itool
===============

Improved memcached-tool on PHP

#### Requirements:
PHP 5.3

#### Usage
    memcached-tool <host[:port] | /path/to/socket> [mode]

##### Examples
    memcached-tool localhost:11211 display    # shows slabs information (display is default mode)
    memcached-tool localhost:11211 dumpkeys   # dumps only keys names and their status
    memcached-tool localhost:11211 dump       # dumps keys and values, values only for non expired keys
    memcached-tool localhost:11211 settings   # shows memcached settings
    memcached-tool localhost:11211 sizes      # group keys by sizes and show how many we waste memory
    memcached-tool localhost:11211 stats      # shows general stats

*Warning!* dumpkeys, dump and sizes modes *will* lock up your cache! It iterates over *every item* and examines the size. 
While the operation is fast, if you have many items you could prevent memcached from serving requests for several seconds.