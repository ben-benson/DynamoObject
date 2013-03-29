<?php

namespace BenBenson;

use \Config;
use \Exception;
use \Aws\DynamoDb\DynamoDbClient;
use \Aws\Common\Enum\Region;
use \Aws\DynamoDb\Exception\DynamoDbException;


/*
    TODO
	- preloading method 
		- preloadAllRelations($cascade=false)
	- offer automatic uuid generation
	- custom exception(s)
	- handle S, N, SS, NN with schema config
	- delete of attribute should potentially delete its relation mapping  (cascade?)

    FUTURE
	- method for validating table mapping configuration
	- create cascading save
*/

class DynamoObject
{
        private static $dynamo;
        private static $initialized = false;
	private static $objectCache = array();

	private $tableName;
	private $data;
	private $isLoaded = false;
	private $modifiedColumns = array();
	private $relations = array();



	// INSTANCE METHODS =========================================================

	public function getTableName()
	{
		return $this->tableName;
	}
        public function getId()
        {
                if ($this->hasRangeKey())
                {
                        return $this->getHashKey() . '.' . $this->getRangeKey();
                }
                return $this->hashKey;
        }
	public function getHashKey()
	{
		if (isset($this->data[Config::$dynamo_table_mapping[$this->tableName]['hashKey']]))
		{
			return $this->data[Config::$dynamo_table_mapping[$this->tableName]['hashKey']];
		}
	}
	public function setHashKey($value)
	{
		if ($this->getHashKey() == $value)
		{
			return;
		}
		$this->data[Config::$dynamo_table_mapping[$this->tableName]['hashKey']] = $value;
		$this->isLoaded = false;
	}
	public function hasRangeKey()
	{
		return isset(Config::$dynamo_table_mapping[$this->tableName]['rangeKey']);
	}
	public function getRangeKey()
	{
		if ($this->hasRangeKey())
		{
			if (isset($this->data[Config::$dynamo_table_mapping[$this->tableName]['rangeKey']]))
			{
				return $this->data[Config::$dynamo_table_mapping[$this->tableName]['rangeKey']];
			}
		}
	}
	public function setRangeKey($value)
	{
		if (! $this->hasRangeKey())
		{
			throw new Exception('no range key property for this object');
		}
		else if ($this->getRangeKey() == $value)
		{
			return;
		}

		$this->data[Config::$dynamo_table_mapping[$this->tableName]['rangeKey']] = $value;
		$this->isLoaded = false;
	}

	public function isKeyValid()
	{
		if (! $this->getHashKey())
		{
			return false;
		}
		if ($this->hasRangeKey() && ! $this->getRangeKey())
		{
			return false;
		}
		return true;
	}

	public function getIterator()
	{
		// merge in relations
		$merged = array_merge($this->data, $this->relations);
		$o = new ArrayObject($merged);
		return $o->getIterator();
	}

	public function getKeys()
	{
		return array_keys($this->data);
	}

	public function isModified()
	{
		if (count($this->modifiedColumns) > 0)
		{
			return true;
		}
		return false;
	}
	public function isColumnModified($column)
	{
		return isset($this->modifiedColumns[$column]);
	}
	public function getModifiedColumns()
	{
		return $this->modifiedColumns;
	}

	public function getAllRelations()
	{
		return $this->relations;
	}



	// PERSISTENCE METHODS =======================================================

	public function load()
        {
                if (! $this->isKeyValid())
                {
                        throw new Exception('object key is not properly defined');
                }

                $get = array(
                        'TableName' => $this->tableName,
                        'Key' => array(
                                'HashKeyElement' => self::getClient()->formatValue($this->getHashKey())
                        )
                );

                if ($this->hasRangeKey())
                {
                        $get['Key']['RangeKeyElement'] = self::getClient()->formatValue($this->getRangeKey());
                }

		$result = array();
                try
                {
                        $result = self::getClient()->getItem($get);
                }
                catch(Exception $e)
                {
                        return;
                }
                if (! isset($result['Item']))
                {
                        return;
                }

                $this->data = self::unformat($result['Item']);
		$this->isLoaded = true;
		$this->modifiedColumns = array();		
		$this->createProxies($this->data);

		// save in static cache
		self::add2cache($this);
	}


        public function update()
        {
               	if (! $this->isModified())
		{
			return;
		}
               	if (! $this->isKeyValid())
		{
			throw new Exception('object key is not properly defined');
		}

                $update = array(
                        'TableName' => $this->tableName,
                     	'Key' => array(
                                'HashKeyElement' => array(
                                        self::type($this->getHashKey()) => $this->getHashKey()
                                ),
                        ),
                        'ReturnValues' => 'ALL_NEW'
                );

		if ($this->hasRangeKey())
		{
			$update['RangeKeyElement'] = array(
                        	self::type($this->getRangeKey()) => $this->getRangeKey()
                        );
		}

		if ($this->isLoaded)
		{
			$cols = array_keys($this->modifiedColumns);
		}
		else
		{
			$cols = array_keys($this->data);
		}

               	foreach ($cols as $name)
                {
			if (isset($this->data[$name]))
			{
        	                $update['AttributeUpdates'][ $name ] = array(
	                                "Value" => array( self::type($this->data[$name]) => $this->data[$name] ),
                	               	"Action" => 'PUT'
                        	);
			}
			else
			{
        	                $update['AttributeUpdates'][ $name ] = array(
                	               	"Action" => 'DELETE'
                        	);
			}

                }

               	$result = self::getClient()->updateItem( $update );

                if (! isset($result['Attributes']))
                {
                        throw new Exception('updated failed, no results returned');
                }

                $this->data = self::unformat($result['Attributes']);
		$this->modifiedColumns = array();
		$this->isLoaded = true;
        }


        public function insert()
        {
		if ($this->isLoaded())
		{
			throw new Exception('cannot insert, already exists');
		}
               	else if (! $this->isKeyValid())
		{
			throw new Exception('object key is not properly defined');
		}
               	else if (! $this->isModified())
		{
			return;
		}

		$insert = array(
			'TableName' => $this->tableName,
			'Item' => self::getClient()->formatAttributes($this->data),
			'Expected' => array(
				Config::$dynamo_table_mapping[$this->tableName]['hashKey'] => array( 'Exists' => false )
			)
                );

		if ($this->hasRangeKey())
		{
			$insert['Expected'][Config::$dynamo_table_mapping[$this->tableName]['rangeKey']] = array( 'Exists' => false );
		}

                try
                {
                        $result = self::getClient()->putItem( $insert );
                }
                catch(Aws\DynamoDb\Exception\ConditionalCheckFailedException $e)
                {
                       	throw new Exception('key already exists');
                }


		$this->modifiedColumns = array();
		$this->isLoaded = true;
        }


	// we allow the caller to perform even when isLoaded==false...  
	// dangerous, but we give them the power

        public function addReplace()
        {
               	if (! $this->isKeyValid())
		{
			throw new Exception('object key is not properly defined');
		}

                $result = self::getClient()->putItem( array(
                        'TableName' => $this->tableName,
                        'Item' => self::getClient()->formatAttributes($this->data),
                        'ReturnValues' => 'ALL_OLD'
                ));

		$this->modifiedColumns = array();
		$this->isLoaded = true;

                if (! isset($result['Attributes']))
                {
                        return;
                }

                return self::unformat($result['Attributes']);
        }

        public function delete()
        {
               	if (! $this->isKeyValid())
		{
			throw new Exception('object key is not properly defined');
		}

		$delete = array(
                        'TableName' => $this->tableName,
                     	'Key' => array(
                                'HashKeyElement' => array(
                                        self::type($this->getHashKey()) => $this->getHashKey()
                                ),
                        ),
                        'ReturnValues' => 'NONE'
                );

		if ($this->hasRangeKey())
		{
			$delete['Key']['RangeKeyElement'] = array(
                        	self::type($this->getRangeKey()) => $this->getRangeKey()
                        );
		}

               	try
                {
                        $result = self::getClient()->deleteItem( $delete );
                }
                catch(Aws\DynamoDb\Exception\ResourceNotFoundException $e)
               	{
                        throw new Exception('key does not exist');
                }
        }



	// STATIC FACTORIES ==========================================================

	public static function create($table, array $data=array())
	{
		$clazz = self::getObjectClass($table);
		return new $clazz($table, $data);
	}

	public static function fetch($table, $hashKey, $rangeKey='')
	{
		$clazz = self::getObjectClass($table);
		$obj = new $clazz($table);
		$obj->setHashKey($hashKey);
		$obj->setRangeKey($rangeKey);

		$id = $obj->getId();

		// check cache
		if (isset(self::$objectCache[$table][$id]))
		{
			error_log("returning cached object for $table->$id");
			return self::$objectCache[$table][$id];
		}

		$obj->load();
		return $obj;
	}

        public static function range_backward($table, $key, $limit=0, $lastKey=array())
        {
                self::initialize();

                $q = array(
                        'TableName' => $table,
                        'HashKeyValue' => array( self::type($key) => $key),
                        'ScanIndexForward' => false
                );

                if ($limit)
                {
                        $q['Limit'] = $limit;
                }
                if ($lastKey)
                {
                        $q['ExclusiveStartKey'] = $lastKey;
                }

                $response = self::getClient()->query( $q );

                $lastKey = null;
                if (isset($response['LastEvaluatedKey']))
                {
                        $lastKey = $response['LastEvaluatedKey'];
                }

		$clazz = self::getObjectClass($table);
		$objs = array();
		foreach ($response['Items'] as $item)
		{
			$obj = new $clazz($table, self::unformat($item));
			$obj->setIsLoaded(true);
			$objs[] = $obj;
		}

                return array($objs, $lastKey);
	}

	public static function range_prefix($table, $key, $rangeKeyPrefix, $limit=0, $lastKey=array())
        {
                self::initialize();
                $q = array(
                        'TableName' => $table,
                        'HashKeyValue' => array( self::type($key) => $key),
                        'RangeKeyCondition' => array(
                                'ComparisonOperator' => 'BEGINS_WITH',
                                'AttributeValueList' => array(
                                        array( self::type($rangeKeyPrefix) => $rangeKeyPrefix )
                                )
                        )
                );

                if ($limit)
                {
                        $q['Limit'] = $limit;
                }
                if ($lastKey)
                {
                        $q['ExclusiveStartKey'] = $lastKey;
                }

                $response = self::getClient()->query( $q );

		$lastKey = null;
                if (isset($response['LastEvaluatedKey']))
                {
                        $lastKey = $response['LastEvaluatedKey'];
                }

		$clazz = self::getObjectClass($table);
		$objs = array();
		foreach ($response['Items'] as $item)
		{
			$obj = new $clazz($table, self::unformat($item));
			$obj->setIsLoaded(true);
			$objs[] = $obj;
		}

                return array($objs, $lastKey);
        }

	public static function range_getall($table, $key)
        {
                self::initialize();

                $result = self::getClient()->query( array(
                        'TableName' => $table,
			'HashKeyValue' => self::getClient()->formatAttributes($key)
                ));

                if (! $result || ! isset($result['Items']))
                {
                        return;
                }

                $objs = array();
		$clazz = self::getObjectClass($table);

                foreach ($result['Items'] as $item)
                {
			$obj = new $clazz($table, self::unformat($item));
			$obj->setIsLoaded(true);
                        $objs[] = $obj;
                }

                return $objs;
        }



	// UTILITIY METHODS ==========================================================

	public static function getClient()
        {
                self::initialize();
                return self::$dynamo;
        }

        public static function getObjectClass($table)
        {
                if (isset(Config::$dynamo_table_mapping[$table]['class']))
                {
                        if (class_exists(Config::$dynamo_table_mapping[$table]['class']))
                        {
                                return Config::$dynamo_table_mapping[$table]['class'];
                        }
                }

                return Config::$dynamo_default_object_class;
        }

	public static function add2cache(\Spiderline\DynamoObject $obj)
	{
		self::initialize();
		self::$objectCache[$obj->getTableName()][$obj->getId()] = $obj;
	}



	// TODO:  pass in objects instead of generic array
        public static function batch_put($tableItems)
        {
                self::initialize();
                $ditems = array();
                $i = 0;

                foreach ($tableItems as $table => $items)
                {
                    foreach ($items as $item)
                    {
                     	$i++;
                        $ditems['RequestItems'][$table][]['PutRequest']['Item'] = self::getClient()->formatAttributes($item);

                        if ($i == 25)
                        {
                                $response = self::getClient()->batchWriteItem($ditems);

                                // examine for any failures
                               	if (! empty($response["UnprocessedItems"]))
                                {
                                       	return false;
                               	}
                               	$i=0;
                                $ditems = array();
                        }
                    }
                }
                if ($i > 0)
                {
                       	$response = self::getClient()->batchWriteItem($ditems);

                        // examine for any failures
                       	if (! empty($response['UnprocessedItems']))
                        {
                                return false;
                        }
               	}
                return true;
        }

        // $tableItems = array();
        // $tableItems["table1"][] = array( key )
        // $tableItems["table1"][] = array( key, range )
        // will throw exception if all items are not found
	// TODO: convert to returning objects
        public static function batch_get($tableItems)
        {
                self::initialize();
                $req = array();

                foreach ($tableItems as $table => $items)
                {
                        foreach ($items as $item)
                        {
                                if (count($item) == 2)
                                {
                                        $req['RequestItems'][$table]['Keys'][] = array(
                                                'HashKeyElement' => array(
                                                        self::type($item[0]) => $item[0]
                                                ),
                                                'RangeKeyElement' => array(
                                                        self::type($item[1]) => $item[1]
                                                )
                                        );
                                }
                                else
                                {
                                        $req['RequestItems'][$table]['Keys'][] = array(
                                                'HashKeyElement' => array(self::type($items[0]) => $items[0])
                                        );
                                }
                        }
                }

                $result = self::getClient()->batchGetItem( $req );

                if (! $result || ! $result['Responses'] || ! $result['Responses']['Items'])
                {
                        return;
                }

                $r = array();
                foreach ($result['Responses']['Items'] as $item)
                {
                        $r[] = self::unformat($item);
                }

                return $r;
        }

	public static function deleteTable($table)
        {
                self::initialize();

                try {
                     	$result = self::getClient()->deleteTable( array(
                                'TableName' => $table
                        ));
                }
                catch(Aws\DynamoDb\Exception\ResourceNotFoundException $e)
                {
                        return 'NOT_FOUND';
                }

                if (! $result || ! isset($result['TableDescription']))
                {
                        return 'ERROR';
                }

                return true;
        }

	// TODO:  follow table configuration
        public static function createTable($table, $key, $keyRange='')
        {
                self::initialize();

                $q = array(
                        'TableName' => $table,
                        'KeySchema' => array(
                                'HashKeyElement' => array(
                                        'AttributeName' => $key,
                                        'AttributeType' => 'S'
                                ),
                                'RangeKeyElement' => array(
                                        'AttributeName' => $keyRange,
                                        'AttributeType' => 'S'
                                )
                        ),
                        'ProvisionedThroughput' => array(
                                'ReadCapacityUnits' => 1,
                                'WriteCapacityUnits' => 1
                        )
                );

                if ($keyRange)
                {
                        $q['KeySchema']['RangeKeyElement'] = array(
                                'AttributeName' => $keyRange,
                                'AttributeType' => 'S'
                        );
                }

                try
                {
                        $result = self::getClient()->createTable($q);
                }
                catch(Exception $e)
                {
                        error_log("error trying to create table: $e");
                        return 'ERROR';
                }

                if (! $result || ! isset($result['TableDescription']))
                {
                        error_log("no table description");
                        return 'ERROR';
                }

                return '';
        }

        public static function tableStatus($table)
        {
                self::initialize();

                try
                {
                        $result = self::getClient()->describeTable( array(
                                'TableName' => "$table"
                        ));
                }
                catch(Aws\DynamoDb\Exception\ResourceNotFoundException $e)
                {
                        return "NOT_FOUND";
                }

                if (! $result || ! isset($result['Table']['TableStatus']))
                {
                        return 'ERROR';
                }

                return $result['Table']['TableStatus'];
        }




	// MAGIC METHODS ========================================================

	public function __get($name)
	{
		if (isset($this->data[$name]))
		{
			if (isset($this->relations[$name]))
			{
				return $this->relations[$name];
			}
			return $this->data[$name];
		}

		// look for name as prefix
		// build multidimensional array

		$r = array();
		foreach ($this->data as $n => $v)
		{
			if (self::startsWith($n, $name . '.'))
			{
				if (isset($this->relations[$n]))
				{
					$r[substr($n, strlen($name) + 1)] = $this->relations[$n];
				}
				else
				{
					$r[substr($n, strlen($name) + 1)] = $v;
				}
			}
		}
		if (! $r)
		{
			return;
		}
		return $this->expand($r);
	}


	public function __set($name, $value)
	{
		if (gettype($value) == 'array')
		{
			$data = $this->flatten($value, $name);
		}
		else
		{
			$data = array($name => $value);
		}
		
		if (! $this->isDataSafe($data))
		{
			throw new Exception('data not safe');
		}

		foreach ($data as $n => $v)
		{
			// we allow changes to keys, but will reset loaded state
			if ($n == Config::$dynamo_table_mapping[$this->tableName]['hashKey'])
			{
				if ($this->getHashKey() && $this->getHashKey() != $v)
				{
					// we are changing key
					$this->isLoaded = false;
				}
			}
			else if ($this->hasRangeKey() && $n == Config::$dynamo_table_mapping[$this->tableName]['rangeKey'])
			{
				if ($this->getRangeKey() && $this->getRangeKey() != $v)
				{
					// we are changing key
					$this->isLoaded = false;
				}
			}

			// digest any object relations provided as values
			if (gettype($v) == 'object')
			{
				$this->relations[$n] = $v;
				$this->data[$n] = $v->getRefValue();
			}
			else
			{
				$this->data[$n] = $v;
			}

			$this->modifiedColumns[$n] = 1;
		}

		// create proxies for any references
		$this->createProxies($data);
	}

	public function __isset($name)
	{
		return isset($this->data[$name]);
	}
	public function __unset($name)
	{
		if (isset($this->data[$name]))
		{
			unset($this->data[$name]);
		}
	}



	// PRIVATE or PROTECTED METHODS =============================================

        private static function initialize()
        {
                if (self::$initialized)
                {
                        return;
                }

                self::$dynamo = DynamoDbClient::factory(array(
                  'key'    => Config::$aws_key,
                  'secret' => Config::$aws_secret,
                  'region' => Config::$dynamo_region
                ));
        }


	protected function __construct($tableName, array $data=array())
	{
		if (! isset(Config::$dynamo_table_mapping[$tableName]))
		{
			throw new Exception('no mapping defined for this table: ' . $tableName);
		}

		$this->tableName = $tableName;

		if (count($data) > 0)
		{
			$this->data = $this->flatten($data);

			if (! $this->isKeyValid())
			{
				throw new Exception('invalid key');
			}

			// ensure seed data has safe values
			if (! $this->isDataSafe($data))
			{
				throw new Exception('invalid data found in provided data array');
			}

			foreach ($data as $n => $v)
			{
				// digest any object relations provided as values
				if (gettype($v) == 'object')
				{
					$this->relations[$n] = $v;
					$this->data[$n] = $v->getRefValue();
				}

				$this->modifiedColumns[$n] = 1;
			}

			$this->createProxies($this->data);
		}
	}

	protected function setIsLoaded($bool)
	{
		$this->isLoaded = $bool;
	}

	private function flatten(array $data, $path='', array &$r=array())
	{
	        foreach ($data as $name => $value)
	        {
	                if ($path == '')
	                {
	                        $subpath = $name;
	                }
	                else
	                {
	                        $subpath = $path . '.' . $name;
	                }
	
	                if (gettype($value) == 'array')
	                {
	                        $this->flatten($value, $subpath, $r);
	                }
	                else
	                {
	                        $r[$subpath] = $value;
	                }
	        }
	
	        return $r;
	}


	private function expand(&$data)
	{
		$r = array();
	
		foreach ($data as $name => $value)
		{
			if (strpos($name, '.') === false)
			{
				// treat as simple property
				$r[$name] = $value;
			}
			else 
			{
				$rs = $r;  // save original in case of error in assignments
	
				try
				{
					$this->assignArrayByPath($r, $name, $value);
				}
				catch(Exception $e)
				{
					$r = $rs; // restore original
					$r[$name] = $value;  // set as simple property
				}
			}
		}
	
		return $r;
	}


	private function assignArrayByPath(&$arr, $path, $value)
	{
		$keys = explode('.', $path);
	
		while (count($keys))
		{
			$key = array_shift($keys);
	
			if (gettype($arr) != 'array' && gettype($arr) != 'NULL')
			{
				throw new Exception("invalid type for $arr " . gettype($arr));
			}
			$arr = &$arr[$key];
		}	

		if (isset($arr))
		{
			throw new Exception('path already assigned');
		}
		$arr = $value;
	}


	private static function startsWith($haystack, $needle)
	{
	    return !strncmp($haystack, $needle, strlen($needle));
	}



       	// hash[name => hash[type => value]]  translated to  hash[name => value]
        protected static function unformat($hash)
        {
                $u = array();
                foreach ($hash as $name => $t)
                {
                       	foreach ($t as $type => $value)
                        {
                               	$u[$name] = $value;
                        }
                }

                return $u;
        }
	
        protected static function type($value)
        {
                if (gettype($value) == "string")
                {
                        return "S";
                }
                else
                {
                        return "N";
                }
        }

	private function createProxies(array &$data)
	{
		foreach ($data as $name => $value)
		{
			if (isset($this->relations[$name]))
			{
				continue;
			}

			// replace value with proxy object if it is a relation/reference
			$proxy = $this->getProxyObject($name, $value, $data);
			if ($proxy)
			{
				$this->relations[$name] = $proxy;
			}
		}
	}


	// given (name, value) return proxy object if pattern is matched, else null
	private function getProxyObject($name, $value, array &$data)
	{
		if (! isset(Config::$dynamo_table_mapping[$this->tableName]['relations']))
		{
			return;
		}

		foreach (Config::$dynamo_table_mapping[$this->tableName]['relations'] as $pattern => $targetTable)
		{
			// does not match pattern
			if (preg_match($pattern, $name) != 1)
			{
				continue;
			}

			$targetHashKey = null;
			$targetRangeKey = null;
			$targetRefType = Config::$dynamo_table_mapping[$targetTable]['refType'];

			if ($targetRefType == 'range')
			{
				$targetRangeKey = $value;
			}
			else if ($targetRefType == 'hash')
			{
				$targetHashKey = $value;
			}
			else  // is composite value of hash.range
			{
				list($targetHashKey, $targetRangeKey) = explode('.', $value);
			}

			if ($targetHashKey == null)
			{
				$targetHashKey = $data[ Config::$dynamo_table_mapping[$targetTable]['hashKey'] ];
			}
			else if ($targetRangeKey == null && isset( Config::$dynamo_table_mapping[$targetTable]['rangeKey'] ))
			{
				$targetRangeKey = $data[  Config::$dynamo_table_mapping[$targetTable]['rangeKey'] ];
			}

			return new DynamoObjectProxy($targetTable, $targetHashKey, $targetRangeKey);
		}
	}

	private function isDataSafe(&$data)
	{
		foreach ($data as $name => $value)
		{
			$type = gettype($value);

			if ($type == 'string' || $type == 'integer')
			{
				continue;
			}
			else if ($type == 'object')
			{
				if (is_a($value, '\Spiderline\DynamoObject') || is_a($value, '\Spiderline\DynamoObjectProxy'))
				{
					continue;
				}
			}

			return false;
		}

		return true;
	}

}

?>
