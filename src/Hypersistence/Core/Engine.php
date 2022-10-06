<?php

namespace Hypersistence\Core;

class Engine {

    const VERSION = '0.2.61';

    public static $map;
    private $loaded = false;
    private $sqlErrorInfo = array();

    const MANY_TO_ONE = 1;
    const ONE_TO_MANY = 2;
    const MANY_TO_MANY = 3;

    private static $TAG_TABLE = 'table';
    private static $TAG_JOIN_COLUMN = 'joinColumn';
    private static $TAG_COLUMN = 'column';
    private static $TAG_INVERSE_JOIN_COLUMN = 'inverseJoinColumn';
    private static $TAG_JOIN_TABLE = 'joinTable';
    private static $TAG_PRIMARY_KEY = 'primaryKey';
    private static $TAG_ITEM_CLASS = 'itemClass';
    private static $TAG_NULLABLE = 'nullable';
    private static $TAG_SEARCH_MODE = 'searchMode';
    private static $TAG_DATETIME = 'dateTime';
    private static $TAG_TO_JSON = 'toJSON';
    private static $TAG_TO_JSON_FIELD = 'toJSONField';
    private static $TAG_USERNAME_FIELD = 'usernameField';
    private static $TAG_PASSWORD_FIELD = 'passwordField';
    private static $TAG_REMEMBER_TOKEN_FIELD = 'rememberTokenField';
    private static $TAG_FILLABLE = 'fillable';
    private static $TAG_AUDITABLE = 'auditable';
    private static $TAG_AUDITABLE_FIELD = 'auditableField';


    /**
     *
     * @return boolean
     */
    public function isLoaded() {
        return $this->loaded;
    }

    public static function init($class) {
        $refClass = new \ReflectionClass($class);
        self::mapClass($refClass);
        return '\\' . $refClass->name;
    }

    /**
     * @param \ReflectionClass $refClass
     */
    private static function mapClass($refClass) {
        if ($refClass->name != 'Hypersistence' && self::is($refClass, self::$TAG_TABLE)) {
            if (!isset(self::$map[$refClass->name])) {
                $table = self::getAnnotationValue($refClass, self::$TAG_TABLE);
                $joinColumn = self::getAnnotationValue($refClass, self::$TAG_JOIN_COLUMN);
                if (!$table) {
                    throw new \Exception('You must specify the table for class ' . $refClass->name . '!');
                }
                self::$map[$refClass->name] = array(
                    self::$TAG_TABLE => $table,
                    self::$TAG_JOIN_COLUMN => $joinColumn,
                    self::$TAG_AUDITABLE => $joinColumn,
                    'parent' => $refClass->getParentClass()->name,
                    'class' => $refClass->name,
                    'properties' => array()
                );
                if (self::is($refClass->getParentClass(), self::$TAG_TABLE)) {
                    if (!$joinColumn) {
                        throw new \Exception('You must specify the join column for subclass ' . $refClass->name . '!');
                    }
                } else {
                    self::$map[$refClass->name]['parent'] = 'Hypersistence';
                }

                $properties = $refClass->getProperties();
                foreach ($properties as $p) {
                    if ($p->class == $refClass->name) {
                        $col = self::getAnnotationValue($p, self::$TAG_COLUMN);
                        $auditable = self::is($p, self::$TAG_AUDITABLE);
                        $auditableField = self::getAnnotationValue($p, self::$TAG_AUDITABLE_FIELD);
                        $relType = self::getRelType($p);
                        $pk = self::is($p, self::$TAG_PRIMARY_KEY);
                        $itemClass = '\\' . self::getAnnotationValue($p, self::$TAG_ITEM_CLASS);
                        $joinColumn = self::getAnnotationValue($p, self::$TAG_JOIN_COLUMN);
                        $inverseJoinColumn = self::getAnnotationValue($p, self::$TAG_INVERSE_JOIN_COLUMN);
                        $joinTable = self::getAnnotationValue($p, self::$TAG_JOIN_TABLE);
                        $fieldJson = self::getAnnotationValue($p, self::$TAG_TO_JSON);
                        if ($relType[0] == self::MANY_TO_ONE && !$itemClass) {
                            throw new \Exception('You must specify the class of many to one relation (' . $p->name . ') in class ' . $p->class . '!');
                        } else if ($relType[0] == self::ONE_TO_MANY) {
                            if (!$itemClass) {
                                throw new \Exception('You must specify the class of one to many relation (' . $p->name . ') in class ' . $p->class . '!');
                            }
                            if (!$joinColumn) {
                                throw new \Exception('You must specify the join column of one to many relation (' . $p->name . ') in class ' . $p->class . '!');
                            }
                        } else if ($relType[0] == self::MANY_TO_MANY) {
                            if (!$itemClass) {
                                throw new \Exception('You must specify the class of many to many relation (' . $p->name . ') in class ' . $p->class . '!');
                            }
                            if (!$joinColumn) {
                                throw new \Exception('You must specify the join column of many to many relation (' . $p->name . ') in class ' . $p->class . '!');
                            }
                            if (!$inverseJoinColumn) {
                                throw new \Exception('You must specify the inverse join column of many to many relation (' . $p->name . ') in class ' . $p->class . '!');
                            }
                            if (!$joinTable) {
                                throw new \Exception('You must specify the join table of many to many relation (' . $p->name . ') in class ' . $p->class . '!');
                            }
                        }
                        if (!is_null($col) || $relType[0] || $pk) {
                            self::$map[$refClass->name]['properties'][$p->name] = array(
                                'var' => $p->name,
                                self::$TAG_COLUMN => $col ? $col : $p->name,
                                self::$TAG_PRIMARY_KEY => $pk,
                                'relType' => $relType[0],
                                'loadType' => $relType[1],
                                self::$TAG_JOIN_COLUMN => $joinColumn,
                                self::$TAG_ITEM_CLASS => $itemClass,
                                self::$TAG_JOIN_TABLE => $joinTable,
                                self::$TAG_INVERSE_JOIN_COLUMN => $inverseJoinColumn,
                                self::$TAG_NULLABLE => self::is($p, self::$TAG_NULLABLE),
                                self::$TAG_SEARCH_MODE => self::getAnnotationValue($p, self::$TAG_SEARCH_MODE),
                                self::$TAG_DATETIME => self::is($p, self::$TAG_DATETIME),
                                self::$TAG_TO_JSON => self::is($p, self::$TAG_TO_JSON),
                                'fieldJSON' => $fieldJson ? $fieldJson : ltrim(strtolower(preg_replace('/[A-Z]([A-Z](?![a-z]))*/', '_$0', $p->name)), '_'),
                                self::$TAG_TO_JSON_FIELD => self::getAnnotationValue($p, self::$TAG_TO_JSON_FIELD),
                                self::$TAG_USERNAME_FIELD => self::is($p, self::$TAG_USERNAME_FIELD),
                                self::$TAG_PASSWORD_FIELD => self::is($p, self::$TAG_PASSWORD_FIELD),
                                self::$TAG_REMEMBER_TOKEN_FIELD => self::is($p, self::$TAG_REMEMBER_TOKEN_FIELD),
                                self::$TAG_FILLABLE => self::is($p, self::$TAG_FILLABLE),
                            );
                            if ($auditable) {
                                $value = self::getAnnotationValue($p, self::$TAG_AUDITABLE);
                                self::$map[$refClass->name]['properties'][$p->name][self::$TAG_AUDITABLE] = $value ? $value : $p->name;
                                if (!is_null($auditableField) && !empty($auditableField)) {
                                    self::$map[$refClass->name]['properties'][$p->name][self::$TAG_AUDITABLE_FIELD] = $auditableField;
                                }
                            }
                        }
                    }
                }
            }
            self::mapClass($refClass->getParentClass());
        }
    }

    /**
     * @param \ReflectionObject $reflection
     */
    private static function getAnnotationValue($reflection, $annotation) {
        $refComments = $reflection->getDocComment();
        if (preg_match('/@' . $annotation . '[ \t]*\([ \t]*([a-zA-Z_0-9 âÂãÃ    áÁàÀêÊéÉèÈíÍìÌôÔõÕóÓòÒúÚùÙûÛçÇºª\\\\%]+)?[ \t]*\)/', $refComments, $matches)) {
            if (isset($matches[1])) {
                return trim($matches[1]);
            }
            return '';
        }
        return null;
    }

    /**
     * @param \ReflectionObject $reflection
     */
    private static function is($reflection, $annotation) {
        $refComments = $reflection->getDocComment();
        if (preg_match('/@' . $annotation . '/', $refComments)) {
            return true;
        }
        return false;
    }

    /**
     * @param \ReflectionObject $reflection
     */
    private static function getRelType($reflection) {
        $type = self::getAnnotationValue($reflection, 'manyToOne');
        if ($type)
            return array(self::MANY_TO_ONE, $type);
        $type = self::getAnnotationValue($reflection, 'oneToMany');
        if ($type)
            return array(self::ONE_TO_MANY, $type);
        $type = self::getAnnotationValue($reflection, 'manyToMany');
        if ($type)
            return array(self::MANY_TO_MANY, $type);
        return array(0, null);
    }

    public static function getPk($className) {
        $className = ltrim($className, '\\');
        $auxClass = $className;
        if ($className) {
            $i = 0;
            while ($className != '' && $className != 'Hypersistence') {
                foreach (self::$map[$className]['properties'] as $p) {
                    if ($p[self::$TAG_PRIMARY_KEY]) {
                        $p['i'] = $i;
                        $p[self::$TAG_TABLE] = self::$map[$className][self::$TAG_TABLE];
                        return $p;
                    }
                }
                $className = self::$map[$className]['parent'];
                $i++;
            }
        }
        throw new \Exception('No primary key found in class ' . $auxClass . '! You must specify a primary key (@primaryKey) in the property that represent it.');
        return null;
    }

    public static function getAuthFields($className, $field) {
        $className = ltrim($className, '\\');
        $auxClass = $className;
        if ($className) {
            while ($className != '' && $className != 'Hypersistence') {
                foreach (self::$map[$className]['properties'] as $p) {
                    if ($p[$field]) {
                        return $p['var'];
                    }
                }
                $className = self::$map[$className]['parent'];
            }
            return null;
        }
        throw new \Exception('No auth field found in class ' . $auxClass . '!');
        return null;
    }

    public static function getPropertyByColumn($className, $column) {
        if ($className) {
            while ($className != '' && $className != 'Hypersistence') {
                foreach (self::$map[$className]['properties'] as $p) {
                    if ($p[self::$TAG_COLUMN] == $column) {
                        return $p;
                    }
                }
                $className = self::$map[$className]['parent'];
            }
        }
        return null;
    }

    public static function getPropertyByVarName($className, $varName) {
        if ($className) {
            $i = 0;
            while ($className != '' && $className != 'Hypersistence') {
                foreach (self::$map[$className]['properties'] as $p) {
                    if ($p['var'] == $varName) {
                        $p['i'] = $i;
                        return $p;
                    }
                }
                $className = self::$map[$className]['parent'];
                $i++;
            }
        }
        return null;
    }

    /**
     * @param boolean $forceReload
     * @return \Hypersistence
     */
    public function load($forceReload = false) {
        if (!$forceReload && $this->loaded) {
            return $this;
        }
        $classThis = self::init($this);

        $tables = array();
        $joins = array();
        $bounds = array();
        $fields = array();

        $aliases = 'abcdefghijklmnopqrstuvwxyz';

        $class = $classThis;

        if ($pk = self::getPk($class)) {
            $get = 'get' . $pk['var'];
            $joins[] = $aliases[$pk['i']] . '.' . $pk[self::$TAG_COLUMN] . ' = ' . ':' . $aliases[$pk['i']] . '_' . $pk[self::$TAG_COLUMN];
            $bounds[':' . $aliases[$pk['i']] . '_' . $pk[self::$TAG_COLUMN]] = $this->$get();
        }


        $i = 0;
        while ($class != '' && $class != 'Hypersistence') {
            $alias = $aliases[$i];
            $class = ltrim($class, '\\');
            $tables[] = DB::ec() . self::$map[$class][self::$TAG_TABLE] . DB::ec() . ' ' . $alias;

            if (self::$map[$class]['parent'] != 'Hypersistence') {
                $parent = self::$map[$class]['parent'];
                $pk = self::getPk(self::$map[$parent]['class']);
                $joins[] = $alias . '.' . self::$map[$class][self::$TAG_JOIN_COLUMN] . ' = ' . $aliases[$i + 1] . '.' . $pk[self::$TAG_COLUMN];
            }

            foreach (self::$map[$class]['properties'] as $p) {
                if ($p['relType'] != self::MANY_TO_MANY && $p['relType'] != self::ONE_TO_MANY) {
                    $fields[] = $alias . '.' . $p[self::$TAG_COLUMN] . ' as ' . $alias . '_' . $p[self::$TAG_COLUMN];
                }
            }

            $class = self::$map[$class]['parent'];
            $i++;
        }

        $sql = 'select ' . implode(',', $fields) . ' from ' . implode(',', $tables) . ' where ' . implode(' and ', $joins);

        if ($stmt = DB::getDBConnection()->prepare($sql)) {
            if ($stmt->execute($bounds) && $stmt->rowCount() > 0) {
                $this->loaded = true;
                $result = $stmt->fetchObject();
                $class = ltrim($classThis, '\\');
                $i = 0;
                while ($class != '' && $class != 'Hypersistence') {
                    $alias = $aliases[$i];
                    foreach (self::$map[$class]['properties'] as $p) {
                        $var = $p['var'];
                        $set = 'set' . $var;
                        if ($p['relType'] != self::MANY_TO_MANY && $p['relType'] != self::ONE_TO_MANY) {
                            $column = $alias . '_' . $p[self::$TAG_COLUMN];
                            if (isset($result->$column)) {
                                if (method_exists($this, $set)) {
                                    if ($p['relType'] == self::MANY_TO_ONE) {
                                        $objClass = $p[self::$TAG_ITEM_CLASS];
                                        self::init($objClass);
                                        $pk = self::getPk($objClass);
                                        if ($pk) {
                                            $objVar = $pk['var'];
                                            $objSet = 'set' . $objVar;
                                            $obj = new $objClass;
                                            $obj->$objSet($result->$column);
                                            $this->$set($obj);
                                            if ($p['loadType'] == 'eager') {
                                                $obj->load();
                                            }
                                        }
                                    } else {
                                        if ($p[self::$TAG_DATETIME]) {
                                            if (!is_null($result->$column)) {
                                                $this->$set(new DateTime($result->$column));
                                            } else {
                                                $this->$set(null);
                                            }
                                        } else {
                                            $this->$set($result->$column);
                                        }
                                    }
                                }
                            }
                        } else {

                            if ($p['relType'] == self::ONE_TO_MANY) {
                                $objClass = ltrim($p[self::$TAG_ITEM_CLASS], '\\');
                                self::init($objClass);
                                $objFk = self::getPropertyByColumn($objClass, $p[self::$TAG_JOIN_COLUMN]);
                                if ($objFk) {
                                    $obj = new $objClass;
                                    $objSet = 'set' . $objFk['var'];
                                    $obj->$objSet($this);
                                    $search = $obj->search();

                                    if ($p['loadType'] == 'eager') {
                                        $search = $search->execute();
                                    }
                                    $this->$set($search);
                                }
                            } else if ($p['relType'] == self::MANY_TO_MANY) {
                                $search = $this->searchManyToMany($p);

                                if ($p['loadType'] == 'eager')
                                    $search = $search->execute();
                                $this->$set($search);
                            }
                        }
                    }

                    $class = self::$map[$class]['parent'];
                    $i++;
                }
            }else {
                $this->sqlErrorInfo[] = $stmt->errorInfo();
                return false;
            }
        } else {
            $this->sqlErrorInfo[] = DB::getDBConnection()->errorInfo();
            return false;
        }
        return $this;
    }

    /**
     * @return boolean
     */
    public function delete() {

        $classThis = self::init($this);

        $tables = array();
        $joins = array();
        $bounds = array();

        $class = $classThis;

        if ($pk = self::getPk($class)) {
            $get = 'get' . $pk['var'];
            $joins[] = $pk[self::$TAG_TABLE] . '.' . $pk[self::$TAG_COLUMN] . ' = ' . ':' . $pk[self::$TAG_TABLE] . '_' . $pk[self::$TAG_COLUMN];
            $bounds[':' . $pk[self::$TAG_TABLE] . '_' . $pk[self::$TAG_COLUMN]] = $this->$get();
        }

        $i = 0;
        while ($class != '' && $class != 'Hypersistence') {
            $class = ltrim($class, '\\');
            $table = DB::ec() . self::$map[$class][self::$TAG_TABLE] . DB::ec();
            $tables[] = $table;
            $parent = self::$map[$class]['parent'];
            if ($parent != 'Hypersistence') {
                $pk = self::getPk(self::$map[$parent]['class']);
                $joins[] = $table . '.' . self::$map[$class][self::$TAG_JOIN_COLUMN] . ' = ' . self::$map[$parent][self::$TAG_TABLE] . '.' . $pk[self::$TAG_COLUMN];
            }

            $class = self::$map[$class]['parent'];
            $i++;
        }

        $sql = 'delete ' . implode(',', $tables) . ' from ' . implode(',', $tables) . ' where ' . implode(' and ', $joins);

        if ($stmt = DB::getDBConnection()->prepare($sql)) {
            if ($stmt->execute($bounds)) {
                return true;
            } else {
                $this->sqlErrorInfo[] = $stmt->errorInfo();
            }
        }
        $this->sqlErrorInfo[] = DB::getDBConnection()->errorInfo();
        return false;
    }

    /**
     * @return boolean
     */
    public function exists() {

        $classThis = self::init($this);

        $tables = array();
        $joins = array();
        $bounds = array();

        $class = $classThis;

        if ($pk = self::getPk($class)) {
            $get = 'get' . $pk['var'];
            $joins[] = $pk[self::$TAG_TABLE] . '.' . $pk[self::$TAG_COLUMN] . ' = ' . ':' . $pk[self::$TAG_TABLE] . '_' . $pk[self::$TAG_COLUMN];
            $bounds[':' . $pk[self::$TAG_TABLE] . '_' . $pk[self::$TAG_COLUMN]] = $this->$get();
        }

        $i = 0;
        while ($class != '' && $class != 'Hypersistence') {
            $class = ltrim($class, '\\');
            $table = DB::ec() . self::$map[$class][self::$TAG_TABLE] . DB::ec();
            $tables[] = $table;
            $parent = self::$map[$class]['parent'];
            if ($parent != 'Hypersistence') {
                $pk = self::getPk(self::$map[$parent]['class']);
                $joins[] = $table . '.' . self::$map[$class][self::$TAG_JOIN_COLUMN] . ' = ' . self::$map[$parent][self::$TAG_TABLE] . '.' . $pk[self::$TAG_COLUMN];
            }

            $class = self::$map[$class]['parent'];
            $i++;
        }

        $sql = 'select count(*) as total from ' . implode(',', $tables) . ' where ' . implode(' and ', $joins);

        if ($stmt = DB::getDBConnection()->prepare($sql)) {
            if ($stmt->execute($bounds)) {
                $res = $stmt->fetchObject();
                if ($res->total > 0) {
                    return true;
                }
            } else {
                $this->sqlErrorInfo[] = $stmt->errorInfo();
            }
        }
        $this->sqlErrorInfo[] = DB::getDBConnection()->errorInfo();
        return false;
    }

    /**
     *
     * @return boolean
     */
    public function save() {
        $classThis = self::init($this);

        $classes = array();

        $class = $classThis;

        $pk = self::getPk($class);
        $get = 'get' . $pk['var'];
        $id = $this->$get();

        $objOld = NULL;
        $new = is_null($id) || !$this->exists();
        if (!$new) {
            $objOld = new $classThis();
            $set = 'set' . $pk['var'];
            $objOld->$set($id);
            $objOld->load();
        }
        while ($class != '' && $class != 'Hypersistence') {
            $class = ltrim($class, '\\');
            $classes[] = $class;
            $class = self::$map[$class]['parent'];
        }
        $classes = array_reverse($classes);
        foreach ($classes as $class) {
            $bounds = array();
            $fields = array();
            $sql = '';

            if (!$new) {//UPDATE
                $where = $pk[self::$TAG_COLUMN] . ' = :' . $pk[self::$TAG_COLUMN];
                $bounds[':' . $pk[self::$TAG_COLUMN]] = $id;

                $properties = self::$map[$class]['properties'];

                foreach ($properties as $p) {
                    if ($p[self::$TAG_COLUMN] != $pk[self::$TAG_COLUMN] && $p['relType'] != self::MANY_TO_MANY && $p['relType'] != self::ONE_TO_MANY) {
                        $get = 'get' . $p['var'];
                        $fields[] = $p[self::$TAG_COLUMN] . ' = :' . $p[self::$TAG_COLUMN];
                        if ($p['relType'] == self::MANY_TO_ONE) {
                            $obj = $this->$get();
                            if ($obj && $obj instanceof \Hypersistence\Hypersistence) {
                                $objClass = $p[self::$TAG_ITEM_CLASS];
                                self::init($objClass);
                                $objPk = self::getPk($objClass);
                                $objGet = 'get' . $objPk['var'];
                                $bounds[':' . $p[self::$TAG_COLUMN]] = $obj->$objGet();
                            } else {
                                $bounds[':' . $p[self::$TAG_COLUMN]] = null;
                            }
                        } else {
                            $bounds[':' . $p[self::$TAG_COLUMN]] = $this->$get();
                        }
                    }
                }

                if (count($fields)) {
                    $sql = 'update ' . DB::ec() . self::$map[$class][self::$TAG_TABLE] . DB::ec() . ' set ' . implode(',', $fields) . ' where ' . $where;
                }
            } else {//INSERT
                $values = array();
                $properties = self::$map[$class]['properties'];

                $joinColumn = self::$map[$class][self::$TAG_JOIN_COLUMN];
                if ($joinColumn) {
                    $fields[] = $joinColumn;
                    $values[] = ':' . $joinColumn;
                    $bounds[':' . $joinColumn] = $id;
                }

                foreach ($properties as $p) {
                    if ($p['var'] == $this->getPrimaryKeyField()) {
                        continue;
                    }
                    if ($p['relType'] != self::MANY_TO_MANY && $p['relType'] != self::ONE_TO_MANY) {
                        $get = 'get' . $p['var'];
                        $fields[] = $p[self::$TAG_COLUMN];
                        $values[] = ':' . $p[self::$TAG_COLUMN];
                        if ($p['relType'] == self::MANY_TO_ONE) {
                            $obj = $this->$get();
                            if ($obj && $obj instanceof \Hypersistence\Hypersistence) {
                                $objClass = $p[self::$TAG_ITEM_CLASS];
                                self::init($objClass);
                                $objPk = self::getPk($objClass);
                                $objGet = 'get' . $objPk['var'];
                                $bounds[':' . $p[self::$TAG_COLUMN]] = $obj->$objGet();
                            } else {
                                $bounds[':' . $p[self::$TAG_COLUMN]] = null;
                            }
                        } else {
                            $bounds[':' . $p[self::$TAG_COLUMN]] = $this->$get();
                        }
                    }
                }

                if (count($fields)) {
                    $sql = 'insert into ' . DB::ec() . self::$map[$class][self::$TAG_TABLE] . DB::ec() . ' (' . implode(',', $fields) . ') values (' . implode(',', $values) . ')';
                }
            }
            if ($sql != '') {

                if ($stmt = DB::getDBConnection()->prepare($sql)) {

                    if ($stmt->execute($bounds)) {
                        if ($new) {
                            $lastId = DB::getDBConnection()->lastInsertId();
                            if ($lastId) {
                                $id = $lastId;
                                $pk = self::getPk(self::init($this));
                                $set = 'set' . $pk['var'];
                                $this->$set($id);
                            }
                        }
                    } else {
                        $this->sqlErrorInfo[] = $stmt->errorInfo();
                        return false;
                    }
                } else {
                    $this->sqlErrorInfo[] = DB::getDBConnection()->errorInfo();
                    return false;
                }
            }
        }
        $changes = $this->checkChanges($classThis, $classes, $objOld);
        if(count($changes) > 0){
            return $this->saveChanges($changes);
        }
        return true;
    }

    private function saveChanges($changes) {
        $driver = config("database.default");
        if ($driver == 'pgsql'){
          $sql = "\dt *history*";
        } else {
          $sql = "SHOW TABLES LIKE 'history'";
        }
        $stmt = DB::getDBConnection()->prepare($sql);
        $stmt->execute();
        if ($stmt->rowCount() == 0 && count($changes) > 0) {
            throw new \Exception('Table history no exists! Please, execute in console the follow command [php artisan hypersistence:make-history-table]');
            exit;
        }
        if (count($changes) > 0) {
            $class = self::init($this);
            $user = \Illuminate\Support\Facades\Auth::user();
            if ($user != NULL) {
                if (!($user instanceof \Hypersistence\Hypersistence)) {
                    throw new \Exception('The auth user is not a instance of Hypersistence!');
                }
                $pk = self::getPk($class);
                $getUserPk = 'get' . $pk['var'];
            }
            $getThisPk = 'get' . $this->getPrimaryKeyField();
            foreach ($changes as $c) {
                $h = new \Hypersistence\History();
                $h->setAuthor($user != NULL ? $user->$getUserPk() : NULL);
                $h->setAuthorClass($user != NULL ? "\\".get_class($user) : NULL);
                $h->setDate(date('Y-m-d H:i:s'));
                $h->setDescription($c);
                $h->setReferenceId($this->$getThisPk());
                $h->setReferenceTable($this->getTableName());
                if (!$h->save()) {
//                    dd($h->sqlErrorInfo);
                    throw new \Exception('Error to save History');
                }
            }
        }
        return true;
    }

    private function checkChanges($classThis, $classes, $objOld) {
        $changes = array();
        $pk = self::getPk($classThis);
        foreach ($classes as $class) {
            $properties = self::$map[$class]['properties'];

            foreach ($properties as $p) {
                if ($p[self::$TAG_COLUMN] != $pk[self::$TAG_COLUMN] && $p['relType'] != self::MANY_TO_MANY && $p['relType'] != self::ONE_TO_MANY) {
                    if (!isset($p[self::$TAG_AUDITABLE])) {
                        continue;
                    }
                    if (is_null($objOld)) {
                        return array('Efetuou o cadastro.');
                    }
                    $get = 'get' . $p['var'];
                    $fields[] = $p[self::$TAG_COLUMN] . ' = :' . $p[self::$TAG_COLUMN];
                    if ($p['relType'] == self::MANY_TO_ONE) {
                        $attrObj = $this->$get();
                        $attrObjOld = $objOld->$get();
                        $desc = $this->checkChangesObject($p, $attrObj, $attrObjOld);
                        if ($desc != '') {
                            $changes[] = $desc;
                        }
                    } else {
                        $title = $p[self::$TAG_AUDITABLE] != '' ? $p[self::$TAG_AUDITABLE] : $p['var'];
                        $newValue = $this->$get();
                        $oldValue = $objOld->$get();
                        if ($newValue != $oldValue) {
                            if ($oldValue === NULL) {
                                $changes[] = 'Configurou o campo ' . $title . ' para ' . $newValue . '.';
                            } else if ($newValue === NULL) {
                                $changes[] = 'Removeu o campo ' . $title . '. Valor antigo: ' . $oldValue . '.';
                            } else {
                                $changes[] = 'Alterou o campo ' . $title . ' de ' . $oldValue . ' para ' . $newValue . '.';
                            }
                        }
                    }
                }
            }
        }
        return $changes;
    }

    private function checkChangesObject($property, $attrObj, $attrObjOld) {
        if (($attrObj && $attrObj instanceof \Hypersistence\Hypersistence) || (($attrObjOld && $attrObjOld instanceof \Hypersistence\Hypersistence))) {
            $objClass = $property[self::$TAG_ITEM_CLASS];
            self::init($objClass);
            if ($attrObjOld != NULL) {
                $attrObjOld->load();
            }
            if ($attrObj != NULL) {
                $attrObj->load();
            }
            $title = $property[self::$TAG_AUDITABLE] != '' ? $property[self::$TAG_AUDITABLE] : $property['var'];
            if (isset($property[self::$TAG_AUDITABLE_FIELD])) {
                $objMehtod = 'get' . $property[self::$TAG_AUDITABLE_FIELD];
            } else {
                $objPk = self::getPk($objClass);
                $objMehtod = 'get' . $objPk['var'];
            }
            $var = $attrObj->$objMehtod();
            if (!is_object($var)) {
                if ($attrObjOld == NULL) {
                    return 'Setou o campo ' . $title . ' para ' . $var . '.';
                } else if ($attrObj == NULL) {
                    return 'Removeu o campo ' . $title . '. Valor antigo: ' . $attrObjOld->$objMehtod() . '.';
                } else if ($attrObjOld->$objMehtod() != $var) {
                    return 'Alterou o campo ' . $title . ' de ' . $attrObjOld->$objMehtod() . ' para ' . $var . '.';
                }
            }
        }
        return '';
    }

    public function getHistory() {
        $pk = $this->getPrimaryKeyField();
        $get = 'get' . $pk;

        $h = new \Hypersistence\History();
        $h->setReferenceId($this->$get());
        $h->setReferenceTable($this->getTableName());
        $list = $h->search()->execute();
        if (count($list) > 0) {
            foreach ($list as $idx => $h) {
                if ($h->getAuthor() != NULL) {
                    $userClass = $h->getAuthorClass();
                    $user = new $userClass();
                    $set = 'set' . $user->getPrimaryKeyField();
                    $user->$set($h->getAuthor());
                    $h->setAuthor($user);
                    $list[$idx] = $h;
                }
            }
        }
        return $list;
    }

    /**
     *
     * @return QueryBuilder
     */
    public function search() {
        return new QueryBuilder($this);
    }

    /**
     *
     * @param array $property
     * @return QueryBuilder
     */
    private function searchManyToMany($property) {
        $class = $property['itemClass'];
        $object = new $class;
        return new QueryBuilder($object, $this, $property);
    }

    public function __call($name, $arguments) {
        if (preg_match('/^(add|delete)([A-Za-z_][A-Za-z0-9_]*)/', $name, $matches)) {

            if (isset($matches[2])) {
                $varName = $matches[2];
                $varName = strtolower($varName[0]) . substr($varName, 1);
                $className = self::init($this);
                $className = ltrim($className, '\\');
                $property = self::getPropertyByVarName($className, $varName);
                if ($property) {

                    if ($property['relType'] == self::MANY_TO_MANY) {

                        $class = $property[self::$TAG_ITEM_CLASS];
                        $obj = $arguments[0];
                        if ($obj instanceof $class) {
                            $obj->load();
                            $table = DB::ec() . $property['joinTable'] . DB::ec();
                            $inverseColumn = $property[self::$TAG_INVERSE_JOIN_COLUMN];
                            $column = $property[self::$TAG_JOIN_COLUMN];

                            $pk = self::getPk($className);
                            $inversePk = self::getPk($class);

                            $get = 'get' . $pk['var'];
                            $inverseGet = 'get' . $inversePk['var'];
                            $changes = [];
                            if (isset($property[self::$TAG_AUDITABLE])) {
                                $title = $property[self::$TAG_AUDITABLE] != '' ? $property[self::$TAG_AUDITABLE] : $property['var'];
                                $action = $matches[1] == 'add' ? 'Adicionou' : 'Removeu';
                                if (isset($property[self::$TAG_AUDITABLE_FIELD])) {
                                    $methodHistory = 'get' . $property[self::$TAG_AUDITABLE_FIELD];
                                } else {
                                    $methodHistory = $inverseGet;
                                }
                                $changes[] = "$action $title " . $obj->$methodHistory();
                            }

                            if ($matches[1] == 'add') {
                                $sql = "insert into $table ($column, $inverseColumn) values (:column, :inverseColumn)";
                            } else if ($matches[1] == 'delete') {
                                $sql = "delete from $table where $column = :column and $inverseColumn = :inverseColumn";
                            }
                            if ($stmt = DB::getDBConnection()->prepare($sql)) {
                                $stmt->bindValue(':column', $this->$get());
                                $stmt->bindValue(':inverseColumn', $obj->$inverseGet());
                                if ($stmt->execute()) {
                                    return $this->saveChanges($changes);
                                }
                            }
                        } else {
                            throw new \Exception('You must pass an instance of ' . $class . ' to ' . $name . '!');
                        }
                    }
                } else {
                    throw new \Exception('Property ' . $varName . ' not found!');
                }
            }
        }
        return false;
    }

    public static function commit() {
        return DB::getDBConnection()->commit();
    }

    public static function rollback() {
        return DB::getDBConnection()->rollBack();
    }

    public function sqlErrorInfo() {
        return $this->sqlErrorInfo;
    }

    public function toJSON() {
        $this->load();
        $json = array();

        $classThis = self::init($this);

        $class = $classThis;

        $i = 0;
        while ($class != '' && $class != 'Hypersistence') {
            $class = ltrim($class, '\\');
            $pk = self::getPk($class);

            $properties = self::$map[$class]['properties'];

            if (self::$map[$class]['parent'] != 'Hypersistence') {
                $parent = self::$map[$class]['parent'];
                $pk = self::getPk(self::$map[$parent]['class']);
            }
            foreach ($properties as $p) {
                if ($p[self::$TAG_TO_JSON] && $p['relType'] != self::MANY_TO_MANY && $p['relType'] != self::ONE_TO_MANY) {
                    $get = 'get' . $p['var'];
                    $fields[] = $p[self::$TAG_COLUMN] . ' = :' . $p[self::$TAG_COLUMN];
                    $field = $p['fieldJSON'];
                    if ($p['relType'] == self::MANY_TO_ONE) {
                        if (isset($p[self::$TAG_TO_JSON_FIELD]) && $p[self::$TAG_TO_JSON_FIELD] != '') {
                            $getJsonField = 'get' . $p[self::$TAG_TO_JSON_FIELD];
                            $obj = $this->$get();
                            if ($obj != NULL && $obj->load()) {
                                $result = $obj->$getJsonField();
                                if ($result && $result instanceof \Hypersistence\Hypersistence) {
                                    $json[$field] = $result->toJSON();
                                } else {
                                    $json[$field] = $result;
                                }
                            } else {
                                $json[$field] = NULL;
                            }
                        } else {
                            $obj = $this->$get();
                            if ($obj && $obj instanceof \Hypersistence\Hypersistence) {
                                $json[$field] = $this->$get()->toJSON();
                            }
                        }
                    } else {
                        $json[$field] = $this->$get();
                    }
                }
            }
            $class = self::$map[$class]['parent'];
            $i++;
        }
        return (object) $json;
    }

    public function fill($data, $checkFillable = true) {

        $class = ltrim(self::init($this), '\\');
        while ($class != null && $class !== 'Hypersistence') {
            $properties = self::$map[$class]['properties'];
            foreach ($properties as $p) {
                $name = $p['var'];
                $setter = 'set' . $name;
                if (!$p[self::$TAG_FILLABLE] && $checkFillable) {
                    continue;
                }
                if (array_key_exists($name, $data)) {
                    if ($data[$name] !== NULL) {
                        if ($p['relType'] == self::MANY_TO_ONE) {
                            $objClass = $p[self::$TAG_ITEM_CLASS];
                            if (is_a($data[$name], $objClass)) {
                                $this->$setter($data[$name]);
                            } else {
                                self::init($objClass);
                                $objPk = self::getPk($objClass);
                                $objSet = 'set' . $objPk['var'];
                                $obj = new $objClass();
                                $obj->$objSet($data[$name]);
                                $this->$setter($obj);
                            }
                        } else {
                            $this->$setter($data[$name]);
                        }
                    }
                } else {
                    $key = preg_grep('/^' . $name . '_/', array_keys($data));
                    if (count($key) > 0 && $p['relType'] == self::MANY_TO_ONE) {
//                        $key = array_shift($key);
                        foreach ($key as $k) {
                            $vars = explode("_", $k);
                            if (count($vars) > 1) {
                                $var = array_shift($vars);
                                if ($var == $name) {
                                    $setter = 'set' . $name;
                                    $getter = 'get' . $name;
                                    $objClass = $p[self::$TAG_ITEM_CLASS];
                                    $this->$setter($this->setRecursiveValuesToFill($objClass, $vars, $data[$k], $this->$getter(), $checkFillable));
                                }
                            }
                        }
                    }
                }
            }
            if (self::$map[$class]['parent'] != null) {
                $class = self::$map[$class]['parent'];
            } else {
                $class = null;
            }
        }
    }

    private function setRecursiveValuesToFill($objClass, $vars, $value, $obj = null, $checkFillable = null) {

        self::init($objClass);
        $obj = $obj != NULL ? $obj : new $objClass();
        $var = array_shift($vars);
        $setter = 'set' . $var;
        $getter = 'get' . $var;
        while ($objClass != null && $objClass !== 'Hypersistence') {
            $p = isset(self::$map[ltrim($objClass, '\\')]['properties'][$var]) ? self::$map[ltrim($objClass, '\\')]['properties'][$var] : array();
            if (count($p) > 0) {
                if ($p['relType'] == self::MANY_TO_ONE) {
                    $obj->$setter($this->setRecursiveValuesToFill($p[self::$TAG_ITEM_CLASS], $vars, $value, $obj->$getter(), $checkFillable));
                    return $obj;
                } else {
                    if (!$p[self::$TAG_FILLABLE] && $checkFillable) {
                        return NULL;
                    }
                    $obj->$setter($value);
                    return $obj;
                }
            }
            if (self::$map[ltrim($objClass, '\\')]['parent'] != null) {
                $objClass = self::$map[ltrim($objClass, '\\')]['parent'];
            } else {
                $objClass = null;
            }
        }
        return null;
    }

    public static function create($data) {
        $r = new \ReflectionClass(get_called_class());

        $className = $r->getName();
        $p = new $className();
        $p->fill($data, false);
        if (!$p->save()) {
            dd($p->sqlErrorInfo());
            throw new \Exception("Database Error!", 1);
        }
        return $p;
    }

    public static function loadById($id) {
        $r = new \ReflectionClass(get_called_class());

        $className = $r->getName();
        $p = new $className();
        $pk = $p->getPrimaryKeyField();
        $setter = "set$pk";
        $p->$setter($id);
        return $p->load();
    }

    public function getTableName() {
        $refClass = new \ReflectionClass($this);
        $tableName = self::getAnnotationValue($refClass, self::$TAG_TABLE);
        if (isset($tableName)) {
            return $tableName;
        }
        return null;
    }

    public function getPrimaryKeyField() {
        $refClass = self::init($this);
        $pk = self::getPk($refClass)['var'];

        if (isset($pk)) {
            return $pk;
        }
        return 'id';
    }

    public function getUsernameField() {
        $refClass = self::init($this);
        $var = self::getAuthFields($refClass, self::$TAG_USERNAME_FIELD);

        if (isset($var)) {
            return $var;
        }
        return 'username';
    }

    public function getPasswordField() {
        $refClass = self::init($this);
        $var = self::getAuthFields($refClass, self::$TAG_PASSWORD_FIELD);

        if (isset($var)) {
            return $var;
        }
        return 'password';
    }

    public function getRememberTokenField() {
        $refClass = self::init($this);
        $var = self::getAuthFields($refClass, self::$TAG_REMEMBER_TOKEN_FIELD);

        if (isset($var)) {
            return $var;
        }
        return 'rememberToken';
    }

}
