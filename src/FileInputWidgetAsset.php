<?php 
namespace dicr\filestore;

use yii\jui\JuiAsset;
use yii\web\AssetBundle;
use yii\web\JqueryAsset;


/**
 * Asset Bundle для FileInputWidget
 * 
 * @author Igor (Dicr) Tarasov <develop@dicr.org>
 * @version 2018
 */
class FileInputWidgetAsset extends AssetBundle {
	
	public $css = [
		'file-input-widget.css'
	];
	
	public $js = [
		'file-input-widget.js'
	];
	
	public $depends = [
		JqueryAsset::class,
		JuiAsset::class
	];

	/**
	 * {@inheritDoc}
	 * @see \yii\web\AssetBundle::init()
	 */
	public function init() {
		$path = preg_split('~\\\+~uism', static::class, -1, PREG_SPLIT_NO_EMPTY);
		$this->sourcePath = '@'.dirname(implode('/', $path)).'/assets';
	}
}
