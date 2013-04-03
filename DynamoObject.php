<?php

namespace BenBenson;

use \Exception;
use \InvalidArgumentException;
use \Aws\DynamoDb\DynamoDbClient;
use \Aws\Common\Enum\Region;
use \Aws\DynamoDb\Exception\DynamoDbException;


interface DynamoObjectInt
{
	static function getTableName();
	static function getHashKeyName();
	static function getRangeKeyName();
	static function getRefType();	// returns 'hash', 'range', or 'hash.range'
}

abstract class DynamoObject implements DynamoObjectInt
{
        private static $dynamo;
	private static $config;
        private static $initialized = false;
	private static $objectCache = array();

	private $data;
	private $isLoaded = false;
	private $modifiedColumns = array();
	private $relations = array();



	// INSTANCE METHODS =========================================================

	public function __construct(array $data=array())
	{
		if (count($data) > 0)
		{
			$this->data = $this->flatten($data);

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
			if (method_exists($this, 'generateHashKey'))
			{
				$this->setHashKey( $this->generateHashKey() );
			}
		}
		if ($this->hasRangeKey() && ! $this->getRangeKey())
		{
			if (method_exists($this, 'generateRangeKey'))
			{
				$this->setRangeKey( $this->generateRangeKey() );
			}
		}
	}

        public function getId()
        {
                if ($this->hasRangeKey())
                {
                        return $this->getHashKey() . '.' . $this->getRangeKey();
                }
                return $this->getHashKey();
        }
	public function getHashKey()
	{
		if ( isset($this->data[$this->getHashKeyName()]) )
		{
			return $this->data[$this->getHashKeyName()];
		}
	}
	public function setHashKey($value)
	{
		if ($this->getHashKey() == $value)
		{
			return;
		}
		$this->data[$this->getHashKeyName()] = $value;
		$this->modifiedColumns[$this->getHashKeyName()] = 1;
		$this->isLoaded = false;
	}
	public function hasRangeKey()
	{
		if ($this->getRangeKeyName())
		{
			return true;
		}
		return false;
	}
	public function getRangeKey()
	{
		if (isset($this->data[$this->getRangeKeyName()]))
		{
			return $this->data[$this->getRangeKeyName()];
		}
	}
	public function setRangeKey($value)
	{
		if (! $this->hasRangeKey())
		{
			throw new Exception('no range key property for this object');
		}
		if ($this->getRangeKey() == $value)
		{
			return;
		}

		$this->data[$this->getRangeKeyName()] = $value;
		$this->modifiedColumns[$this->getRangeKeyName()] = 1;
		$this->isLoaded = false;
	}
        public function getRefValue()
        {
		$refType = $this->getRefType();

		if ($refType == 'hash.range')
		{
	               	return $this->getHashKey() . '.' . $this->getRangeKey();
		}
                else if ($refType == 'range')
                {
                       	return $this->getRangeKey();
                }

		return $this->getHashKey();
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
	public function getAllRelations()
	{
		return array_values($this->relations);
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
	public function isLoaded()
	{
		return $this->isLoaded;
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
                        'TableName' => $this->getTableName(),
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
			'TableName' => $this->getTableName(),
			'Item' => self::getClient()->formatAttributes($this->data),
			'Expected' => array(
				$this->getHashKeyName() => array( 'Exists' => false )
			)
                );

		if ($this->hasRangeKey())
		{
			$insert['Expected'][$this->getRangeKeyName()] = array( 'Exists' => false );
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
                        'TableName' => $this->getTableName(),
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

        public function put()
        {
               	if (! $this->isKeyValid())
		{
			throw new Exception('object key is not properly defined');
		}

                $result = self::getClient()->putItem( array(
                        'TableName' => $this->getTableName(),
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
                        'TableName' => $this->getTableName(),
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

	public static function fetch($hashKey, $rangeKey=null)
	{
                self::initialize();

		$clazz = get_called_class();
		if (! is_subclass_of($clazz, '\BenBenson\DynamoObject'))
		{
			throw new Exception('must be called on subclass of DynamoObject');
		}

		$obj = new $clazz();
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
		if (isset(self::$objectCache[$clazz::getTableName()][$id]))
		{
			error_log('returning cached object for ' . $clazz::getTableName());
			return self::$objectCache[$clazz::getTableName()][$id];
		}

		$obj->load();
		return $obj;
	}

        public static function range_backward($key, $limit=0, $lastKey=array())
        {
                self::initialize();
		$clazz = get_called_class();
		if (! is_subclass_of($clazz, \BenBenson\DynamoObject))
		{
			throw new Exception('must be called on subclass of DynamoObject');
		}

                $q = array(
                        'TableName' => $clazz::getTableName(),
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

		$objs = array();
		foreach ($response['Items'] as $item)
		{
			$obj = new $clazz(self::unformat($item));
			$obj->setIsLoaded(true);
			$objs[] = $obj;
		}

                return array($objs, $lastKey);
	}

	public static function range_prefix($key, $rangeKeyPrefix, $limit=0, $lastKey=array())
        {
                self::initialize();
		$clazz = get_called_class();
		if (! is_subclass_of($clazz, \BenBenson\DynamoObject))
		{
			throw new Exception('must be called on subclass of DynamoObject');
		}

                $q = array(
                        'TableName' => $clazz::getTableName(),
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

		$objs = array();
		foreach ($response['Items'] as $item)
		{
			$obj = new $clazz(self::unformat($item));
			$obj->setIsLoaded(true);
			$objs[] = $obj;
		}

                return array($objs, $lastKey);
        }

	public static function range_getall($key)
        {
                self::initialize();
		$clazz = get_called_class();
		if (! is_subclass_of($clazz, \BenBenson\DynamoObject))
		{
			throw new Exception('must be called on subclass of DynamoObject');
		}

                $result = self::getClient()->query( array(
                        'TableName' => $clazz::getTableName(),
			'HashKeyValue' => self::getClient()->formatAttributes($key)
                ));

                if (! $result || ! isset($result['Items']))
                {
                        return;
                }

                $objs = array();

                foreach ($result['Items'] as $item)
                {
			$obj = new $clazz(self::unformat($item));
			$obj->setIsLoaded(true);
                        $objs[] = $obj;
                }

                return $objs;
        }



	// UTILITIY METHODS ==========================================================

	public static function initialize(array $config=array())
	{
		if ($config)
		{
			if (
				! isset($config['aws_key']) || 
				! isset($config['aws_secret']) || 
				! isset($config['dynamo_region'])
			)
			{
				throw new InvalidArgumentException('missing configuration property');
			}

			self::$config = $config;

        	       	self::$dynamo = DynamoDbClient::factory(array(
        	       	  'key'    => self::$config['aws_key'],
        	       	  'secret' => self::$config['aws_secret'],
        	       	  'region' => self::$config['dynamo_region']
        	       	));

			self::$initialized = true;
			return;
		}

		if (self::$initialized)
		{
			return;
		}

		throw new Exception('DynamoObject Not Configured!');
        }

	public static function getClient()
        {
                self::initialize();
                return self::$dynamo;
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
			if (! $obj instanceof \BenBenson\DynamoObject && ! $obj instanceof \BenBenson\DynamoObjectProxy)
			{
				throw new InvalidArgumentException('not an instance of DynamoObject or DynamoObjectProxy');
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
				// TODO: now we need to find matching provided object based on tableName and key(s)
				// $map[$obj->getTableName()][$obj->getId()] = $obj;

				$data = self::unformat($item);
				$obj = null;

				foreach ($map[$table] as $id => $o)
				{
					if (self::matchesKey($o, $data))
					{
						$obj = $o;
						break;
					}
				}
				if ($obj == null)
				{
					error_log("WARNING: didnt find a match from DynamoObject::batch_load()");
					continue;
				}

				if ($obj->isProxy() == true)
				{
					// just store new object in cache
					$clazz = $obj->getClassName();
					$nObj = new $clazz($data);
					$nObj->setIsLoaded(true);
					self::addToCache($nObj);
				}
				else
				{
					// adds to cache for us
					$obj->setInternalDataArray($data, true);
				}

			} // loop on items
                } // loop on table
        }

	private static function matchesKey($obj, array &$data)
	{
		if (! $obj->getHashKey())
		{
			return false;
		}
		if (! isset($data[$obj->getHashKeyName()]))
		{
			return false;
		}
		if ($data[$obj->getHashKeyName()] != $obj->getHashKey())
		{
			return false;
		}
		if (! $obj->hasRangeKey())
		{
			return true;
		}
		if (! isset($data[$obj->getRangeKeyName()]))
		{
			return false;
		}
		if ($data[$obj->getRangeKeyName()] != $obj->getRangeKey())
		{
			return false;
		}
		return true;
	}

	public static function deleteTable()
        {
                $clazz = get_called_class();
                if (! is_subclass_of($clazz, \BenBenson\DynamoObject))
                {
                        throw new Exception('must be called on subclass of DynamoObject');
                }

                try {
                     	$result = self::getClient()->deleteTable( array(
                                'TableName' => $this->getTableName()
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

        public static function createTable()
        {
                $clazz = get_called_class();
                if (! is_subclass_of($clazz, '\BenBenson\DynamoObject'))
                {
                        throw new Exception('must be called on subclass of DynamoObject');
                }

		$hashKeyName = null;
		$hashKeyType = 'S';
		$rangeKeyName = null;
		$rangeKeyType = 'S';
		$readCapacityUnits = 1;
		$writeCapacityUnits = 1;

		$hashKeyName = $clazz::getHashKeyName();
		if (method_exists($clazz, 'getHashKeyType'))
		{
			$hashKeyType = $clazz::getHashKeyType();
		}

		$rangeKeyName = $clazz::getRangeKeyName();
		if (method_exists($clazz, 'getRangeKeyType'))
		{
			$rangeKeyType = $clazz::getRangeKeyType();
		}

		if (method_exists($clazz, 'getTableReadCapacityUnits'))
		{
			$readCapacityUnits = $clazz::getTableReadCapacityUnits();
		}
		if (method_exists($clazz, 'getTableWriteCapacityUnits'))
		{
			$writeCapacityUnits = $clazz::getTableWriteCapacityUnits();
		}

                $q = array(
                        'TableName' => $clazz::getTableName(),
                        'KeySchema' => array(
                                'HashKeyElement' => array(
                                        'AttributeName' => $hashKeyName,
                                        'AttributeType' => $hashKeyType
                                )
                        ),
                        'ProvisionedThroughput' => array(
                                'ReadCapacityUnits' => $readCapacityUnits,
                                'WriteCapacityUnits' => $writeCapacityUnits
                        )
                );

                if ($rangeKeyName)
                {
                        $q['KeySchema']['RangeKeyElement'] = array(
                                'AttributeName' => $rangeKeyName,
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

        public function getTableStatus()
        {
                $clazz = get_called_class();
                if (! is_subclass_of($clazz, '\BenBenson\DynamoObject'))
                {
                        throw new Exception('must be called on subclass of DynamoObject');
                }

                try
                {
                        $result = self::getClient()->describeTable( array(
                                'TableName' => $this->getTableName()
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
			if ($this->startsWith($n, $name . '.'))
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


	// TODO: prevent setting object as value unless matched by relations pattern in config
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
			if ($this->startsWith($n, $name . '.'))
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
			if ($this->startsWith($n, $name . '.'))
			{
				if (isset($this->relations[$n]))
				{
					unset($this->relations[$n]);
				}
				unset($this->data[$n]);
			}
		}

	}



	// PRIVATE METHODS =============================================


	private function setIsLoaded($bool)
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
        private static function unformat($hash)
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
	
        private static function type($value)
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
		if (! method_exists($this, 'getRelationPatterns'))
		{
			return;
		}
		foreach ($this->getRelationPatterns() as $pattern => $targetClass)
		{
			// does not match pattern
			if (preg_match($pattern, $name) != 1)
			{
				continue;
			}

			$targetHashKey = null;
			$targetRangeKey = null;
			$targetRefType = $targetClass::getRefType();

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
				$targetHashKey = $data[ $targetClass::getHashKeyName() ];
			}
			else if ($targetRangeKey == null && $targetClass::getRangeKeyName() != null)
			{
				$targetRangeKey = $data[  $targetClass::getRangeKeyName() ];
			}

			return new DynamoObjectProxy($targetClass, $targetHashKey, $targetRangeKey);
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
	private function getInternalDataArray()
	{
		return $this->data;
	}

	// required for batch_load, we need ability to set fully loaded data array on existing objects
	private function setInternalDataArray(array $data, $isLoaded)
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
