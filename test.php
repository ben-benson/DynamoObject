<?php

require '../../vendor/autoload.php';
require 'DynamoObject.php';
require 'DynamoObjectProxy.php';

use \BenBenson\DynamoObject;


class TestAccount extends DynamoObject {
	public static function getTableName() {
		return 'test_account';
	}
	public static function getHashKeyName() {
		return 'account_uuid';
	}
	public static function getRangeKeyName() {
		return null;
	}
	public static function getRefType() {
		return 'hash';
	}
	public static function getRelationPatterns() {
		return array('/^users\..*/' => '\TestUser');
	}
	public static function generateHashKey() {
		return DynamoObject::genUUIDv4();
	}
}

class TestUser extends DynamoObject {
	public static function getTableName() {
		return 'test_user';
	}
	public static function getHashKeyName() {
		return 'account_uuid';
	}
	public static function getRangeKeyName() {
		return 'user_uuid';
	}
	public static function getRefType() {
		return 'range';
	}
	public static function generateRangeKey() {
		return DynamoObject::genUUIDv4();
	}
}


header("Content-Type: text/plain");

DynamoObject::initialize( array(
	'aws_key' => 'AKIAJKDHFDJDEHENEBE',
	'aws_secret' => '5lJdfhjdsfjJd65757HJhksdft6HJdf6',
	'dynamo_region' => 'us-east-1'
));


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


// you can provide an array to prepopulate
$user2 = new TestUser( array(
	'account_uuid' => $account->getHashKey(),
	'first_name' => 'Sally',
	'last_name' => 'Doe'
));

// BATCH PUT
DynamoObject::batch_put(array($user1, $user2));


// UPDATE 
// you can choose where relations are set, but they must match configured pattern to be recognized)
$account->{'users.' . $user1->getRangeKey()} = $user1;
$account->{'users.' . $user2->getRangeKey()} = $user2;
$account->update();


// CLEAR CACHE
DynamoObject::clearCache();


// FETCH BY ID
$account = TestAccount::fetch($account_uuid);

foreach ($account->getAllRelations() as $obj)
{
	print "Relation ID:" . $obj->getId() . "\n";
}

// MULTI-DIMENSIONAL ACCESS OF PROPERTIES
foreach ($account->users as $user)
{
	print "user id: " . $user->getRangeKey() . "\n"; 	// accessing key info will NOT load object
	print "user first name: " . $user->first_name . "\n"; 	// now object is loaded
}


// RESET TEST CONTEXT
DynamoObject::clearCache();
$account = TestAccount::fetch($account_uuid);


// BATCH LOAD EXAMPLE
// proxy objects are still used, but when lazy loading occurs the objects will be found in cache

DynamoObject::batch_load( $account->getAllRelations() );

foreach ($account->users as $user)
{
	print "user: " . $user->first_name . "\n";  // already preloaded
}



?>

