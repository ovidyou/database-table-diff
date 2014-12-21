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

  // Key representing current database connection.
  private $dbKey;

  // Key representing main database.
  private $mainDbKey;

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
    foreach($this->databases as $dbKey => &$dbConfig) {
      if (empty($dbConfig['driver'])) {
        throw new Exception("Invalid database configuration: 'driver' dbKey is mandatory.");
      }
      if (!in_array($dbConfig['driver'], array('sql', 'mysql', 'pgsql', 'sqlite'))) {
        throw new Exception("Invalid database configuration: driver '${dbConfig['driver']}' is not supported.");
      }
      if (empty($dbConfig['dbname'])) {
        throw new Exception("Invalid database configuration: 'dbname' dbKey is mandatory.");
      }
      // SQLite doesn't need other configuration options.
      if ($dbConfig['driver'] == 'sqlite') {
        $dbConfig['user'] = '';
        $dbConfig['pass'] = '';
        continue;
      }
      if (empty($dbConfig['user'])) {
        throw new Exception("Invalid database configuration: 'user' dbKey is mandatory.");
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
    foreach($this->databases as $dbKey => $dbConfig) {
      $dsn = $this->getDSN($dbConfig);
      $user = $dbConfig['user'];
      $pass = isset($dbConfig['pass']) ? $dbConfig['pass'] : '';

      try {
        $this->connections[$dbKey] = new PDO($dsn, $user, $pass);
      } catch (Exception $e) {
        throw new Exception("Could not connect to database '$dbKey'." . $e->getMessage());
      }

      // Set current connection dbKey to match the first database.
      if (!$this->dbKey) {
        $this->setDbKey($dbKey);
        $this->mainDbKey = $dbKey;
      }
    }
  }

  /**
   * Returns the current database connection.
   *
   * @return PDO instance
   */
  private function db() {
    return $this->connections[$this->dbKey];
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
   * Returns the configuration of the active database.
   *
   * @return array
   */
  private function getActiveDbConfig() {
    return $this->databases[$this->dbKey];
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
   * Returns the SQL statement for selecting all columns in a table.
   *
   * @param $table - Table name
   *
   * @return string
   */
  private function getTableColumnsSql($table) {
    $dbConfig = $this->getActiveDbConfig();

    switch($dbConfig['driver']) {
      case 'mysql':
      case 'sql':
        return "SHOW COLUMNS FROM $table";

      case 'pgsql':
        return "SELECT column_name FROM information_schema.columns WHERE table_name ='$table'";

      case 'sqlite':
        return "PRAGMA table_info('$table')";
    }
  }

  /**
   * Gets all tables from all configured databases,
   * or from a single database when $dbKey is specified.
   *
   * @param string $dbKey
   *
   * @return array
   */
  public function getTables($dbKey = '') {
    static $tables;

    if ($dbKey) {
      $this->setDbKey($dbKey);
      $sql = $this->getTablesSql();
      $query = $this->db()->query($sql);
      $keyTables = $query->fetchAll(PDO::FETCH_COLUMN);
      asort($keyTables);
      return $keyTables;
    }

    if (isset($tables)) {
      return $tables;
    }

    $tables = array();

    foreach ($this->databases as $dbKey => $dbConfig) {
      $tables[$dbKey] = $this->getTables($dbKey);
    }

    return $tables;
  }

  /**
   * Returns the diff between the tables of main database and other databases.
   *
   * @return array
   */
  public function getTablesDiff() {
    static $diffArr;

    if (isset($difArr)) {
      return $diffArr;
    }

    $tables = $this->getTables();
    $diffArr = array();

    $main_db_tables = $tables[$this->mainDbKey];
    unset($tables[$this->mainDbKey]);

    foreach ($tables as $key => $tableArr) {
      $diffArr[$this->mainDbKey . '#' . $key] = array(
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
    $tablesDiffArr = $this->getTablesDiff();
    $columnsDiffArr = $this->getTableColumnsDiff();
    $output = '';

    foreach ($tablesDiffArr as $key => $diff) {
      $dbNames = explode('#', $key);
      $leftDB = $dbNames[0];
      $rightDB = $dbNames[1];

      asort($diff['left']);
      asort($diff['right']);

      $leftTables = implode('<br>- ', $diff['left']);
      $rightTables = implode('<br>+ ', $diff['right']);

      if ($leftTables) {
        $leftTables = '<div style="color: darkred; margin-left: 20px;">- ' . $leftTables . '</div>';
      }

      if ($rightTables) {
        $rightTables = '<div style="color: darkgreen; margin-left: 20px;">+ ' . $rightTables . '</div>';
      }

      $columnsDiffOutput = '';

      foreach ($columnsDiffArr[$key] as $tableName => $columnsDiff) {
        asort($columnsDiff['left']);
        asort($columnsDiff['right']);

        $leftColumns = implode('<br>- ', $columnsDiff['left']);
        $rightColumns = implode('<br>+ ', $columnsDiff['right']);

        $columnsDiffOutput .= "<div>{<b>$tableName</b>}</div>";

        if ($leftColumns) {
          $columnsDiffOutput .= '<div style="color: darkred; margin-left: 20px;">- ' . $leftColumns . '</div>';
        }

        if ($rightColumns) {
          $columnsDiffOutput .= '<div style="color: darkgreen; margin-left: 20px;">- ' . $rightColumns . '</div>';
        }

        $columnsDiffOutput .= "<br>";

      }

      if ($columnsDiffOutput) {
        $columnsDiffOutput = '<div style="margin-left: 20px;">' . $columnsDiffOutput . '</div>';
      }
      else {
        $columnsDiffOutput = 'No difference has been found.';
      }



      $output .= <<<EOT
<h3 style="font-weight: normal"><strong>$rightDB</strong> vs. <strong>$leftDB</strong></h3>
<br><h4 style="margin-left: 20px;">TABLES DIFF:</h4>
$leftTables
$rightTables<br><br>
<h4 style="margin-left: 20px;">COLUMNS DIFF:</h4>
$columnsDiffOutput<br><br>
EOT;
    }

    if (!$htmlOutput) {
      $output = str_replace('<br>', "\n", $output);
      $output = str_replace('</h3>', "\n", $output);
      $output = str_replace('</h4>', "\n", $output);
      $output = str_replace('</div>', "\n", $output);
      $output = str_replace('<strong>', "'", $output);
      $output = str_replace('</strong>', "'", $output);
      $output = strip_tags($output);
    }

    return $output;
  }

  /**
   * Returns the column names for the specified table of the active database.
   *
   * @param $table
   *
   * @return array
   */
  public function getTableColumns($table) {
    $sql = $this->getTableColumnsSql($table);
    $query = $this->db()->query($sql);
    return $query->fetchAll(PDO::FETCH_COLUMN);
  }

  /**
   * Retrieves the column names for all tables of all databases.
   *
   * @return array
   */
  public function getAllTableColumns() {
    static $allTableColumns;

    if (isset($allTableColumns)) {
      return $allTableColumns;
    }

    $tables = $this->getTables();
    $allTableColumns = array();

    foreach($tables as $key => $tableArr) {
      $allTableColumns[$key] = array();
      $this->setDbKey($key);

      foreach ($tableArr as $k => $tableName) {
        $allTableColumns[$key][$tableName] = $this->getTableColumns($tableName);
      }
    }
    return $allTableColumns;
  }

  /**
   * Returns the diff between the tables of main database and other databases.
   *
   * @return array
   */
  public function getTableColumnsDiff() {
    static $diffArr;

    if (isset($diffArr)) {
      return $diffArr;
    }

    $diffArr = array();
    $allTableColumns = $this->getAllTableColumns();

    $mainDbTableColumns = $allTableColumns[$this->mainDbKey];
    unset($allTableColumns[$this->mainDbKey]);

    foreach ($allTableColumns as $dbKey => $tableArr) {
      $intersectArr = array_intersect(array_keys($mainDbTableColumns), array_keys($tableArr));
      $diffArr[$this->mainDbKey . '#' . $dbKey] = array();

      foreach($intersectArr as $k => $tableName) {
        $leftDiff = array_diff($mainDbTableColumns[$tableName], $tableArr[$tableName]);
        $rightDiff = array_diff($tableArr[$tableName], $mainDbTableColumns[$tableName]);

        if (!empty($leftDiff) || !empty($rightDiff)) {
          $diffArr[$this->mainDbKey . '#' . $dbKey][$tableName] = array(
            'left' => $leftDiff,
            'right' => $rightDiff,
          );
        }
      }
    }

    return $diffArr;
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
   * Sets the active dbKey.
   *
   * @param $dbKey
   */
  public function setDbKey($dbKey) {
    if (!isset($this->databases[$dbKey])) {
      throw new Exception("Invalid dbKey specified: '$dbKey'");
    }
    $this->dbKey = $dbKey;
  }

}