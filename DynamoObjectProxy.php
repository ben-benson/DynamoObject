<?php

namespace BenBenson;

use \Exception;
use \BenBenson\DynamoObject;
use \BenBenson\DynamoObjectInt;


class DynamoObjectProxy
{
	public $clazz;
	public $hashKey;
	public $rangeKey;
	public $id;

	private $instance = null;


	public function __construct($clazz, $hashKey, $rangeKey=null)
	{
		if (! class_exists($clazz) || ! is_subclass_of($clazz, '\BenBenson\DynamoObject'))
		{
			throw new Exception('invalid class name: ' . $clazz);
		}

		$this->clazz = $clazz;
		$this->hashKey = $hashKey;
		$this->rangeKey = $rangeKey;
	}
 
	public function getClassName()
	{
		return $this->clazz;
	}
	public function getTableName()
	{
		$clazz = $this->clazz;
		return $clazz::getTableName();
	}
	public function hasRangeKey()
	{
		$clazz = $this->clazz;
		if ($clazz::getRangeKeyName() == null)
		{
			return false;
		}
		return true;
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
		return $this->hashKey;
	}
	public function getRangeKey()
	{
		return $this->rangeKey;
	}
	public function getRefType()
	{
		$clazz = $this->clazz;
		return $clazz::getRefType();
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
		$clazz = $this->clazz;
		return $clazz::fetch($this->hashKey, $this->rangeKey);
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
