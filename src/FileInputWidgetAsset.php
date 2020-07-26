<?php
/**
 * @copyright 2019-2020 Dicr http://dicr.org
 * @author Igor A Tarasov <develop@dicr.org>
 * @license GPL
 * @version 26.07.20 06:10:10
 */

declare(strict_types = 1);
namespace dicr\file;

use dicr\asset\JuiAsset;
use yii\web\AssetBundle;
use yii\web\JqueryAsset;

/**
 * Asset Bundle для FileInputWidget
 */
class FileInputWidgetAsset extends AssetBundle
{
    /** @inheritDoc */
    public $sourcePath = __DIR__ . '/assets';

    /** @inheritDoc */
    public $css = [
        'file-input-widget.css'
    ];

    /** @inheritDoc */
    public $js = [
        'file-input-widget.js'
    ];

    /** @inheritDoc */
    public $depends = [
        JqueryAsset::class,
        JuiAsset::class,
    ];
}
