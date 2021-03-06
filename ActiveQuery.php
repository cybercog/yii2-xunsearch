<?php

namespace xj\xunsearch;

use yii\base\Component;
use yii\base\InvalidParamException;
use yii\base\NotSupportedException;
use yii\db\ActiveQueryInterface;
use yii\db\ActiveQueryTrait;
use yii\db\ActiveRelationTrait;
use yii\db\QueryTrait;

/**
 * Normal Query
 * ------------
 *
 * ActiveQuery mainly provides the following methods to retrieve the query results:
 *
 * - [[one()]]: returns a single record populated with the first row of data.
 * - [[all()]]: returns all records based on the query results.
 * - [[count()]]: returns the number of records.
 *
 * You can use query methods, such as [[where()]], [[limit()]] and [[orderBy()]] to customize the query options.
 * 
 * - [[asArray()]]: whether to return each record as an array.
 *
 * ```php
 * $demos = Demo::find()->asArray()->all();
 * ```
 * @author xjflyttp <xjflyttp@gmail.com>
 */
class ActiveQuery extends Component implements ActiveQueryInterface {

    use QueryTrait;

use ActiveQueryTrait;

    /**
     * @event Event an event that is triggered when the query is initialized via [[init()]].
     */
    const EVENT_INIT = 'init';

    /**
     * @var array map of query condition to builder methods.
     * These methods are used by [[buildCondition]] to build SQL conditions from array syntax.
     */
    protected $conditionBuilders = [
        'NOT' => 'buildNotCondition',
        'AND' => 'buildAndCondition',
        'OR' => 'buildAndCondition',
        'WILD' => 'buildWildCondition',
    ];
    /**
     * Search Query String Cache
     * @var string 
     */
    public $query;
    /**
     * enable fuzzy query
     * @var bool 
     * @see http://www.xunsearch.com/doc/php/api/XSSearch
     */
    public $fuzzy = false;

    /**
     * Constructor.
     * @param array $modelClass the model class associated with this query
     * @param array $config configurations to be applied to the newly created query object
     */
    public function __construct($modelClass, $config = []) {
        $this->modelClass = $modelClass;
        parent::__construct($config);
    }

    /**
     * Initializes the object.
     * This method is called at the end of the constructor. The default implementation will trigger
     * an [[EVENT_INIT]] event. If you override this method, make sure you call the parent implementation at the end
     * to ensure triggering of the event.
     */
    public function init() {
        parent::init();
        $this->trigger(self::EVENT_INIT);
    }

    private function setCondition(XSSearch $search) {
        $params = [];
//        $search->setLimit($this->limit, $this->offset);
        $this->buildLimit($this->limit, $this->offset);
        $this->buildOrderBy($this->orderBy);
        $this->query = $query = $this->buildWhere($this->where, $params);
        $search->setQuery($query);
        //fuzzy query
        if ($this->fuzzy) {
            $search->setFuzzy();
        }
    }

    /**
     * Parses the condition specification and generates the corresponding SQL expression.
     * @param string|array $condition the condition specification. Please refer to [[Query::where()]]
     * on how to specify a condition.
     * @param array $params the binding parameters to be populated
     * @return string the generated SQL expression
     */
    public function buildCondition($condition, &$params) {
        if (!is_array($condition)) {
            return (string) $condition;
        } elseif (empty($condition)) {
            return '';
        }

        if (isset($condition[0])) { // operator format: operator, operand 1, operand 2, ...
            $operator = strtoupper($condition[0]);
            if (isset($this->conditionBuilders[$operator])) {
                $method = $this->conditionBuilders[$operator];
            } else {
                $method = 'buildSimpleCondition';
            }
            array_shift($condition);
            return $this->$method($operator, $condition, $params);
        } else { // hash format: 'column1' => 'value1', 'column2' => 'value2', ...
            return $this->buildHashCondition($condition, $params);
        }
    }

    /**
     * Creates a condition based on column-value pairs.
     * @param array $condition the condition specification.
     * @param array $params the binding parameters to be populated
     * @return string the generated SQL expression
     */
    public function buildHashCondition($condition, &$params) {
        $parts = [];
        foreach ($condition as $column => $value) {
            if (is_array($value)) {
                foreach ($value as $v) {
                    $parts[] = "$column:$v";
                }
                return count($parts) === 1 ? $parts[0] : '(' . implode(') OR (', $parts) . ')';
            } else {
                if ($value !== null) {
                    $parts[] = "$column:$value";
                }
            }
        }
        return count($parts) === 1 ? $parts[0] : '(' . implode(') AND (', $parts) . ')';
    }

    /**
     * @param string|array $condition
     * @param array $params the binding parameters to be populated
     * @return string the WHERE clause built from [[Query::$where]].
     */
    public function buildWhere($condition, &$params) {
        $where = $this->buildCondition($condition, $params);

        return $where === '' ? '' : $where;
    }

    /**
     * @param array $columns
     * @return string the ORDER BY clause built from [[Query::$orderBy]].
     */
    public function buildOrderBy($columns) {
        if (empty($columns)) {
            return '';
        }
        $search = $this->getSearch();
        if (count($columns) === 1) {
            foreach ($columns as $name => $direction) {
                $search->setSort($name, $direction === SORT_DESC ? false : true);
            }
        } else {
            $multiSort = [];
            foreach ($columns as $name => $direction) {
                $multiSort[$name] = $direction === SORT_DESC ? false : true;
            }
            $search->setMultiSort($multiSort);
        }

//        return 'ORDER BY ' . implode(', ', $orders);
    }

    /**
     * @param integer $limit
     * @param integer $offset
     * @return string the LIMIT and OFFSET clauses
     */
    public function buildLimit($limit, $offset) {
        if (!$this->hasOffset($offset)) {
            $offset = 0;
        }
        if ($this->hasLimit($limit)) {
            $this->getSearch()->setLimit($limit, $offset);
        }
    }

    /**
     * Checks to see if the given limit is effective.
     * @param mixed $limit the given limit
     * @return boolean whether the limit is effective
     */
    protected function hasLimit($limit) {
        return is_string($limit) && ctype_digit($limit) || is_integer($limit) && $limit >= 0;
    }

    /**
     * Checks to see if the given offset is effective.
     * @param mixed $offset the given offset
     * @return boolean whether the offset is effective
     */
    protected function hasOffset($offset) {
        return is_integer($offset) && $offset > 0 || is_string($offset) && ctype_digit($offset) && $offset !== '0';
    }

    /**
     * Connects two or more SQL expressions with the `AND` or `OR` operator.
     * @param string $operator the operator to use for connecting the given operands
     * @param array $operands the SQL expressions to connect.
     * @param array $params the binding parameters to be populated
     * @return string the generated SQL expression
     */
    public function buildAndCondition($operator, $operands, &$params) {
        $parts = [];
        foreach ($operands as $operand) {
            if (is_array($operand)) {
                $operand = $this->buildCondition($operand, $params);
            }
            if ($operand !== '') {
                $parts[] = $operand;
            }
        }
        if (!empty($parts)) {
            return '(' . implode(") $operator (", $parts) . ')';
        } else {
            return '';
        }
    }

    /**
     * Inverts an SQL expressions with `NOT` operator.
     * @param string $operator the operator to use for connecting the given operands
     * @param array $operands the SQL expressions to connect.
     * @param array $params the binding parameters to be populated
     * @return string the generated SQL expression
     * @throws InvalidParamException if wrong number of operands have been given.
     */
    public function buildNotCondition($operator, $operands, &$params) {
        if (count($operands) != 1) {
            throw new InvalidParamException("Operator '$operator' requires exactly one operand.");
        }

        $operand = reset($operands);
        if (is_array($operand)) {
            $operand = $this->buildCondition($operand, $params);
        }
        if ($operand === '') {
            return '';
        }

        return "$operator ($operand)";
    }

    /**
     * Creates an SQL expressions like `"column" operator value`.
     * @param string $operator the operator to use. Anything could be used e.g. `>`, `<=`, etc.
     * @param array $operands contains two column names.
     * @param array $params the binding parameters to be populated
     * @return string the generated SQL expression
     */
    public function buildWildCondition($operator, $operands, &$params) {
        $parts = [];
        foreach ($operands as $operand) {
            if (is_array($operand)) {
                $operand = $this->buildCondition($operand, $params);
            }
            if ($operand !== '') {
                $parts[] = $operand;
            }
        }
        if (!empty($parts)) {
            return implode(' ', $operands);
        } else {
            return '';
        }
    }

    public function buildSimpleCondition($operator, $operands, &$params) {
        return $operator . ' ' . implode(' ', $operands);
    }

    private function getDb() {
        $modelClass = $this->modelClass;
        return $modelClass::getDb();
    }

    /**
     * get getSearch
     * return \xj\xunsearch\XSSearch
     */
    private function getSearch() {
        return $this->getDb()->getSearch();
    }

    /**
     * 
     * @param Databases $db
     * @return type
     */
    public function one($db = null) {
        if ($db === null) {
            $db = $this->getDb();
        }
        $search = $db->getSearch();
        $this->limit(1);
        $this->setCondition($search);
        $docs = $search->search();
        $rows = [];
        foreach ($docs as $doc) {
            if ($doc instanceof XSDocument) {
                $rows[] = $doc->getFields();
            }
        }
        if (!empty($rows)) {
            $models = $this->createModels($rows);
            if (!$this->asArray) {
                foreach ($models as $model) {
                    $model->afterFind();
                }
            }
            return $models[0];
        }
        return null;
    }

    public function all($db = null) {
        if ($db === null) {
            $db = $this->getDb();
        }
        $search = $db->getSearch();
        $this->setCondition($search);
        $docs = $search->search();
        $rows = [];
        foreach ($docs as $doc) {
            if ($doc instanceof XSDocument) {
                $rows[] = $doc->getFields();
            }
        }
        if (!empty($rows)) {
            $models = $this->createModels($rows);
            if (!$this->asArray) {
                foreach ($models as $model) {
                    $model->afterFind();
                }
            }
            return $models;
        }
        return [];
    }

    public function count($q = '*', $db = null) {
        if ($db === null) {
            $db = $this->getDb();
        }
        $search = $db->getSearch();
        $this->setCondition($search);
        return $docs = $search->count();
    }

    public function exists($db = null) {
        throw new NotSupportedException();
    }

    public function findFor($name, $model) {
        throw new NotSupportedException();
    }

    public function via($relationName, callable $callable = null) {
        throw new NotSupportedException();
    }

}
