<?php
/*
 * @copyright 2019-2021 Dicr http://dicr.org
 * @author Igor A Tarasov <develop@dicr.org>
 * @license GPL-3.0-or-later
 * @version 14.05.21 11:36:54
 */

/** @noinspection PhpUnhandledExceptionInspection */
declare(strict_types = 1);

/** */
const YII_DEBUG = true;
/** */
const YII_ENV = 'dev';

require_once(dirname(__DIR__) . '/vendor/autoload.php');
require_once(dirname(__DIR__) . '/vendor/yiisoft/yii2/Yii.php');

require(__DIR__ . '/config.remote.php');

new yii\web\Application([
    'id' => 'testapp',
    'basePath' => __DIR__,
    'bootstrap' => [
        'dicr/file' => dicr\file\Bootstrap::class
    ],
    'components' => [
        'cache' => yii\caching\ArrayCache::class
    ]
]);

