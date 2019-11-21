<?php
/**
 * Copyright (c) 2019. 
 *
 * @author Igor A Tarasov <develop@dicr.org>
 */

/** @noinspection LongInheritanceChainInspection */

declare(strict_types = 1);
namespace app\models;

use dicr\file\FileAttributeBehavior;
use yii\db\ActiveRecord;

/**
 * Test model
 *
 * @property int $id
 * @property \dicr\file\StoreFile $icon
 * @property \dicr\file\StoreFile[] $pics
 * @method loadFileAttributes()
 *
 * @author Igor (Dicr) Tarasov <develop@dicr.org>
 * @version 2019
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
