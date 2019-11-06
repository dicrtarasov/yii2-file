<?php
/**
 * Copyright (c) 2019.
 *
 * @author Igor (Dicr) Tarasov <develop@dicr.org>
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
