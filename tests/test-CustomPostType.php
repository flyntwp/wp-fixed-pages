<?php
/**
 * Class ConstructionPlanTest
 *
 * @package Wp_Starter_Plugin
 */

namespace WPFixedPages\Tests;

/**
 * Construction plan test case.
 */
require_once dirname(__DIR__) . '/lib/CustomPostType.php';

use Mockery;
use Brain\Monkey\Functions;
use WPFixedPages\CustomPostType;

class QueryBuilderTest extends TestCase
{
    public function testConstruct()
    {
        $name = 'custom-page';
        Functions\expect('register_post_type')
        ->once()
        ->with($name, Mockery::type('array'));
        new CustomPostType('custom-page');
    }
}
