<?php

namespace BenBenson;

use \Config;
use \Exception;
use \InvalidArgumentException;
use \Aws\DynamoDb\DynamoDbClient;
use \Aws\Common\Enum\Region;
use \Aws\DynamoDb\Exception\DynamoDbException;

class DynamoObject
{
        public static $config;
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
		if (isset($this->data[self::$config['tables'][$this->tableName]['hash_key']]))
		{
			return $this->data[self::$config['tables'][$this->tableName]['hash_key']];
		}
	}
	public function getHashKeyName()
	{
		return self::$config['tables'][$this->tableName]['hash_key'];
	}
	public function setHashKey($value)
	{
		if ($this->getHashKey() == $value)
		{
			return;
		}
		$this->data[self::$config['tables'][$this->tableName]['hash_key']] = $value;
		$this->modifiedColumns[self::$config['tables'][$this->tableName]['hash_key']] = 1;
		$this->isLoaded = false;
	}
	public function hasRangeKey()
	{
		return isset(self::$config['tables'][$this->tableName]['range_key']);
	}
	public function getRangeKey()
	{
		if ($this->hasRangeKey())
		{
			if (isset($this->data[self::$config['tables'][$this->tableName]['range_key']]))
			{
				return $this->data[self::$config['tables'][$this->tableName]['range_key']];
			}
		}
	}
	public function getRangeKeyName()
	{
		if ($this->hasRangeKey())
		{
			return self::$config['tables'][$this->tableName]['range_key'];
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

		$this->data[self::$config['tables'][$this->tableName]['range_key']] = $value;
		$this->modifiedColumns[self::$config['tables'][$this->tableName]['range_key']] = 1;
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
	public function isLoaded()
	{
		return $this->isLoaded;
	}

        public function getRefValue()
        {
                $refType = self::$config['tables'][$this->tableName]['ref_type'];

                if ($refType == 'range')
                {
                       	return $this->getRangeKey();
                }
               	else if ($refType == 'hash')
                {
                       	return $this->getHashKey();
                }

               	return $this->getHashKey . '.' . $this->getRangeKey();
        }

        public function isProxy()
        {
                return false;
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
		self::addToCache($this);
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
				self::$config['tables'][$this->tableName]['hash_key'] => array( 'Exists' => false )
			)
                );

		if ($this->hasRangeKey())
		{
			$insert['Expected'][self::$config['tables'][$this->tableName]['range_key']] = array( 'Exists' => false );
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

        public function put()
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

	public static function initialize(array $config=array())
	{
		if (! $config)
		{
			if (self::$initialized)
			{
				return;
			}
			else if (isset(Config::$dynamo_config))
			{
				$config = Config::$dynamo_config;
			}
			else
			{
				throw new Exception('DynamoObject Not Configured!');
			}
		}

		// TODO: perform validation of config
		self::$config = $config;

               	self::$dynamo = DynamoDbClient::factory(array(
               	  'key'    => self::$config['aws_key'],
               	  'secret' => self::$config['aws_secret'],
               	  'region' => self::$config['dynamo_region']
               	));
		
		self::$initialized = true;
        }

	public static function create($table, array $data=array())
	{
		$clazz = self::getObjectClass($table);
		return new $clazz($table, $data);
	}

	public static function fetch($table, $hashKey, $rangeKey=null)
	{
		$obj = self::create($table);
		$obj->setHashKey($hashKey);

		if ($obj->hasRangeKey() && ! $rangeKey)
		{
			throw new IllegalArgumentException('range key required for this object');
		}
		if ($rangeKey)
		{
			$obj->setRangeKey($rangeKey);
		}
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
                if (isset(self::$config['tables'][$table]['class']))
                {
                        if (class_exists(self::$config['tables'][$table]['class']))
                        {
                                return self::$config['tables'][$table]['class'];
                        }
                }

                return self::$config['default_class'];
        }

	public static function addToCache(\BenBenson\DynamoObject $obj)
	{
		self::initialize();
		self::$objectCache[$obj->getTableName()][$obj->getId()] = $obj;
	}
	public static function clearCache()
	{
		self::initialize();
		self::$objectCache = array();
	}
	public static function genUUIDv4()
	{
            return sprintf( '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
                // 32 bits for "time_low"
                mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ),

                // 16 bits for "time_mid"
                mt_rand( 0, 0xffff ),

                // 16 bits for "time_hi_and_version",
                // four most significant bits holds version number 4
                mt_rand( 0, 0x0fff ) | 0x4000,

                // 16 bits, 8 bits for "clk_seq_hi_res",
                // 8 bits for "clk_seq_low",
                // two most significant bits holds zero and one for variant DCE1.1
                mt_rand( 0, 0x3fff ) | 0x8000,

                // 48 bits for "node"
                mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff )
            );
	}


        public static function batch_put(array $objs)
        {
                self::initialize();
                $batch = array();
                $i = 0;

                foreach ($objs as $obj)
                {
			if (! $obj instanceof \BenBenson\DynamoObject)
			{
				throw new InvalidArgumentException();
			}
			else if (! $obj->isKeyValid())
			{
				throw new InvalidArgumentException('object key is invalid');
			}

                    	$i++;
                        $batch['RequestItems'][$obj->getTableName()][]['PutRequest']['Item'] = self::getClient()->formatAttributes($obj->getInternalDataArray());

                        if ($i == 25)
                        {
                                $response = self::getClient()->batchWriteItem($batch);

                                // examine for any failures
                               	if (! empty($response['UnprocessedItems']))
                                {
                                       	return false;
                               	}
                               	$i=0;
                                $batch = array();
                        }
                }
                if ($i > 0)
                {
                       	$response = self::getClient()->batchWriteItem($batch);

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

        public static function batch_load(array $objs)
        {
                self::initialize();
                $batch = array();
		$map = array();

                foreach ($objs as $obj)
                {
			if (! $obj instanceof \BenBenson\DynamoObject && ! $obj instanceof \BenBenson\DynamoObjectProxy)
			{
				throw new InvalidArgumentException();
			}
			else if (! $obj->isKeyValid() )
			{
				throw new Exception('object key invalid');
			}
			else if (! $obj->isProxy() && $obj->isLoaded())
			{
				continue;
			}

			$map[$obj->getTableName()][$obj->getId()] = $obj;

			if ($obj->hasRangeKey())
			{
				$batch['RequestItems'][$obj->getTableName()]['Keys'][] = array(
					'HashKeyElement' => array(
						self::type($obj->getHashKey()) => $obj->getHashKey()
					),
					'RangeKeyElement' => array(
						self::type($obj->getRangeKey()) => $obj->getRangeKey()
					)
				);
			}
			else
			{
				$batch['RequestItems'][$obj->getTableName()]['Keys'][] = array(
					'HashKeyElement' => array(self::type($obj->getHashKey()) => $obj->getHashKey())
				);
			}
                }

                $result = self::getClient()->batchGetItem( $batch );

                if (! $result || ! $result['Responses'])
                {
                        return;
                }

                foreach ($result['Responses'] as $table => $a)
                {
			foreach ($result['Responses'][$table]['Items'] as $item)
			{				
				$data = self::unformat($item);
				$obj = self::create($table, $data);
				$obj->setIsLoaded(true);
				$id = $obj->getId();

				$pObj = $map[$table][$id];
				if ($pObj->isProxy() == true)
				{
					// just store new object in cache
					self::addToCache($obj);
				}
				else
				{
					// adds to cache for us
					$pObj->setInternalDataArray($data, true);
				}
			}
                }
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
        public static function createTable($table)
        {
                self::initialize();

		if (! isset(self::$config['tables'][$table]))
		{
			throw new Exception('no mapping defined for this table: ' . $table);
		}

		$hashKey = null;
		$hashKeyType = 'S';
		$rangeKey = null;
		$rangeKeyType = 'S';
		$readCapacityUnits = 1;
		$writeCapacityUnits = 1;

		if (isset(self::$config['tables'][$table]['hash_key']))
		{
			$hashKey = self::$config['tables'][$table]['hash_key'];
		}
		if (isset(self::$config['tables'][$table]['hash_key_type']))
		{
			$hashKeyType = self::$config['tables'][$table]['hash_key_type'];
		}
		if (isset(self::$config['tables'][$table]['range_key']))
		{
			$rangeKey = self::$config['tables'][$table]['range_key'];
		}
		if (isset(self::$config['tables'][$table]['range_key_type']))
		{
			$rangeKeyType = self::$config['tables'][$table]['range_key_type'];
		}
		if (isset(self::$config['tables'][$table]['read_capacity_units']))
		{
			$readCapacityUnits = self::$config['tables'][$table]['read_capacity_units'];
		}
		if (isset(self::$config['tables'][$table]['writeCapacityUnits']))
		{
			$writeCapacityUnits = self::$config['tables'][$table]['write_capacity_units'];
		}

                $q = array(
                        'TableName' => $table,
                        'KeySchema' => array(
                                'HashKeyElement' => array(
                                        'AttributeName' => $hashKey,
                                        'AttributeType' => $hashKeyType
                                )
                        ),
                        'ProvisionedThroughput' => array(
                                'ReadCapacityUnits' => $readCapacityUnits,
                                'WriteCapacityUnits' => $writeCapacityUnits
                        )
                );

                if ($rangeKey)
                {
                        $q['KeySchema']['RangeKeyElement'] = array(
                                'AttributeName' => $rangeKey,
                                'AttributeType' => $rangeKeyType
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
        }

        public static function getTableStatus($table)
        {
                self::initialize();

                try
                {
                        $result = self::getClient()->describeTable( array(
                                'TableName' => $table
                        ));
                }
                catch(Aws\DynamoDb\Exception\ResourceNotFoundException $e)
                {
                        return 'NOT_FOUND';
                }

                if (! $result || ! isset($result['Table']['TableStatus']))
                {
                        return 'ERROR';
                }

                return $result['Table']['TableStatus'];
        }


	public static function isTableConfigured($table)
	{
		return isset(self::$config['tables'][$table]);
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
			if ($n == $this->getHashKeyName())
			{
				if ($this->getHashKey() && $this->getHashKey() != $v)
				{
					// we are changing key
					$this->isLoaded = false;
				}
			}
			else if ($this->hasRangeKey() && $n == $this->getRangeKeyName())
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
		if (isset($this->data[$name]))
		{
			return true;
		}
		foreach ($this->data as $n)
		{
			if (self::startsWith($n, $name . '.'))
			{
				return true;
			}
		}
		return false;
	}

	public function __unset($name)
	{
		if (isset($this->data[$name]))
		{
			if (isset($this->relations[$name]))
			{
				unset($this->relations[$name]);
			}
			unset($this->data[$name]);
			return;
		}
		foreach ($this->data as $n)
		{
			if (self::startsWith($n, $name . '.'))
			{
				if (isset($this->relations[$n]))
				{
					unset($this->relations[$n]);
				}
				unset($this->data[$n]);
			}
		}

	}



	// PRIVATE or PROTECTED METHODS =============================================

	protected function __construct($tableName, array $data=array())
	{
		if (! isset(self::$config['tables'][$tableName]))
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

		if (! $this->getHashKey())
		{
			if (isset(self::$config['tables'][$tableName]['hash_key_gen']))
			{
				$gen = self::$config['tables'][$tableName]['hash_key_gen'];
				$key = self::$gen();
				$this->setHashKey( $key );
			}
		}
		if ($this->hasRangeKey() && ! $this->getRangeKey())
		{
			if (isset(self::$config['tables'][$tableName]['range_key_gen']))
			{
				$gen = self::$config['tables'][$tableName]['range_key_gen'];
				$key = self::$gen();
				$this->setRangeKey( $key );
			}
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
		if (! isset(self::$config['tables'][$this->tableName]['relations']))
		{
			return;
		}

		foreach (self::$config['tables'][$this->tableName]['relations'] as $pattern => $targetTable)
		{
			// does not match pattern
			if (preg_match($pattern, $name) != 1)
			{
				continue;
			}

			$targetHashKey = null;
			$targetRangeKey = null;
			$targetRefType = self::$config['tables'][$targetTable]['ref_type'];

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
				$targetHashKey = $data[ self::$config['tables'][$targetTable]['hash_key'] ];
			}
			else if ($targetRangeKey == null && isset( self::$config['tables'][$targetTable]['range_key'] ))
			{
				$targetRangeKey = $data[  self::$config['tables'][$targetTable]['range_key'] ];
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
				if (is_a($value, '\BenBenson\DynamoObject') || is_a($value, '\BenBenson\DynamoObjectProxy'))
				{
					continue;
				}
			}

			return false;
		}

		return true;
	}

	// required for batch_put
	protected function getInternalDataArray()
	{
		return $this->data;
	}

	// required for batch_load, we need ability to set fully loaded data array on existing objects
	protected function setInternalDataArray(array $data, $isLoaded)
	{
		if (! $this->isDataSafe($data))
		{
			throw new Exception('data not safe');
		}

		$this->data = $data;
		$this->isLoaded = $isLoaded;
		$this->relations = array();
		$this->modifiedColumns = array();

		if ($isLoaded)
		{
			self::addToCache($this);
		}
		else
		{
			foreach ($this->data as $key)
			{
				$this->modifiedColumns[$key] = 1;
			}
		}

		$this->createProxies($this->data);		
	}

}

?>
