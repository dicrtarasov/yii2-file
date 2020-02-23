<?php
/**
 * @copyright 2019-2020 Dicr http://dicr.org
 * @author Igor A Tarasov <develop@dicr.org>
 * @license GPL
 * @version 24.02.20 01:12:49
 */

declare(strict_types = 1);

namespace dicr\tests\webapp;

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
}
