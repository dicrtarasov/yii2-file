<?php
namespace dicr\file;

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
     *            если не задано, то берется из error_get_last
     * @param \Throwable $prev
     */
    public function __construct(string $msg = '', \Throwable $prev = null)
    {
        if ($msg == '') {
            $error = error_get_last();
            error_clear_last();
            $msg = $error['message'];
        }

        parent::__construct($msg, 0, $prev);
    }
}
