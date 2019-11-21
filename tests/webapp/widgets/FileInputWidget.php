<?php
/**
 * Copyright (c) 2019. 
 *
 * @author Igor A Tarasov <develop@dicr.org>
 */

/** @noinspection LongInheritanceChainInspection */
declare(strict_types = 1);

namespace app\widgets;

use dicr\file\FileInputWidgetTrait;
use yii\bootstrap\InputWidget;

/**
 * Виджет ввода картинок.
 *
 * @author Igor (Dicr) Tarasov <develop@dicr.org>
 * @version 2019
 */
class FileInputWidget extends InputWidget
{
    use FileInputWidgetTrait;
}
