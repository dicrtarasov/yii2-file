<?php
/*
 * @copyright 2019-2021 Dicr http://dicr.org
 * @author Igor A Tarasov <develop@dicr.org>
 * @license GPL-3.0-or-later
 * @version 16.08.21 09:53:13
 */

declare(strict_types = 1);
namespace dicr\file;

use Yii;
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
    public function bootstrap($app): void
    {
        $app->i18n->translations['dicr/file'] = [
            'class' => PhpMessageSource::class,
            'sourceLanguage' => 'ru',
            'basePath' => __DIR__ . '/messages'
        ];

        Yii::$app->response->formatters[CSVResponseFormatter::FORMAT] = CSVResponseFormatter::class;
    }
}
