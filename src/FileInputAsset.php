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
class FileInputAsset extends AssetBundle
{
    /** @var string */
    public $sourcePath = __DIR__.'/assets';

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
        JuiAsset::class
    ];
}
