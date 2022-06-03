<?php

namespace Hypersistence\Core;

class QueryBuilder {

    private $srcObject;
    private $property;
    private $object;
    private $rows = 0;
    private $offset = 0;
    private $page = 0;
    private $totalRows = 0;
    private $totalPages = 0;
    private $resultList = array();
    private $chars = 'abcdefghijklmnopqrstuvwxyz';
    private $joins = array();
    private $orderBy = array();
    private $filters = array();
    private $bounds = array();

    public function __construct($object, $srcObject = null, $property = null) {
        $this->object = $object;
        $this->property = $property;
        $this->srcObject = $srcObject;
    }

    /**
     *
     * @return array|Hypersistence
     */
    public function execute() {
        $this->totalRows = 0;
        $this->totalPages = 0;
        $this->resultList = array();


        $classThis = Engine::init($this->object);

        $tables = array();
        $fields = array();
        $fieldsNoAlias = array();
        $objectRefs = array();

        $class = $classThis;

        //When it is a many to many relation.
        if ($this->property && $this->object) {
            $srcClass = Engine::init($this->srcObject);
            $srcPk = Engine::getPk($srcClass);
            $srcGet = 'get' . $srcPk['var'];
            $srcId = $this->srcObject->$srcGet();
            $pk = Engine::getPk($class);

            $tables[] = DB::ec() . $this->property['joinTable'] . DB::ec();
            $filter = $this->property['joinTable'] . '.' . $this->property['joinColumn'] . ' = :' . $this->property['joinTable'] . '_' . $this->property['joinColumn'];
            $this->filters[md5($filter)] = $filter;
            $this->bounds[':' . $this->property['joinTable'] . '_' . $this->property['joinColumn']] = $srcId;
            $filter = $this->property['joinTable'] . '.' . $this->property['inverseJoinColumn'] . ' = ' . $this->chars[$pk['i']] . '.' . $pk['column'];
            $this->filters[md5($filter)] = $filter;
        }


        $i = 0;
        while ($class != 'Hypersistence') {
            $alias = str_replace('\\', '', $this->chars[$i]);
            $class = ltrim($class, '\\');
            $joinTable = DB::ec() . Engine::$map[$class]['table'] . DB::ec() . ' ' . $alias;
            if ($i == 0) {
                $tables[] = $joinTable;
            } else {
                $parentAlias = $this->chars[$i];
                $pk = Engine::getPk($class);
                $join = 'join ' . $joinTable . ' on(' . $lastAlias . '.' . Engine::$map[$lastClass]['joinColumn'] . ' = ' . $parentAlias . '.' . $pk['column'] . ')';
                $this->joins[md5($join)] = $join;
            }
            $lastClass = $class;
            $lastAlias = $alias;

            foreach (Engine::$map[$class]['properties'] as $p) {
                //if($p['relType'] != Engine::MANY_TO_MANY){
                if ($p['relType'] != Engine::ONE_TO_MANY && $p['relType'] != Engine::MANY_TO_MANY) {
                    $fields[] = $alias . '.' . $p['column'] . ' as ' . $alias . '_' . $p['column'];
                    $fieldsNoAlias[] = $alias . '.' . $p['column'];
                }
                $get = 'get' . $p['var'];
                $value = $this->object->$get();
                if (!is_null($value)) {
                    if ($value instanceof \Hypersistence\Hypersistence) {
                        if ($p['relType'] == Engine::MANY_TO_ONE || $p['relType'] == Engine::ONE_TO_MANY || $p['relType'] == Engine::MANY_TO_MANY) {
                            $this->joinFilter($class, $p, $value, $alias);
                        }
                    } else {
                        if (is_numeric($value)) {
                            $filter = $alias . '.' . $p['column'] . ' = :' . $alias . '_' . $p['column'];
                            $this->filters[md5($filter)] = $filter;
                            $this->bounds[':' . $alias . '_' . $p['column']] = $value;
                        } else {
                            $filter = $alias . '.' . $p['column'] . ' like :' . $alias . '_' . $p['column'];
                            $this->filters[md5($filter)] = $filter;
                            $this->bounds[':' . $alias . '_' . $p['column']] = $this->searchMode($p, $value);
                        }
                    }
                }

                //}
            }

            $class = Engine::$map[$class]['parent'];
            $i++;
        }

        if (count($this->filters))
            $where = ' where ' . implode(' and ', $this->filters);
        else
            $where = '';

       if(config("database.default") == 'pgsql'){
          if (count($this->joins) > 0) {
              $count = 'distinct(COALESCE( cast(' . implode(' as varchar), \'\'),COALESCE(cast(', $fieldsNoAlias) . ' as varchar), \'\'))';
          } else {
              $count = '*';
          }
          $sql = 'select count(' . $count . ') as total from ' . implode(',', $tables) . ' ' . implode(' ', $this->joins) . $where;
       }

        $bounds = array();

        foreach ($this->bounds as $key => $val) {
            if ($key != ':offset' && $key != ':limit') {
                $bounds[$key] = $val;
            }
        }
        $calc_rows = '';
        if(config("database.default") == 'pgsql'){
          if ($stmt = DB::getDBConnection()->prepare($sql)) {
              if ($stmt->execute($bounds) && $stmt->rowCount() > 0) {
                  $result = $stmt->fetchObject();
                  $this->totalRows = $result->total;
                  $this->totalPages = $this->rows > 0 ? ceil($this->totalRows / $this->rows) : 1;
              } else {
                  return array();
                  dd($stmt);
              }
          }
       } else {
          $calc_rows = 'SQL_CALC_FOUND_ROWS ';
       }

        $limit = '';
        if ($this->page > 0 && $this->rows > 0) {
            $limit = ' LIMIT :limit OFFSET :offset';
            $offset = $this->page > 0 ? ($this->page - 1) * $this->rows : $this->offset;
            $this->bounds[':offset'] = array($offset, DB::PARAM_INT);
            $this->bounds[':limit'] = array($this->rows, DB::PARAM_INT);
        }

        if (count($this->orderBy))
            $orderBy = ' order by ' . implode(',', $this->orderBy);
        else
            $orderBy = '';

        $fields = array_merge($fields, $this->orderBy);
        $str_fields = str_replace(' desc', '', str_replace(' asc', '', implode(',', $fields)));
        $sql = "select $calc_rows" . $str_fields . ' from ' . implode(',', $tables) . ' ' . implode(' ', $this->joins) . $where . $orderBy . $limit;
        if ($stmt = DB::getDBConnection()->prepare($sql)) {

            if ($stmt->execute($this->bounds) && $stmt->rowCount() > 0) {
               if(config("database.default") != 'pgsql'){
                $stm = DB::getDBConnection()->prepare('SELECT FOUND_ROWS() as total;');
                $stm->execute();
                $r = $stm->fetchObject();
                $this->totalRows = $r->total;
                $this->totalPages = $this->rows > 0 ? ceil($this->totalRows / $this->rows) : 1;
            }
                while ($result = $stmt->fetchObject()) {
                    $class = $classThis;
                    $object = new $class;
                    $i = 0;
                    while ($class != '' && $class != 'Hypersistence') {
                        $alias = str_replace('\\', '', $this->chars[$i]);
                        $class = ltrim($class, '\\');
                        foreach (Engine::$map[$class]['properties'] as $p) {
                            $var = $p['var'];
                            $set = 'set' . $var;
                            $get = 'get' . $var;
                            if ($p['relType'] != Engine::MANY_TO_MANY && $p['relType'] != Engine::ONE_TO_MANY) {
                                $column = $alias . '_' . $p['column'];
                                if (isset($result->$column)) {

                                    if (isset($objectRefs[$column])) {
                                        $object->$set($objectRefs[$column]);
                                    } else {
                                        if (method_exists($object, $set)) {
                                            if ($p['relType'] == Engine::MANY_TO_ONE) {
                                                $objClass = $p['itemClass'];
                                                Engine::init($objClass);
                                                $pk = Engine::getPk($objClass);
                                                if ($pk) {
                                                    $objVar = $pk['var'];
                                                    $objSet = 'set' . $objVar;
                                                    $obj = new $objClass;
                                                    $obj->$objSet($result->$column);
                                                    $object->$set($obj);
                                                    if ($p['loadType'] == 'eager') {
                                                        $obj->load();
                                                    }
                                                }
                                            } else {
                                                if ($p['dateTime']) {
                                                    if (!is_null($result->$column)) {
                                                        $object->$set(new DateTime($result->$column));
                                                    } else {
                                                        $object->$set(null);
                                                    }
                                                } else {
                                                    $object->$set($result->$column);
                                                }
                                            }
                                        }
                                    }
                                }
                            } else if ($p['relType'] == Engine::ONE_TO_MANY) {
                                $objClass = $p['itemClass'];
                                Engine::init($objClass);
                                $objClass = ltrim($objClass, '\\');
                                $objFk = Engine::getPropertyByColumn($objClass, $p['joinColumn']);
                                if ($objFk) {
                                    $obj = new $objClass;
                                    $objSet = 'set' . $objFk['var'];
                                    $obj->$objSet($object);
                                    $search = $obj->search();
                                    if ($p['loadType'] == 'eager') {
                                        $search = $search->execute();
                                    }
                                    $object->$set($search);
                                }
                            } else if ($p['relType'] == Engine::MANY_TO_MANY) {
                                $objClass = $p['itemClass'];

                                $obj = new $objClass;
                                $search = new QueryBuilder($obj, $object, $p);

                                if ($p['loadType'] == 'eager')
                                    $search = $search->execute();
                                $object->$set($search);
                            }
                        }

                        $class = Engine::$map[$class]['parent'];
                        $i++;
                    }
                    $this->resultList[] = $object;
                }
            }
        }
        return $this->resultList;
    }

    /**
     * @return array|Hypersistence
     */
    public function fetchAll() {
        $this->rows = 0;
        $this->offset = 0;
        $this->page = 0;
        if ($this->execute()) {
            return $this->resultList;
        } else {
            return array();
        }
    }

    /**
     *
     * @param string $orderBy
     * @param string $orderDirection
     * @return \HypersistenceResultSet
     */
    public function orderBy($orderBy, $orderDirection = 'asc') {

        $className = Engine::init($this->object);

        $order = preg_replace('/[ \t]/', '', $orderBy);
        $parts = explode('.', $orderBy);

        $var = $parts[0];
        $parts = array_slice($parts, 1);
        $className = ltrim($className, '\\');
        $p = Engine::getPropertyByVarName($className, $var);
        if ($p['relType'] == Engine::MANY_TO_ONE) {
            $this->joinOrderBy($className, $p, $parts, $orderDirection, $this->chars[$p['i']]);
        } else {
            $this->orderBy[] = $this->chars[$p['i']] . '.' . $p['column'] . ' ' . $orderDirection;
        }

        return $this;
    }

    /**
     *
     * @param string $property
     * @param string $value
     * @param string $opperation
     * @return \HypersistenceResultSet
     */
    public function filter($property, $value, $opperation = '=') {

        $className = Engine::init($this->object);

        $property = preg_replace('/[ \t]/', '', $property);
        $opperation = preg_replace('/[^!=><isISlkeLKEnotNOTinIN ]/', '', $opperation);
        $parts = explode('.', $property);

        $var = $parts[0];
        $parts = array_slice($parts, 1);
        $className = ltrim($className, '\\');
        $p = Engine::getPropertyByVarName($className, $var);
        if ($p['relType'] == Engine::MANY_TO_ONE) {
            $i = 0;
            $key = ':' . $this->chars[$p['i']] . '_' . $p['column'];
            while (isset($this->bounds[$key . $i]))
                $i++;
            if ((strtolower($opperation) == 'is' || strtolower($opperation) == 'is not') && (strtolower($value) == 'null' || is_null($value))) {
                $filter = $this->chars[$p['i']] . '.' . $p['column'] . ' ' . $opperation . ' null';
                $this->filters[md5($filter)] = $filter;
                return $this;
            } else if (is_array($value)) {
                $opperation = str_contains(strtolower($opperation), 'in') ? $opperation : 'in';
                $inCondition = '';
                $separator = '';
                $keys = '';

                foreach ($value as $v) {
                    if (!is_object($v) && !is_array($v) && !is_null($v)) {
                        $keys .= $separator . $key . $i;
                        $this->bounds[$key . $i] = $v;
                        $i++;
                        ;
                    } else if (is_object($v) && $v instanceof \Hypersistence\Hypersistence) {
                        $pk = $v->getPk();
                        $get = 'get' . $pk['var'];
                        $val = $v->$get();
                        $keys .= $separator . $key . $i;
                        $this->bounds[$key . $i] = $val;
                        $i++;
                    }
                    $separator = ",";
                }
                $value = "(" . trim($keys) . ")";
            }
            $this->joinPersonalFilter($className, $p, $parts, $opperation, $value, $this->chars[$p['i']]);
        } else {
            $i = 0;
            $key = ':' . $this->chars[$p['i']] . '_' . $p['column'];
            while (isset($this->bounds[$key . $i]))
                $i++;
            if ((strtolower($opperation) == 'is' || strtolower($opperation) == 'is not') && (strtolower($value) == 'null' || is_null($value))) {
                $filter = $this->chars[$p['i']] . '.' . $p['column'] . ' ' . $opperation . ' null';
                $this->filters[md5($filter)] = $filter;
                return $this;
            } else if (is_array($value)) {
                $opperation = str_contains(strtolower($opperation), 'in') ? $opperation : 'in';
                $inCondition = '';
                $separator = '';
                $keys = '';
                foreach ($value as $v) {
                    if (!is_object($v) && !is_array($v) && !is_null($v)) {
                        $keys .= $separator . $key . $i;
                        $this->bounds[$key . $i] = $v;
                        $i++;
                        ;
                    } else if (is_object($v) && $v instanceof \Hypersistence\Hypersistence) {
                        $pk = $v->getPk();
                        $get = 'get' . $pk['var'];
                        $val = $v->$get();
                        $keys .= $separator . $key . $i;
                        $this->bounds[$key . $i] = $val;
                        $i++;
                    }
                    $separator = ",";
                }
                $filter = $this->chars[$p['i']] . '.' . $p['column'] . " $opperation (" . $keys . ')';
                $this->filters[md5($filter)] = $filter;
            } else {
                $filter = $this->chars[$p['i']] . '.' . $p['column'] . ' ' . $opperation . ' ' . $key . $i;
                $this->filters[md5($filter)] = $filter;
                $this->bounds[$key . $i] = $value;
            }
        }

        return $this;
    }

    private function joinOrderBy($className, $property, $parts, $orderDirection, $classAlias, $alias = '') {
        $auxClass = ltrim($property['itemClass'], '\\');

        if ($alias == '') {
            $alias = str_replace('\\', '', $className) . '_';
        }
        $var = $parts[0];
        $alias .= $property['var'] . '_';
        $parts = array_slice($parts, 1);
        $i = 0;
        while ($auxClass != 'Hypersistence') {
            Engine::init($auxClass);
            $table = DB::ec() . Engine::$map[$auxClass]['table'] . DB::ec();
            $char = $this->chars[$i];
            $pk = Engine::getPk($auxClass);
            $join = 'left join ' . $table . ' ' . $alias . $char . ' on(' . $alias . $char . '.' . $pk['column'] . ' = ' . $classAlias . '.' . $property['column'] . ')';
            $this->joins[md5($join)] = $join;
            $classAlias = $alias . $char;
            $property = $pk;
            foreach (Engine::$map[$auxClass]['properties'] as $p) {
                if ($p['var'] == $var) {
                    $p['i'] = $i;
                    if ($p['relType'] == Engine::MANY_TO_ONE) {
                        $this->joinOrderBy($auxClass, $p, $parts, $orderDirection, $classAlias, $alias);
                    } else {
                        $this->orderBy[] = $alias . $char . '.' . $p['column'] . ' ' . $orderDirection;
                    }
                    break 2;
                }
            }
            if (isset(Engine::$map[$auxClass]['joinColumn'])) {
                $property['column'] = Engine::$map[$auxClass]['joinColumn'];
            }
            $auxClass = Engine::$map[$auxClass]['parent'];
            $i++;
        }
    }

    private function joinPersonalFilter($className, $property, $parts, $opperation, $value, $classAlias, $alias = '') {
        $auxClass = ltrim($property['itemClass'], '\\');

        if ($alias == '') {
            $alias = str_replace('\\', '', $className) . '_';
        }
        $var = $parts[0];
        $alias .= $property['var'] . '_';
        $parts = array_slice($parts, 1);
        $i = 0;
        while ($auxClass != 'Hypersistence') {
            Engine::init($auxClass);
            $table = DB::ec() . Engine::$map[$auxClass]['table'] . DB::ec();
            $char = $this->chars[$i];
            $pk = Engine::getPk($auxClass);
            $join = 'left join ' . $table . ' ' . $alias . $char . ' on(' . $alias . $char . '.' . $pk['column'] . ' = ' . $classAlias . '.' . $property['column'] . ')';
            $this->joins[md5($join)] = $join;
            $classAlias = $alias . $char;
            $property = $pk;
            foreach (Engine::$map[$auxClass]['properties'] as $p) {
                if ($p['var'] == $var) {
                    $p['i'] = $i;
                    if ($p['relType'] == Engine::MANY_TO_ONE) {
                        $this->joinPersonalFilter($auxClass, $p, $parts, $opperation, $value, $classAlias, $alias);
                    } else {
                        if ("in" == strtolower($opperation)) {
                            $filter = $alias . $char . '.' . $p['column'] . " $opperation " . $value;
                            $this->filters[md5($filter)] = $filter;
                        } else {
                            $i = 0;
                            $key = ':' . $alias . $char . '_' . $p['column'];
                            while (isset($this->bounds[$key . $i]))
                                $i++;
                            $filter = $alias . $char . '.' . $p['column'] . ' ' . $opperation . ' ' . $key . $i;
                            $this->filters[md5($filter)] = $filter;
                            $this->bounds[$key . $i] = $value;
                        }
                    }
                    break 2;
                }
            }
            $auxClass = Engine::$map[$auxClass]['parent'];
            $i++;
        }
    }

    private function joinFilter($className, $property, $object, $classAlias, $alias = '') {
        $auxClass = $property['itemClass'];
        if ($alias == '') {
            $alias = str_replace('\\', '', $className) . '_';
        }
        $alias .= $property['var'] . '_';
        $last = null;
        $i = 0;
        while ($auxClass != 'Hypersistence') {
            Engine::init($auxClass);

            $auxClass = ltrim($auxClass, '\\');
            $table = DB::ec() . Engine::$map[$auxClass]['table'] . DB::ec();
            $char = $this->chars[$i];
            $pk = Engine::getPk($auxClass);
            if ($property['relType'] == Engine::MANY_TO_ONE) {
                $join = 'left join ' . $table . ' ' . $alias . $char . ' on(' . $alias . $char . '.' . $pk['column'] . ' = ' . $classAlias . '.' . $property['column'] . ')';
            } else if ($property['relType'] == Engine::ONE_TO_MANY) {
                $join = 'left join ' . $table . ' ' . $alias . $char . ' on(' . $alias . $char . '.' . $property['joinColumn'] . ' = ' . $classAlias . '.' . $pk['column'] . ')';
            } else if ($property['relType'] == Engine::MANY_TO_MANY) {
                $joinTable = DB::ec() . $property['joinTable'] . DB::ec();
                $joinPk = Engine::getPk($property['itemClass']);
                $join = 'left join ' . $joinTable . ' ' . $alias . $char . '_j' . ' on(' . $alias . $char . '_j' . '.' . $property['joinColumn'] . ' = ' . $classAlias . '.' . $pk['column'] . ')'
                        . ' left join ' . $table . ' ' . $alias . $char . ' on(' . $alias . $char . '.' . $joinPk['column'] . ' = ' . $alias . $char . '_j' . '.' . $property['inverseJoinColumn'] . ')';
            } else if ($property['relType'] == 0) {
                $join = 'left join ' . $table . ' ' . $alias . $char . ' on(' . $alias . $char . '.' . $pk['column'] . ' = ' . $classAlias . '.' . $last['joinColumn'] . ')';
            }

            $this->joins[md5($join)] = $join;
            $classAlias = $alias . $char;

            $getPkField = 'get' . $pk['var'];
            $value = $object->$getPkField();
            if (!is_null($value) && $value != 0 && !empty($value)) {
                $filter = $alias . $char . '.' . $pk['column'] . ' = :' . $alias . $char . '_' . $pk['column'];
                $this->filters[md5($filter)] = $filter;
                $this->bounds[':' . $alias . $char . '_' . $pk['column']] = $value;
                return;
            }

            $property = $pk;
            $last = Engine::$map[$auxClass];
            foreach (Engine::$map[$auxClass]['properties'] as $p) {

                $get = 'get' . $p['var'];
                $value = $object->$get();
                if (!is_null($value) && !$value instanceof QueryBuilder) {
                    $p['i'] = $i;
                    if ($p['relType'] == Engine::MANY_TO_ONE || $p['relType'] == Engine::ONE_TO_MANY) {
                        $this->joinFilter($auxClass, $p, $value, $classAlias, $alias);
                    } else if ($p['relType'] != Engine::MANY_TO_MANY && $p['relType'] != Engine::ONE_TO_MANY) {
                        if (is_numeric($value)) {
                            $filter = $alias . $char . '.' . $p['column'] . ' = :' . $alias . $char . '_' . $p['column'];
                            $this->filters[md5($filter)] = $filter;
                            $this->bounds[':' . $alias . $char . '_' . $p['column']] = $value;
                        } else {
                            $filter = $alias . $char . '.' . $p['column'] . ' like :' . $alias . $char . '_' . $p['column'];
                            $this->filters[md5($filter)] = $filter;
                            $this->bounds[':' . $alias . $char . '_' . $p['column']] = '%' . preg_replace('/[ \t]/', '%', trim($value)) . '%';
                        }
                        if ($p['primaryKey']) {
                            return;
                        }
                    }
                }
            }
            $auxClass = Engine::$map[$auxClass]['parent'];
            $i++;
        }
    }

    private function searchMode($property, $value) {
        $mode = $property['searchMode'];
        if (preg_match('/^%[a-zA-Z]$/', $mode)) {
            return "%$value";
        } elseif (preg_match('/^%[a-zA-Z]%$/', $mode)) {
            return "%$value%";
        } elseif (preg_match('/^[a-zA-Z]%$/', $mode)) {
            return "$value%";
        } elseif (preg_match('/^[a-zA-Z]%[a-zA-Z]$/', $mode)) {
            return preg_replace("/[ \t\n\r]/", '%', $value);
        } elseif (preg_match('/^%[a-zA-Z]%[a-zA-Z]$/', $mode)) {
            return '%' . preg_replace("/[ \t\n\r]/", '%', $value);
        } elseif (is_null($mode) || preg_match('/^%[a-zA-Z]%[a-zA-Z]%$/', $mode)) {
            return '%' . preg_replace("/[ \t\n\r]/", '%', $value) . '%';
        } elseif (preg_match('/^[a-zA-Z]%[a-zA-Z]%$/', $mode)) {
            return preg_replace("/[ \t\n\r]/", '%', $value) . '%';
        } else if ($mode === '') {
            return $value;
        }
    }

    /**
     *
     * @param int $rows
     * @return \HypersistenceResultSet
     */
    public function setRows($rows) {
        $this->rows = $rows >= 0 ? (int) $rows : 0;
        return $this;
    }

    /**
     *
     * @param int $offset
     * @return \HypersistenceResultSet
     */
    public function setOffset($offset) {
        $this->offset = $offset >= 0 ? (int) $offset : 0;
        return $this;
    }

    /**
     *
     * @param int $page
     * @return \HypersistenceResultSet
     */
    public function setPage($page) {
        $this->page = $page >= 0 ? (int) $page : 0;
        return $this;
    }

    public function getTotalRows() {
        return $this->totalRows;
    }

    public function getTotalPages() {
        return $this->totalPages;
    }

    /**
     *
     * @return array|Hypersistence
     */
    public function getResultList() {
        return $this->resultList;
    }

}
