<?php
/**
 * @copyright 2019-2019 Dicr http://dicr.org
 * @author Igor A Tarasov <develop@dicr.org>
 * @license GPL
 * @version 24.11.19 00:29:11
 */

/** @noinspection ClassOverridesFieldOfSuperClassInspection */

declare(strict_types = 1);
namespace dicr\file;

use yii\jui\JuiAsset;
use yii\web\AssetBundle;
use yii\web\JqueryAsset;

/**
 * Asset Bundle для FileInputWidget
 *
 * @author Igor (Dicr) Tarasov <develop@dicr.org>
 * @version 2018
 */
class FileInputWidgetAsset extends AssetBundle
{
    /** @var string */
    public $sourcePath = __DIR__ . '/assets';

    /** @var string[] */
    public $css = [
        'file-input-widget.css'
    ];

    /** @var string[] */
    public $js = [
        'file-input-widget.js'
    ];

    /** @var string[] */
    public $depends = [
        JqueryAsset::class,
        JuiAsset::class,
    ];
}
