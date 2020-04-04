<?php /**
 * @copyright 2019-2020 Dicr http://dicr.org
 * @author Igor A Tarasov <develop@dicr.org>
 * @license GPL
 * @version 04.04.20 20:15:06
 */ /** @noinspection PhpUnhandledExceptionInspection, PhpUnused */
declare(strict_types = 1);

use dicr\file\LocalFileStore;
use dicr\tests\webapp\TestModel;
use yii\base\Exception;
use yii\db\Schema;
use yii\debug\Module;
use yii\web\Application;

error_reporting(- 1);
ini_set('display_errors', '1');

define('YII_DEBUG', true);

define('VENDOR', __DIR__ . '/../../vendor');
require_once(VENDOR . '/autoload.php');
require_once(VENDOR . '/yiisoft/yii2/Yii.php');

/** @noinspection SpellCheckingInspection */
$app = new Application([
    'id' => 'testapp',
    'basePath' => __DIR__,
    'vendorPath' => VENDOR,
    'language' => 'ua',
    'aliases' => [
        '@dicr/file' => dirname(__DIR__, 2) . '/src',
        '@dicr/tests' => dirname(__DIR__),
        '@bower' => '@vendor/bower-asset',
        '@npm' => '@vendor/npm-asset'
    ],
    'layout' => false,
    'controllerNamespace' => 'dicr\\tests\\webapp',
    'components' => [
        'request' => [
            'cookieValidationKey' => 'L4Q_cKd35rQWIMBZn-cF34HVAQ4hj7Hf'
        ],
        'db' => [
            'class' => yii\db\Connection::class,
            'dsn' => 'sqlite::memory:',
        ],
        'assetManager' => [
            'appendTimestamp' => true,
            'forceCopy' => true
        ],
        'fileStore' => [
            'class' => LocalFileStore::class,
            'path' => '@webroot/files',
            'url' => '@web/files',
            'thumbFileConfig' => [
                'store' => 'cacheStore'
            ]
        ],
        'cacheStore' => [
            'class' => LocalFileStore::class,
            'path' => '@webroot/thumb',
            'url' => '@web/thumb'
        ]
    ],
    'modules' => [
        'debug' => Module::class
    ],
    'bootstrap' => [
        'debug'
    ],
]);

$app->db->createCommand()->createTable('test', [
    'id' => Schema::TYPE_PK
])->execute();

$model = new TestModel([
    'id' => 1
]);

if ($model->save() === false) {
    throw new Exception('error creating test model');
}

$app->run();

