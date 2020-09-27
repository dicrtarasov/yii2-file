<?php
/*
 * @copyright 2019-2020 Dicr http://dicr.org
 * @author Igor A Tarasov <develop@dicr.org>
 * @license MIT
 * @version 28.09.20 02:44:26
 */

declare(strict_types = 1);

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
     * @inheritDoc
     */
    public static function tableName() : string
    {
        return '{{test}}';
    }

    /**
     * {@inheritDoc}
     */
    public function attributeLabels() : array
    {
        return [
            'icon' => 'Иконка',
            'pics' => 'Картинки',
            'docs' => 'Документы'
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function behaviors() : array
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
