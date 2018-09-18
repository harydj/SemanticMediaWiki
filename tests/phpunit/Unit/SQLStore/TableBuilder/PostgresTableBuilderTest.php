<?php

namespace SMW\Tests\SQLStore\TableBuilder;

use SMW\SQLStore\TableBuilder\PostgresTableBuilder;
use SMW\SQLStore\TableBuilder\Table;

/**
 * @covers \SMW\SQLStore\TableBuilder\PostgresTableBuilder
 * @group semantic-mediawiki
 *
 * @license GNU GPL v2+
 * @since 2.5
 *
 * @author mwjames
 */
class PostgresTableBuilderTest extends \PHPUnit_Framework_TestCase {

	public function testCanConstruct() {

		$connection = $this->getMockBuilder( '\DatabaseBase' )
			->disableOriginalConstructor()
			->getMockForAbstractClass();

		$connection->expects( $this->any() )
			->method( 'getType' )
			->will( $this->returnValue( 'postgres' ) );

		$this->assertInstanceOf(
			PostgresTableBuilder::class,
			PostgresTableBuilder::factory( $connection )
		);
	}

	public function testCreateTableOnNewTable() {

		$connection = $this->getMockBuilder( '\DatabaseBase' )
			->disableOriginalConstructor()
			->setMethods( [ 'tableExists', 'query' ] )
			->getMockForAbstractClass();

		$connection->expects( $this->any() )
			->method( 'getType' )
			->will( $this->returnValue( 'postgres' ) );

		$connection->expects( $this->any() )
			->method( 'tableExists' )
			->will( $this->returnValue( false ) );

		$connection->expects( $this->once() )
			->method( 'query' )
			->with( $this->stringContains( 'CREATE TABLE' ) );

		$instance = PostgresTableBuilder::factory( $connection );

		$table = new Table( 'foo' );
		$table->addColumn( 'bar', 'text' );

		$instance->create( $table );
	}

	public function testUpdateTableWithNewField() {

		$connection = $this->getMockBuilder( '\DatabaseBase' )
			->disableOriginalConstructor()
			->setMethods( [ 'tableExists', 'query' ] )
			->getMockForAbstractClass();

		$connection->expects( $this->any() )
			->method( 'getType' )
			->will( $this->returnValue( 'postgres' ) );

		$connection->expects( $this->any() )
			->method( 'tableExists' )
			->will( $this->returnValue( true ) );

		$connection->expects( $this->at( 2 ) )
			->method( 'query' )
			->with( $this->stringContains( 'SELECT a.attname as' ) )
			->will( $this->returnValue( [] ) );

		$connection->expects( $this->at( 3 ) )
			->method( 'query' )
			->with( $this->stringContains( 'ALTER TABLE "foo" ADD "bar" TEXT' ) );

		$instance = PostgresTableBuilder::factory( $connection );

		$table = new Table( 'foo' );
		$table->addColumn( 'bar', 'text' );

		$instance->create( $table );
	}

	public function testUpdateTableWithNewFieldAndDefault() {

		$connection = $this->getMockBuilder( '\DatabaseBase' )
			->disableOriginalConstructor()
			->setMethods( [ 'tableExists', 'query' ] )
			->getMockForAbstractClass();

		$connection->expects( $this->any() )
			->method( 'getType' )
			->will( $this->returnValue( 'postgres' ) );

		$connection->expects( $this->any() )
			->method( 'tableExists' )
			->will( $this->returnValue( true ) );

		$connection->expects( $this->at( 2 ) )
			->method( 'query' )
			->with( $this->stringContains( 'SELECT a.attname as' ) )
			->will( $this->returnValue( [] ) );

		$connection->expects( $this->at( 3 ) )
			->method( 'query' )
			->with( $this->stringContains( 'ALTER TABLE "foo" ADD "bar" TEXT'. " DEFAULT '0'" ) );

		$instance = PostgresTableBuilder::factory( $connection );

		$table = new Table( 'foo' );
		$table->addColumn( 'bar', 'text' );
		$table->addDefault( 'bar', 0 );

		$instance->create( $table );
	}

	public function testCreateIndex() {

		$connection = $this->getMockBuilder( '\DatabaseBase' )
			->disableOriginalConstructor()
			->setMethods( [ 'tableExists', 'query', 'indexInfo' ] )
			->getMockForAbstractClass();

		$connection->expects( $this->any() )
			->method( 'getType' )
			->will( $this->returnValue( 'postgres' ) );

		$connection->expects( $this->any() )
			->method( 'tableExists' )
			->will( $this->returnValue( false ) );

		$connection->expects( $this->any() )
			->method( 'indexInfo' )
			->will( $this->returnValue( false ) );

		$connection->expects( $this->at( 3 ) )
			->method( 'query' )
			->with( $this->stringContains( 'SELECT  i.relname AS indexname' ) )
			->will( $this->returnValue( [] ) );

		$connection->expects( $this->at( 5 ) )
			->method( 'query' )
			->with( $this->stringContains( 'CREATE INDEX foo_idx_bar ON foo (bar)' ) );

		$instance = PostgresTableBuilder::factory( $connection );

		$table = new Table( 'foo' );
		$table->addColumn( 'bar', 'text' );
		$table->addIndex( 'bar' );

		$instance->create( $table );
	}

	public function testDropTable() {

		$connection = $this->getMockBuilder( '\DatabaseBase' )
			->disableOriginalConstructor()
			->setMethods( [ 'tableExists', 'query' ] )
			->getMockForAbstractClass();

		$connection->expects( $this->any() )
			->method( 'getType' )
			->will( $this->returnValue( 'postgres' ) );

		$connection->expects( $this->once() )
			->method( 'tableExists' )
			->will( $this->returnValue( true ) );

		$connection->expects( $this->once() )
			->method( 'query' )
			->with( $this->stringContains( 'DROP TABLE IF EXISTS "foo"' ) );

		$instance = PostgresTableBuilder::factory( $connection );

		$table = new Table( 'foo' );
		$instance->drop( $table );
	}

	public function testDoCheckOnAfterCreate() {

		$connection = $this->getMockBuilder( '\DatabaseBase' )
			->disableOriginalConstructor()
			->setMethods( [ 'query', 'getType', 'onTransactionIdle' ] )
			->getMockForAbstractClass();

		$connection->expects( $this->any() )
			->method( 'getType' )
			->will( $this->returnValue( 'postgres' ) );

		$connection->expects( $this->any() )
			->method( 'onTransactionIdle' )
			->will( $this->returnCallback( function( $callback ) { return $callback(); } ) );

		$connection->expects( $this->at( 4 ) )
			->method( 'query' )
			->with( $this->stringContains( 'ALTER SEQUENCE' ) );

		$instance = PostgresTableBuilder::factory( $connection );

		$instance->checkOn( $instance::POST_CREATION );
	}

	public function testOptimizeTable() {

		$connection = $this->getMockBuilder( '\DatabaseBase' )
			->disableOriginalConstructor()
			->setMethods( [ 'query' ] )
			->getMockForAbstractClass();

		$connection->expects( $this->any() )
			->method( 'getType' )
			->will( $this->returnValue( 'postgres' ) );

		$connection->expects( $this->at( 1 ) )
			->method( 'query' )
			->with( $this->stringContains( 'ANALYZE "foo"' ) );

		$instance = PostgresTableBuilder::factory( $connection );

		$table = new Table( 'foo' );
		$instance->optimize( $table );
	}

}
