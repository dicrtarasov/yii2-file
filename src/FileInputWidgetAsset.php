<?php
/*
 * @copyright 2019-2021 Dicr http://dicr.org
 * @author Igor A Tarasov <develop@dicr.org>
 * @license GPL-3.0-or-later
 * @version 22.05.21 21:42:34
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
        'file-input-widget.scss'
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
