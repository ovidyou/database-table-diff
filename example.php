<?php

// Include the class definition
require 'DatabaseTableDiff.php';

// Database configurations
// You can add as many databases as you wish,
// but note that the first one you declare will be compared with all the rest.

$databases['initial_db'] = array(
  'driver' => 'pgsql',
  'host' => 'localhost',
  'port' => '',
  'dbname' => 'my_initial_db',
  'user' => 'root',
  'pass' => 'root',
);

$databases['migrated_db'] = array(
  'driver' => 'mysql',
  'host' => 'localhost',
  'port' => '',
  'dbname' => 'my_migrated_db',
  'user' => 'root',
  'pass' => 'root',
);


// Create a new instance of DatabaseTableDiff.
$DTD = new DatabaseTableDiff($databases);

// Print a html formatted output of the tables differences.
print $DTD->getFormattedTablesDiff();

