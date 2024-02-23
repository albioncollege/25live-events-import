<?php

include( '../../../wp-load.php' );

// set the debug level
global $events_import_debug;

// set to 'log' to enable an output while actually adding events into the system
$events_import_debug = 'log';

// set to 'dump' to just display the full JSON object returned by the feed, 
// and not actually insert any data. helps with figuring out field IDs to 
// map values into their wp_postmeta records.
// $events_import_debug = 'dump';

do_25live_import();
