<?php
/**
 * @copyright 2019-2019 Dicr http://dicr.org
 * @author Igor A Tarasov <develop@dicr.org>
 * @license GPL
 * @version 21.11.19 06:46:40
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
