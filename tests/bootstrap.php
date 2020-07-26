<?php
/**
 * @copyright 2019-2020 Dicr http://dicr.org
 * @author Igor A Tarasov <develop@dicr.org>
 * @license GPL
 * @version 26.07.20 06:10:10
 */

/** @noinspection PhpUnhandledExceptionInspection */
declare(strict_types = 1);

/**
 *
 */
define('YII_DEBUG', true);
/**
 *
 */
define('YII_ENV', 'dev');

require_once(dirname(__DIR__) . '/vendor/autoload.php');
require_once(dirname(__DIR__) . '/vendor/yiisoft/yii2/Yii.php');

require(__DIR__ . '/config.remote.php');

new yii\web\Application([
    'id' => 'testapp',
    'basePath' => __DIR__,
    'bootstrap' => [
        'dicr/file' => dicr\file\Bootstrap::class
    ]
]);

