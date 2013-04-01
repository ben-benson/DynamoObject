<?php

namespace BenBenson;

use \Exception;
use \BenBenson\DynamoObject;


class DynamoObjectProxy
{
	public $tableName;
	public $hashKey;
	public $rangeKey;
	public $id;

	private $instance = null;


	public function __construct($tableName, $hashKey, $rangeKey=null)
	{
		if (! isset(DynamoObject::$config['tables'][$tableName]))
		{
			throw new Exception('no table config for ' . $tableName);
		}

		$this->tableName = $tableName;
		$this->hashKey = $hashKey;
		$this->rangeKey = $rangeKey;
	}
 
	public function getTableName()
	{
		return $this->tableName;
	}
	public function getId()
	{
		if ($this->hasRangeKey())
		{
			return $this->hashKey . '.' . $this->rangeKey;
		}
		return  $this->hashKey;
	}
	public function getHashKey()
	{
		return $this->hashKey;
	}
	public function hasRangeKey()
	{
		if ($this->rangeKey == null)
		{
			return false;
		}
		return true;
	}
	public function getRangeKey()
	{
		return $this->rangeKey;
	}
	public function getRefValue()
	{
		$refType = Config::$dynamo_table_mapping[$this->tableName]['refType'];

		if ($refType == 'range')
		{
			return $this->getRangeKey();
		}
		if ($refType == 'hash')
		{
			return $this->getHashKey();
		}
		else
		{
			return $this->getHashKey . '.' . $this->getRangeKey();
		}
	}


	public function getInstance()
	{
	        if( null === $this->instance)
		{
			$this->instance = $this->initInstance();
		}
		return $this->instance;
	}
 
	private function initInstance()
	{
		//error_log('Loading: ' . $this->tableName);
		return DynamoObject::fetch($this->tableName, $this->hashKey, $this->rangeKey);
	}
 
	public function __call($name, $arguments)
	{
		$instance = $this->getInstance();
		//error_log('Calling: ' . $this->tableName . '->' . $name . '(' . print_r($arguments, true) . ');');
		return call_user_func_array( array($instance, $name), $arguments );
	}
 
	public function __get($name)
	{
		//error_log('Getting property: ' . $this->tableName . '->' . $name);
		return $this->getInstance()->$name;
	}

	public function __set($name, $value)
	{
        	//error_log('Setting property: ' . $this->tableName . '->' . $name);
		$this->getInstance()->$name = $value;
	}

	public function __isset($name)
	{
		//error_log('Checking isset for property: ' . $this->tableName . '->' . $name);
		return isset($this->getInstance()->$name);
	}

	public function __unset($name)
	{
		//error_log('Unsetting property: ' . $this->tableName . '->' . $name);
		unset($this->getInstance()->$name);
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

	public function isProxy()
	{
		return true;
	}

	public function isProxyObjectLoaded()
	{
		if ($this->instance == null)
		{
			return false;
		}
		return true;
	}
}
