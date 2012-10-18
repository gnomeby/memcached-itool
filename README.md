memcached-itool
===============

The improved memcached-tool on PHP.

New advantages in comparison with default tool are:
* *display* mode shows all slabs not only slabs with keys
* *display* mode also shows percent of wasted memory in chunks
* *dump* mode doesn''t trigger deletion of expired keys
* New *removeexp* mode triggers deletion of expired keys
* New *dumpkeys* mode only shows key names
* New *sizes* mode groups keys by size and shows percent of wasted memory in chunks
* New *settings* mode shows memcached setting during startup


#### Requirements:
PHP 5.3

#### Usage
    memcached-tool <host[:port] | /path/to/socket> [mode]

##### Examples
    memcached-tool localhost:11211 display    # shows slabs information (display is default mode)
    memcached-tool localhost:11211 dumpkeys   # dumps only keys names and their status
    memcached-tool localhost:11211 dump       # dumps keys and values, values only for non expired keys
    memcached-tool localhost:11211 removeexp  # remove expired keys (you may need run several times)
    memcached-tool localhost:11211 settings   # shows memcached settings
    memcached-tool localhost:11211 sizes      # group keys by sizes and show how many we waste memory
    memcached-tool localhost:11211 stats      # shows general stats

*Warning!* dumpkeys, dump, removeexp and sizes modes *will* lock up your cache! It iterates over *every item* and examines the size. 
While the operation is fast, if you have many items you could prevent memcached from serving requests for several seconds.

*Warning!* dump and removeexp modes influence on memcached internal statistic like *expired_unfetched* and *get_misses*. So we recommend only use it for debugging purposes.