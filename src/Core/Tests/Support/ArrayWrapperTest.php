<?php

/*
 * This file is part of the Yosymfony\Spress.
 *
 * (c) YoSymfony <http://github.com/yosymfony>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Yosymfony\Spress\Core\Tests\Support;

use PHPUnit\Framework\TestCase;
use Yosymfony\Spress\Core\Support\ArrayWrapper;

class ArrayWrapperTest extends TestCase
{
    public function testAdd()
    {
        $a = new ArrayWrapper();
        $a->add('name', 'Yo! Symfony');
        $a->add('name', 'Yo! Symfony 2');
        $a->add('title', 'Hi');
        $a->add('site.pages.index[.]html', 'The content');

        $this->assertEquals('Yo! Symfony', $a->get('name'));
        $this->assertEquals('Hi', $a->get('title'));
        $this->assertEquals('The content', $a->get('site.pages.index[.]html'));
    }

    public function testFlatten()
    {
        $a = new ArrayWrapper([
            'item 1' => [1, 2, 3],
            'item 2' => [4, 5, 6],
        ]);

        $arraySingleLevel = $a->flatten();

        $this->assertTrue(is_array($arraySingleLevel));
        $this->assertCount(6, $arraySingleLevel);
        $this->assertTrue($arraySingleLevel === [1, 2, 3, 4, 5, 6]);
    }

    public function testGet()
    {
        $a = new ArrayWrapper([
            'site' => [
                'name' => 'Yo! Symfony',
                'index.html' => 'Index content',
            ],
        ]);

        $this->assertTrue(is_array($a->get('site')));
        $this->assertEquals('Yo! Symfony', $a->get('site.name'));
        $this->assertEquals('Index content', $a->get('site.index[.]html'));
        $this->assertEquals('Default value', $a->get('site.not-exists', 'Default value'));
        $this->assertEquals('Default value', $a->get('', 'Default value'));
        $this->assertEquals('Default value', $a->get(null, 'Default value'));
        $this->assertNull($a->get(''));
        $this->assertNull($a->get(null));
    }

    public function testHas()
    {
        $a = new ArrayWrapper();
        $a->set('site.name', 'Yo! Symfony');
        $a->add('site.pages.index[.]html', 'The content');
        $a->add('index[.]html', 'The content');

        $this->assertTrue($a->has('site.name'));
        $this->assertTrue($a->has('site.pages.index[.]html'));
        $this->assertTrue($a->has('index[.]html'));
        $this->assertFalse($a->has('site.notExists'));
    }

    public function testInitialize()
    {
        $a = new ArrayWrapper();
        $a->setArray([
            'name' => 'Yo! Symfony',
        ]);

        $this->assertEquals('Yo! Symfony', $a->get('name'));
        $this->assertCount(1, $a->getArray());
    }

    public function testPaginate()
    {
        $a = new ArrayWrapper();
        $a->setArray([
            'element 1' => 'value 1',
            'element 2' => 'value 2',
            'element 3' => 'value 3',
            'element 4' => 'value 4',
            'element 5' => 'value 5',
            'element 6' => 'value 6',
        ]);

        $pages = $a->paginate(5);

        $this->assertCount(2, $pages);
        $this->assertCount(5, $pages[1]);
        $this->assertCount(1, $pages[2]);
        $this->assertEquals('value 1', $pages[1]['element 1']);

        $pages = $a->paginate(5, -1);
        $this->assertCount(2, $pages);
        $this->assertCount(5, $pages[-1]);
        $this->assertCount(1, $pages[0]);

        $pages = $a->paginate(0);

        $this->assertCount(0, $pages);

        $pages = $a->paginate(-1);

        $this->assertCount(0, $pages);
    }

    public function testPaginateSubArray()
    {
        $a = new ArrayWrapper();
        $a->setArray([
            'level1' => [
                'level2' => [
                    'element 1' => 'value 1',
                    'element 2' => 'value 2',
                    'element 3' => 'value 3',
                    'element 4' => 'value 4',
                    'element 5' => 'value 5',
                    'element 6' => 'value 6',
                ],
            ],
        ]);

        $pages = $a->paginate(5, 1, 'level1.level2');

        $this->assertCount(2, $pages);
        $this->assertCount(5, $pages[1]);
        $this->assertCount(1, $pages[2]);
        $this->assertEquals('value 1', $pages[1]['element 1']);
    }

    public function testRemove()
    {
        $a = new ArrayWrapper();
        $a->add('site.name', 'Yo! Symfony');
        $a->add('site.pages.index[.]html', 'The content');
        $a->remove('site.name');

        $this->assertFalse($a->has('site.name'));
    }

    public function testSet()
    {
        $a = new ArrayWrapper();
        $a->set('site.name', 'Yo! Symfony');
        $a->add('site.pages.index[.]html', 'The content');

        $this->assertEquals('Yo! Symfony', $a->get('site.name'));
        $this->assertEquals('The content', $a->get('site.pages.index[.]html'));
    }

    public function testSort()
    {
        $a = new ArrayWrapper([
            'a' => '2015-11-01',
            'b' => '2015-11-03',
            'c' => '2015-11-02',
        ]);

        $b = $a->sort();

        $this->assertCount(3, $b);
        $this->assertEquals('2015-11-01', array_values($b)[0]);
        $this->assertEquals('2015-11-02', array_values($b)[1]);
        $this->assertEquals('2015-11-03', array_values($b)[2]);
    }

    public function testSortKey()
    {
        $a = new ArrayWrapper([
            'level1' => [
                'a' => '2015-11-01',
                'b' => '2015-11-03',
                'c' => '2015-11-02',
            ],
        ]);

        $b = $a->sort('level1');

        $this->assertCount(3, $b);
        $this->assertEquals('2015-11-01', array_values($b)[0]);
        $this->assertEquals('2015-11-02', array_values($b)[1]);
        $this->assertEquals('2015-11-03', array_values($b)[2]);
    }

    public function testSortBy()
    {
        $a = new ArrayWrapper([
            'a' => ['date' => '2015-11-01', 'id' => 3],
            'b' => ['date' => '2015-11-02', 'id' => 2],
            'c' => ['date' => '2015-11-03', 'id' => 1],
        ]);

        $b = $a->sortBy(function ($key, $value) {
            return $value['id'];
        });

        $this->assertCount(3, $b);
        $this->assertEquals(1, array_values($b)[0]['id']);
        $this->assertEquals(2, array_values($b)[1]['id']);
        $this->assertEquals(3, array_values($b)[2]['id']);
    }

    public function testSortByDescending()
    {
        $a = new ArrayWrapper([
            'a' => ['date' => '2015-11-01', 'id' => 3],
            'b' => ['date' => '2015-11-02', 'id' => 2],
            'c' => ['date' => '2015-11-03', 'id' => 1],
        ]);

        $b = $a->sortBy(function ($key, $value) {
            return $value['id'];
        }, null, SORT_REGULAR, true);

        $this->assertCount(3, $b);
        $this->assertEquals(3, array_values($b)[0]['id']);
        $this->assertEquals(2, array_values($b)[1]['id']);
        $this->assertEquals(1, array_values($b)[2]['id']);
    }

    public function testSortByKey()
    {
        $a = new ArrayWrapper([
            'level1' => [
                'a' => ['date' => '2015-11-01', 'id' => 3],
                'b' => ['date' => '2015-11-02', 'id' => 2],
                'c' => ['date' => '2015-11-03', 'id' => 1],
            ],
        ]);

        $b = $a->sortBy(function ($key, $value) {
            return $value['id'];
        }, 'level1');

        $this->assertCount(3, $b);
        $this->assertEquals(1, array_values($b)[0]['id']);
        $this->assertEquals(2, array_values($b)[1]['id']);
        $this->assertEquals(3, array_values($b)[2]['id']);
    }

    public function testWhere()
    {
        $data = [];
        $a = new ArrayWrapper(['value1' => 1, 'value2' => 2]);

        $filtered = $a->where(function ($key, $value) {
            return $value > 1;
        });

        $this->assertCount(1, $filtered);
    }
}
