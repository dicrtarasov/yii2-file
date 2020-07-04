<?php
/**
 * @copyright 2019-2020 Dicr http://dicr.org
 * @author Igor A Tarasov <develop@dicr.org>
 * @license GPL
 * @version 04.07.20 13:13:13
 */

/** @noinspection PhpUnused */
declare(strict_types=1);

define('YII_DEBUG', true);
define('YII_ENV', 'dev');
define('VENDOR', __DIR__ . '/../vendor');

require_once(VENDOR . '/autoload.php');
require_once(VENDOR . '/yiisoft/yii2/Yii.php');

require(__DIR__ . '/config.remote.php');


