DynamoObject
============

Dynamo PHP ORM  --  Object relational mapper for Amazon Dynamo, written in PHP.

I wrote this for my own purposes, but thought I might as well turn it out into the wild.  This ORM is tailored for Amazon Dynamo.  Unlike other ORMs I've seen, it allows one to define object relations using pattern matching - and therefore does not restrict the power of no-sql dynamic column creation.

This is an alpha release...  I intend to provide updates as I make them.


## Features

* Support for composite primary keys (HashKey + RangeKey) 
* Lazy loading
    * Access table and key information without invoking lazy loader
    * Preload objects in batch with graceful fallback to lazy loader
    * Define relations using regexp patterns
    * Reference values can be 'hash_key', 'range_key' or 'both'
    * If reference value is 'range_key', required hash_key property is automatically looked up
* Access name/value pairs as multi-dimensional array
* Sane methods:
    * Dynamo::create('tableName') - create new object for given table
    * insert() - exception if exists
    * update() - exception if not existing, refreshes all properties
    * put() - replaces any existing, returns old values
    * delete() - exception if not exists
    * load() - load properties from DB
* Optimized for speed
    * Never makes DB calls that you don't expect/request
    * Allows put() call to replace any existing entity without initial load from DB
    * For loaded entities, performs update on only changed columns
    * Maintains request-scope cache of retrieved objects
    * Refreshes all object properties on update() - no additional dynamo call needed
* Very simple configuration
    * DynamoObject::initialize($config_array);
* Subclass to create specific object types and add your own helper methods
* Detects change to object key(s) so you can reuse object as template
* Automatically generated keys with support for custom defined key generators


## Example Usage

    use \BenBenson\DynamoObject;


    header("Content-Type: text/plain");

    DynamoObject::initialize( getConfig() );  // read below


    // CREATE TABLES FROM CONFIG
    //DynamoObject::createTable('test_account');
    //DynamoObject::createTable('test_user');

    // INSERT NEW ACCOUNT RECORD
    $account = DynamoObject::create('test_account');
    $account->account_type = 'ABC';
    $account->insert();

    $account_uuid = $account->getHashKey(); // automatically generated

    $user1 = DynamoObject::create('test_user');
    $user1->account_uuid = $account->getHashKey();
    $user1->first_name = 'John';
    $user1->last_name = 'Doe';

    $user2 = DynamoObject::create('test_user');
    $user2->account_uuid = $account->getHashKey();
    $user2->first_name = 'Sally';
    $user2->last_name = 'Doe';

    // BATCH PUT
    DynamoObject::batch_put(array($user1, $user2));

    // UPDATE
    $account->{'users.' . $user1->getRangeKey()} = $user1;
    $account->{'users.' . $user2->getRangeKey()} = $user2;
    $account->update();


    // CLEAR CACHE
    DynamoObject::clearCache();


    // FETCH BY ID
    $account = DynamoObject::fetch('test_account', $account_uuid);

    foreach ($account->getAllRelations() as $name => $obj)
    {
        print "Relation: $name  ID:" . $obj->getId() . "\n";
    }

    // MULTI-DIMENSIONAL ACCESS OF PROPERTIES
    foreach ($account->users as $user)
    {
    	print "user id: " . $user->getRangeKey() . "\n"; 	// accessing key info will NOT load object
    	print "user first name: " . $user->first_name . "\n"; 	// now object is loaded
    }


    // CLEAR CACHE
    DynamoObject::clearCache();


    // BATCH LOAD
    $account = DynamoObject::fetch('test_account', $account_uuid);

    DynamoObject::batch_load( array_values($account->getAllRelations()) );
    
    foreach ($account->users as $user)
    {
    	print "user: " . $user->first_name . "\n";  // already preloaded
    }



    function getConfig()
    {
        return array(
    	'aws_key' => 'AKIAJV55GJBYPGDIIBTQ',
    	'aws_secret' => '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',
    	'dynamo_region' => 'us-east-1',
    	'default_class' => '\BenBenson\DynamoObject',
    	'tables' => array(
    		'test_account' => array(
    			'class' => '\BenBenson\DynamoObject',
                    	'hash_key' => 'account_uuid',
    			'hash_key_gen' => 'genUUIDv4',
    			'ref_type' => 'hash',
                            'relations' => array(
                                    '/^users\./' => 'test_user'
                            )
                    ),
    		'test_user' => array(
    			'class' => '\BenBenson\DynamoObject',
    			'hash_key' => 'account_uuid',
                    	'range_key' => 'user_uuid',
    			'range_key_gen' => 'genUUIDv4',
    			'ref_type' => 'range'
                    )
            )
        );
    };
    

## METHODS
### Instance Methods

    // id methods
    public function getTableName()
    public function getId()
    public function getHashKey()
    public function getHashKeyName()    
	public function setHashKey($value)
	public function hasRangeKey()
    public function getRangeKey()
    public function getRangeKeyName()
	public function setRangeKey($value)
	public function isKeyValid()

	// you can perform:  foreach($dynamoObject as $property => $value)
	public function getIterator()
	public function getKeys()

	// returns array( $propertyName => $object or $objectProxy)
	public function getAllRelations()
	public function isModified()
	public function isColumnModified($column)
	public function getModifiedColumns()
    public function isLoaded()
    public function isProxy()  // always returns false (DynamoObjectProxy object return true)
    

### Persistence Methods

    public function insert()    // exception if exists
    public function update()    // exception if not existing, reloads object afterward
    public function put()       // replace if exists, returns old values
    public function delete()    // exception if not existing
    public function load()      // reload from DB


### Factories

	// construct new object -- instantiates object class according to table config
	public static function create($table, array $data=array())

	// retrieve an object from cache or from the database given its table and key(s)
	public static function fetch($table, $hashKey, $rangeKey='')

    // other factories
    public static function range_backward($table, $key, $limit=0, $lastKey=array())
	public static function range_prefix($table, $key, $rangeKeyPrefix, $limit=0, $lastKey=array())
	public static function range_getall($table, $key)


### Utility Methods

	// return current Dynamo Client
	public static function getClient()
    
    public static function getObjectClass($table)
	public static function addToCache(\Spiderline\DynamoObject $object)
    public static function clearCache();
    public static function genUUIDv4()
    
    public static function batch_put(array $dynamoObjects)
    public static function batch_load(array $dynamoObjects)

	// helper methods for initializing a db
	public static function deleteTable($table)
    public static function createTable($table)  // uses table configuration
    public static function getTableStatus($table)



## Configuration Class

    class Config {
        public static $aws_key = 'KDEJHEWJ2373KJD82K382';
        public static $aws_secret = '154HDfgh2383+TehjWg734REfies3423kjJhg6Rh';
        public static $dynamo_region = 'us-east-1';
        public static $dynamo_default_object_class = '\BenBenson\DynamoObject';

        public static $dynamo_table_mapping = array(
                'custom_object' => array(
                        'class' => '\Your\CustomObject', // must be subclass of DynamoObject
                        'hashKey' => 'account_uuid',
                        'rangeKey' => 'custom_uuid',
			            'refType' => 'range',   		// can be hash, range or 'hash.range'
                        'relations' => array(
                                '/^subobjects\./' => 'another_object'
                        )
                ),
                'another_object' => array(
                        'class' => '\Your\CustomObject2',
                        'hashKey' => 'account_uuid',
                        'rangeKey' => 'custom_uuid',
			            'refType' => 'range'
                )
        );
    }


## TODO

* json output method
* batch_load() cascade support
* custom exception(s)
* handle S, N, SS, NN with schema config
* method for validating table mapping configuration
* cascading save
* support for optimistic locking (using forced version property)


## Author 

Ben Benson ( http://benbenson.com/ )
