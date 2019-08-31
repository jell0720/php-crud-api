<?php

namespace Tqdev\PhpCrudApi\Database;

use Tqdev\PhpCrudApi\Column\Reflection\ReflectedTable;
use Tqdev\PhpCrudApi\Middleware\Communication\VariableStore;
use Tqdev\PhpCrudApi\Record\Condition\ColumnCondition;
use Tqdev\PhpCrudApi\Record\Condition\Condition;

class GenericDB
{
    private $driver;
    private $address;
    private $port;
    private $database;
    private $username;
    private $password;
    private $dsn;
    private $pdo;
    private $reflection;
    private $definition;
    private $conditions;
    private $columns;
    private $converter;
    private $commandsOverride;
    private $createSingleReturnOverride;

    private function getDsn(): string
    {
        if ($this->dsn) {
            return $this->dsn;
        }
        switch ($this->driver) {
            case 'mysql':
                return "$this->driver:host=$this->address;port=$this->port;dbname=$this->database;charset=utf8mb4";
            case 'pgsql':
                return "$this->driver:host=$this->address port=$this->port dbname=$this->database options='--client_encoding=UTF8'";
            case 'sqlsrv':
                return "$this->driver:Server=$this->address,$this->port;Database=$this->database";
            default:
                return '';
        }
    }

    private function getCommands(): array
    {
        if ($retval = $this->checkGetCommandsOverride()) {
            return $retval;
        }

        switch ($this->driver) {
            case 'mysql':
                return [
                    'SET SESSION sql_warnings=1;',
                    'SET NAMES utf8mb4;',
                    'SET SESSION sql_mode = "ANSI,TRADITIONAL";',
                ];
            case 'pgsql':
                return [
                    "SET NAMES 'UTF8';",
                ];
            case 'sqlsrv':
                return [];
            default:
                return [];
        }

    }

    private function getOptions(): array
    {
        $options = array(
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        );
        switch ($this->driver) {
            case 'mysql':
                return $options + [
                    \PDO::ATTR_EMULATE_PREPARES => false,
                    \PDO::MYSQL_ATTR_FOUND_ROWS => true,
                    \PDO::ATTR_PERSISTENT => true,
                ];
            case 'pgsql':
                return $options + [
                    \PDO::ATTR_EMULATE_PREPARES => false,
                    \PDO::ATTR_PERSISTENT => true,
                ];
            case 'sqlsrv':
                return $options + [
                    \PDO::SQLSRV_ATTR_DIRECT_QUERY => false,
                    \PDO::SQLSRV_ATTR_FETCHES_NUMERIC_TYPE => true,
                ];
            default:
                return $options;
        }
    }

    private function initPdo(): bool
    {
        if ($this->pdo) {
            $result = $this->pdo->reconstruct($this->getDsn(), $this->username, $this->password, $this->getOptions());
        } else {
            $this->pdo = new LazyPdo($this->getDsn(), $this->username, $this->password, $this->getOptions());
            $result = true;
        }
        $commands = $this->getCommands();
        foreach ($commands as $command) {
            $this->pdo->addInitCommand($command);
        }
        $this->reflection = new GenericReflection($this->pdo, $this->driver, $this->database);
        $this->definition = new GenericDefinition($this->pdo, $this->driver, $this->database);
        $this->conditions = new ConditionsBuilder($this->driver);
        $this->columns = new ColumnsBuilder($this->driver);
        $this->converter = new DataConverter($this->driver);
        return $result;
    }

    public function __construct(string $driver, string $address, int $port, string $database, string $username, string $password, string $dsn)
    {
        $this->driver = $driver;
        $this->address = $address;
        $this->port = $port;
        $this->database = $database;
        $this->username = $username;
        $this->password = $password;
        $this->dsn = $dsn;
        
        $this->initPdo();
    }

    public function reconstruct(string $driver, string $address, int $port, string $database, string $username, string $password, string $dsn): bool
    {
        if ($driver) {
            $this->driver = $driver;
        }
        if ($address) {
            $this->address = $address;
        }
        if ($port) {
            $this->port = $port;
        }
        if ($database) {
            $this->database = $database;
        }
        if ($username) {
            $this->username = $username;
        }
        if ($password) {
            $this->password = $password;
        }
        if ($dsn) {
            $this->dsn = $dsn;
        }
        return $this->initPdo();
    }

    public function pdo(): LazyPdo
    {
        return $this->pdo;
    }

    public function reflection(): GenericReflection
    {
        return $this->reflection;
    }

    public function definition(): GenericDefinition
    {
        return $this->definition;
    }

    private function addMiddlewareConditions(string $tableName, Condition $condition): Condition
    {
        $condition1 = VariableStore::get("authorization.conditions.$tableName");
        if ($condition1) {
            $condition = $condition->_and($condition1);
        }
        $condition2 = VariableStore::get("multiTenancy.conditions.$tableName");
        if ($condition2) {
            $condition = $condition->_and($condition2);
        }
        return $condition;
    }

    public function createSingle(ReflectedTable $table, array $columnValues) /*: ?String*/
    {
        $this->converter->convertColumnValues($table, $columnValues);
        $insertColumns = $this->columns->getInsert($table, $columnValues);
        $tableName = $table->getName();
        $pkName = $table->getPk()->getName();
        $parameters = array_values($columnValues);
        $sql = 'INSERT INTO "' . $tableName . '" ' . $insertColumns;
        $stmt = $this->query($sql, $parameters);
        // return primary key value if specified in the input
        if (isset($columnValues[$pkName])) {
            return $columnValues[$pkName];
        }
        // work around missing "returning" or "output" in mysql
        switch ($this->driver) {
            case 'mysql':
                $stmt = $this->query('SELECT LAST_INSERT_ID()', []);
                break;
        }
        $pkValue = $stmt->fetchColumn(0);
        if ($this->driver == 'sqlsrv' && $table->getPk()->getType() == 'bigint') {
            return (int) $pkValue;
        }

        if (is_callable($this->createSingleReturnOverride)) {
            if ($retval = $this->checkCreateSingleReturnOverride([$table, $pkValue])) {
                return $retval;
            }
        }

        return $pkValue;
    }

    public function selectSingle(ReflectedTable $table, array $columnNames, string $id) /*: ?array*/
    {
        $selectColumns = $this->columns->getSelect($table, $columnNames);
        $tableName = $table->getName();
        $condition = new ColumnCondition($table->getPk(), 'eq', $id);
        $condition = $this->addMiddlewareConditions($tableName, $condition);
        $parameters = array();
        $whereClause = $this->conditions->getWhereClause($condition, $parameters);
        $sql = 'SELECT ' . $selectColumns . ' FROM "' . $tableName . '" ' . $whereClause;
        $stmt = $this->query($sql, $parameters);
        $record = $stmt->fetch() ?: null;
        if ($record === null) {
            return null;
        }
        $records = array($record);
        $this->converter->convertRecords($table, $columnNames, $records);
        return $records[0];
    }

    public function selectMultiple(ReflectedTable $table, array $columnNames, array $ids): array
    {
        if (count($ids) == 0) {
            return [];
        }
        $selectColumns = $this->columns->getSelect($table, $columnNames);
        $tableName = $table->getName();
        $condition = new ColumnCondition($table->getPk(), 'in', implode(',', $ids));
        $condition = $this->addMiddlewareConditions($tableName, $condition);
        $parameters = array();
        $whereClause = $this->conditions->getWhereClause($condition, $parameters);
        $sql = 'SELECT ' . $selectColumns . ' FROM "' . $tableName . '" ' . $whereClause;
        $stmt = $this->query($sql, $parameters);
        $records = $stmt->fetchAll();
        $this->converter->convertRecords($table, $columnNames, $records);
        return $records;
    }

    public function selectCount(ReflectedTable $table, Condition $condition): int
    {
        $tableName = $table->getName();
        $condition = $this->addMiddlewareConditions($tableName, $condition);
        $parameters = array();
        $whereClause = $this->conditions->getWhereClause($condition, $parameters);
        $sql = 'SELECT COUNT(*) FROM "' . $tableName . '"' . $whereClause;
        $stmt = $this->query($sql, $parameters);
        return $stmt->fetchColumn(0);
    }

    public function selectAll(ReflectedTable $table, array $columnNames, Condition $condition, array $columnOrdering, int $offset, int $limit): array
    {
        if ($limit == 0) {
            return array();
        }
        $selectColumns = $this->columns->getSelect($table, $columnNames);
        $tableName = $table->getName();
        $condition = $this->addMiddlewareConditions($tableName, $condition);
        $parameters = array();
        $whereClause = $this->conditions->getWhereClause($condition, $parameters);
        $orderBy = $this->columns->getOrderBy($table, $columnOrdering);
        $offsetLimit = $this->columns->getOffsetLimit($offset, $limit);
        $sql = 'SELECT ' . $selectColumns . ' FROM "' . $tableName . '"' . $whereClause . $orderBy . $offsetLimit;
        $stmt = $this->query($sql, $parameters);
        $records = $stmt->fetchAll();
        $this->converter->convertRecords($table, $columnNames, $records);
        return $records;
    }

    public function updateSingle(ReflectedTable $table, array $columnValues, string $id)
    {
        if (count($columnValues) == 0) {
            return 0;
        }
        $this->converter->convertColumnValues($table, $columnValues);
        $updateColumns = $this->columns->getUpdate($table, $columnValues);
        $tableName = $table->getName();
        $condition = new ColumnCondition($table->getPk(), 'eq', $id);
        $condition = $this->addMiddlewareConditions($tableName, $condition);
        $parameters = array_values($columnValues);
        $whereClause = $this->conditions->getWhereClause($condition, $parameters);
        $sql = 'UPDATE "' . $tableName . '" SET ' . $updateColumns . $whereClause;
        $stmt = $this->query($sql, $parameters);
        return $stmt->rowCount();
    }

    public function deleteSingle(ReflectedTable $table, string $id)
    {
        $tableName = $table->getName();
        $condition = new ColumnCondition($table->getPk(), 'eq', $id);
        $condition = $this->addMiddlewareConditions($tableName, $condition);
        $parameters = array();
        $whereClause = $this->conditions->getWhereClause($condition, $parameters);
        $sql = 'DELETE FROM "' . $tableName . '" ' . $whereClause;
        $stmt = $this->query($sql, $parameters);
        return $stmt->rowCount();
    }

    public function incrementSingle(ReflectedTable $table, array $columnValues, string $id)
    {
        if (count($columnValues) == 0) {
            return 0;
        }
        $this->converter->convertColumnValues($table, $columnValues);
        $updateColumns = $this->columns->getIncrement($table, $columnValues);
        $tableName = $table->getName();
        $condition = new ColumnCondition($table->getPk(), 'eq', $id);
        $condition = $this->addMiddlewareConditions($tableName, $condition);
        $parameters = array_values($columnValues);
        $whereClause = $this->conditions->getWhereClause($condition, $parameters);
        $sql = 'UPDATE "' . $tableName . '" SET ' . $updateColumns . $whereClause;
        $stmt = $this->query($sql, $parameters);
        return $stmt->rowCount();
    }

    private function query(string $sql, array $parameters): \PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        //echo "- $sql -- " . json_encode($parameters, JSON_UNESCAPED_UNICODE) . "\n";
        $stmt->execute($parameters);
        return $stmt;
    }

    public function getCacheKey(): string
    {
        return md5(json_encode([
            $this->driver,
            $this->address,
            $this->port,
            $this->database,
            $this->username,
            $this->dsn
        ]));
    }

    public function setSQLOverride(string $sqlFunction, $value)
    {
        $this->reflection->setSQLOverride($sqlFunction, $value);
    }

    public function setGetCommandsOverride($value)
    {
        $this->commandsOverride = $value;
    }

    public function checkGetCommandsOverride() {
        if ($this->commandsOverride) {
            if (is_array($this->commandsOverride)) {
                return $this->commandsOverride;
            }
            if (is_callable($this->commandsOverride)) {
                return call_user_func($this->commandsOverride);
            }
        }
    }

    public function setCreateSingleReturnOverride(callable $callable) {
        $this->createSingleReturnOverride = $callable;
    }

    public function checkCreateSingleReturnOverride($parameter) {
        if (is_callable($this->createSingleReturnOverride)) {
            return call_user_func_array($this->createSingleReturnOverride, $parameter);
        }
    }

    public function setTypeConverterArrays(string $driver, array $from, array $to )
    {
        $this->definition->setTypeConverterArrays($driver, $from, $to);
        $this->reflection->setTypeConverterArrays($driver, $from, $to);
    }

    public function setColumnsBuilder(string $fnname, $callable)
    {
        $this->columns->setOverride($fnname, $callable);
    }

    public function setDataConverter(string $fnname, $callable)
    {
        $this->converter->setOverride($fnname, $callable);
    }

}
