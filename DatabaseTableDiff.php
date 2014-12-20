<?php
/**
 * Database Table Diff - A quick way to compare databases from multiple sources
 * (MySQL, PostgreSQL, SQLite) and see differences between table structures.
 *
 * @author Ovi Indrei <ovidiu.indrei@gmail.com>
 */

class DatabaseTableDiff {

  // Configuration array for all databases.
  private $databases;

  // Array with database connections.
  private $connections;

  // Key representing current connection.
  private $key;

  // Array storing tables for all databases;
  private $tables;

  /**
   * Constructor
   *
   * @param array $databases - Configuration array for all databases.
   */
  public function __construct($databases = array()) {
    $this->databases = $databases;
    $this->checkConfiguration();
    $this->connect();
  }

  /**
   * Check configuration.
   */
  private function checkConfiguration() {
    foreach($this->databases as $key => &$dbConfig) {
      if (empty($dbConfig['driver'])) {
        throw new Exception("Invalid database configuration: 'driver' key is mandatory.");
      }
      if (!in_array($dbConfig['driver'], array('sql', 'mysql', 'pgsql', 'sqlite'))) {
        throw new Exception("Invalid database configuration: driver '${dbConfig['driver']}' is not supported.");
      }
      if (empty($dbConfig['dbname'])) {
        throw new Exception("Invalid database configuration: 'dbname' key is mandatory.");
      }
      // SQLite doesn't need other configuration options.
      if ($dbConfig['driver'] == 'sqlite') {
        $dbConfig['user'] = '';
        $dbConfig['pass'] = '';
        continue;
      }
      if (empty($dbConfig['user'])) {
        throw new Exception("Invalid database configuration: 'user' key is mandatory.");
      }
      if (empty($dbConfig['dsn']) && empty($dbConfig['host']) && empty($dbConfig['unix_host'])) {
        throw new Exception("Invalid database configuration: missing one of the mutually exclusive keys: 'dsn', 'unix_host' or 'host'.");
      }
    }
  }

  /**
   * Connect to all databases.
   */
  private function connect() {
    foreach($this->databases as $key => $dbConfig) {
      $dsn = $this->getDSN($dbConfig);
      $user = $dbConfig['user'];
      $pass = isset($dbConfig['pass']) ? $dbConfig['pass'] : '';

      try {
        $this->connections[$key] = new PDO($dsn, $user, $pass);
      } catch (Exception $e) {
        throw new Exception("Could not connect to database '$key'." . $e->getMessage());
      }

      // Set current connection key to match the first database.
      if (!$this->key) {
        $this->setKey($key);
      }
    }
  }

  /**
   * Returns the current database connection.
   *
   * @return PDO instance
   */
  private function db() {
    return $this->connections[$this->key];
  }

  /**
   * Forms the Data Source Name string (DSN) for a database configuration.
   * This will be used by PDO to connect to the database.
   *
   * @param $dbConfig - a database configuration array.
   *
   * @return string
   */
  private function getDSN($dbConfig) {
    $dsn = !empty($dbConfig['dsn']) ? $dbConfig['dsn'] : '';
    if (!$dsn) {
      $driver = $dbConfig['driver'];

      if ($driver == 'sqlite') {
        $dsn = 'sqlite:' . $dbConfig['dbname'];
      }
      else {
        $port = !empty($dbConfig['port']) ? ';port='. $dbConfig['port'] : '';
        $host = !empty($dbConfig['unix_host']) ? 'unix_host' . $dbConfig['unix_host'] :  'host=' . $dbConfig['host'];

        $dsn = $driver . ':' . $host . $port . ';dbname=' . $dbConfig['dbname'];
      }
    }

    return $dsn;
  }

  /**
   * Returns the driver of the active database.
   *
   * @return string
   */
  private function getActiveDriver() {
    return $this->databases[$this->key]['driver'];
  }

  /**
   * Returns the configuration of the active database.
   *
   * @return array
   */
  private function getActiveDbConfig() {
    return $this->databases[$this->key];
  }

  /**
   * Returns the SQL statement for selecting all tables in a database.
   *
   * @return string
   */
  private function getTablesSql() {
    $dbConfig = $this->getActiveDbConfig();

    switch($dbConfig['driver']) {
      case 'mysql':
      case 'sql':
        return "SHOW TABLES";

      case 'pgsql':
        $schema = !empty($dbConfig['schema']) ? $dbConfig['schema'] : 'public';
        return "SELECT table_name FROM information_schema.tables
                WHERE table_type = 'BASE TABLE' AND table_schema = '$schema'";

      case 'sqlite':
        $dbname = $dbConfig['dbname'];
        return "SELECT * FROM $dbname.sqlite_master WHERE type='table';";
    }
  }

  /**
   * Gets all tables from all configured databases,
   * or from a single database when $key is specified.
   *
   * @param string $key
   *
   * @return array
   */
  public function getTables($key = '') {
    if ($key) {
      $this->setKey($key);
      $sql = $this->getTablesSql();
      $query = $this->db()->query($sql);
      return $query->fetchAll(PDO::FETCH_COLUMN);
    }
    else {
      if (!empty($this->tables)) {
        return $this->tables;
      }
      $tables = array();
      foreach ($this->databases as $key => $dbConfig) {
        $tables[$key] = $this->getTables($key);
      }
      $this->tables = $tables;
      return $tables;
    }
  }

  /**
   * Returns the diff between the tables of main database and other databases.
   *
   * @return array
   */
  public function getTablesDiff() {
    $tables = !empty($this->tables) ? $this->tables : $this->getTables();
    $diffArr = array();

    $keys = array_keys($this->databases);
    $main_db_key = array_shift($keys);
    $main_db_tables = array_shift($tables);

    foreach ($tables as $key => $tableArr) {
      $diffArr[$main_db_key . '#' . $key] = array(
        'left' => array_diff($main_db_tables, $tableArr),
        'right' => array_diff($tableArr, $main_db_tables),
      );
    }

    return $diffArr;
  }

  /**
   * Returns a formatted diff for tables.
   *
   * @param bool $htmlOutput
   *
   * @return string
   */
  public function getFormattedTablesDiff($htmlOutput = TRUE) {
    $diffArr = $this->getTablesDiff();
    $output = '';

    foreach ($diffArr as $key => $diff) {
      $dbNames = explode('#', $key);
      $leftDB = $dbNames[0];
      $rightDB = $dbNames[1];

      asort($diff['left']);
      asort($diff['right']);

      $leftTables = implode('<br>- ', $diff['left']);
      $rightTables = implode('<br>+ ', $diff['right']);

      if ($leftTables) {
        $leftTables = '<div style="color: darkred">- ' . $leftTables . '</div>';
      }

      if ($rightTables) {
        $rightTables = '<div style="color: darkgreen">+ ' . $rightTables . '</div>';
      }

      $output .= <<<EOT
<h3 style="font-weight: normal"><strong>$rightDB</strong> tables compared to <strong>$leftDB</strong> tables</h3>
$leftTables
$rightTables<br><br>
EOT;
    }

    if (!$htmlOutput) {
      $output = str_replace('<br>', "\n", $output);
      $output = str_replace('</h3>', "\n", $output);
      $output = str_replace('<strong>', "'", $output);
      $output = str_replace('</strong>', "'", $output);
      $output = strip_tags($output);
    }

    return $output;
  }

  /**
   * Prints a string, array or object to the standard output.
   *
   * @param $str
   */
  public function write($str) {
    print '<pre>' . print_r($str, true) . '</pre>';
  }

  /**
   * Sets the active key.
   *
   * @param $key
   */
  public function setKey($key) {
    if (!isset($this->databases[$key])) {
      throw new Exception("Invalid key specified: '$key'");
    }
    $this->key = $key;
  }

}