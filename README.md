DynamoObject
============

Dynamo PHP ORM  --  Object relational mapper for Amazon Dynamo, written in PHP.

I wrote this for my own purposes, but thought I might as well turn it out into the wild.  This ORM has the essential ingredients (lazy loading, preloading, subclassing for custom object types) and is tailored around Dynamo.  Unlike other ORMs I've seen, it allows one to define object relations using pattern matching - so does not restrict the power of no-sql dynamic column creation.

This is an alpha release...  I intend to provide updates as I make them.


## Features

* Lazy loading of object relations
* Access table and key information without invoking lazy loader
* Preload objects in batch get (graceful fallback to lazy loader)
* Define relations using regexp patterns
* Access name/value pairs as multi-dimensional array
* Sane methods: insert(), update(), put(), delete() and load()
* Never makes DB calls that you don't expect/request
* For loaded entities, performs update on only changed columns
* Allows put() call to replace any existing entity without initial load from DB
* Very simple configuration
* Subclass to create specific object types and add your own helper methods
* Maintains request-scope cache of retrieved objects
* Detects change to object key(s) so you can reuse object as template


## Example Usage

    use \BenBenson\DynamoObject;

    $account_uuid = DynamoObject::genUUIDv4();
    $range_id = DynamoObject::genUUIDv4();

    // CONSTRUCT NEW OBJECT  (class instantiated based on table config)
    $objekt = DynamoObject::create('objekt', $account_uuid, $range_id);
    $objekt->property1 = 'some value';
    $objekt->property2 = 'another value';
    $objekt->insert();

    // GET EXISTING OBJECT BY ID  (uses cache)
    $o = DynamoObject::fetch('objekt', $account_uuid, $range_id);
    

    // MULTI-DIMENSIONAL ARRAYS (using dot "." notation)
    $o->{'somearray.sub_a.name1'} = 'value 1';
    $o->{'somearray.sub_a.name2'} = 'value 2';
    $o->{'somearray.sub_b.name1'} = 'value 3';
    $o->update();

    $array = $o->somearray;
    $array['sub_b']['name2'] = 'value 4';
    $array['sub_c']['name1'] = 'value 5';

    $o->somearray = $array;  // apply changes to object
    $o->update();
    

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
	public static function add2cache(\Spiderline\DynamoObject $obj)
    public static function genUUIDv4()
    
    public static function batch_put($tableItems)
    public static function batch_get($tableItems)

	// helper methods for initializing a db
	public static function deleteTable($table)
    public static function createTable($table, $key, $keyRange='')
    public static function tableStatus($table)



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

* output to json
* protected access to internal data array
* preloadAllRelations($cascade=false)
* custom exception(s)
* handle S, N, SS, NN with schema config
* method for validating table mapping configuration
* cascading save


## Author 

Ben Benson ( http://benbenson.com/ )

