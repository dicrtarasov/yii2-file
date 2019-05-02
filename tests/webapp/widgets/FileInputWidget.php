<?php
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