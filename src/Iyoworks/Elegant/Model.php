<?php namespace Elegant;
use Illuminate\Database\Eloquent\Model as IModel;
use Cache;
use Auth;
class Model extends IModel {
	public $timestamps = false;
	protected $includes;
	public $autoSetCreator = null;
	protected $softDelete;
	public $rules = array();
	private $ruleSubs = array();
	protected $messages = array();
	protected $errors;
	public $modelName = null;
	public $present = null;
	public $useCache = true;
	public $ttl = 20; // Time To Live - for cache
	// protected $url = array();

	public function __construct($attributes = array())
	{
		parent::__construct($attributes);
		  // initialize empty messages object
		$this->errors = new \Illuminate\Support\MessageBag();
		$this->modelName = get_class($this);

	}
	public  function newQuery(){
		$query = parent::newQuery();
		if($this->includes)
			return $query->with($this->includes);
		return $query;
	}
	/* Creator ****************************/

	private function autoSetCreator(){
		$this->setAttribute($this->autoSetCreator, Auth::user()->id);
	}

	/* Save ****************************/
	public function preCreate() {}
	public function postCreate() {}
	public function preSave() { return true; }
	public function postSave(){}

	public function save($validate=true, $preSave=null, $postSave=null)
	{
		$newRecord = !$this->exists;
		if ($validate)
			if (!$this->valid()) return false;
		if($newRecord)
			$this->preCreate();
		if ($this->autoSetCreator)
			$this->autoSetCreator();
		$before = is_null($preSave) ? $this->preSave() : $preSave($this);
		  // check before & valid, then pass to parent
		$success = ($before) ? parent::save() : false;
		if ($success){
			is_null($postSave) ? $this->postSave() : $postSave($this);
			if($newRecord)
				$this->postCreate();
			$this->cacheDelete();
		}
		return $success;
	}
	public function onForceSave(){}
	public function forceSave($onForceSave=null, $validate=true, $rules=array(), $messages=array())
	{
		if ($validate)
			$this->valid($rules, $messages);
		 $before = is_null($onForceSave) ? $this->onForceSave() : $onForceSave($this);  // execute onForceSave
		return $before ? parent::save() : false; // save regardless of the result of validation
	}
	/* Soft Delete ****************************/
	public function preSoftDelete() {  return true;  }
	public function postSoftDelete()  { }
	public function softDelete($val = true, $preSoftDelete=null, $postSoftDelete=null)
	{
		if ($this->exists)
		{
			$before = is_null($preSoftDelete) ? $this->preSoftDelete() : $preSoftDelete($this);
			$success = null;
			if($before) {
				$this->setAttribute($this->softDelete, $val);
				$success = $this->save(false);
			}
			else
				$success = false;
			if ($success)
			{
				is_null($postSoftDelete) ? $this->postSoftDelete() : $postSoftDelete($this);
				$this->cacheDelete();
			}
			return $success;
		}
	}

	/* Hard Delete ****************************/
	public function preDelete()  { return true;}
	public function postDelete(){}
	public function delete( $preDelete=null, $postDelete=null)
	{
		if ($this->exists)
		{
			$before = is_null($preDelete) ? $this->preDelete() : $preDelete($this);

			$success = ($before) ? parent::delete() : false;
			if ($success)
			{
				is_null($postDelete) ? $this->postDelete() : $postDelete($this);
				$this->cacheDelete();
			}
			return $success;
		}
	}

	/* Validate ****************************/
	public function valid( $rules=array(), $messages=array())
	{
		$valid = true; // innocent until proven guilty
		if(!empty($rules) || !empty($this->rules))
		{
			$rules = (empty($rules)) ? $this->rules : $rules;
			// check for overrides
			if (!empty($this->ruleSubs))
				$rules = $this->ruleSubs +  $rules;
			$messages = (empty($messages)) ? $this->messages : $messages;
			if ($this->exists)
			{  // if the model exists, this is an update
				$data = $this->get_dirty();
				$rules = array_intersect_key($rules, $data); // so just validate the fields that are being updated
			}
			else
				$data = $this->attributes;  // otherwise validate everything!

			$validator = Validator::make($data, $rules, $messages); // construct the validator
			$valid = $validator->valid();

			if($valid) // if the model is valid, unset old errors
			$this->errors->messages = array();
			else // otherwise set the new ones
			$this->errors = $validator->errors;
		}
		return $valid;
	}
	/* Caching ****************************/
	protected function getCacheKey($id, $key=null)
	{
		if($key)
			return 'model_'.$this->table.'_'.$id.'.'.$key;
		return 'model_'.$this->table.'_'.$id;
	}
	protected function cachePut($value, $key = null,$mins = 6){
		if($this->useCache){
			$cacheKey =  $this->getCacheKey($this->id, $key);
			if($mins === true)
				Cache::forever($cacheKey, $value);
			elseif($value instanceOf \Closure)
				Cache::remember( $cacheKey , $mins, $value);
			else
				Cache::put( $cacheKey , $value, $mins);
		}
	}
	protected function cacheGet($key = null, $value =null){
		return Cache::get($this->getCacheKey($this->id, $key), $value);
	}
	protected function cacheDelete(){
		if($this->useCache and Cache::has($this->getCacheKey($this->id)))
			Cache::forget($this->getCacheKey($this->id));
	}
	public function isDeleted(){
		if(!is_null($this->softDelete))
			return $this->{$this->softDelete};
		else
			throw new ElegantException("Column does not exist", "The softdelete column name has not been specified for the \"{$this->modelName}\" model.");
	}
	public function deleted($val =1){
		if(!is_null($this->softDelete))
			return $this->newQuery()->where($this->softDelete, '=',$val);
		else
			throw new ElegantException("Column does not exist", "The softdelete column name has not been specified for the \"{$this->modelName}\" model.");
	}

	/**
	 * Convert the model instance to an array.
	 * @return array
	 */
	public function toArray()
	{
		$attributes = parent::toArray();
		if(!is_null($this->present()))
			$attributes = array_merge($attributes, $this->present()->toArray());
		return $attributes;
	}
	/**
	 * Wrap a collection of objects with an array
	 * @return array
	 */
	public function arrayWrap($collection)
	{
		$collection = (array) $collection;
		return array_pop($collection);
	}

	public function present(){
		if(!is_object($this->present) and is_string($this->present)){
			$name = $this->present;
			$this->present = new $name($this);
		}
		return $this->present;
	}

	public function __get($key)
	{
		if($this->present())
		{
			if($this->present()->has($key))
				return $this->present()->$key;
		}
		return parent::__get($key);
	}

	/* STATIC FUNCTIONS ****************************/
	public static function dne($id)
	{
		if (static::find($id))
			return false;
		return true;
	}
	// public static function all($excSoftDeletes= true){
	// 	$instance = new static;
	// 	if(!is_null($instance->softDelete) and $excSoftDeletes)
	// 		return static::discarded(0)->get();
	// 	return parent::all();
	// }

	public static function discarded($val =1){
		$instance  = new static;
		return $instance->deleted($val);
	}

	public static function findFirst($col, $val){
		$instance = new static;
		return $instance->newQuery()->where($col, '=',$val)->first();
	}

}
