<?php
use app\models\TestModel;
use yii\base\Exception;
use yii\db\Schema;
use yii\web\Application;

error_reporting(-1);
ini_set('display_errors', 1);

define('YII_ENABLE_ERROR_HANDLER', false);
define('YII_DEBUG', true);

if (!isset($_SERVER['SCRIPT_NAME'])) {
	$_SERVER['SCRIPT_NAME'] = __FILE__;
}

if (!isset($_SERVER['SCRIPT_FILENAME'])) {
	$_SERVER['SCRIPT_FILENAME'] = __FILE__;
}

define('VENDOR', __DIR__ . '/../../vendor');

require_once(VENDOR . '/autoload.php');
require_once(VENDOR . '/yiisoft/yii2/Yii.php');

$app = new Application([
	'id' => 'testapp',
	'basePath' => __DIR__,
	'vendorPath' => VENDOR,
	'aliases' => [
		'@dicr/file' => dirname(__DIR__) . '/src',
		'@dicr/tests' => __DIR__,
		'@bower' => '@vendor/bower-asset',
		'@npm'   => '@vendor/npm-asset'
	],
	'layout' => false,
	'components' => [
		'request' => [
			'cookieValidationKey' => 'L4Q_cKd35rQWIMBZn-cF34HVAQ4hj7Hf'
		],
		'db' => [
			'class' => 'yii\db\Connection',
			'dsn' => 'sqlite::memory:',
		],
		'assetManager' => [
			'appendTimestamp' => true
		],
		'fileStore' => [
			'class' => 'dicr\file\FileStore',
			'path' => '@webroot/files',
			'url' => '@web/files'
		]
	],
	 'modules' => ['debug' => 'yii\debug\Module'],
	 'bootstrap' => ['debug'],
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

return $app;
