<?php
/*
 * @copyright 2019-2020 Dicr http://dicr.org
 * @author Igor A Tarasov <develop@dicr.org>
 * @license MIT
 * @version 14.09.20 04:45:30
 */

declare(strict_types = 1);
namespace dicr\file;

use yii\base\BootstrapInterface;
use yii\i18n\PhpMessageSource;

/**
 * Автозагрузка при настройке пакета.
 */
class Bootstrap implements BootstrapInterface
{
    /**
     * @inheritDoc
     */
    public function bootstrap($app) : void
    {
        $app->i18n->translations['dicr/file'] = [
            'class' => PhpMessageSource::class,
            'sourceLanguage' => 'ru',
            'basePath' => __DIR__ . '/messages'
        ];
    }
}
