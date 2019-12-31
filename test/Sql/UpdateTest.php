<?php

/**
 * @see       https://github.com/laminas/laminas-db for the canonical source repository
 * @copyright https://github.com/laminas/laminas-db/blob/master/COPYRIGHT.md
 * @license   https://github.com/laminas/laminas-db/blob/master/LICENSE.md New BSD License
 */

namespace LaminasTest\Db\Sql;

use Laminas\Db\Sql\Expression;
use Laminas\Db\Sql\Join;
use Laminas\Db\Sql\TableIdentifier;
use Laminas\Db\Sql\Update;
use Laminas\Db\Sql\Where;
use LaminasTest\Db\TestAsset\TrustingSql92Platform;
use LaminasTest\Db\TestAsset\UpdateIgnore;
use PHPUnit\Framework\TestCase;

class UpdateTest extends TestCase
{
    /**
     * @var Update
     */
    protected $update;

    /**
     * Sets up the fixture, for example, opens a network connection.
     * This method is called before a test is executed.
     */
    protected function setUp()
    {
        $this->update = new Update;
    }

    /**
     * Tears down the fixture, for example, closes a network connection.
     * This method is called after a test is executed.
     */
    protected function tearDown()
    {
    }

    /**
     * @covers \Laminas\Db\Sql\Update::table
     */
    public function testTable()
    {
        $this->update->table('foo', 'bar');
        self::assertEquals('foo', $this->readAttribute($this->update, 'table'));

        $tableIdentifier = new TableIdentifier('foo', 'bar');
        $this->update->table($tableIdentifier);
        self::assertEquals($tableIdentifier, $this->readAttribute($this->update, 'table'));
    }

    /**
     * @covers \Laminas\Db\Sql\Update::__construct
     */
    public function testConstruct()
    {
        $update = new Update('foo');
        self::assertEquals('foo', $this->readAttribute($update, 'table'));
    }

    /**
     * @covers \Laminas\Db\Sql\Update::set
     */
    public function testSet()
    {
        $this->update->set(['foo' => 'bar']);
        self::assertEquals(['foo' => 'bar'], $this->update->getRawState('set'));
    }

    /**
     * @covers \Laminas\Db\Sql\Update::set
     */
    public function testSortableSet()
    {
        $this->update->set([
            'two'   => 'с_two',
            'three' => 'с_three',
        ]);
        $this->update->set(['one' => 'с_one'], 10);

        self::assertEquals(
            [
                'one'   => 'с_one',
                'two'   => 'с_two',
                'three' => 'с_three',
            ],
            $this->update->getRawState('set')
        );
    }

    /**
     * @covers \Laminas\Db\Sql\Update::where
     */
    public function testWhere()
    {
        $this->update->where('x = y');
        $this->update->where(['foo > ?' => 5]);
        $this->update->where(['id' => 2]);
        $this->update->where(['a = b'], Where::OP_OR);
        $this->update->where(['c1' => null]);
        $this->update->where(['c2' => [1, 2, 3]]);
        $this->update->where([new \Laminas\Db\Sql\Predicate\IsNotNull('c3')]);
        $where = $this->update->where;

        $predicates = $this->readAttribute($where, 'predicates');
        self::assertEquals('AND', $predicates[0][0]);
        self::assertInstanceOf('Laminas\Db\Sql\Predicate\Literal', $predicates[0][1]);

        self::assertEquals('AND', $predicates[1][0]);
        self::assertInstanceOf('Laminas\Db\Sql\Predicate\Expression', $predicates[1][1]);

        self::assertEquals('AND', $predicates[2][0]);
        self::assertInstanceOf('Laminas\Db\Sql\Predicate\Operator', $predicates[2][1]);

        self::assertEquals('OR', $predicates[3][0]);
        self::assertInstanceOf('Laminas\Db\Sql\Predicate\Literal', $predicates[3][1]);

        self::assertEquals('AND', $predicates[4][0]);
        self::assertInstanceOf('Laminas\Db\Sql\Predicate\IsNull', $predicates[4][1]);

        self::assertEquals('AND', $predicates[5][0]);
        self::assertInstanceOf('Laminas\Db\Sql\Predicate\In', $predicates[5][1]);

        self::assertEquals('AND', $predicates[6][0]);
        self::assertInstanceOf('Laminas\Db\Sql\Predicate\IsNotNull', $predicates[6][1]);

        $where = new Where;
        $this->update->where($where);
        self::assertSame($where, $this->update->where);

        $this->update->where(function ($what) use ($where) {
            self::assertSame($where, $what);
        });

        $this->expectException('Laminas\Db\Sql\Exception\InvalidArgumentException');
        $this->expectExceptionMessage('Predicate cannot be null');
        $this->update->where(null);
    }

    /**
     * @group Laminas-240
     * @covers \Laminas\Db\Sql\Update::where
     */
    public function testPassingMultipleKeyValueInWhereClause()
    {
        $update = clone $this->update;
        $update->table('table');
        $update->set(['fld1' => 'val1']);
        $update->where(['id1' => 'val1', 'id2' => 'val2']);
        self::assertEquals(
            'UPDATE "table" SET "fld1" = \'val1\' WHERE "id1" = \'val1\' AND "id2" = \'val2\'',
            $update->getSqlString(new TrustingSql92Platform())
        );
    }

    /**
     * @covers \Laminas\Db\Sql\Update::getRawState
     */
    public function testGetRawState()
    {
        $this->update->table('foo')
            ->set(['bar' => 'baz'])
            ->where('x = y');

        self::assertEquals('foo', $this->update->getRawState('table'));
        self::assertEquals(true, $this->update->getRawState('emptyWhereProtection'));
        self::assertEquals(['bar' => 'baz'], $this->update->getRawState('set'));
        self::assertInstanceOf('Laminas\Db\Sql\Where', $this->update->getRawState('where'));
    }

    /**
     * @covers \Laminas\Db\Sql\Update::prepareStatement
     */
    public function testPrepareStatement()
    {
        $mockDriver = $this->getMockBuilder('Laminas\Db\Adapter\Driver\DriverInterface')->getMock();
        $mockDriver->expects($this->any())->method('getPrepareType')->will($this->returnValue('positional'));
        $mockDriver->expects($this->any())->method('formatParameterName')->will($this->returnValue('?'));
        $mockAdapter = $this->getMockBuilder('Laminas\Db\Adapter\Adapter')
            ->setMethods()
            ->setConstructorArgs([$mockDriver])
            ->getMock();

        $mockStatement = $this->getMockBuilder('Laminas\Db\Adapter\Driver\StatementInterface')->getMock();
        $pContainer = new \Laminas\Db\Adapter\ParameterContainer([]);
        $mockStatement->expects($this->any())->method('getParameterContainer')->will($this->returnValue($pContainer));

        $mockStatement->expects($this->at(1))
            ->method('setSql')
            ->with($this->equalTo('UPDATE "foo" SET "bar" = ?, "boo" = NOW() WHERE x = y'));

        $this->update->table('foo')
            ->set(['bar' => 'baz', 'boo' => new Expression('NOW()')])
            ->where('x = y');

        $this->update->prepareStatement($mockAdapter, $mockStatement);

        // with TableIdentifier
        $this->update = new Update;
        $mockDriver = $this->getMockBuilder('Laminas\Db\Adapter\Driver\DriverInterface')->getMock();
        $mockDriver->expects($this->any())->method('getPrepareType')->will($this->returnValue('positional'));
        $mockDriver->expects($this->any())->method('formatParameterName')->will($this->returnValue('?'));
        $mockAdapter = $this->getMockBuilder('Laminas\Db\Adapter\Adapter')
            ->setMethods()
            ->setConstructorArgs([$mockDriver])
            ->getMock();

        $mockStatement = $this->getMockBuilder('Laminas\Db\Adapter\Driver\StatementInterface')->getMock();
        $pContainer = new \Laminas\Db\Adapter\ParameterContainer([]);
        $mockStatement->expects($this->any())->method('getParameterContainer')->will($this->returnValue($pContainer));

        $mockStatement->expects($this->at(1))
            ->method('setSql')
            ->with($this->equalTo('UPDATE "sch"."foo" SET "bar" = ?, "boo" = NOW() WHERE x = y'));

        $this->update->table(new TableIdentifier('foo', 'sch'))
            ->set(['bar' => 'baz', 'boo' => new Expression('NOW()')])
            ->where('x = y');

        $this->update->prepareStatement($mockAdapter, $mockStatement);
    }

    /**
     * @covers \Laminas\Db\Sql\Update::getSqlString
     */
    public function testGetSqlString()
    {
        $this->update->table('foo')
            ->set(['bar' => 'baz', 'boo' => new Expression('NOW()'), 'bam' => null])
            ->where('x = y');

        self::assertEquals(
            'UPDATE "foo" SET "bar" = \'baz\', "boo" = NOW(), "bam" = NULL WHERE x = y',
            $this->update->getSqlString(new TrustingSql92Platform())
        );

        // with TableIdentifier
        $this->update = new Update;
        $this->update->table(new TableIdentifier('foo', 'sch'))
            ->set(['bar' => 'baz', 'boo' => new Expression('NOW()'), 'bam' => null])
            ->where('x = y');

        self::assertEquals(
            'UPDATE "sch"."foo" SET "bar" = \'baz\', "boo" = NOW(), "bam" = NULL WHERE x = y',
            $this->update->getSqlString(new TrustingSql92Platform())
        );
    }

    /**
     * @group 6768
     * @group 6773
     */
    public function testGetSqlStringForFalseUpdateValueParameter()
    {
        $this->update = new Update;
        $this->update->table(new TableIdentifier('foo', 'sch'))
            ->set(['bar' => false, 'boo' => 'test', 'bam' => true])
            ->where('x = y');
        self::assertEquals(
            'UPDATE "sch"."foo" SET "bar" = \'\', "boo" = \'test\', "bam" = \'1\' WHERE x = y',
            $this->update->getSqlString(new TrustingSql92Platform())
        );
    }

    /**
     * @covers \Laminas\Db\Sql\Update::__get
     */
    public function testGetUpdate()
    {
        $getWhere = $this->update->__get('where');
        self::assertInstanceOf('Laminas\Db\Sql\Where', $getWhere);
    }

    /**
     * @covers \Laminas\Db\Sql\Update::__get
     */
    public function testGetUpdateFails()
    {
        $getWhat = $this->update->__get('what');
        self::assertNull($getWhat);
    }

    /**
     * @covers \Laminas\Db\Sql\Update::__clone
     */
    public function testCloneUpdate()
    {
        $update1 = clone $this->update;
        $update1->table('foo')
                ->set(['bar' => 'baz'])
                ->where('x = y');

        $update2 = clone $this->update;
        $update2->table('foo')
            ->set(['bar' => 'baz'])
            ->where([
                'id = ?' => 1,
            ]);
        self::assertEquals(
            'UPDATE "foo" SET "bar" = \'baz\' WHERE id = \'1\'',
            $update2->getSqlString(new TrustingSql92Platform)
        );
    }

    /**
     * @coversNothing
     */
    public function testSpecificationconstantsCouldBeOverridedByExtensionInPrepareStatement()
    {
        $updateIgnore = new UpdateIgnore();

        $mockDriver = $this->getMockBuilder('Laminas\Db\Adapter\Driver\DriverInterface')->getMock();
        $mockDriver->expects($this->any())->method('getPrepareType')->will($this->returnValue('positional'));
        $mockDriver->expects($this->any())->method('formatParameterName')->will($this->returnValue('?'));
        $mockAdapter = $this->getMockBuilder('Laminas\Db\Adapter\Adapter')
            ->setMethods()
            ->setConstructorArgs([$mockDriver])
            ->getMock();

        $mockStatement = $this->getMockBuilder('Laminas\Db\Adapter\Driver\StatementInterface')->getMock();
        $pContainer = new \Laminas\Db\Adapter\ParameterContainer([]);
        $mockStatement->expects($this->any())->method('getParameterContainer')->will($this->returnValue($pContainer));

        $mockStatement->expects($this->at(1))
            ->method('setSql')
            ->with($this->equalTo('UPDATE IGNORE "foo" SET "bar" = ?, "boo" = NOW() WHERE x = y'));

        $updateIgnore->table('foo')
            ->set(['bar' => 'baz', 'boo' => new Expression('NOW()')])
            ->where('x = y');

        $updateIgnore->prepareStatement($mockAdapter, $mockStatement);
    }

    /**
     * @coversNothing
     */
    public function testSpecificationconstantsCouldBeOverridedByExtensionInGetSqlString()
    {
        $this->update = new UpdateIgnore();

        $this->update->table('foo')
            ->set(['bar' => 'baz', 'boo' => new Expression('NOW()'), 'bam' => null])
            ->where('x = y');

        self::assertEquals(
            'UPDATE IGNORE "foo" SET "bar" = \'baz\', "boo" = NOW(), "bam" = NULL WHERE x = y',
            $this->update->getSqlString(new TrustingSql92Platform())
        );

        // with TableIdentifier
        $this->update = new UpdateIgnore();
        $this->update->table(new TableIdentifier('foo', 'sch'))
            ->set(['bar' => 'baz', 'boo' => new Expression('NOW()'), 'bam' => null])
            ->where('x = y');

        self::assertEquals(
            'UPDATE IGNORE "sch"."foo" SET "bar" = \'baz\', "boo" = NOW(), "bam" = NULL WHERE x = y',
            $this->update->getSqlString(new TrustingSql92Platform())
        );
    }

    /**
     * @covers \Laminas\Db\Sql\Update::where
     */
    public function testJoin()
    {
        $this->update->table('Document');
        $this->update->set(['x' => 'y'])
            ->join(
                'User', // table name
                'User.UserId = Document.UserId' // expression to join on
                // default JOIN INNER
            )
            ->join(
                'Category',
                'Category.CategoryId = Document.CategoryId',
                Join::JOIN_LEFT // (optional), one of inner, outer, left, right
            );

        self::assertEquals(
            'UPDATE "Document" INNER JOIN "User" ON "User"."UserId" = "Document"."UserId" '
            . 'LEFT JOIN "Category" ON "Category"."CategoryId" = "Document"."CategoryId" SET "x" = \'y\'',
            $this->update->getSqlString(new TrustingSql92Platform())
        );
    }

    /**
     * @testdox unit test: Test join() returns Update object (is chainable)
     * @covers \Laminas\Db\Sql\Update::join
     */
    public function testJoinChainable()
    {
        $return = $this->update->join('baz', 'foo.fooId = baz.fooId', Join::JOIN_LEFT);
        self::assertSame($this->update, $return);
    }
}
