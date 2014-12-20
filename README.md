# Database Table Diff
##### A quick way to compare databases from multiple sources (MySQL, PostgreSQL, SQLite) and see differences between table structures.
---
Current status: compares only table names from databases.

## Features
- Saves time comparing database table structures
- Supports MySQL, PostgreSQL, SQLite
- Cross-comparision between different database engines
- Easy setup and configuration
- Compatible with PHP 5.0 and later

## Why you might need it

Migrating database schema changes from dev, to test, to production can end up unsuccessfull at times. 
You need a way to quickly check if there are differences between databases and this is where this tool comes handy.

After migrating a database to a new database engine (e.g. from MySQL to PostgreSQL), you need to ensure that all tables and their respective columns have been successfully migrated.

There are many other scenarios when you would need a quick way to compare databases.

## License

This software is licenced under the [GPL 3](http://www.gnu.org/licenses/gpl.html). Please read LICENSE for information on the software availability and distribution.

## Setup / Configuration

Before you can use it, you need to ensure you have the [PDO](http://php.net/manual/en/book.pdo.php) Extension installed on your server.

Then you can just copy the files under this project into a folder that is accessible to a web server, so you can run it in your browser.

An example configuration can be seen in the provided *example.php*, or below:

```php
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
```

## TODO

- Add column names diff for tables
- Add column properties diff for tables
- Support more database engines
