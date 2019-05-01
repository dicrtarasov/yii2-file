<?php
namespace app\widgets;

use dicr\file\FileInputTrait;
use yii\bootstrap\InputWidget;

/**
 * Виджет ввода картинок.
 *
 * @author Igor (Dicr) Tarasov <develop@dicr.org>
 * @version 2019
 */
class FileInputWidget extends InputWidget
{
    use FileInputTrait;
}