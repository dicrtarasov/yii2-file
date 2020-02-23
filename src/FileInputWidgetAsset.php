<?php
/**
 * @copyright 2019-2020 Dicr http://dicr.org
 * @author Igor A Tarasov <develop@dicr.org>
 * @license GPL
 * @version 23.02.20 09:50:18
 */

/** @noinspection ClassOverridesFieldOfSuperClassInspection */

declare(strict_types = 1);

namespace dicr\file;

use dicr\asset\JuiAsset;
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
