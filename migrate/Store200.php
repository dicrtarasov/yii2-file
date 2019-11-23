<?php
/**
 * Copyright (c) 2019.
 *
 * @author Igor A Tarasov <develop@dicr.org>
 */

/** @noinspection PhpUnused */

declare(strict_types = 1);
namespace dicr\file\migrate;

use dicr\file\AbstractFileStore;
use dicr\file\StoreFile;
use InvalidArgumentException;
use yii\base\BaseObject;

/**
 * Обновление схемы хранилища.
 *
 * Из схемы:
 * {model}/{id}/{attribute}/{id}~{name}
 *
 * В схему:
 * {model}/{id}/{attribute}~{id}~{name}
 *
 * @author Igor (Dicr) Tarasov <develop@dicr.org>
 * @version 2019
 */
class Store200 extends BaseObject
{
    /**
     * Выполняет переименовывание файлов.
     *
     * @param \dicr\file\AbstractFileStore $store
     * @throws \dicr\file\StoreException
     * @throws \yii\base\InvalidConfigException
     */
    public static function process(AbstractFileStore $store)
    {
        // получаем список моделей
        foreach ($store->list('', ['dir' => true]) as $modelDir) {
            self::processModel($modelDir);
        }
    }

    /**
     * Обрабатывает модель.
     *
     * @param \dicr\file\StoreFile $modelDir
     * @throws \dicr\file\StoreException
     * @throws \yii\base\InvalidConfigException
     */
    protected static function processModel(StoreFile $modelDir)
    {
        // получаем список директорий модели
        $dirs = $modelDir->getList(['dir' => true]);

        // если директории - id модели, то обрабатываем каждый id
        if (is_numeric(($dirs[0])->name)) {
            // обходим директории id
            foreach ($dirs as $idDir) {
                // обходим все аттрибуты
                foreach ($idDir->getList(['dirs' => true]) as $attrDir) {
                    self::processAttribute($attrDir);
                }

                // если директория id пустая, то удаляем
                if (empty($idDir->list)) {
                    $idDir->delete();
                }
            }

        } else {
            // модель не содержит id
            foreach ($dirs as $attrDir) {
                self::processAttribute($attrDir);
            }
        }

        // если директори модели пустая, то удаляем
        if (empty($modelDir->list)) {
            $modelDir->delete();
        }
    }

    /**
     * Обрабатывает файлы аттрибута.
     *
     * @param \dicr\file\StoreFile $attrDir
     * @throws \dicr\file\StoreException
     * @throws \yii\base\InvalidConfigException
     */
    protected static function processAttribute(StoreFile $attrDir)
    {
        $parent = $attrDir->parent;
        if ($parent === null) {
            throw new InvalidArgumentException('attrDir has not parent');
        }

        foreach ($attrDir->getList(['dir' => false]) as $file) {
            // разбираем имя файла
            $matches = null;
            if (preg_match('~^(\d+)\~(.+)$~u', $file->name, $matches)) {
                $pos = (int)$matches[1];
                $name = $matches[2];

                echo $file->path . "\n";

                // перемещаем файл
                $file->path = $parent->child(StoreFile::createStorePrefix($attrDir->name, $pos, $name))->path;
            }
        }

        // если директория пустая, то удаляем
        if (empty($attrDir->list)) {
            $attrDir->delete();
        }
    }
}
