<?php

namespace GabrielSilva\SQLDumper;

use Exception;
use mysqli;
use mysqli_result;
use Throwable;

/**
 * SQLDumper instance.
 * @category SQL dumper
 * @package eugabrielsilva/sql-dumper
 * @author Gabriel Silva
 * @copyright Copyright (c) Gabriel Silva
 * @license MIT
 * @link https://github.com/eugabrielsilva/sql-dumper
 */
class SQLDumper
{

    /**
     * The database hostname.\
     * Default: `localhost`
     * @var string
     */
    public string $host = 'localhost';

    /**
     * The connection username.\
     * Default: `root`
     * @var string
     */
    public string $user = 'root';

    /**
     * The connection password.\
     * Default: `(empty)`
     * @var string
     */
    public string $password = '';

    /**
     * The database name to connect.\
     * Default: `app`
     * @var string
     */
    public string $db = 'app';

    /**
     * The connection port.\
     * Default: `3306`
     * @var int
     */
    public int $port = 3306;

    /**
     * The database charset.\
     * Default: `utf8`
     * @var string
     */
    public string $charset = 'utf8';

    /**
     * Tables to include in dump. Leave empty for all.
     * @var array
     */
    public array $includes = [];

    /**
     * Tables to exclude from dump. Leave empty for none.
     * @var array
     */
    public array $excludes = [];

    /**
     * Create tables in dump.\
     * Default: `true`
     * @var bool
     */
    public bool $createTables = true;

    /**
     * Create database in dump.\
     * Default: `false`
     * @var bool
     */
    public bool $createDatabase = false;

    /**
     * Drop tables in dump.\
     * Default: `false`
     * @var bool
     */
    public bool $dropTables = false;

    /**
     * Drop database in dump.\
     * Default: `false`
     * @var bool
     */
    public bool $dropDatabase = false;

    /**
     * Insert data in dump.\
     * Default: `true`
     * @var bool
     */
    public bool $insertData = true;

    /**
     * Delete data from table before inserting.\
     * Default: `false`
     * @var bool
     */
    public bool $deleteData = false;

    /**
     * Insert type (`INSERT`, `INSERT IGNORE` or `REPLACE`).\
     * Default: `INSERT`
     * @var string
     */
    public string $insertType = 'INSERT';

    /**
     * Use `IF EXISTS` and `IF NOT EXISTS` clauses in dump to prevent errors.\
     * Default: `true`
     */
    public bool $safeMode = true;

    /**
     * The connection instance.
     * @var mysqli|null
     */
    private ?mysqli $connection = null;

    public function __construct()
    {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    }

    /**
     * Connects to the database.
     * @return mysqli Returns the connection instance.
     */
    public function connect()
    {
        try {
            $this->connection = new mysqli($this->host, $this->user, $this->password, $this->db, $this->port);
            $this->connection->set_charset($this->charset);
            return $this->connection;
        } catch (Throwable $e) {
            throw new Exception("SQLDumper: [SQL {$e->getCode()}] {$e->getMessage()}", $e->getCode());
        }
    }

    /**
     * Dumps the SQL to a string.
     * @return string Returns the SQL dump.
     */
    public function dumpToString()
    {
        return $this->generateDump();
    }

    /**
     * Dumps the SQL to a file.
     * @param string $filename Target file to dump.
     * @return bool Returns true on success, false on failure.
     */
    public function dumpToFile(string $filename)
    {
        $dir = pathinfo($filename, PATHINFO_DIRNAME);
        if (!is_dir($dir) || !is_writable($dir)) throw new Exception('SQLDumper: Target directory does not exist or is not writable');
        $result = file_put_contents($filename, $this->generateDump());
        return $result !== false;
    }

    /**
     * Generates the SQL dump.
     * @return string Returns the SQL dump.
     */
    private function generateDump()
    {
        // Check connection
        if (!$this->connection instanceof mysqli) $this->connect();

        // Prepare result
        $result = '-- ----------------------------------------------------------------------------' . "\n";
        $result .= '-- Host:              ' . $this->host . "\n";
        $result .= '-- Server version:    ' . $this->connection->get_server_info() . "\n";
        $result .= '-- Generated in:      ' . date('m/d/Y H:i:s P e') . "\n";
        $result .= '-- Dump generated by: SQLDumper (https://github.com/eugabrielsilva/sql-dumper)' . "\n";
        $result .= '-- ----------------------------------------------------------------------------' . "\n\n";
        $result .= '/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;' . "\n";
        $result .= '/*!40101 SET NAMES ' . $this->connection->character_set_name() . ' */;' . "\n";
        $result .= '/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;' . "\n";
        $result .= '/*!40103 SET TIME_ZONE=\'+00:00\' */;' . "\n";
        $result .= '/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;' . "\n";
        $result .= '/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE=\'NO_AUTO_VALUE_ON_ZERO\' */;' . "\n";
        $result .= '/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;' . "\n\n";

        // DROP DATABASE statement
        if ($this->dropDatabase) $result .= "-- Deleting database {$this->db}\nDROP DATABASE" . ($this->safeMode ? ' IF EXISTS' : '') . " `{$this->db}`;\n\n";

        // CREATE DATABASE statement
        if ($this->createDatabase) $result .= "-- Creating database {$this->db}\nCREATE DATABASE" . ($this->safeMode ? ' IF NOT EXISTS' : '') . " `{$this->db}`;\n\n";

        // Get tables
        $tables = $this->query('SHOW TABLES');
        $tables = array_column($tables ?? [], 'Tables_in_' . $this->db);

        if (!empty($tables)) {
            foreach ($tables as $table) {
                // Check if table is included or excluded
                if (!empty($this->includes) && !in_array($table, $this->includes)) continue;
                if (!empty($this->excludes) && in_array($table, $this->excludes)) continue;

                // DROP TABLE statement
                if ($this->dropTables) $result .= "-- Deleting table $table\nDROP TABLE" . ($this->safeMode ? ' IF EXISTS' : '') . " `{$table}`;\n\n";

                // Get CREATE statemet
                if ($this->createTables) {
                    $createQuery = $this->query('SHOW CREATE TABLE `' . $table . '`', true);
                    if (!empty($createQuery[0]['Create Table'])) {
                        $result .= "-- Creating table $table\n";

                        if ($this->safeMode) {
                            $createQuery = $this->replaceFirst($createQuery[0]['Create Table'], 'CREATE TABLE', 'CREATE TABLE IF NOT EXISTS');
                        } else {
                            $createQuery = $createQuery[0]['Create Table'];
                        }

                        $result .= $createQuery . ";\n\n";
                    }
                }

                // Get data
                if (!$this->insertData) continue;
                $data = $this->query('SELECT * FROM `' . $table . '`', true);

                if (!empty($data)) {
                    // Insert DELETE statement
                    if ($this->deleteData) $result .= "-- Deleting data from $table\nTRUNCATE TABLE `$table`;\n\n";

                    // Get columns
                    $columns = array_keys($data[0]);

                    // Create INSERT statement
                    $result .= "-- Inserting data into $table\n";
                    $result .= $this->insertType . ' INTO `' . $table . '` (`' . implode('`, `', $columns) . "`) VALUES";

                    // Parse values
                    foreach ($data as $row) {
                        $value = '  (';
                        foreach ($row as $item) {
                            if (is_null($item)) {
                                $value .= 'NULL, ';
                            } else {
                                $value .= "'" . $this->connection->escape_string((string)$item) . "', ";
                            }
                        }
                        $value = rtrim($value, ', )') . '), ';
                        $result .= "\n" . $value;
                    }

                    $result = rtrim($result, ', ');
                    $result .= ";\n\n";
                }
            }
        }

        // Finish result
        $result .= '/*!40103 SET TIME_ZONE=IFNULL(@OLD_TIME_ZONE, \'system\') */;' . "\n";
        $result .= '/*!40101 SET SQL_MODE=IFNULL(@OLD_SQL_MODE, \'\') */;' . "\n";
        $result .= '/*!40014 SET FOREIGN_KEY_CHECKS=IFNULL(@OLD_FOREIGN_KEY_CHECKS, 1) */;' . "\n";
        $result .= '/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;' . "\n";
        $result .= '/*!40111 SET SQL_NOTES=IFNULL(@OLD_SQL_NOTES, 1) */;';
        return $result;
    }

    /**
     * Replaces the first ocurrence of a given substring in a string.
     * @param string $haystack The string to search in.
     * @param string $needle The substring to search for in the haystack.
     * @param string $replace The replacement string.
     * @return string Returns the resulting string.
     */
    private function replaceFirst(string $haystack, string $needle, string $replace)
    {
        $pos = mb_strpos($haystack, $needle);
        if ($pos !== false) $haystack = substr_replace($haystack, $replace, $pos, mb_strlen($needle));
        return $haystack;
    }

    /**
     * Execute an SQL query.
     * @param string $query Query to execute.
     * @return array|bool Returns an array with the results or a bool indicating query status.
     */
    private function query(string $query)
    {
        try {
            $query = $this->connection->query($query);
            if (!$query instanceof mysqli_result) return $query;
            $result = $query->fetch_all(MYSQLI_ASSOC);
            $query->close();
            return $result;
        } catch (Throwable $e) {
            throw new Exception("SQLDumper: [SQL {$e->getCode()}] {$e->getMessage()}", $e->getCode());
        }
    }
}
