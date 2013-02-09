<?php
namespace ActiveEloquent;

use Validator;
use IoC;

abstract class Base extends \Eloquent
{
	public $errors                    = array();
	protected  $error_handler         = null;
	protected  $custom_rules          = array(); // Override for instance
	protected  $custom_messages       = array(); // Override for instance

	public static $rules              = array();
	public static $messages           = array();
	
	protected static $valid_callbacks = array(
		'before_validation',
		'after_validation',
		'before_save',
		'after_save',
		'before_create',
		'after_create',
		'before_update',
		'after_update',
		'before_delete',
		'after_delete',
	);

	public function __construct($attributes = array(), $exists = false)
	{
		$this->error_handler = new \Laravel\Messages();
		parent::__construct($attributes, $exists);
	}

	public function __toString()
	{
		$class = get_called_class();
		$id    = $this->id ?: '(new)';
		return "{$class} {$id}";
	}

	public static function to_col($option = 'id', $value = 'name') { return static::to_collection($option, $value); }

	public static function to_collection($option = 'id', $value = 'name')
	{
		$objects = static::all();
		$collection = array();
		foreach ($objects as $object) {
			$collection[$object->$option] = $object->$value;
		}

		return $collection;
	}

	public function attr_accessors()
	{
		$accessors = array();
		foreach (static::$accessible as $a) {
			$accessors[$a] = isset($this->$a) ? $this->$a : null;
		}

		return $accessors;
	}

	public function exists()
	{
		return isset($this->exists) and $this->exists;
	}

	public function set_validation($rules = array(), $messages = array())
	{
		$this->custom_rules    = $rules;
		$this->custom_messages = $messages;

		return $this;
	}

	public function is_valid($exceptions = false)
	{
		$valid    = true;
		$data     = array();
		$rules    = $this->custom_rules ?: static::$rules;
		$messages = $this->custom_messages ?: static::$messages;

		$this->invoke_callback('before_validation');

		if ($rules) {
			if ($this->exists) {
				$data = array_merge($this->get_dirty(), $this->attr_accessors());
				$rules = array_intersect_key($rules, $data);
			} else {
				$data = array_merge($this->attributes, $this->attr_accessors());
			}

			$validator = Validator::make($data, $rules, $messages);

			if ($valid = $validator->valid()) {
				$this->error_handler->messages = array();
			} else {
				$this->errors = $validator->errors;
				if ($exceptions) {
					throw new ValidationException($this->error_handler->messages);
				}
			}
		}

		$this->invoke_callback('after_validation');

		return $valid;
	}

	public function save($force_save = false, $exceptions = false)
	{
		// Commenting out for now //if ( ! $this->dirty()) return true;

		if (static::$timestamps) {
			$this->timestamp();
		}

		if ($force_save or $this->is_valid($exceptions)) {
			$this->fire_event('saving');
			$this->invoke_callback('before_save');

			// If the model exists, we only need to update it in the database, and the update
			// will be considered successful if there is one affected row returned from the
			// fluent query instance. We'll set the where condition automatically.
			if ($this->exists) {
				$this->invoke_callback('before_update');

				$query = $this->query()->where(static::$key, '=', $this->get_key());

				$result = $query->update($this->get_dirty()) === 1;

				$result and $this->fire_event('updated');

				$this->invoke_callback('after_update');
			}

			// If the model does not exist, we will insert the record and retrieve the last
			// insert ID that is associated with the model. If the ID returned is numeric
			// then we can consider the insert successful.
			else {
				$this->invoke_callback('before_create');

				$id = $this->query()->insert_get_id($this->attributes, $this->sequence());

				$this->set_key($id);

				$this->exists = $result = is_numeric($this->get_key());

				$result and $this->fire_event('created');

				$this->invoke_callback('after_create');
			}

			$this->invoke_callback('after_save');

			$result and $this->fire_event('saved');

			// After the model has been "saved", we will set the original attributes to
			// match the current attributes so the model will not be viewed as being
			// dirty and subsequent calls won't hit the database.
			$this->original = $this->attributes;

			return $result;
		} else {
			return false;
		}
	}

	public function delete()
	{
		$this->invoke_callback('before_delete');

		$return = parent::delete();

		$this->invoke_callback('after_delete');

		return $return;
	}

	public static function create($attributes, $force_save = false)
	{
		$model = new static($attributes);

		$success = $model->save($force_save);

		return ($success) ? $model : false;
	}

	/**
	 * Update a model instance in the database.
	 *
	 * @param  mixed  $id
	 * @param  array  $attributes
	 * @return int
	 */
	public static function update($id, $attributes)
	{
		$this->invoke_callback('before_update');
		$model = new static(array(), true);

		if (static::$timestamps) $attributes['updated_at'] = new DateTime;

		$result = $model->query()->where($model->key(), '=', $id)->update($attributes);
		$this->invoke_callback('after_update');

		return $result;
	}

	/**
	 * Handle the dynamic retrieval of attributes and associations.
	 *
	 * @param  string  $key
	 * @return mixed
	 */
	public function __get($key)
	{
		// First we will check to see if the requested key is an already loaded
		// relationship and return it if it is. All relationships are stored
		// in the special relationships array so they are not persisted.
		if (array_key_exists($key, $this->relationships)) {
			return $this->relationships[$key];
		}

		// Next we'll check if the requested key is in the array of attributes
		// for the model. These are simply regular properties that typically
		// correspond to a single column on the database for the model.
		elseif (array_key_exists($key, $this->attributes)) {
			if (in_array(substr($key, strlen($key) - 3), ['_on', '_at'])) {
				if ($value = $this->get_attribute($key)) {
					$datetime = $value instanceof DateTime ? $value : new DateTime($value);
					if(ends_with($key, '_at') or ends_with($key, '_on')) {
						return $datetime;
					}
				} else {
					return new NullDateTime;
				}
			} else {
				return $this->{"get_{$key}"}();
			}
		}

		// If the item is not a loaded relationship, it may be a relationship
		// that hasn't been loaded yet. If it is, we will lazy load it and
		// set the value of the relationship in the relationship array.
		elseif (method_exists($this, $key)) {
			return $this->relationships[$key] = $this->$key()->results();
		}

		// One last chance before it's probably doomed.  Check if it's set in
		// accessible - then return null to emulate other orms' behavior
		elseif (static::$accessible && in_array($key, static::$accessible)) {
			return null;
		}
		// Finally we will just assume the requested key is just a regular
		// attribute and attempt to call the getter method for it, which
		// will fall into the __call method if one doesn't exist.
		else {
			if (in_array(substr($key, strlen($key) - 3), ['_on', '_at'])) {
				if ($value = $this->get_attribute($key)) {
					$datetime = $value instanceof DateTime ? $value : new DateTime($value);
					if(ends_with($key, '_at') or ends_with($key, '_on')) {
						return $datetime;
					}
				} else {
					return null;
				}
			} else {
				return $this->{"get_{$key}"}();
			}
		}
	}

	public function __set($key, $value)
	{
		// only update an attribute if there's a change
		if (!array_key_exists($key, $this->attributes) || $value !== $this->$key) {
			if (in_array(substr($key, strlen($key) - 3), ['_on', '_at'])) {
				if ($value) {
					$value = $value instanceof DateTime ? $value : new DateTime(is_object($value) ? $value->format('Y-m-d H:i:s') : $value);
					return $this->set_attribute($key, $value);
				} else {
					return new NullDateTime;
				}
			} else {
				parent::__set($key, $value);
			}
		}
	}

	protected function invoke_callback($name)
	{
		method_exists($this, $name) and $this->$name();
	}

}
