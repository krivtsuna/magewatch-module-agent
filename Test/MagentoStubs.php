<?php

declare(strict_types=1);

/**
 * Minimal Magento types so agent unit tests can run without magento/framework.
 */
namespace Magento\Framework\App {
    if (! interface_exists(CacheInterface::class)) {
        interface CacheInterface
        {
            /**
             * @return string|false
             */
            public function load(string $identifier);

            /**
             * @param  string  $data
             * @param  list<string>  $tags
             */
            public function save($data, $identifier, array $tags = [], $lifeTime = null): bool;

            public function remove($identifier): bool;

            public function clean($tags = []): bool;
        }
    }

    if (! class_exists(ResourceConnection::class)) {
        class ResourceConnection
        {
            public function getConnection() {}

            public function getTableName($modelEntity) {}
        }
    }
}

namespace Magento\Framework\DB {
    if (! class_exists(Select::class)) {
        class Select
        {
            public function from($name, $cols = '*') {}

            public function join($name, $cond, $cols = '*') {}

            public function joinInner($name, $cond, $cols = '*') {}

            public function joinLeft($name, $cond, $cols = '*') {}

            public function where($cond, $value = null, $type = null) {}

            public function group($spec) {}

            public function order($spec) {}

            public function limit($count = null, $offset = null) {}

            public function distinct($flag = true) {}
        }
    }
}

namespace Magento\Framework\DB\Adapter {
    if (! interface_exists(AdapterInterface::class)) {
        interface AdapterInterface
        {
            public function select();

            public function fetchAll($sql, $bind = [], $fetchMode = null);

            public function fetchCol($sql, $bind = [], $fetchMode = null);

            public function fetchOne($sql, $bind = [], $fetchMode = null);

            public function fetchPairs($sql, $bind = []);

            public function isTableExists($tableName, $schemaName = null);

            public function quoteInto($text, $value, $type = null, $count = null);
        }
    }
}

namespace Magento\Framework\DB\Sql {
    if (! class_exists(Expression::class)) {
        class Expression
        {
            public function __construct(private readonly string $expression = '') {}

            public function __toString(): string
            {
                return $this->expression;
            }
        }
    }
}
