<?php
/**
 * @copyright 2019-2020 Dicr http://dicr.org
 * @author Igor A Tarasov <develop@dicr.org>
 * @license GPL
 * @version 04.04.20 20:11:32
 */

/** @noinspection PhpUnused */
declare(strict_types=1);

error_reporting(-1);
ini_set('display_errors', '1');

define('YII_ENABLE_ERROR_HANDLER', false);
define('YII_DEBUG', true);

define('VENDOR', __DIR__ . '/../vendor');

require_once(VENDOR . '/autoload.php');
require_once(VENDOR . '/yiisoft/yii2/Yii.php');

Yii::setAlias('@dicr/tests', __DIR__);
Yii::setAlias('@dicr/file', dirname(__DIR__) . '/src');

require(__DIR__ . '/config.remote.php');


