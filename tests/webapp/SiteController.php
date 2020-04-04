<?php
/**
 * @copyright 2019-2020 Dicr http://dicr.org
 * @author Igor A Tarasov <develop@dicr.org>
 * @license GPL
 * @version 04.04.20 19:20:13
 */

declare(strict_types = 1);

namespace dicr\tests\webapp;

use dicr\file\T;
use Yii;
use yii\web\Controller;

/**
 * Default test Controller
 *
 * @noinspection PhpUnused
 */
class SiteController extends Controller
{
    /**
     * Индекс
     *
     * @return string
     */
    public function actionIndex()
    {
        $model = TestModel::findOne(['id' => 1]);
        if ($model === null) {
            $model = new TestModel();
        }

        if (Yii::$app->request->isPost) {
            $model->load(Yii::$app->request->post());
            $model->loadFileAttributes();

            if ($model->validate()) {
                $model->save();

                return $this->redirect(['index'], 303);
            }
        }

        return $this->render('index', [
            'model' => $model
        ]);
    }

    /**
     * @noinspection PhpMethodMayBeStaticInspection
     */
    public function actionTest()
    {
        Yii::$app->language = 'ua';

        echo T::t('Добавить');
    }
}
