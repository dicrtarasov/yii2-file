<?php
/**
 * @copyright 2019-2020 Dicr http://dicr.org
 * @author Igor A Tarasov <develop@dicr.org>
 * @license GPL
 * @version 11.07.20 09:32:04
 */

declare(strict_types=1);

namespace dicr\tests\webapp;

use dicr\file\FileAttributeBehavior;
use dicr\file\StoreFile;
use yii\db\ActiveRecord;

/**
 * Test model
 *
 * @property int $id
 * @property StoreFile $icon
 * @property StoreFile[] $pics
 * @method loadFileAttributes()
 */
class TestModel extends ActiveRecord
{
    /**
     * Название таблицы
     *
     * @return string
     */
    public static function tableName()
    {
        return '{{test}}';
    }

    /**
     * {@inheritDoc}
     * @see \yii\base\Model::attributeLabels()
     */
    public function attributeLabels()
    {
        return [
            'icon' => 'Иконка',
            'pics' => 'Картинки',
            'docs' => 'Документы'
        ];
    }

    /**
     * {@inheritDoc}
     * @see \yii\base\Component::behaviors()
     */
    public function behaviors()
    {
        return [
            'fileAttribute' => [
                'class' => FileAttributeBehavior::class,
                'attributes' => [
                    'icon' => 1,
                    'pics' => 0,
                    'docs' => 0
                ]
            ]
        ];
    }
}
