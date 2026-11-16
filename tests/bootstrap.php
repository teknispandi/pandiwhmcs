<?php

/**
 * Minimal stand-ins for the WHMCS functions and classes the module uses.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace {
    define('WHMCS', true);

    $GLOBALS['moduleLog'] = [];
    $GLOBALS['activityLog'] = [];
    $GLOBALS['tokenChecks'] = 0;

    function logModuleCall($module, $action, $request, $response, $processed = null, $replaceVars = [])
    {
        $GLOBALS['moduleLog'][] = compact('module', 'action', 'request', 'response', 'processed', 'replaceVars');
    }

    function logActivity($message, $userId = 0)
    {
        $GLOBALS['activityLog'][] = $message;
    }

    function check_token($namespace = 'WHMCS.default')
    {
        ++$GLOBALS['tokenChecks'];
    }
}

namespace WHMCS\Domains\DomainLookup {
    class ResultsList extends \ArrayObject
    {
    }

    class SearchResult
    {
        const STATUS_NOT_REGISTERED = 'available';
        const STATUS_REGISTERED = 'registered';
        const STATUS_RESERVED = 'reserved';
        const STATUS_TLD_NOT_SUPPORTED = 'unknown';

        public $sld;
        public $tld;
        public $status;
        public $score;

        public function __construct($sld, $tld)
        {
            $this->sld = $sld;
            $this->tld = $tld;
        }

        public function setStatus($status)
        {
            $this->status = $status;
        }

        public function setScore($score)
        {
            $this->score = $score;
        }
    }
}

namespace WHMCS\Database {
    /**
     * In-memory subset of the Laravel query builder.
     */
    class Capsule
    {
        /** @var array table => list of row objects */
        public static $tables = [];

        public static function table($name)
        {
            return new FakeQuery($name);
        }

        public static function schema()
        {
            return new FakeSchema();
        }
    }

    class FakeSchema
    {
        public function hasTable($table)
        {
            return array_key_exists($table, Capsule::$tables);
        }

        public function create($table, callable $callback)
        {
            Capsule::$tables[$table] = [];
            $callback(new FakeBlueprint());
        }
    }

    class FakeBlueprint
    {
        public function __call($name, $arguments)
        {
            return $this;
        }
    }

    class FakeQuery
    {
        private $table;
        private $wheres = [];

        public function __construct($table)
        {
            $this->table = $table;
        }

        public function where($column, $operator, $value = null)
        {
            if (func_num_args() === 2) {
                $value = $operator;
                $operator = '=';
            }
            $this->wheres[] = [$column, $operator, $value];

            return $this;
        }

        public function whereIn($column, array $values)
        {
            $this->wheres[] = [$column, 'in', $values];

            return $this;
        }

        public function first()
        {
            foreach ($this->rows() as $row) {
                if ($this->matches($row)) {
                    return $row;
                }
            }

            return null;
        }

        public function get()
        {
            return array_values(array_filter($this->rows(), [$this, 'matches']));
        }

        public function update(array $values)
        {
            $count = 0;
            foreach ($this->rows() as $row) {
                if ($this->matches($row)) {
                    foreach ($values as $key => $value) {
                        $row->$key = $value;
                    }
                    ++$count;
                }
            }

            return $count;
        }

        public function insert(array $values)
        {
            if (!array_key_exists($this->table, Capsule::$tables)) {
                throw new \RuntimeException('Table ' . $this->table . ' does not exist');
            }
            Capsule::$tables[$this->table][] = (object) $values;

            return true;
        }

        public function delete()
        {
            if (!array_key_exists($this->table, Capsule::$tables)) {
                return 0;
            }
            $before = count(Capsule::$tables[$this->table]);
            Capsule::$tables[$this->table] = array_values(array_filter(Capsule::$tables[$this->table], function ($row) {
                return !$this->matches($row);
            }));

            return $before - count(Capsule::$tables[$this->table]);
        }

        private function rows()
        {
            return isset(Capsule::$tables[$this->table]) ? Capsule::$tables[$this->table] : [];
        }

        private function matches($row)
        {
            foreach ($this->wheres as $where) {
                list($column, $operator, $value) = $where;
                $actual = isset($row->$column) ? $row->$column : null;
                if ($operator === 'in') {
                    if (!in_array($actual, $value)) {
                        return false;
                    }
                } elseif ($operator === '=') {
                    if ($actual != $value) {
                        return false;
                    }
                } elseif ($operator === '<=') {
                    if (!($actual <= $value)) {
                        return false;
                    }
                } else {
                    throw new \LogicException('Unsupported operator ' . $operator);
                }
            }

            return true;
        }
    }
}
