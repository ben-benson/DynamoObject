DynamoObject
============

Dynamo PHP ORM  --  Object relational mapper for Amazon Dynamo, written in PHP.

I wrote this for my own purposes, but thought I might as well release it out into the wild.  This ORM is tailored for Amazon Dynamo.  Among many other features, it allows you to define object relations using pattern matching - and therefore does not restrict the power of no-sql dynamic column creation.

This is an alpha release...  I intend to provide updates as I make them.


## Features

* Support for composite primary keys (HashKey + RangeKey) 
* Lazy loading
    * Access table and key information without invoking lazy loader
    * Preload objects in batch with graceful fallback to lazy loader
* Access name/value pairs as multi-dimensional array
    * property1.abc.name=value ... becomes ... $obj->property1['abc']['name']='value';
* Sane methods:
    * insert() - exception if exists
    * update() - exception if not existing, refreshes all properties
    * put() - replaces any existing, returns old values
    * delete() - exception if not exists
    * load() - load properties from DB
    * see other methods in documentation below....
* Optimized for speed
    * Never makes DB calls that you don't expect/request
    * Allows put() call to replace any existing entity without first loading from DB
    * For loaded entities, performs update on only changed columns
    * Maintains request-scope cache of retrieved objects
    * Refreshes all object properties on update() - no additional dynamo call needed
* Very simple configuration
    * Just subclass DynamoObject and define a few methods
* Add your own custom helper methods per object
* Detects change to object key(s) so you can reuse object as template
* Automatically generate keys 
    * Use your own custom defined key generators
    * Use the provided UUIDv4 generator
* Flexible references
    * Store references to other tables under any property name you like
    * Define relations using regexp patterns
    * Reference values can be 'hash', 'range' or 'hash.range'
    * If reference value is 'range', required hash property is automatically derived
	* Allows you to get maximum power out of dynamic no-sql schema
	* For example, log records with reference to a related object:
		* history.&lt;date&gt; = &lt;reference_uuid&gt;

## Example Usage

Take a look at the provided 'test.php' script.  Here are some examples.

    // CREATE TABLES FROM CONFIG
    TestAccount::createTable();
    TestUser::createTable();

    // INSERT NEW ACCOUNT RECORD
    $account = new TestAccount();
    $account->account_type = 'ABC';
    $account->ben = 'benson';
    $account->insert();

    $account_uuid = $account->getHashKey(); // automatically generated

    $user1 = new TestUser();
    $user1->account_uuid = $account->getHashKey();
    $user1->first_name = 'John';
    $user1->last_name = 'Doe';

    // you can also provide an array to prepopulate
    $user2 = new TestUser( array(
    	'account_uuid' => $account->getHashKey(),
    	'first_name' => 'Sally',
    	'last_name' => 'Doe'
    ));

    // BATCH PUT
    DynamoObject::batch_put(array($user1, $user2));

    // UPDATE - you can choose where relations are set
    $account->{'users.' . $user1->getRangeKey()} = $user1;
    $account->{'users.' . $user2->getRangeKey()} = $user2;
    $account->update();



### multi-dimensional access of properties and lazy loading

    // FETCH BY ID
    $account = TestAccount::fetch($account_uuid);

    // MULTI-DIMENSIONAL ACCESS OF PROPERTIES
    foreach ($account->users as $user)
    {
        print "user id: " . $user->getRangeKey() . "\n";        // accessing key will NOT load object
    	print "user first name: " . $user->first_name . "\n"; 	// now object is loaded
    }



### preloading (preventing lazy loading)

    $account = TestAccount::fetch($account_uuid);

    // BATCH LOAD EXAMPLE
    DynamoObject::batch_load( $account->getAllRelations() );

    foreach ($account->users as $user)
    {
    	print "user: " . $user->first_name . "\n";  // already preloaded
    }

    

## METHODS
### Instance Methods

    // id methods
    public function __construct(array $data=array())

    public function getTableName()      // defined by subclass
    public function getId()
    public function getHashKey()
    public function getHashKeyName()    // defined by subclass
	public function setHashKey($value)
	public function hasRangeKey()
    public function getRangeKey()
    public function getRangeKeyName()   // defined by subclass
	public function setRangeKey($value)
    public function getRefValue()
	public function isKeyValid()

	public function getIterator()
	public function getKeys()
	public function getAllRelations()
	public function isModified()
	public function isColumnModified($column)
	public function getModifiedColumns()
    public function isLoaded()
    public function isProxy()
    

### Persistence Methods

    public function load()      // (re)load from DB
    public function insert()    // exception if exists
    public function update()    // exception if not existing, refreshes object automatically
    public function put()       // replace if exists, returns old values
    public function delete()    // exception if not existing


### Factories  (called against subclass type)

	// retrieve an object from cache or from the database given its table and key(s)
	public static function fetch($hashKey, $rangeKey='')

    // other factories
    public static function range_backward($table, $key, $limit=0, $lastKey=array())
	public static function range_prefix($table, $key, $rangeKeyPrefix, $limit=0, $lastKey=array())
	public static function range_getall($table, $key)


### Utility Methods

    public static function initialize(array $config=array())
	public static function getClient()  \\ returns Dynamo Client 
	public static function addToCache(\Spiderline\DynamoObject $object)
    public static function clearCache();
    public static function genUUIDv4()
    
    public static function batch_put(array $dynamoObjects)
    public static function batch_load(array $dynamoObjects)

	public static function deleteTable()
    public static function createTable()
    public static function getTableStatus($table)



## Configuration

Provided a few details for connecting to Amazon Dynamo...

    $config = array(
        'aws_key' => 'M76HJV55GJBYPGDIIBTQ',
        'aws_secret' => 'Wxj99L5QnsVb3ujdTeAzACb+X7E33Zi73p6Y9hJ',
    	'dynamo_region' => 'us-east-1'
    );
    
    DynamoObject::initialize( $config );


Then create a subclass for each object and define a few require methods.

    class TestAccount extends DynamoObject
    {
        public static function getTableName() {
    		return 'test_account';
    	}
    	public static function getHashKeyName() {
    		return 'account_uuid';
    	}
    	public static function getRangeKeyName() {
    		return null;  // return null if there is no range key
    	}
    	public static function getRefType() {
    		return 'hash';  // 'hash', 'range', or 'hash.range'
    	}
        
        
        // OPTIONAL (DEFAULTED) METHODS

        // return array of Pattern => Class_Name
    	public static function getRelationPatterns() {
    		return array('/^users\..*/' => '\TestUser');  
    	}
        
        // auto-generation of hashKey
    	public static function generateHashKey() {
    		return DynamoObject::genUUIDv4();       
    	}
        
        public static function getHashKeyType() {
            return 'S';
        }
        public static function getRangeKeyType() {
            return 'S';
        }
        public static function getTableReadCapacityUnits() {
            return 1;
        }
        public static function getTableWriteCapacityUnits() {
            return 1;
        }
    }


## TODO

* json output method
* batch_load() cascade support
* custom exception(s)
* handle S, N, SS, NN with schema config
* cascading save
* force use of defined hashKeyType and rangeKeyType
* support for optimistic locking (using forced version property)


## Author 

Ben Benson ( http://benbenson.com/ )
