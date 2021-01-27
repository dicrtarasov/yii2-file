<?php
/*
 * @copyright 2019-2021 Dicr http://dicr.org
 * @author Igor A Tarasov <develop@dicr.org>
 * @license MIT
 * @version 27.01.21 19:13:22
 */

declare(strict_types = 1);
namespace dicr\file;

use Throwable;
use yii\base\Exception;

/**
 * Ошибка хранилища файлов.
 */
class StoreException extends Exception
{
    /**
     * Конструктор
     *
     * @param ?string $msg если не задано, то берется из error_get_last
     * @param ?Throwable $prev
     */
    public function __construct(?string $msg = null, ?Throwable $prev = null)
    {
        $msg = (string)$msg;
        if ($msg === '') {
            $error = error_get_last();
            error_clear_last();
            $msg = $error['message'] ?? 'Неопределенная ошибка';
        }

        parent::__construct($msg, 0, $prev);
    }
}
