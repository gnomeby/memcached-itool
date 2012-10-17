memcached-itool
===============

Improved memcached-tool on PHP

#### Requirements:
PHP 5.3

#### Usage
    memcached-tool <host[:port] | /path/to/socket> [mode]

##### Examples
    memcached-tool localhost:11211 display    # shows slabs information (display is default mode)
    memcached-tool localhost:11211 stats      # shows general stats
    memcached-tool localhost:11211 settings   # shows memcached settings
    memcached-tool localhost:11211 dumpkeys   # dumps only keys names and their status
    memcached-tool localhost:11211 dump       # dumps keys and values, values only for non expired keys
