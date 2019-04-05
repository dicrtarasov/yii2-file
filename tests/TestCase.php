<?php

namespace dicr\tests;

use Yii;
use yii\di\Container;

/**
 * This is the base class for all yii framework unit tests.
 */
abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    public static $appClass = '\yii\web\Application';

    public $config = [
        'id' => 'testapp',
        'basePath' => __DIR__,
        'vendorPath' => VENDOR,
        'components' => [
            'request' => [
                'cookieValidationKey' => 'MD44rEeFtNSeJ37sOzD954sI',
                'scriptFile' => __DIR__ .'/index.php',
                'scriptUrl' => '/index.php',
            ],
        ]
    ];

    protected function setUp()
    {
        parent::setUp();
        $this->mockApplication();
    }

    protected function tearDown()
    {
        $this->destroyApplication();
        parent::tearDown();
    }

    protected function mockApplication()
    {
        return new self::$appClass($this->config);
    }

    /**
     * Destroys application in Yii::$app by setting it to null.
     */
    protected function destroyApplication()
    {
        Yii::$app = null;
        Yii::$container = new Container();
    }
}
