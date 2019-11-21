<?php
/**
 * Copyright (c) 2019.
 *
 * @author Igor A Tarasov <develop@dicr.org>
 */

declare(strict_types = 1);
namespace dicr\file;

use Throwable;
use yii\base\Exception;

/**
 * Ошибка хранилища файлов.
 *
 * @author Igor (Dicr) Tarasov <develop@dicr.org>
 * @version 180624
 */
class StoreException extends Exception
{
    /**
     * Конструктор
     *
     * @param string|null $msg
     *     если не задано, то берется из error_get_last
     * @param \Throwable $prev
     */
    public function __construct(string $msg = '', Throwable $prev = null)
    {
        if ($msg === '') {
            $error = @error_get_last();
            /** @scrutinizer ignore-unhandled */
            @error_clear_last();
            $msg = $error['message'] ?? 'Неопределенная ошибка';
        }

        parent::__construct($msg, 0, $prev);
    }
}
