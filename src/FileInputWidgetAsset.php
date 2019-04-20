<?php
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

    public $sourcePath = __DIR__.'/assets';

    public $css = [
        'file-input-widget.css'
    ];

    public $js = [
        'file-input-widget.js'
    ];

    public $depends = [
        JqueryAsset::class,
        JuiAsset::class
    ];
}
